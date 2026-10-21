<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Storage;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCA\WorkoutCalendar\Storage\UserTokenStore;
use OCA\WorkoutCalendar\Tests\Support\InMemoryConfigMocks;
use OCA\WorkoutCalendar\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class SecretStoreTest extends TestCase
{
    use InMemoryConfigMocks;

    /** @var array<string, array<string, string|int>> */
    private array $data = [];
    private MemoryLogger $logger;

    private function store(): SecretStore
    {
        $this->logger = new MemoryLogger();

        return new SecretStore($this->memoryUserConfig($this->data), $this->fakeCrypto(), $this->logger);
    }

    public function testNothingStoredReadsAsMissing(): void
    {
        $store = $this->store();

        self::assertNull($store->credentials('anna'));
        self::assertNull($store->tokens('anna'));
        self::assertSame([], $this->logger->records);
    }

    public function testSecretsAreStoredEncrypted(): void
    {
        $store = $this->store();
        $store->saveCredentials('anna', new Credentials('anna@example.org', 'secret-123'));
        $store->saveTokens('anna', new GarminTokens('access-xyz', 'refresh-xyz', 'client'));

        $raw = json_encode($this->data, JSON_THROW_ON_ERROR);
        foreach (['anna@example.org', 'secret-123', 'access-xyz', 'refresh-xyz'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
        $credentials = $store->credentials('anna');
        self::assertSame('anna@example.org', $credentials?->email);
        self::assertSame('secret-123', $credentials?->password);
        self::assertEquals(new GarminTokens('access-xyz', 'refresh-xyz', 'client'), $store->tokens('anna'));
    }

    public function testUndecryptableSecretsReadAsMissing(): void
    {
        $store = $this->store();
        $this->data['anna'] = ['credentials' => 'broken-secret', 'tokens' => 'broken-secret'];

        self::assertNull($store->credentials('anna'));
        self::assertNull($store->tokens('anna'));
        self::assertCount(2, $this->logger->byLevel('warning'));
        self::assertStringNotContainsString('broken-secret', $this->logger->dump());
    }

    public function testInvalidDecryptedJsonReadsAsMissing(): void
    {
        $store = $this->store();
        $this->data['anna'] = [
            'credentials' => 'enc:' . strrev(base64_encode('{"email":"a@b.c"}')),
            'tokens' => 'enc:' . strrev(base64_encode('not json')),
        ];

        self::assertNull($store->credentials('anna'));
        self::assertNull($store->tokens('anna'));
        self::assertCount(2, $this->logger->byLevel('warning'));
    }

    public function testClearRemovesSecrets(): void
    {
        $store = $this->store();
        $store->saveCredentials('anna', new Credentials('a@b.c', 'pw'));
        $store->saveTokens('anna', new GarminTokens('t', null, 'c'));

        $store->clear('anna');

        self::assertNull($store->credentials('anna'));
        self::assertNull($store->tokens('anna'));
    }

    public function testUserTokenStoreDelegatesToSecretStore(): void
    {
        $store = $this->store();
        $tokens = new GarminTokens('t', 'r', 'c');
        $tokenStore = new UserTokenStore($store, 'anna');

        self::assertNull($tokenStore->load());
        $tokenStore->save($tokens);

        self::assertEquals($tokens, $tokenStore->load());
        self::assertEquals($tokens, $store->tokens('anna'));
    }
}
