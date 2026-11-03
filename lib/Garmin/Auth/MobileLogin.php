<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;

/** Login like the Garmin Connect iOS app (python-garminconnect: strategy "mobile"). */
final class MobileLogin implements LoginStrategy
{
    public const SSO_CLIENT_ID = 'GCM_IOS_DARK';
    public const SERVICE_URL = 'https://mobile.integration.garmin.com/gcm/ios';
    public const PORTAL_SSO_CLIENT_ID = 'GarminConnect';
    public const PORTAL_SERVICE_URL = 'https://connect.garmin.com/app';

    private const STEP = 'mobile.login';
    private const DEFAULT_MFA_METHOD = 'email';

    private readonly ResponseFailures $failures;

    public function __construct(
        private readonly HttpClient $http,
        private readonly DiTokenService $tokens,
    ) {
        $this->failures = new ResponseFailures();
    }

    public function name(): string
    {
        return 'mobile';
    }

    public function login(Credentials $credentials): GarminTokens
    {
        // Body as in python-garminconnect, which follows the iOS app.
        $body = json_encode([
            'username' => $credentials->email,
            'password' => $credentials->password,
            'rememberMe' => true,
            'captchaToken' => '',
        ], JSON_THROW_ON_ERROR);
        $response = $this->http->send(new HttpRequest(
            self::STEP,
            'POST',
            $this->url('/mobile/api/login', self::SSO_CLIENT_ID, self::SERVICE_URL),
            $this->headers(),
            $body,
        ));
        $this->failures->assertOk($response, self::STEP);

        $data = $response->json();
        $type = $data['responseStatus']['type'] ?? null;
        if (!is_string($type)) {
            throw $this->failures->create(FailureCategory::ProtocolChanged, self::STEP, 'Response without responseStatus.type', $response);
        }

        // ASSUMPTION: The status names for a wrong password and MFA come from python-garminconnect;
        // they have not been verified with a real failed attempt.
        $category = match (true) {
            $type === 'SUCCESSFUL' => null,
            str_contains($type, 'MFA') => FailureCategory::MfaRequired,
            str_contains($type, 'INVALID') || str_contains($type, 'PASSWORD') => FailureCategory::InvalidCredentials,
            default => FailureCategory::ProtocolChanged,
        };
        if ($category !== null) {
            $failure = $this->failures->create($category, self::STEP, "responseStatus.type = $type", $response);
            if ($category === FailureCategory::MfaRequired) {
                // The code must be confirmed in this very SSO session, so the cookies travel with the failure.
                $method = $data['customerMfaInfo']['mfaLastMethodUsed'] ?? null;
                $challenge = new MfaChallenge(is_string($method) && $method !== '' ? $method : null, $this->http->exportCookies());
                $failure = new GarminException($category, self::STEP, $failure->getMessage(), $failure->status, $failure->excerpt, mfaChallenge: $challenge);
            }

            throw $failure;
        }

        $ticket = $data['serviceTicketId'] ?? null;
        if (!is_string($ticket) || $ticket === '') {
            throw $this->failures->create(FailureCategory::ProtocolChanged, self::STEP, 'Success without serviceTicketId', $response);
        }

        return $this->tokens->exchangeTicket($ticket, self::SERVICE_URL, $this->name());
    }

    /**
     * Confirms an MFA code in the SSO session of the challenge. Like python-garminconnect, the portal endpoint
     * is tried when the mobile one is blocked, answers without JSON or does not report success.
     *
     * @throws GarminException
     */
    public function verifyCode(MfaChallenge $challenge, string $code): GarminTokens
    {
        $this->http->importCookies($challenge->cookies);
        $body = json_encode([
            'mfaMethod' => $challenge->method ?? self::DEFAULT_MFA_METHOD,
            'mfaVerificationCode' => $code,
            'rememberMyBrowser' => true,
            'reconsentList' => [],
            'mfaSetup' => false,
        ], JSON_THROW_ON_ERROR);

        $endpoints = [
            ['mobile.mfa-verify', '/mobile/api/mfa/verifyCode', self::SSO_CLIENT_ID, self::SERVICE_URL],
            ['portal.mfa-verify', '/portal/api/mfa/verifyCode', self::PORTAL_SSO_CLIENT_ID, self::PORTAL_SERVICE_URL],
        ];
        $last = null;
        $rejected = false;
        foreach ($endpoints as [$step, $path, $clientId, $serviceUrl]) {
            $response = $this->http->send(new HttpRequest($step, 'POST', $this->url($path, $clientId, $serviceUrl), $this->headers(), $body));
            try {
                $this->failures->assertOk($response, $step);
            } catch (GarminException $e) {
                $last = $e;
                continue;
            }

            $data = $response->json();
            if ($data === null) {
                $last = $this->failures->create(FailureCategory::ProtocolChanged, $step, 'Response is not JSON', $response);
                continue;
            }
            $type = $data['responseStatus']['type'] ?? null;
            if ($type !== 'SUCCESSFUL') {
                $rejected = true;
                $typeText = is_string($type) ? $type : 'missing';
                $last = $this->failures->create(FailureCategory::MfaCodeInvalid, $step, "responseStatus.type = $typeText", $response);
                continue;
            }
            $ticket = $data['serviceTicketId'] ?? null;
            if (!is_string($ticket) || $ticket === '') {
                $last = $this->failures->create(FailureCategory::ProtocolChanged, $step, 'Success without serviceTicketId', $response);
                continue;
            }

            return $this->tokens->exchangeTicket($ticket, self::SERVICE_URL, $this->name());
        }

        // At least one JSON answer without success means Garmin looked at the code; otherwise the last attempt decides.
        if ($rejected && $last->category !== FailureCategory::MfaCodeInvalid) {
            $last = new GarminException(FailureCategory::MfaCodeInvalid, $last->step, $last->getMessage(), $last->status, $last->excerpt);
        }

        throw $last;
    }

    /** @return list<string> Netscape cookie lines of the current SSO session */
    public function exportCookies(): array
    {
        return $this->http->exportCookies();
    }

    private function url(string $path, string $clientId, string $serviceUrl): string
    {
        $query = http_build_query(['clientId' => $clientId, 'locale' => 'en-US', 'service' => $serviceUrl]);

        return GarminConstants::SSO . "$path?$query";
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'User-Agent' => GarminConstants::IOS_USER_AGENT,
            'Accept' => 'application/json, text/plain, */*',
            'Content-Type' => 'application/json',
            'Origin' => GarminConstants::SSO,
        ];
    }
}
