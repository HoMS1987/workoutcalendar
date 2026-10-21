<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;

/** Login via the SSO embed widget (python-garminconnect: strategy "widget"). */
final class WidgetLogin implements LoginStrategy
{
    private const SSO_BASE = GarminConstants::SSO . '/sso';
    private const EMBED = self::SSO_BASE . '/embed';

    private readonly ResponseFailures $failures;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @param (callable(int): void)|null $sleep Wait between fetching and submitting the form, replaceable in tests */
    public function __construct(
        private readonly HttpClient $http,
        private readonly DiTokenService $tokens,
        ?callable $sleep = null,
    ) {
        $this->failures = new ResponseFailures();
        $this->sleep = $sleep !== null ? \Closure::fromCallable($sleep) : static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function name(): string
    {
        return 'widget';
    }

    public function login(Credentials $credentials): GarminTokens
    {
        $browser = ['User-Agent' => GarminConstants::DESKTOP_USER_AGENT, 'Accept-Language' => 'en-US,en;q=0.9'];
        $embedParams = ['id' => 'gauth-widget', 'embedWidget' => 'true', 'gauthHost' => self::SSO_BASE];
        $signinUrl = self::SSO_BASE . '/signin?' . http_build_query([
            ...$embedParams,
            'gauthHost' => self::EMBED,
            'service' => self::EMBED,
            'source' => self::EMBED,
            'redirectAfterAccountLoginUrl' => self::EMBED,
            'redirectAfterAccountCreationUrl' => self::EMBED,
        ]);

        $step = 'widget.embed';
        $response = $this->http->send(new HttpRequest($step, 'GET', self::EMBED . '?' . http_build_query($embedParams), $browser));
        $this->failures->assertOk($response, $step);

        $step = 'widget.signin-page';
        $response = $this->http->send(new HttpRequest($step, 'GET', $signinUrl, $browser + ['Referer' => self::EMBED]));
        $this->failures->assertOk($response, $step);
        $csrf = self::csrf($response->body);
        if ($csrf === null) {
            throw $this->failures->create(FailureCategory::ProtocolChanged, $step, 'Kein CSRF-Token im Anmeldeformular', $response);
        }

        // WHY: python-garminconnect also waits 3-8 s; submitting immediately looks like a bot.
        ($this->sleep)(random_int(3, 8));

        $step = 'widget.signin-post';
        $response = $this->http->send(new HttpRequest($step, 'POST', $signinUrl, $browser + [
            'Referer' => $response->effectiveUrl !== '' ? $response->effectiveUrl : $signinUrl,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'username' => $credentials->email,
            'password' => $credentials->password,
            'embed' => 'true',
            '_csrf' => $csrf,
        ])));
        $this->failures->assertOk($response, $step);

        $title = ResponseFailures::pageTitle($response->body);
        if ($title !== 'Success') {
            // ASSUMPTION: With a wrong password Garmin shows the form again, with MFA a page with "MFA"
            // in the title. Neither has been verified with a real failed attempt.
            $category = match (true) {
                stripos($title, 'MFA') !== false => FailureCategory::MfaRequired,
                self::csrf($response->body) !== null => FailureCategory::InvalidCredentials,
                default => FailureCategory::ProtocolChanged,
            };
            throw $this->failures->create($category, $step, "Unerwarteter Seitentitel: '$title'", $response);
        }
        if (!preg_match('/[?&]ticket=(ST-[^"&\s\\\\]+)/', $response->body, $ticket)) {
            throw $this->failures->create(FailureCategory::ProtocolChanged, $step, 'Kein Service-Ticket auf der Erfolgsseite', $response);
        }

        return $this->tokens->exchangeTicket($ticket[1], self::EMBED, $this->name());
    }

    private static function csrf(string $html): ?string
    {
        return preg_match('/name="_csrf"\s+value="(.+?)"/', $html, $m) ? $m[1] : null;
    }
}
