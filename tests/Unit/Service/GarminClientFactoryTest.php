<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\HttpResponse;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\Jwt;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class GarminClientFactoryTest extends TestCase
{
    use InMemoryConfigMocks;

    public function testEachCallGetsFreshHttpClient(): void
    {
        $provider = new class () implements HttpClientProvider {
            public int $created = 0;

            public function create(): HttpClient
            {
                $this->created++;

                return new FakeHttpClient();
            }
        };
        $store = [];
        $logger = new MemoryLogger();
        $factory = new GarminClientFactory($provider, new SecretStore($this->memoryUserConfig($store), $this->fakeCrypto(), $logger), $logger);

        $factory->client('anna', new Credentials('a@b.de', 'pw'));
        $factory->client('bert', new Credentials('b@c.de', 'pw'));
        $factory->authenticator();

        self::assertSame(3, $provider->created);
    }

    public function testMobileLoginGetsItsOwnHttpClientWithImportedCookies(): void
    {
        $clients = [];
        $provider = new class ($clients) implements HttpClientProvider {
            /** @param list<FakeHttpClient> $clients */
            public function __construct(private array &$clients)
            {
            }

            public function create(): HttpClient
            {
                return $this->clients[] = new FakeHttpClient();
            }
        };
        $store = [];
        $logger = new MemoryLogger();
        $factory = new GarminClientFactory($provider, new SecretStore($this->memoryUserConfig($store), $this->fakeCrypto(), $logger), $logger);

        $factory->mobileLogin(['c1', 'c2'], ['pw']);
        $factory->mobileLogin();

        self::assertCount(2, $clients);
        self::assertSame(['c1', 'c2'], $clients[0]->cookies);
        self::assertSame([], $clients[1]->cookies);
    }

    private const SESSION_COOKIE = "sso.garmin.com\tFALSE\t/\tTRUE\t0\tCASTGC\tTGT-SESSION-VALUE-4711";
    private const FLAG_COOKIE = ".garmin.com\tTRUE\t/\tTRUE\t0\tGARMIN-SSO\t1";

    public function testMobileLoginMasksCookieValuesInResponseExcerpts(): void
    {
        $http = new FakeHttpClient(new HttpResponse(500, [], 'echo TGT-SESSION-VALUE-4711 status 1'));

        try {
            $this->factoryWith($http)->mobileLogin([self::SESSION_COOKIE, self::FLAG_COOKIE], ['pw'])->login(new Credentials('a@b.de', 'pw'));
            self::fail('GarminException expected');
        } catch (GarminException $e) {
            // WHY "1" stays: values that short are no session secrets, masking them would garble every number.
            self::assertSame('echo *** status 1', $e->excerpt);
        }
    }

    public function testMobileLoginMasksCookieValuesInNetworkErrors(): void
    {
        $http = new FakeHttpClient(new NetworkException('reset after TGT-SESSION-VALUE-4711'));
        $logger = new MemoryLogger();

        try {
            $this->factoryWith($http, $logger)->mobileLogin([self::SESSION_COOKIE])->login(new Credentials('a@b.de', 'pw'));
            self::fail('NetworkException expected');
        } catch (NetworkException) {
        }

        self::assertStringContainsString('reset after ***', $logger->dump());
        self::assertStringNotContainsString('TGT-SESSION-VALUE-4711', $logger->dump());
    }

    public function testClientMasksRememberedCookieValuesInTheLog(): void
    {
        $http = new FakeHttpClient(new NetworkException('reset after TGT-SESSION-VALUE-4711'));
        $logger = new MemoryLogger();
        $store = [];
        $secrets = new SecretStore($this->memoryUserConfig($store), $this->fakeCrypto(), $logger);
        $secrets->saveSsoCookies('anna', [self::SESSION_COOKIE]);
        $secrets->saveTokens('anna', new GarminTokens(Jwt::make(['exp' => time() + 3600]), 'r', 'C'));
        $factory = new GarminClientFactory($this->provider($http), $secrets, $logger);

        try {
            $factory->client('anna', new Credentials('a@b.de', 'pw'))->activities(0, 1);
            self::fail('Exception expected');
        } catch (NetworkException|GarminException) {
        }

        self::assertStringContainsString('reset after ***', $logger->dump());
        self::assertStringNotContainsString('TGT-SESSION-VALUE-4711', $logger->dump());
    }

    private function factoryWith(FakeHttpClient $http, ?MemoryLogger $logger = null): GarminClientFactory
    {
        $logger ??= new MemoryLogger();
        $store = [];

        return new GarminClientFactory($this->provider($http), new SecretStore($this->memoryUserConfig($store), $this->fakeCrypto(), $logger), $logger);
    }

    private function provider(FakeHttpClient $http): HttpClientProvider
    {
        return new class ($http) implements HttpClientProvider {
            public function __construct(private readonly HttpClient $http)
            {
            }

            public function create(): HttpClient
            {
                return $this->http;
            }
        };
    }
}
