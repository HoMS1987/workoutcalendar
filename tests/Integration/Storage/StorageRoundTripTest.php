<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Integration\Storage;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCA\WorkoutCalendar\Sync\DeletionMode;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\Config\IUserConfig;
use OCP\Security\ICrypto;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Runs inside a real Nextcloud server (see tests/Integration/bootstrap.php). */
final class StorageRoundTripTest extends TestCase
{
    private IUserConfig $config;
    private string $uid;

    protected function setUp(): void
    {
        $this->config = Server::get(IUserConfig::class);
        $this->uid = 'wc-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->config->deleteAllUserConfig($this->uid);
    }

    public function testSecretsSurviveRealConfigAndCrypto(): void
    {
        $secrets = new SecretStore($this->config, Server::get(ICrypto::class), new NullLogger());

        $secrets->saveCredentials($this->uid, new Credentials('anna@example.org', 'secret-123'));
        $secrets->saveTokens($this->uid, new GarminTokens('access-xyz', 'refresh-xyz', 'client'));

        $credentials = $secrets->credentials($this->uid);
        self::assertSame('anna@example.org', $credentials?->email);
        self::assertSame('secret-123', $credentials?->password);
        self::assertEquals(new GarminTokens('access-xyz', 'refresh-xyz', 'client'), $secrets->tokens($this->uid));

        foreach (['credentials', 'tokens'] as $key) {
            $raw = $this->config->getValueString($this->uid, Application::APP_ID, $key, '', true);
            self::assertNotSame('', $raw);
            foreach (['anna@example.org', 'secret-123', 'access-xyz', 'refresh-xyz'] as $secret) {
                self::assertStringNotContainsString($secret, $raw);
            }
        }

        $secrets->clear($this->uid);
        self::assertNull($secrets->credentials($this->uid));
        self::assertNull($secrets->tokens($this->uid));
    }

    public function testSettingsAndStateSurviveRealConfig(): void
    {
        $store = new UserStore($this->config);
        $settings = new SyncSettings(null, 60, 10, DeletionMode::Delete, 14);
        $state = new SyncState();
        $state->initialized = true;
        $state->nextRunAt = time() - 10;

        $store->saveSettings($this->uid, $settings);
        $store->saveState($this->uid, $state);

        self::assertEquals($settings, $store->settings($this->uid));
        self::assertEquals($state, $store->state($this->uid));
        self::assertContains($this->uid, $store->dueUsers(time(), 100000));

        $store->clear($this->uid);
        self::assertEquals(new SyncSettings(), $store->settings($this->uid));
        self::assertNotContains($this->uid, $store->dueUsers(time(), 100000));
    }
}
