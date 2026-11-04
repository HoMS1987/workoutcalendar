<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Storage;

use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\MfaChallenge;
use OCA\WorkoutCalendar\Storage\ApiKeyRecord;
use OCA\WorkoutCalendar\Storage\MfaOrigin;
use OCA\WorkoutCalendar\Storage\PendingMfa;
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

    public function testPendingMfaRoundTripsEncrypted(): void
    {
        $store = $this->store();
        $pending = new PendingMfa(
            new MfaChallenge('email', ['cookie-line-xyz']),
            MfaOrigin::Setup,
            1000,
            new Credentials('anna@example.org', 'secret-123'),
        );

        $store->savePendingMfa('anna', $pending);

        $raw = json_encode($this->data, JSON_THROW_ON_ERROR);
        foreach (['cookie-line-xyz', 'secret-123', 'anna@example.org'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
        self::assertEquals($pending, $store->pendingMfa('anna', 1001));
    }

    public function testPendingMfaWithoutCredentialsRoundTrips(): void
    {
        $store = $this->store();
        $pending = new PendingMfa(new MfaChallenge(null, []), MfaOrigin::Sync, 5);

        $store->savePendingMfa('anna', $pending);

        self::assertEquals($pending, $store->pendingMfa('anna', 6));
    }

    public function testExpiredPendingMfaIsDeletedOnRead(): void
    {
        $store = $this->store();
        $store->savePendingMfa('anna', new PendingMfa(new MfaChallenge('email', ['c']), MfaOrigin::Relogin, 0));

        self::assertNull($store->pendingMfa('anna', PendingMfa::LIFETIME_SECONDS));
        self::assertArrayNotHasKey('mfa_pending', $this->data['anna']);
    }

    public function testPendingMfaExpiryBoundary(): void
    {
        $pending = new PendingMfa(new MfaChallenge(null, []), MfaOrigin::Setup, 100);

        self::assertSame(1900, $pending->expiresAt());
        self::assertFalse($pending->isExpired(1899));
        self::assertTrue($pending->isExpired(1900));
    }

    public function testInvalidPendingMfaReadsAsMissing(): void
    {
        $store = $this->store();
        $this->data['anna'] = ['mfa_pending' => 'enc:' . strrev(base64_encode('{"origin":"nope"}'))];

        self::assertNull($store->pendingMfa('anna', 1));
        self::assertCount(1, $this->logger->byLevel('warning'));
    }

    public function testDeletePendingMfa(): void
    {
        $store = $this->store();
        $store->savePendingMfa('anna', new PendingMfa(new MfaChallenge(null, []), MfaOrigin::Setup, 1));

        $store->deletePendingMfa('anna');

        self::assertNull($store->pendingMfa('anna', 2));
    }

    public function testSsoCookiesRoundTripEncrypted(): void
    {
        $store = $this->store();
        self::assertSame([], $store->ssoCookies('anna'));

        $store->saveSsoCookies('anna', ['remember-me-cookie']);

        self::assertStringNotContainsString('remember-me-cookie', json_encode($this->data, JSON_THROW_ON_ERROR));
        self::assertSame(['remember-me-cookie'], $store->ssoCookies('anna'));
    }

    public function testInvalidSsoCookiesReadAsEmpty(): void
    {
        $store = $this->store();
        $this->data['anna'] = ['sso_cookies' => 'enc:' . strrev(base64_encode('{"cookies":"x"}'))];

        self::assertSame([], $store->ssoCookies('anna'));
    }

    public function testClearRemovesPendingMfaAndSsoCookies(): void
    {
        $store = $this->store();
        $store->savePendingMfa('anna', new PendingMfa(new MfaChallenge(null, []), MfaOrigin::Setup, 1));
        $store->saveSsoCookies('anna', ['c']);

        $store->clear('anna');

        self::assertNull($store->pendingMfa('anna', 2));
        self::assertSame([], $store->ssoCookies('anna'));
        self::assertArrayNotHasKey('mfa_pending', $this->data['anna'] ?? []);
        self::assertArrayNotHasKey('sso_cookies', $this->data['anna'] ?? []);
    }

    public function testApiKeyRoundTripsEncrypted(): void
    {
        $store = $this->store();
        self::assertNull($store->apiKey('anna'));

        $store->saveApiKey('anna', new ApiKeyRecord('garmin-key-xyz', 1234));

        self::assertStringNotContainsString('garmin-key-xyz', json_encode($this->data, JSON_THROW_ON_ERROR));
        self::assertEquals(new ApiKeyRecord('garmin-key-xyz', 1234), $store->apiKey('anna'));
    }

    public function testClearRemovesTheApiKey(): void
    {
        $store = $this->store();
        $store->saveApiKey('anna', new ApiKeyRecord('k', 1));

        $store->clear('anna');

        self::assertNull($store->apiKey('anna'));
        self::assertArrayNotHasKey('api_key', $this->data['anna'] ?? []);
    }

    public function testDeleteCredentialsKeepsTokensApiKeyAndCookies(): void
    {
        $store = $this->store();
        $store->saveCredentials('anna', new Credentials('anna@example.org', 'pw'));
        $store->saveTokens('anna', new GarminTokens('a', null, 'personal-api'));
        $store->saveApiKey('anna', new ApiKeyRecord('k', 1));
        $store->saveSsoCookies('anna', ['c']);

        $store->deleteCredentials('anna');

        self::assertSame(['c'], $store->ssoCookies('anna'));

        self::assertNull($store->credentials('anna'));
        self::assertArrayNotHasKey('credentials', $this->data['anna']);
        self::assertSame('personal-api', $store->tokens('anna')?->clientId);
        self::assertEquals(new ApiKeyRecord('k', 1), $store->apiKey('anna'));
    }

    public function testUnreadableApiKeyIsNull(): void
    {
        $store = $this->store();
        $this->data['anna'] = ['api_key' => 'enc:' . strrev(base64_encode('{"key":5}'))];

        self::assertNull($store->apiKey('anna'));
        self::assertCount(1, $this->logger->byLevel('warning'));
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
