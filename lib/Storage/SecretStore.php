<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCA\WorkoutCalendar\Garmin\Auth\Credentials;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCP\Config\IUserConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/** A person's credentials and tokens, as an ICrypto-encrypted JSON string in IUserConfig (ADR 0003). */
final class SecretStore
{
    private const CREDENTIALS = 'credentials';
    private const TOKENS = 'tokens';

    public function __construct(
        private readonly IUserConfig $config,
        private readonly ICrypto $crypto,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function credentials(string $uid): ?Credentials
    {
        $data = $this->read($uid, self::CREDENTIALS);
        if ($data === null) {
            return null;
        }
        if (!is_string($data['email'] ?? null) || !is_string($data['password'] ?? null)) {
            $this->warn($uid, self::CREDENTIALS);

            return null;
        }

        return new Credentials($data['email'], $data['password']);
    }

    public function saveCredentials(string $uid, Credentials $credentials): void
    {
        $this->write($uid, self::CREDENTIALS, ['email' => $credentials->email, 'password' => $credentials->password]);
    }

    public function tokens(string $uid): ?GarminTokens
    {
        $data = $this->read($uid, self::TOKENS);
        if ($data === null) {
            return null;
        }
        try {
            return GarminTokens::fromArray($data);
        } catch (\InvalidArgumentException) {
            $this->warn($uid, self::TOKENS);

            return null;
        }
    }

    public function saveTokens(string $uid, GarminTokens $tokens): void
    {
        $this->write($uid, self::TOKENS, $tokens->toArray());
    }

    public function clear(string $uid): void
    {
        foreach ([self::CREDENTIALS, self::TOKENS] as $key) {
            $this->config->deleteUserConfig($uid, Application::APP_ID, $key);
        }
    }

    /** @return ?array<string, mixed> null for a missing, undecryptable or invalid value */
    private function read(string $uid, string $key): ?array
    {
        $raw = $this->config->getValueString($uid, Application::APP_ID, $key, '', true);
        if ($raw === '') {
            return null;
        }
        try {
            $data = json_decode($this->crypto->decrypt($raw), true);
        } catch (\Throwable) {
            $this->warn($uid, $key);

            return null;
        }
        if (!is_array($data)) {
            $this->warn($uid, $key);

            return null;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function write(string $uid, string $key, array $data): void
    {
        $this->config->setValueString(
            $uid,
            Application::APP_ID,
            $key,
            $this->crypto->encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            true,
        );
    }

    /** Warning without the value: only person and key name. */
    private function warn(string $uid, string $key): void
    {
        $this->logger->warning('Stored credentials are unreadable and treated as missing', ['uid' => $uid, 'key' => $key]);
    }
}
