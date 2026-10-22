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

/** Exchanges service tickets for DI tokens and refreshes them (flow as in python-garminconnect 0.3.19). */
final class DiTokenService
{
    public const TOKEN_URL = 'https://diauth.garmin.com/di-oauth2-service/oauth/token';
    public const TICKET_GRANT_TYPE = 'https://connectapi.garmin.com/di-oauth2-service/oauth/grant/service_ticket';

    /** Order as in python-garminconnect: newest client ID first. */
    public const CLIENT_IDS = [
        'GARMIN_CONNECT_MOBILE_ANDROID_DI_2025Q2',
        'GARMIN_CONNECT_MOBILE_ANDROID_DI_2024Q4',
        'GARMIN_CONNECT_MOBILE_ANDROID_DI',
        'GARMIN_CONNECT_MOBILE_IOS_DI',
    ];

    private readonly ResponseClassifier $classifier;
    private readonly Redactor $redactor;

    public function __construct(private readonly HttpClient $http)
    {
        $this->classifier = new ResponseClassifier();
        $this->redactor = new Redactor();
    }

    /**
     * Exchanges the service ticket of a successful sign-in for DI tokens.
     *
     * @param string $serviceUrl Service the ticket was issued for
     * @param string $strategy Name of the login strategy, prefix of the log step
     * @throws GarminException
     */
    public function exchangeTicket(string $ticket, string $serviceUrl, string $strategy): GarminTokens
    {
        $step = "$strategy.di-exchange";
        $last = null;
        foreach (self::CLIENT_IDS as $clientId) {
            $last = $this->post($step, $clientId, [
                'client_id' => $clientId,
                'service_ticket' => $ticket,
                'grant_type' => self::TICKET_GRANT_TYPE,
                'service_url' => $serviceUrl,
            ]);
            if ($last->status === 200) {
                $access = $this->accessTokenFrom($last, $step);
                $refresh = $last->json()['refresh_token'] ?? null;

                return new GarminTokens($access, is_string($refresh) ? $refresh : null, GarminTokens::clientIdFromJwt($access) ?? $clientId);
            }
            // As in python-garminconnect, a failed exchange moves on to the next client ID; a block ends the attempt.
            if ($this->classifier->classify($last) === FailureCategory::Blocked) {
                throw $this->failure(FailureCategory::Blocked, $step, 'Ticket exchange blocked', $last);
            }
        }

        throw $this->failure(FailureCategory::ProtocolChanged, $step, 'Ticket exchange failed for every known client ID', $last);
    }

    /**
     * New tokens from the refresh token. If Garmin sends no new refresh token, the old one is kept.
     *
     * @throws GarminException TokenRejected if Garmin no longer accepts the refresh token
     */
    public function refresh(GarminTokens $tokens): GarminTokens
    {
        $step = 'refresh';
        if ($tokens->refreshToken === null) {
            throw new GarminException(FailureCategory::TokenRejected, $step, 'No refresh token available');
        }
        $response = $this->post($step, $tokens->clientId, [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens->clientId,
            'refresh_token' => $tokens->refreshToken,
        ]);
        if ($response->status !== 200) {
            // OAuth 2.0 (RFC 6749, 5.2) answers an invalid or expired grant with 400 and a rejected client
            // with 401; both need a new sign-in.
            $category = in_array($response->status, [400, 401], true)
                ? FailureCategory::TokenRejected
                : ($this->classifier->classify($response) ?? FailureCategory::ProtocolChanged);
            throw $this->failure($category, $step, 'Token refresh failed', $response);
        }
        $access = $this->accessTokenFrom($response, $step);
        $refresh = $response->json()['refresh_token'] ?? null;

        return new GarminTokens(
            $access,
            is_string($refresh) && $refresh !== '' ? $refresh : $tokens->refreshToken,
            GarminTokens::clientIdFromJwt($access) ?? $tokens->clientId,
        );
    }

    /** @param array<string, string> $form */
    private function post(string $step, string $clientId, array $form): HttpResponse
    {
        return $this->http->send(new HttpRequest($step, 'POST', self::TOKEN_URL, GarminConstants::NATIVE_HEADERS + [
            // Client authentication as in python-garminconnect: client ID as user name, empty secret.
            'Authorization' => 'Basic ' . base64_encode("$clientId:"),
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Cache-Control' => 'no-cache',
        ], http_build_query($form)));
    }

    private function accessTokenFrom(HttpResponse $response, string $step): string
    {
        $access = $response->json()['access_token'] ?? null;
        if (!is_string($access) || $access === '') {
            throw $this->failure(FailureCategory::ProtocolChanged, $step, 'Response without access_token', $response);
        }

        return $access;
    }

    private function failure(FailureCategory $category, string $step, string $message, ?HttpResponse $response): GarminException
    {
        return new GarminException($category, $step, $message, $response?->status, $this->redactor->excerpt($response->body ?? ''));
    }
}
