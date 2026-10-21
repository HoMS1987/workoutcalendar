<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;

/** Login like the Garmin Connect iOS app (python-garminconnect: strategy "mobile"). */
final class MobileLogin implements LoginStrategy
{
    public const SSO_CLIENT_ID = 'GCM_IOS_DARK';
    public const SERVICE_URL = 'https://mobile.integration.garmin.com/gcm/ios';

    private const STEP = 'mobile.login';

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
        $query = http_build_query(['clientId' => self::SSO_CLIENT_ID, 'locale' => 'en-US', 'service' => self::SERVICE_URL]);
        $response = $this->http->send(new HttpRequest(self::STEP, 'POST', GarminConstants::SSO . "/mobile/api/login?$query", [
            'User-Agent' => GarminConstants::IOS_USER_AGENT,
            'Accept' => 'application/json, text/plain, */*',
            'Content-Type' => 'application/json',
            'Origin' => GarminConstants::SSO,
        ], json_encode([
            'username' => $credentials->email,
            'password' => $credentials->password,
            'rememberMe' => true,
            'captchaToken' => '',
        ], JSON_THROW_ON_ERROR)));
        $this->failures->assertOk($response, self::STEP);

        $data = $response->json();
        $type = $data['responseStatus']['type'] ?? null;
        if (!is_string($type)) {
            throw $this->failures->create(FailureCategory::ProtocolChanged, self::STEP, 'Antwort ohne responseStatus.type', $response);
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
            throw $this->failures->create($category, self::STEP, "responseStatus.type = $type", $response);
        }

        $ticket = $data['serviceTicketId'] ?? null;
        if (!is_string($ticket) || $ticket === '') {
            throw $this->failures->create(FailureCategory::ProtocolChanged, self::STEP, 'Erfolg ohne serviceTicketId', $response);
        }

        return $this->tokens->exchangeTicket($ticket, self::SERVICE_URL, $this->name());
    }
}
