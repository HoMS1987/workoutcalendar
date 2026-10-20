<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Controller;

use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Controller\ApiController;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Notification\FailureNotifications;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\CalendarSetupService;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\StatusService;
use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\SetupStep;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryCalendarObjects;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use OCA\WorkoutCalendar\Tests\Support\RecordingNotification;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/** Ablauf und Fehlerabbildung des Controllers ohne Nextcloud, Garmin über FakeHttpClient. */
final class ApiControllerTest extends TestCase
{
    use InMemoryConfigMocks;

    private const UID = 'anna';
    private const NOW = 1_792_497_600;
    private const ACCOUNT = '0123456789abcdef';

    /** @var array<string, array<string, string|int>> */
    private array $store = [];
    private UserStore $users;
    private SecretStore $secrets;
    private MemoryCalendarObjects $objects;
    private FakeHttpClient $http;
    private MemoryLogger $logger;
    /** @var array<string, mixed> */
    private array $requestParams = [];
    private ?string $sessionUid = self::UID;

    protected function setUp(): void
    {
        $config = $this->memoryUserConfig($this->store);
        $this->logger = new MemoryLogger();
        $this->users = new UserStore($config);
        $this->secrets = new SecretStore($config, $this->fakeCrypto(), new MemoryLogger());
        $this->objects = new MemoryCalendarObjects();
        $this->http = new FakeHttpClient();
    }

    public function testWithoutUserIsUnauthorized(): void
    {
        $this->sessionUid = null;

        $response = $this->controller()->status();

        self::assertSame(401, $response->getStatus());
    }

