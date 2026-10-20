<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Tests\Unit\Garmin\Auth;

use OCA\GarminCalendar\Garmin\Auth\Authenticator;
use OCA\GarminCalendar\Garmin\Auth\Credentials;
use OCA\GarminCalendar\Garmin\Auth\GarminTokens;
use OCA\GarminCalendar\Garmin\Auth\LoginStrategy;
use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\GarminCalendar\Garmin\GarminException;
use OCA\GarminCalendar\Garmin\Http\NetworkException;
use OCA\GarminCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
    }

    private static function strategy(string $name, GarminTokens|\Throwable $result, int &$calls = 0): LoginStrategy
    {
        return new class ($name, $result, $calls) implements LoginStrategy {
            public function __construct(private string $name, private GarminTokens|\Throwable $result, private int &$calls)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function login(Credentials $credentials): GarminTokens
            {
                $this->calls++;
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    public function testFallsBackToNextStrategyAndLogsWhichOneWorked(): void
    {
        $tokens = new GarminTokens('a', 'r', 'C');
        $authenticator = new Authenticator([
            self::strategy('mobile', new GarminException(FailureCategory::Blocked, 'mobile.login', 'blockiert', 429)),
            self::strategy('widget', $tokens),
        ], $this->logger);

        self::assertSame($tokens, $authenticator->login(new Credentials('a@b.de', 'geheim')));

        self::assertSame([], $this->logger->byLevel('warning'), 'Warnungen loggt gedrosselt erst der Sync-Ablauf');
        $attempt = $this->logger->byLevel('debug')[0];
        self::assertSame('mobile', $attempt['context']['strategy']);
        self::assertSame('BLOCKED', $attempt['context']['category']);
        self::assertSame(429, $attempt['context']['status']);
        $info = $this->logger->byLevel('info')[0];
        self::assertSame('widget', $info['context']['strategy']);
        self::assertSame(['mobile'], $info['context']['failedBefore']);
        self::assertStringNotContainsString('geheim', $this->logger->dump());
    }

    public function testInvalidCredentialsStopWithoutTryingOtherStrategies(): void
    {
        $widgetCalls = 0;
        $authenticator = new Authenticator([
            self::strategy('mobile', new GarminException(FailureCategory::InvalidCredentials, 'mobile.login', 'abgelehnt')),
            self::strategy('widget', new GarminTokens('a', 'r', 'C'), $widgetCalls),
        ], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::InvalidCredentials, $e->category);
        }
        self::assertSame(0, $widgetCalls, 'Weitere Versuche könnten das Garmin-Konto sperren');
    }

    public function testAllStrategiesFailingThrowsSummaryOfAttempts(): void
    {
        $authenticator = new Authenticator([
            self::strategy('mobile', new GarminException(FailureCategory::Blocked, 'mobile.login', 'x', 429)),
            self::strategy('widget', new GarminException(FailureCategory::ProtocolChanged, 'widget.signin-page', 'kein CSRF')),
        ], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::ProtocolChanged, $e->category);
            self::assertSame('widget.signin-page', $e->step);
            $expected = ['mobile' => 'BLOCKED @ mobile.login', 'widget' => 'PROTOCOL_CHANGED @ widget.signin-page'];
            self::assertSame($expected, $e->attempts);
            self::assertSame($expected, $e->logContext()['attempts']);
        }
        self::assertSame([], $this->logger->byLevel('error'));
        self::assertSame([], $this->logger->byLevel('warning'));
    }

    public function testFinalFailureAlsoCarriesAttempts(): void
    {
        $authenticator = new Authenticator([
            self::strategy('mobile', new GarminException(FailureCategory::Blocked, 'mobile.login', 'x', 429)),
            self::strategy('widget', new GarminException(FailureCategory::InvalidCredentials, 'widget.signin-post', 'abgelehnt')),
        ], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::InvalidCredentials, $e->category);
            self::assertSame(['mobile' => 'BLOCKED @ mobile.login', 'widget' => 'INVALID_CREDENTIALS @ widget.signin-post'], $e->attempts);
        }
    }

    public function testNetworkErrorBecomesGarminException(): void
    {
        $authenticator = new Authenticator([self::strategy('mobile', new NetworkException('timeout'))], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException erwartet');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::Network, $e->category);
        }
    }

    public function testCredentialsHidePasswordInDumps(): void
    {
        $credentials = new Credentials('a@b.de', 'geheim');
        ob_start();
        var_dump($credentials);
        $dump = ob_get_clean() . print_r($credentials, true);

        self::assertStringNotContainsString('geheim', $dump);
        self::assertSame('geheim', $credentials->password);
    }
}
