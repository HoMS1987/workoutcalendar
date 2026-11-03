<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;

/** Exchanges a personal API key for a short-lived access token. */
final class ApiKeyTokenService
{
    public const TOKEN_URL = GarminConstants::PERSONAL_API . '/personalapitoken-service/personal-api/personal-api-key/token';
    public const CLIENT_ID = 'personal-api';

    private const STEP = 'apikey.token';

    /** Claims that may hold the Garmin user, in order of preference. The names are a guess until checked against a real token. */
    private const USER_ID_CLAIMS = ['garmin_guid', 'userProfilePK', 'user_id', 'sub'];

    private readonly ResponseFailures $failures;

    /** @param Redactor $redactor Must know the key as a secret, so it is masked in messages and excerpts */
    public function __construct(private readonly HttpClient $http, private readonly Redactor $redactor)
    {
        $this->failures = new ResponseFailures($redactor);
    }

    /**
     * @throws GarminException ApiKeyRejected if Garmin answers 401 or 403, otherwise classified as usual
     */
    public function exchange(string $apiKey): GarminTokens
    {
        try {
            $response = $this->http->send(new HttpRequest(self::STEP, 'POST', self::TOKEN_URL, [
                'Authorization' => 'ApiKey ' . $apiKey,
                'Accept' => '*/*',
            ]));
        } catch (NetworkException $e) {
            throw new GarminException(FailureCategory::Network, self::STEP, $this->redactor->redact($e->getMessage()), previous: $e);
        }

        if ($response->status === 401 || $response->status === 403) {
            throw $this->failures->create(FailureCategory::ApiKeyRejected, self::STEP, "HTTP {$response->status}", $response);
        }
        $this->failures->assertOk($response, self::STEP);

        $access = $response->json()['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw $this->failures->create(FailureCategory::ProtocolChanged, self::STEP, 'Response without access_token', $response);
        }

        return new GarminTokens($access, null, self::CLIENT_ID);
    }

    /** The Garmin user the token belongs to: the first non-empty string among the known claims. */
    public static function garminUserId(GarminTokens $tokens): ?string
    {
        $claims = $tokens->claims();
        foreach (self::USER_ID_CLAIMS as $name) {
            $value = $claims[$name] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
