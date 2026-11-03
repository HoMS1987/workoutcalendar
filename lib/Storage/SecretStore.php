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
    private const MFA_PENDING = 'mfa_pending';
    private const SSO_COOKIES = 'sso_cookies';
    private const API_KEY = 'api_key';

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

    /** Deletes credentials and tokens only; SSO cookies, pending MFA and the API key have their own delete methods. */
    public function deleteCredentials(string $uid): void
    {
        foreach ([self::CREDENTIALS, self::TOKENS] as $key) {
            $this->config->deleteUserConfig($uid, Application::APP_ID, $key);
        }
    }

    public function apiKey(string $uid): ?ApiKeyRecord
    {
        $data = $this->read($uid, self::API_KEY);
        if ($data === null) {
            return null;
        }
        try {
            return ApiKeyRecord::fromArray($data);
        } catch (\InvalidArgumentException) {
            $this->warn($uid, self::API_KEY);

            return null;
        }
    }

    public function saveApiKey(string $uid, ApiKeyRecord $record): void
    {
        $this->write($uid, self::API_KEY, $record->toArray());
    }

    public function deleteApiKey(string $uid): void
    {
        $this->config->deleteUserConfig($uid, Application::APP_ID, self::API_KEY);
    }

    /** An expired request is deleted and reads as missing. */
    public function pendingMfa(string $uid, int $now): ?PendingMfa
    {
        $data = $this->read($uid, self::MFA_PENDING);
        if ($data === null) {
            return null;
        }
        try {
            $pending = PendingMfa::fromArray($data);
        } catch (\InvalidArgumentException) {
            $this->warn($uid, self::MFA_PENDING);

            return null;
        }
        if ($pending->isExpired($now)) {
            $this->deletePendingMfa($uid);

            return null;
        }

        return $pending;
    }

    public function savePendingMfa(string $uid, PendingMfa $pending): void
    {
        $this->write($uid, self::MFA_PENDING, $pending->toArray());
    }

    public function deletePendingMfa(string $uid): void
    {
        $this->config->deleteUserConfig($uid, Application::APP_ID, self::MFA_PENDING);
    }

    /**
     * Remembered SSO cookies (Netscape lines); empty if missing or invalid.
     *
     * @return list<string>
     */
    public function ssoCookies(string $uid): array
    {
        $data = $this->read($uid, self::SSO_COOKIES);
        $cookies = $data['cookies'] ?? null;
        if (!is_array($cookies) || !array_is_list($cookies) || array_filter($cookies, static fn ($c) => !is_string($c)) !== []) {
            if ($data !== null) {
                $this->warn($uid, self::SSO_COOKIES);
            }

            return [];
        }

        return $cookies;
    }

    /** @param list<string> $cookies */
    public function saveSsoCookies(string $uid, array $cookies): void
    {
        $this->write($uid, self::SSO_COOKIES, ['cookies' => $cookies]);
    }

    public function deleteSsoCookies(string $uid): void
    {
        $this->config->deleteUserConfig($uid, Application::APP_ID, self::SSO_COOKIES);
    }

    public function clear(string $uid): void
    {
        foreach ([self::CREDENTIALS, self::TOKENS, self::MFA_PENDING, self::SSO_COOKIES, self::API_KEY] as $key) {
            $this->config->deleteUserConfig($uid, Application::APP_ID, $key);
        }
    }

    /** @return ?array<string, mixed> null for a missing, undecryptable or invalid value */
    private function read(string $uid, string $key): ?array
    {
        // lazy: not loaded with the person's other settings on every request.
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
            true, // lazy
        );
    }

    /** Never logs the value, only person and key name. */
    private function warn(string $uid, string $key): void
    {
        $this->logger->warning('Stored secret is unreadable and treated as missing', ['uid' => $uid, 'key' => $key]);
    }
}
