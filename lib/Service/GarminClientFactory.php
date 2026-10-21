<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Garmin\Auth\Authenticator;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\MobileLogin;
use OCA\WorkoutCalendar\Garmin\Auth\WidgetLogin;
use OCA\WorkoutCalendar\Garmin\Diagnostics\LoggingHttpClient;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserTokenStore;
use Psr\Log\LoggerInterface;

/**
 * Builds the Garmin client and login. Every call gets its own HTTP client so that cookies
 * are never shared between people.
 */
final class GarminClientFactory
{
    public function __construct(
        private readonly HttpClientProvider $http,
        private readonly SecretStore $secrets,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function client(string $uid, Credentials $credentials): GarminClient
    {
        $http = $this->http([$credentials->password, $credentials->email]);

        return new GarminClient(
            $http,
            $this->authenticatorFor($http),
            new DiTokenService($http),
            new UserTokenStore($this->secrets, $uid),
            $credentials,
            $this->logger,
        );
    }

    /** Login without stored tokens, e.g. to check new credentials. */
    public function authenticator(): Authenticator
    {
        return $this->authenticatorFor($this->http());
    }

    private function authenticatorFor(HttpClient $http): Authenticator
    {
        $diTokens = new DiTokenService($http);

        return new Authenticator([new MobileLogin($http, $diTokens), new WidgetLogin($http, $diTokens)], $this->logger);
    }

    /** @param list<string> $knownSecrets */
    private function http(array $knownSecrets = []): HttpClient
    {
        return new LoggingHttpClient($this->http->create(), $this->logger, new ResponseClassifier(), new Redactor($knownSecrets));
    }
}
