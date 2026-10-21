<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\Controller;

use OC\AppFramework\DependencyInjection\DIContainer;
use OCA\WorkoutCalendar\AppInfo\Application;
use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Controller\ApiController;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Service\AccountService;
use OCA\WorkoutCalendar\Service\CalendarSetupService;
use OCA\WorkoutCalendar\Service\CurlHttpClientProvider;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Service\StatusService;
use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside a real Nextcloud server. The HTTP client for Garmin is replaced in the app container
 * by a stand-in with prepared responses; Garmin is never contacted.
 */
final class ApiControllerTest extends TestCase
{
    /** Services that hold the HTTP client directly or indirectly and are therefore rebuilt. */
    private const DEPENDENT = [
        AccountService::class,
        SyncRunner::class,
        CalendarSetupService::class,
        StatusService::class,
        ApiController::class,
    ];

    private FakeHttpClient $http;
    /** @var object{created: int} */
    private object $calls;
    /** @var list<IUser> */
    private array $users = [];
    private string $uid;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->calls = (object)['created' => 0];
        $http = $this->http;
        $calls = $this->calls;
        $provider = new class ($http, $calls) implements HttpClientProvider {
            /** @param object{created: int} $calls */
            public function __construct(private readonly HttpClient $http, private readonly object $calls)
            {
            }

            public function create(): HttpClient
            {
                $this->calls->created++;

                return $this->http;
            }
        };

        // The app services are created in the app container; a registration on the server container does not reach them.
        $container = $this->appContainer();
        $container->registerService(HttpClientProvider::class, static fn (): HttpClientProvider => $provider);
        $this->rebuildDependents($container);

