<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenProvider;
use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\Authenticator;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\DiTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\MobileLogin;
use OCA\WorkoutCalendar\Garmin\Auth\PasswordTokenProvider;
use OCA\WorkoutCalendar\Garmin\Auth\WidgetLogin;
use OCA\WorkoutCalendar\Garmin\Diagnostics\LoggingHttpClient;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\Diagnostics\ResponseClassifier;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
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
    /** Shorter cookie values (e.g. the flag "1") are not session secrets; masking them would garble every number in the log. */
    private const MIN_MASKED_COOKIE_LENGTH = 8;

    /** Wait of the widget login before submitting the form; null for the real wait. */
    private ?\Closure $widgetSleep = null;

    public function __construct(
        private readonly HttpClientProvider $http,
        private readonly SecretStore $secrets,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Copy whose widget login waits with `$sleep` instead of sleep(), for tests.
     *
     * @param callable(int): void $sleep
     */
    public function withWidgetSleep(callable $sleep): self
    {
        $copy = clone $this;
        $copy->widgetSleep = \Closure::fromCallable($sleep);

        return $copy;
    }

    public function client(string $uid, Credentials $credentials, bool $passwordLogin = true): GarminClient
    {
        $cookies = $this->secrets->ssoCookies($uid);
        $redactor = new Redactor([$credentials->password, $credentials->email, ...self::cookieValues($cookies)]);
        $http = $this->http($redactor);
        if ($cookies !== []) {
            $http->importCookies($cookies);
        }

        $tokens = new PasswordTokenProvider(
            $this->authenticatorFor($http, $redactor),
            new DiTokenService($http),
            new UserTokenStore($this->secrets, $uid),
            $credentials,
            $this->logger,
            passwordLogin: $passwordLogin,
        );

        return new GarminClient($http, $tokens, $this->logger, GarminConstants::CONNECT_API, [$credentials->password]);
    }

    /** Client for sign-in with the personal API key; the key is exchanged for an access token when needed. */
    public function apiKeyClient(string $uid, string $apiKey): GarminClient
    {
        $redactor = new Redactor([$apiKey]);
        $http = $this->http($redactor);
        $tokens = new ApiKeyTokenProvider(new ApiKeyTokenService($http, $redactor), new UserTokenStore($this->secrets, $uid), $apiKey);

        return new GarminClient($http, $tokens, $this->logger, GarminConstants::PERSONAL_API, [$apiKey]);
    }

    /** Key exchange without stored tokens, e.g. to check a newly entered key. */
    public function apiKeyTokens(string $apiKey): ApiKeyTokenService
    {
        $redactor = new Redactor([$apiKey]);

        return new ApiKeyTokenService($this->http($redactor), $redactor);
    }

    /**
     * Login with its own HTTP connection, e.g. to confirm a code in the SSO session of a challenge.
     *
     * @param list<string> $cookies Netscape cookie lines to continue a session; their values are masked as well
     * @param list<string> $knownSecrets Values the log and response excerpts must never contain
     */
    public function mobileLogin(array $cookies = [], array $knownSecrets = []): MobileLogin
    {
        $redactor = new Redactor([...$knownSecrets, ...self::cookieValues($cookies)]);
        $http = $this->http($redactor);
        if ($cookies !== []) {
            $http->importCookies($cookies);
        }

        return new MobileLogin($http, new DiTokenService($http), $redactor);
    }

    /**
     * The value column of Netscape cookie lines, as secrets for a Redactor. A whole line never appears in a text.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function cookieValues(array $lines): array
    {
        $values = [];
        foreach ($lines as $line) {
            $value = explode("\t", $line)[6] ?? '';
            if (strlen($value) >= self::MIN_MASKED_COOKIE_LENGTH) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** Login without stored tokens, e.g. to check new credentials. */
    public function authenticator(): Authenticator
    {
        return $this->authenticatorFor($this->http(new Redactor()));
    }

    private function authenticatorFor(HttpClient $http, ?Redactor $redactor = null): Authenticator
    {
        $diTokens = new DiTokenService($http);

        return new Authenticator([new MobileLogin($http, $diTokens, $redactor), new WidgetLogin($http, $diTokens, $this->widgetSleep)], $this->logger);
    }

    private function http(Redactor $redactor): HttpClient
    {
        return new LoggingHttpClient($this->http->create(), $this->logger, new ResponseClassifier(), $redactor);
    }
}
