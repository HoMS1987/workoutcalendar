<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;

/** Exchanges a personal API key for a short-lived access token. */
final class ApiKeyTokenService
{
    public const TOKEN_URL = GarminConstants::PERSONAL_API . '/personalapitoken-service/personal-api/personal-api-key/token';
    public const CLIENT_ID = 'personal-api';

    private const STEP = 'apikey.token';

    /**
     * Claims that may hold the Garmin user, in order of preference. The names are a guess until checked
     * against a real token. WHY no 'sub': it may name the key rather than the user, so a renewed key would
     * count as another account.
     */
    private const USER_ID_CLAIMS = ['garmin_guid', 'userProfilePK', 'user_id'];

    /** Header, payload and signature in base64url, nothing around them. */
    private const JWT_PATTERN = '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*$/';

    private readonly ResponseFailures $failures;
    private readonly ResponseClassifier $classifier;

    /** @param Redactor $redactor Must know the key as a secret, so it is masked in messages and excerpts */
    public function __construct(private readonly HttpClient $http, private readonly Redactor $redactor)
    {
        $this->failures = new ResponseFailures($redactor);
        $this->classifier = new ResponseClassifier();
    }

    /**
     * @throws GarminException ApiKeyRejected if Garmin answers 401 or a 403 that is not a Cloudflare challenge,
     *         otherwise classified as usual (a Cloudflare challenge 403 is Blocked)
     */
    public function exchange(#[\SensitiveParameter] string $apiKey): GarminTokens
    {
        try {
            $response = $this->http->send(new HttpRequest(self::STEP, 'POST', self::TOKEN_URL, [
                'Authorization' => 'ApiKey ' . $apiKey,
                'Accept' => '*/*',
            ]));
        } catch (NetworkException $e) {
            // No previous: the original exception carries the unredacted text, which may contain the key.
            throw new GarminException(FailureCategory::Network, self::STEP, $this->redactor->redact($e->getMessage()));
        }

        // WHY only a challenge: a Cloudflare block says nothing about the key and must not pause the sync,
        // but cf-ray and server: cloudflare are on every response of the host, Garmin's own rejection included.
        if ($response->status === 401 || ($response->status === 403 && !$this->isCloudflareChallenge($response))) {
            throw $this->failures->create(FailureCategory::ApiKeyRejected, self::STEP, "HTTP {$response->status}", $response);
        }
        $this->failures->assertOk($response, self::STEP);

        // WHY text: the endpoint answers with the bare JWT, not with JSON (as Garmin's own Python client reads it).
        $tokens = new GarminTokens(trim($response->body), null, self::CLIENT_ID);
        if (preg_match(self::JWT_PATTERN, $tokens->accessToken) !== 1 || $tokens->expiresAt() === null) {
            throw $this->failures->create(FailureCategory::ProtocolChanged, self::STEP, 'Response is not a JWT with exp', $response);
        }

        return $tokens;
    }

    /** The Garmin user the token belongs to: the first non-empty string or integer among the known claims. */
    public static function garminUserId(GarminTokens $tokens): ?string
    {
        $claims = $tokens->claims();
        foreach (self::USER_ID_CLAIMS as $name) {
            $value = $claims[$name] ?? null;
            // WHY: Garmin's profile IDs are usually numbers.
            if (is_int($value)) {
                $value = (string)$value;
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function isCloudflareChallenge(HttpResponse $response): bool
    {
        $markers = $this->classifier->cloudflareMarkers($response);

        return isset($markers['cf-mitigated']) || isset($markers['challenge-page']);
    }
}