        $this->uid = $this->user('wc-api-');
        $user = Server::get(IUserManager::class)->get($this->uid);
        self::assertInstanceOf(IUser::class, $user);
        Server::get(IUserSession::class)->setUser($user);
    }

    protected function tearDown(): void
    {
        Server::get(IUserSession::class)->setUser(null);
        $container = $this->appContainer();
        $container->registerAlias(HttpClientProvider::class, CurlHttpClientProvider::class);
        $this->rebuildDependents($container);
        // Deleting the person also removes their calendars and settings.
        foreach ($this->users as $user) {
            $user->delete();
        }
    }

    public function testLoginErrorReturnsCategory(): void
    {
        $this->http->queue(FakeHttpClient::json(200, ['responseStatus' => ['type' => 'INVALID_USERNAME_PASSWORD']]));

        $response = $this->controller()->login('anna@example.org', 'falsch');

        self::assertSame(422, $response->getStatus());
        self::assertSame(['error' => 'INVALID_CREDENTIALS'], $response->getData());
        self::assertGreaterThan(0, $this->calls->created, 'Der Ersatz-Client wurde nicht verwendet');
        self::assertSame('garmin', $this->currentStatus()['setup']['step']);
    }

    public function testWizardFlow(): void
    {
        $controller = $this->controller();
        $this->http->queue(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C', 'exp' => time() + 3600]), 'refresh_token' => 'r']),
        );

        $login = $controller->login('anna@example.org', 'geheim');
        self::assertSame(200, $login->getStatus(), json_encode($login->getData()) ?: '');
        self::assertSame('calendar', $login->getData()['setup']['step']);
        self::assertSame('a***@example.org', $login->getData()['setup']['maskedEmail']);
        self::assertStringNotContainsString('anna@example.org', (string)json_encode($login->getData()));

        $calendars = $controller->calendars();
        self::assertSame(200, $calendars->getStatus());
        self::assertIsString($calendars->getData()['defaultName']);
        self::assertIsArray($calendars->getData()['calendars']);

        $choice = $controller->calendar('Training ' . bin2hex(random_bytes(3)));
        self::assertSame(200, $choice->getStatus(), json_encode($choice->getData()) ?: '');
        self::assertFalse($choice->getData()['needsConfirmation']);
        self::assertSame('history', $choice->getData()['status']['setup']['step']);
        $calendarId = $choice->getData()['calendar']['id'];
        self::assertIsInt($calendarId);

        $yesterday = (new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')))->format('Y-m-d') . ' 07:00:00';
        $this->http->queue(
            FakeHttpClient::json(200, [self::activity(101, $yesterday)]),
            FakeHttpClient::json(200, []),
        );

        $history = $controller->history(90);

        self::assertSame(200, $history->getStatus(), json_encode($history->getData()) ?: '');
        $status = $history->getData();
        self::assertSame('done', $status['setup']['step']);
        self::assertSame(90, $status['settings']['historyDays']);
        self::assertSame(1, $status['sync']['eventCount']);
        self::assertNotNull($status['sync']['lastSuccessAt']);
        // The first run has happened, so the import no longer waits for it.
        self::assertFalse($status['sync']['backfill']['pending']);
        self::assertSame(1, Server::get(CalendarService::class)->countAppEvents($calendarId));
        self::assertSame(['mobile.login', 'mobile.di-exchange', 'api.activities', 'api.activities'], $this->http->steps());
    }

    public function testSettingsAreClamped(): void
    {
        $response = $this->controller()->updateSettings(intervalMinutes: 5, jitterPercent: 90, deletionMode: 'delete', deletionCheckDays: 1000);

        self::assertSame(200, $response->getStatus(), json_encode($response->getData()) ?: '');
        self::assertSame([
            'historyDays' => 90,
            'intervalMinutes' => 15,
            'jitterPercent' => 50,
            'deletionMode' => 'delete',
            'deletionCheckDays' => 365,
        ], $response->getData()['settings']);
    }

    public function testOtherUsersDataIsNotReachable(): void
    {
        $other = $this->user('wc-api-other-');
        $foreign = Server::get(CalendarService::class)->createCalendar($other, 'Fremder Kalender');
        $this->http->queue(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );
        $controller = $this->controller();
        self::assertSame(200, $controller->login('anna@example.org', 'geheim')->getStatus());

        $response = $controller->calendar(calendarId: $foreign->id, confirmed: true);

        self::assertSame(400, $response->getStatus());
        self::assertSame(['error' => 'invalidRequest'], $response->getData());
        self::assertNull($this->currentStatus()['setup']['calendar']);
    }

    public function testInvalidRequestsAreRejected(): void
    {
        $controller = $this->controller();

        self::assertSame(400, $controller->history(45)->getStatus());
        self::assertSame(400, $controller->history('90')->getStatus());
        // There is no history to choose in the Garmin step yet.
        self::assertSame(400, $controller->history(90)->getStatus());
        self::assertSame(400, $controller->logout('alles')->getStatus());
        self::assertSame(400, $controller->reset('alles')->getStatus());
        self::assertSame(400, $controller->updateSettings(deletionMode: 'weg')->getStatus());
        self::assertSame(400, $controller->login('', '')->getStatus());
        self::assertSame([], $this->http->requests);
    }

    public function testWithoutSessionUserIsUnauthorized(): void
    {
        Server::get(IUserSession::class)->setUser(null);

        $response = $this->controller()->status();

        self::assertSame(401, $response->getStatus());
    }

    public function testLogoutKeepsEventsAndReturnsStatus(): void
    {
        $this->http->queue(
            FakeHttpClient::json(200, ['responseStatus' => ['type' => 'SUCCESSFUL'], 'serviceTicketId' => 'ST-42']),
            FakeHttpClient::json(200, ['access_token' => Jwt::make(['client_id' => 'C']), 'refresh_token' => 'r']),
        );
        $controller = $this->controller();
        self::assertSame(200, $controller->login('anna@example.org', 'geheim')->getStatus());

        $response = $controller->logout('none');

        self::assertSame(200, $response->getStatus());
        self::assertFalse($response->getData()['setup']['connected']);

        $reset = $controller->reset('none');
        self::assertSame(200, $reset->getStatus());
        self::assertSame('garmin', $reset->getData()['setup']['step']);
        self::assertNull($reset->getData()['setup']['maskedEmail']);
    }

    private function controller(): ApiController
    {
        return Server::get(ApiController::class);
    }

    /** @return array<string, mixed> */
    private function currentStatus(): array
    {
        $response = $this->controller()->status();
        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(200, $response->getStatus());

        return $response->getData();
    }

    private function appContainer(): DIContainer
    {
        return \OC::$server->getRegisteredAppContainer(Application::APP_ID);
    }

    /** Cached instances would still hold the previous HTTP client. */
    private function rebuildDependents(DIContainer $container): void
    {
        $container->registerService(
            GarminClientFactory::class,
            static fn (DIContainer $c): GarminClientFactory => $c->resolve(GarminClientFactory::class),
        );
        foreach (self::DEPENDENT as $class) {
            $container->registerService($class, static fn (DIContainer $c): object => $c->resolve($class));
        }
    }

    private function user(string $prefix): string
    {
        $uid = $prefix . bin2hex(random_bytes(4));
        $user = Server::get(IUserManager::class)->createUser($uid, 'Wc-' . bin2hex(random_bytes(16)) . '!');
        self::assertInstanceOf(IUser::class, $user);
        $this->users[] = $user;

        return $uid;
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
