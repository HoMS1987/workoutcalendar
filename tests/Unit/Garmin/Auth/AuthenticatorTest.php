<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Auth\Authenticator;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\LoginStrategy;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
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
            self::strategy('mobile', new GarminException(FailureCategory::Blocked, 'mobile.login', 'blocked', 429)),
            self::strategy('widget', $tokens),
        ], $this->logger);

        self::assertSame($tokens, $authenticator->login(new Credentials('a@b.de', 'secret')));

        self::assertSame([], $this->logger->byLevel('warning'), 'Warnings are logged, throttled, only by the sync run');
        $attempt = $this->logger->byLevel('debug')[0];
        self::assertSame('mobile', $attempt['context']['strategy']);
        self::assertSame('BLOCKED', $attempt['context']['category']);
        self::assertSame(429, $attempt['context']['status']);
        $info = $this->logger->byLevel('info')[0];
        self::assertSame('widget', $info['context']['strategy']);
        self::assertSame(['mobile'], $info['context']['failedBefore']);
        self::assertStringNotContainsString('secret', $this->logger->dump());
    }

    public function testInvalidCredentialsStopWithoutTryingOtherStrategies(): void
    {
        $widgetCalls = 0;
        $authenticator = new Authenticator([
            self::strategy('mobile', new GarminException(FailureCategory::InvalidCredentials, 'mobile.login', 'rejected')),
            self::strategy('widget', new GarminTokens('a', 'r', 'C'), $widgetCalls),
        ], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::InvalidCredentials, $e->category);
        }
        self::assertSame(0, $widgetCalls, 'Further attempts could lock the Garmin account');
    }

    public function testAllStrategiesFailingThrowsSummaryOfAttempts(): void
    {
        $authenticator = new Authenticator([
            self::strategy('mobile', new GarminException(FailureCategory::Blocked, 'mobile.login', 'x', 429)),
            self::strategy('widget', new GarminException(FailureCategory::ProtocolChanged, 'widget.signin-page', 'no CSRF')),
        ], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
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
            self::strategy('widget', new GarminException(FailureCategory::InvalidCredentials, 'widget.signin-post', 'rejected')),
        ], $this->logger);

        try {
            $authenticator->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
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
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::Network, $e->category);
        }
    }

    public function testCredentialsHidePasswordInDumps(): void
    {
        $credentials = new Credentials('a@b.de', 'secret');
        ob_start();
        var_dump($credentials);
        $dump = ob_get_clean() . print_r($credentials, true);

        self::assertStringNotContainsString('secret', $dump);
        self::assertSame('secret', $credentials->password);
    }
}
