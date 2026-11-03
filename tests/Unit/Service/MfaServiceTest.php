<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\BackgroundJob\MfaCleanupJob;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;
use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\LoginOutcome;
use OCA\WorkoutCalendar\Service\MfaException;
use OCA\WorkoutCalendar\Service\MfaService;
use OCA\WorkoutCalendar\Service\PendingMfaSaver;
use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\MfaOrigin;
use OCA\WorkoutCalendar\Storage\PendingMfa;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLockingProvider;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCA\WorkoutCalendar\Tests\Support\RecordingNotification;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class MfaServiceTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const NOW = 1_792_497_600;

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private SecretStore $secrets;
    private MemoryCalendarObjects $objects;
    private FakeHttpClient $http;
    private MemoryLogger $logger;
    private MemoryLockingProvider $locking;
    private int $calendarId;
    private int $now = self::NOW;
    /** @var list<array{string, int, mixed}> */
    private array $scheduled = [];
    /** @var list<string> */
    private array $notificationCalls = [];

    protected function setUp(): void
    {
        $config = $this->memoryUserConfig($this->store);
        $this->logger = new MemoryLogger();
        $this->users = new UserStore($config);
        $this->secrets = new SecretStore($config, $this->fakeCrypto(), $this->logger);
        $this->objects = new MemoryCalendarObjects();
        $this->http = new FakeHttpClient();
        $this->locking = new MemoryLockingProvider();
        $this->calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training', null, ['VEVENT']);
    }

    public function testRequestSignsInDirectlyAndClearsFlagWithoutRememberedCookies(): void
    {
        $this->connected();
        $this->flagged();
        $this->http->queue(self::loginOk(), self::diToken('NEW'));

        self::assertSame(LoginOutcome::SignedIn, $this->service()->request(self::UID));

        self::assertSame(['mobile.login', 'mobile.di-exchange'], $this->http->steps());
        self::assertSame('NEW', $this->secrets->tokens(self::UID)?->clientId);
        $state = $this->users->state(self::UID);
        self::assertFalse($state->mfaAccount);
        self::assertFalse($state->isMfaAccount());
        self::assertSame([self::NOW], $this->users->mfaRequests(self::UID));
        self::assertSame([], $this->locking->held());
    }

    public function testRequestSignedInWithRememberedCookiesKeepsTheFlag(): void
    {
        $this->connected();
        $this->flagged();
        $this->secrets->saveSsoCookies(self::UID, ['remembered-browser']);
        $this->http->queue(self::loginOk(), self::diToken('NEW'));

        $outcome = $this->service()->request(self::UID);
        $cookiesSent = $this->http->cookies;

        self::assertSame(LoginOutcome::SignedIn, $outcome);
        self::assertSame(['remembered-browser'], $cookiesSent);
        self::assertTrue($this->users->state(self::UID)->mfaAccount);
        self::assertSame('NEW', $this->secrets->tokens(self::UID)?->clientId);
    }

    public function testRequestWithMfaStoresAReloginRequestWithoutPausing(): void
    {
        $this->connected();
        $this->http->cookies = ['sso-session'];
        $this->http->queue(self::mfaRequired('sms'));

        self::assertSame(LoginOutcome::CodeRequired, $this->service()->request(self::UID));

        $pending = $this->secrets->pendingMfa(self::UID, self::NOW);
        self::assertNotNull($pending);
        self::assertSame(MfaOrigin::Relogin, $pending->origin);
        self::assertSame('sms', $pending->challenge->method);
        self::assertSame(['sso-session'], $pending->challenge->cookies);
        self::assertNull($pending->credentials);
        self::assertSame(self::NOW, $pending->createdAt);
        $state = $this->users->state(self::UID);
        self::assertNull($state->pausedFor);
        self::assertTrue($state->mfaAccount);
        self::assertSame('sms', $state->mfaMethod);
        self::assertSame([[MfaCleanupJob::class, self::NOW + 1800, ['uid' => self::UID]]], $this->scheduled);
        self::assertSame([], $this->logger->byLevel('info'), 'sms is a known method');
    }

    public function testRequestWithAnUnknownMethodLogsOnlyTheMethodName(): void
    {
        $this->connected();
        $this->http->cookies = ['sso-session-secret'];
        $this->http->queue(self::mfaRequired('totp'));

        $this->service()->request(self::UID);

        $info = array_values(array_filter(
            $this->logger->byLevel('info'),
            static fn (array $r): bool => str_contains($r['message'], 'method'),
        ));
        self::assertCount(1, $info);
        self::assertSame(['uid' => self::UID, 'method' => 'totp'], $info[0]['context']);
        self::assertStringNotContainsString('sso-session-secret', $this->logger->dump());
    }

    public function testRequestIsRateLimited(): void
    {
        $this->connected();
        $this->http->queue(self::mfaRequired('email'));
        $service = $this->service();
        $service->request(self::UID);
        $this->now = self::NOW + 30;
        $requestsBefore = count($this->http->requests);

        try {
            $service->request(self::UID);
            self::fail('MfaException expected');
        } catch (MfaException $e) {
            self::assertSame('MFA_RATE_LIMITED', $e->error);
            self::assertSame(30, $e->retryAfter);
        }

        self::assertCount($requestsBefore, $this->http->requests);
        self::assertSame([self::NOW], $this->users->mfaRequests(self::UID));
    }

    public function testFailedRequestStillCountsForTheLimit(): void
    {
        $this->connected();
        $this->http->queue(new HttpResponse(500, [], 'down'));

        try {
            $this->service()->request(self::UID);
            self::fail('GarminException expected');
        } catch (GarminException) {
        }

        self::assertSame([self::NOW], $this->users->mfaRequests(self::UID));
    }

    public function testRequestKeepsOnlyTimesFromTheLastHour(): void
    {
        $this->connected();
        $this->users->saveMfaRequests(self::UID, [self::NOW - 7200, self::NOW - 600]);
        $this->http->queue(self::mfaRequired('email'));

        $this->service()->request(self::UID);

        self::assertSame([self::NOW - 600, self::NOW], $this->users->mfaRequests(self::UID));
    }

    public function testRequestWithRejectedPasswordPausesForInvalidCredentials(): void
    {
        $this->connected();
        $this->http->queue(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]));

        try {
            $this->service()->request(self::UID);
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::InvalidCredentials, $e->category);
        }

        $state = $this->users->state(self::UID);
        self::assertSame('INVALID_CREDENTIALS', $state->pausedFor);
        self::assertSame('INVALID_CREDENTIALS', $state->failureCategory);
        self::assertSame('mobile.login', $state->failureStep);
        self::assertSame(self::NOW, $state->failureSince);
        self::assertSame(1, $state->failureCount);
        self::assertSame([], $this->notificationCalls);
        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    public function testRequestWhilePausedForRejectedPasswordDoesNotContactGarmin(): void
    {
        $this->connected();
        $state = $this->users->state(self::UID);
        $state->pausedFor = 'INVALID_CREDENTIALS';
        $this->users->saveState(self::UID, $state);

        try {
            $this->service()->request(self::UID);
            self::fail('MfaException expected');
        } catch (MfaException $e) {
            self::assertSame('MFA_CREDENTIALS_REJECTED', $e->error);
        }

        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->users->mfaRequests(self::UID));
        self::assertSame('INVALID_CREDENTIALS', $this->users->state(self::UID)->pausedFor);
    }

    public function testRequestDuringSetupSignsInDespiteAPauseForRejectedPassword(): void
    {
        $this->connected();
        $state = $this->users->state(self::UID);
        $state->pausedFor = 'INVALID_CREDENTIALS';
        $this->users->saveState(self::UID, $state);
        $this->pending(MfaOrigin::Setup, new Credentials('a@b.de', 'new-password'));
        $this->http->queue(self::mfaRequired('email'));

        self::assertSame(LoginOutcome::CodeRequired, $this->service()->request(self::UID));

        self::assertSame('new-password', json_decode((string)$this->http->requests[0]->body, true)['password']);
    }

    public function testRequestWithoutCredentialsDoesNotContactGarmin(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->service()->request(self::UID);
        } finally {
            self::assertSame([], $this->http->requests);
            self::assertSame([], $this->users->mfaRequests(self::UID));
        }
    }

    public function testRequestDuringSetupUsesTheCredentialsOfThePendingRequest(): void
    {
        $credentials = new Credentials('new@b.de', 'typed');
        $this->secrets->savePendingMfa(self::UID, new PendingMfa(new MfaChallenge('email', ['c1']), MfaOrigin::Setup, self::NOW - 120, $credentials));
        $this->http->queue(self::mfaRequired('email'));

        self::assertSame(LoginOutcome::CodeRequired, $this->service()->request(self::UID));

        self::assertSame('new@b.de', json_decode((string)$this->http->requests[0]->body, true)['username']);
        $pending = $this->secrets->pendingMfa(self::UID, self::NOW);
        self::assertSame(MfaOrigin::Setup, $pending?->origin);
        self::assertSame('typed', $pending->credentials?->password);
        self::assertSame(self::NOW, $pending->createdAt);
        self::assertNull($this->secrets->credentials(self::UID));
    }

    public function testRequestAfterAnExpiredSetupRequestNeverUsesTheStoredCredentials(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Setup, new Credentials('new@b.de', 'typed'));
        $this->now = self::NOW + PendingMfa::LIFETIME_SECONDS;

        try {
            $this->service()->request(self::UID);
            self::fail('MfaException expected');
        } catch (MfaException $e) {
            self::assertSame('MFA_EXPIRED', $e->error);
        }

        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->users->mfaRequests(self::UID));
        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
        self::assertSame('secret', $this->secrets->credentials(self::UID)?->password);
    }

    public function testVerifyAcceptsCodeWithSpaces(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin);
        $this->http->queue(self::verifyOk(), self::diToken('NEW'));

        $this->service()->verify(self::UID, ' 123 456 ');

        self::assertSame(['mobile.mfa-verify', 'mobile.di-exchange'], $this->http->steps());
        self::assertSame('123456', json_decode((string)$this->http->requests[0]->body, true)['mfaVerificationCode']);
        self::assertStringNotContainsString('123456', $this->logger->dump());
    }

    public function testVerifyRejectsSevenDigitsWithoutCallingGarmin(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin);
        $service = $this->service();

        foreach (['1234567', '12345a', ''] as $code) {
            try {
                $service->verify(self::UID, $code);
                self::fail('InvalidArgumentException expected for ' . $code);
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->http->requests);
        self::assertNotNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    public function testVerifyFromSetupStoresCredentialsAndContinuesAtTheCalendar(): void
    {
        $this->pending(MfaOrigin::Setup, new Credentials('Anna@Example.de', 'typed'));
        $this->http->queue(self::verifyOk(), self::diToken('NEW'));

        $this->service()->verify(self::UID, '123456');

        $setup = $this->users->setup(self::UID);
        self::assertSame(SetupStep::Calendar, $setup->step);
        self::assertTrue($setup->connected);
        self::assertSame('A***@Example.de', $setup->maskedEmail);
        self::assertSame('typed', $this->secrets->credentials(self::UID)?->password);
        self::assertSame('NEW', $this->secrets->tokens(self::UID)?->clientId);
        self::assertTrue($this->users->state(self::UID)->mfaAccount);
        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    public function testVerifyFromSyncEndsThePauseAndDismissesTheNotification(): void
    {
        $this->connected();
        $state = $this->users->state(self::UID);
        $state->pausedFor = 'MFA_REQUIRED';
        $state->notifiedCategory = 'MFA_REQUIRED';
        $state->failureCategory = 'MFA_REQUIRED';
        $state->failureCount = 2;
        $this->users->saveState(self::UID, $state);
        $this->pending(MfaOrigin::Sync);
        $this->http->queue(self::verifyOk(), self::diToken('NEW'));

        $this->service()->verify(self::UID, '123456');

        $state = $this->users->state(self::UID);
        self::assertNull($state->pausedFor);
        self::assertNull($state->notifiedCategory);
        self::assertNull($state->failureCategory);
        self::assertSame(self::NOW, $state->nextRunAt);
        self::assertTrue($state->mfaAccount);
        self::assertSame(['markProcessed:' . self::UID], $this->notificationCalls);
        self::assertSame('secret', $this->secrets->credentials(self::UID)?->password);
        self::assertSame('NEW', $this->secrets->tokens(self::UID)?->clientId);
    }

    public function testVerifySavesRememberedCookiesAndKeepsTheFlag(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin, cookies: ['challenge-cookie']);
        $this->http->queue(self::verifyOk(), self::diToken('NEW'));

        $this->service()->verify(self::UID, '123456');

        self::assertSame(['challenge-cookie'], $this->secrets->ssoCookies(self::UID));
        $state = $this->users->state(self::UID);
        self::assertTrue($state->mfaAccount);
        self::assertSame('email', $state->mfaMethod);
        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    public function testWrongCodeKeepsThePendingRequest(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin);
        $wrong = FakeHttpClient::json(200, ['responseStatus' => ['type' => 'MFA_CODE_INVALID']]);
        $this->http->queue($wrong, $wrong);

        try {
            $this->service()->verify(self::UID, '000000');
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            self::assertSame(FailureCategory::MfaCodeInvalid, $e->category);
        }

        self::assertNotNull($this->secrets->pendingMfa(self::UID, self::NOW));
        self::assertSame('C', $this->secrets->tokens(self::UID)?->clientId);
    }

    public function testVerifyAfterExpiryIsMfaExpired(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin);
        $this->now = self::NOW + PendingMfa::LIFETIME_SECONDS;

        try {
            $this->service()->verify(self::UID, '123456');
            self::fail('MfaException expected');
        } catch (MfaException $e) {
            self::assertSame('MFA_EXPIRED', $e->error);
            self::assertNull($e->retryAfter);
        }

        self::assertSame([], $this->http->requests);
        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    public function testVerifyWithoutRequestIsMfaNotPending(): void
    {
        $this->connected();

        try {
            $this->service()->verify(self::UID, '123456');
            self::fail('MfaException expected');
        } catch (MfaException $e) {
            self::assertSame('MFA_NOT_PENDING', $e->error);
        }

        self::assertSame([], $this->http->requests);
    }

    public function testCancelDeletesThePendingRequest(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin);

        $this->service()->cancel(self::UID);

        self::assertNull($this->secrets->pendingMfa(self::UID, self::NOW));
        self::assertSame([], $this->locking->held());
    }

    public function testVerifyWhileSyncRunsIsBusy(): void
    {
        $this->connected();
        $this->pending(MfaOrigin::Relogin);
        $this->locking->hold('workoutcalendar/sync/' . md5(self::UID));
        $this->http->queue(self::verifyOk(), self::diToken('NEW'));
        $service = $this->service();

        foreach ([
            fn () => $service->verify(self::UID, '123456'),
            fn () => $service->request(self::UID),
            fn () => $service->cancel(self::UID),
        ] as $call) {
            try {
                $call();
                self::fail('PersonBusyException expected');
            } catch (PersonBusyException) {
            }
        }

        self::assertSame([], $this->http->requests);
        self::assertNotNull($this->secrets->pendingMfa(self::UID, self::NOW));
    }

    private function connected(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, substr(hash('sha256', 'a@b.de'), 0, 16), 'a***@b.de', $this->calendarId, true));
        $this->secrets->saveCredentials(self::UID, new Credentials('a@b.de', 'secret'));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make([]), 'r', 'C'));
    }

    private function flagged(): void
    {
        $state = $this->users->state(self::UID);
        $state->mfaAccount = true;
        $state->mfaMethod = 'email';
        $this->users->saveState(self::UID, $state);
    }

    /** @param list<string> $cookies */
    private function pending(MfaOrigin $origin, ?Credentials $credentials = null, array $cookies = ['c1']): void
    {
        $this->secrets->savePendingMfa(self::UID, new PendingMfa(new MfaChallenge('email', $cookies), $origin, self::NOW, $credentials));
    }

    private static function loginOk(): HttpResponse
    {
        return FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']);
    }

    private static function mfaRequired(string $method): HttpResponse
    {
        return FakeHttpClient::json(200, [
            'responseStatus' => ['type' => 'MFA_REQUIRED'],
            'customerMfaInfo' => ['mfaLastMethodUsed' => $method],
        ]);
    }

    private static function verifyOk(): HttpResponse
    {
        return FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-7']);
    }

    private static function diToken(string $clientId): HttpResponse
    {
        return FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => $clientId]), 'refresh_token' => 'r']);
    }

    private function service(): MfaService
    {
        $http = $this->http;
        $provider = new class ($http) implements HttpClientProvider {
            public function __construct(private readonly HttpClient $http)
            {
            }

            public function create(): HttpClient
            {
                return $this->http;
            }
        };
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('scheduleAfter')->willReturnCallback(function (string $job, int $runAfter, mixed $argument = null): void {
            $this->scheduled[] = [$job, $runAfter, $argument];
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $garmin = new GarminClientFactory($provider, $this->secrets, $this->logger);
        $lock = new PersonLock($this->locking);
        $accounts = new AccountService(
            $garmin,
            $this->secrets,
            $this->users,
            new CalendarService($this->objects),
            $jobList,
            new FailureNotifications($this->notificationManager(), $time),
            $time,
            $lock,
            $this->logger,
            new PendingMfaSaver($this->secrets, $jobList, $this->logger),
        );

        return new MfaService($accounts, $garmin, $this->secrets, $this->users, $lock, $time, $this->logger);
    }

    private function notificationManager(): IManager
    {
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturnCallback(static fn (): INotification => new RecordingNotification());
        $manager->method('markProcessed')->willReturnCallback(function (INotification $n): void {
            $this->notificationCalls[] = 'markProcessed:' . $n->getUser();
        });
        $manager->method('notify')->willReturnCallback(function (INotification $n): void {
            $this->notificationCalls[] = 'notify:' . $n->getUser();
        });

        return $manager;
    }
}
