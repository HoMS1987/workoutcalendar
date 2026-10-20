<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Service;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Http\HttpClient;
use OCA\WorkoutCalendar\Service\GarminClientFactory;
use OCA\WorkoutCalendar\Service\HttpClientProvider;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Tests\Support\FakeHttpClient;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
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
}