    public function testLoginErrorReturnsCategory(): void
    {
        $this->http->queue(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]));

        $response = $this->controller()->login('anna@example.org', 'falsch');

        self::assertSame(422, $response->getStatus());
        self::assertSame(['error' => 'INVALID_CREDENTIALS'], $response->getData());
    }

    public function testWizardFlow(): void
    {
        $controller = $this->controller();
        $this->queueSuccessfulLogin();
        self::assertSame('calendar', $controller->login('anna@example.org', 'geheim')->getData()['setup']['step']);

        $choice = $controller->calendar('Training');
        self::assertSame(200, $choice->getStatus());
        self::assertFalse($choice->getData()['needsConfirmation']);
        self::assertSame('Training', $choice->getData()['calendar']['name']);
        $calendarId = $choice->getData()['calendar']['id'];

        $this->http->queue(FakeHttpClient::json(200, [self::activity(101, '2026-10-19 07:00:00')]), FakeHttpClient::json(200, []));
        $response = $controller->history(90);

        self::assertSame(200, $response->getStatus(), (string)json_encode($response->getData()));
        self::assertSame('done', $response->getData()['setup']['step']);
        self::assertSame(1, $response->getData()['sync']['eventCount']);
        self::assertArrayHasKey('garmin-101.ics', $this->objects->objects[$calendarId]);
    }

    public function testHistoryAfterSetupContinuesBackfillWithoutGarmin(): void
    {
        $this->done();
        $state = $this->users->state(self::UID);
        $state->initialized = true;
        $state->backfillDone = true;
        $state->backfillBefore = '2026-09-20';
        $state->backfillUntil = '2026-07-22';
        $this->users->saveState(self::UID, $state);

        $response = $this->controller()->history(365);

        self::assertSame(200, $response->getStatus());
        self::assertSame(365, $response->getData()['settings']['historyDays']);
        self::assertTrue($response->getData()['sync']['backfill']['running']);
        $state = $this->users->state(self::UID);
        self::assertSame('2025-10-20', $state->backfillUntil);
        self::assertSame(self::NOW, $state->nextRunAt);
        self::assertSame([], $this->http->requests);
    }

    public function testHistoryNullMeansCompleteHistoryOnlyWhenSent(): void
    {
        $this->done();

        self::assertSame(400, $this->controller()->history()->getStatus());

        $this->requestParams = ['historyDays' => null];
        $response = $this->controller()->history();

        self::assertSame(200, $response->getStatus());
        self::assertNull($response->getData()['settings']['historyDays']);
    }

    public function testInvalidHistoryIsRejected(): void
    {
        $this->done();

        self::assertSame(400, $this->controller()->history(45)->getStatus());
        self::assertSame(400, $this->controller()->history('90')->getStatus());
        self::assertSame(90, $this->users->settings(self::UID)->historyDays);
    }

    public function testHistoryInOtherStepsIsRejected(): void
    {
        self::assertSame(400, $this->controller()->history(90)->getStatus());
        self::assertSame(90, $this->users->settings(self::UID)->historyDays);
    }

    public function testSettingsAreClampedAndHistoryStays(): void
    {
        $this->users->saveSettings(self::UID, new SyncSettings(historyDays: 365));

        $response = $this->controller()->updateSettings(intervalMinutes: 5, jitterPercent: 70, deletionMode: 'keep', deletionCheckDays: 1);

        self::assertSame([
            'historyDays' => 365,
            'intervalMinutes' => 15,
            'jitterPercent' => 50,
            'deletionMode' => 'keep',
            'deletionCheckDays' => 7,
        ], $response->getData()['settings']);
    }

    public function testMissingSettingsKeepTheirValue(): void
    {
        $this->users->saveSettings(self::UID, new SyncSettings(intervalMinutes: 60, jitterPercent: 10));

        $settings = $this->controller()->updateSettings(deletionCheckDays: 14)->getData()['settings'];

        self::assertSame(60, $settings['intervalMinutes']);
        self::assertSame(10, $settings['jitterPercent']);
        self::assertSame(14, $settings['deletionCheckDays']);
    }

    public function testInvalidSettingsAreRejected(): void
    {
        self::assertSame(400, $this->controller()->updateSettings(deletionMode: 'weg')->getStatus());
        self::assertSame(400, $this->controller()->updateSettings(intervalMinutes: '60')->getStatus());
    }

    public function testSyncArmsQueueAndReturnsOutcome(): void
    {
        $response = $this->controller()->sync();

        self::assertSame(200, $response->getStatus());
        self::assertSame('notConnected', $response->getData()['outcome']);
        self::assertSame('garmin', $response->getData()['status']['setup']['step']);
    }

    public function testUnexpectedErrorIsLoggedWithoutDetails(): void
    {
        $this->done();
        $this->http->queue(new \RuntimeException('Geheim anna@example.org'));

        $response = $this->controller()->sync();

        self::assertSame(500, $response->getStatus());
        self::assertSame(['error' => 'internal'], $response->getData());
        self::assertCount(1, $this->logger->records);
        self::assertSame(['exceptionClass' => \RuntimeException::class], $this->logger->records[0]['context']);
        self::assertStringNotContainsString('Geheim', $this->logger->dump());
    }

    public function testForeignCalendarIsRejected(): void
    {
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Calendar, true, self::ACCOUNT, 'a***@example.org'));
        $foreign = $this->objects->addCalendar('bernd', 'privat', 'Privat');

        $response = $this->controller()->calendar(calendarId: $foreign, confirmed: true);

        self::assertSame(400, $response->getStatus());
        self::assertSame(['error' => 'invalidRequest'], $response->getData());
        self::assertNull($this->users->setup(self::UID)->calendarId);
    }

    public function testCalendarsListsOwnCalendars(): void
    {
        $own = $this->objects->addCalendar(self::UID, 'personal', 'Persönlich', '#0082c9');
        $this->objects->addCalendar('bernd', 'privat', 'Privat');

        self::assertSame([
            'defaultName' => 'Garmin activities',
            'calendars' => [['id' => $own, 'name' => 'Persönlich', 'color' => '#0082c9']],
        ], $this->controller()->calendars()->getData());
    }

    public function testInvalidCleanupIsRejected(): void
    {
        $this->done();

        self::assertSame(400, $this->controller()->logout('alles')->getStatus());
        self::assertSame(400, $this->controller()->reset(null)->getStatus());
        self::assertTrue($this->users->setup(self::UID)->connected);
    }

    public function testLogoutReturnsStatus(): void
    {
        $this->done();

        $response = $this->controller()->logout('none');

        self::assertSame(200, $response->getStatus());
        self::assertFalse($response->getData()['setup']['connected']);
    }

    public function testEmptyLoginIsRejectedWithoutGarmin(): void
    {
        self::assertSame(400, $this->controller()->login(' ', 'x')->getStatus());
        self::assertSame(400, $this->controller()->login('a@b.de', '')->getStatus());
        self::assertSame([], $this->http->requests);
    }

    private function done(): void
    {
        $calendarId = $this->objects->addCalendar(self::UID, 'workoutcalendar', 'Training');
        $this->secrets->saveCredentials(self::UID, new Credentials('anna@example.org', 'geheim'));
        $this->secrets->saveTokens(self::UID, new GarminTokens(Jwt::make(['exp' => self::NOW + 3600]), 'r', 'C'));
        $this->users->saveSetup(self::UID, new SetupState(SetupStep::Done, true, self::ACCOUNT, 'a***@example.org', $calendarId, true));
    }

    private function queueSuccessfulLogin(): void
    {
        $this->http->queue(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );
    }

    private function controller(): ApiController
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
        $garminLogger = new MemoryLogger();
        $garmin = new GarminClientFactory($provider, $this->secrets, $garminLogger);
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $jobs = $this->createStub(IJobList::class);
        $notifications = new FailureNotifications($this->notificationManager(), $time);
        $calendars = new CalendarService($this->objects);
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $l10nFactory = $this->createStub(IFactory::class);
        $l10nFactory->method('getUserLanguage')->willReturn('en');
        $l10nFactory->method('get')->willReturn($l);

        $request = $this->createStub(IRequest::class);
        $request->method('getParams')->willReturnCallback(fn (): array => $this->requestParams);
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturnCallback(function (): ?IUser {
            if ($this->sessionUid === null) {
                return null;
            }
            $user = $this->createStub(IUser::class);
            $user->method('getUID')->willReturn($this->sessionUid);

            return $user;
        });

        return new ApiController(
            'workoutcalendar',
            $request,
            $session,
            new AccountService($garmin, $this->secrets, $this->users, $calendars, $jobs, $notifications, $time),
            new CalendarSetupService($calendars, $this->users, $jobs, $time, $l),
            $calendars,
            new SyncRunner(
                $this->users,
                $this->secrets,
                $this->objects,
                $garmin,
                $notifications,
                $this->createStub(ILockingProvider::class),
                $l10nFactory,
                $this->createStub(IUserManager::class),
                $garminLogger,
            ),
            new StatusService($this->users, $calendars),
            $this->users,
            $time,
            $this->logger,
        );
    }

    private function notificationManager(): IManager
    {
        $manager = $this->createStub(IManager::class);
        $manager->method('createNotification')->willReturnCallback(static fn (): INotification => new RecordingNotification());

        return $manager;
    }

    /** @return array<string, mixed> */
    private static function activity(int $id, string $start): array
    {
        return [
            'activityId' => $id,
            'activityName' => 'Abendlauf',
            'startTimeGMT' => $start,
            'startTimeLocal' => $start,
            'duration' => 1800.0,
            'distance' => 5000.0,
            'activityType' => ['typeKey' => 'running'],
        ];
    }
}
