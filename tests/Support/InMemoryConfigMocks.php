<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCP\Config\IUserConfig;
use OCP\Config\ValueType;
use OCP\Security\ICrypto;

/** Mocks for IUserConfig and ICrypto that keep values in memory. Usable in TestCase classes only. */
trait InMemoryConfigMocks
{
    /**
     * @param array<string, array<string, string|int>> $store [uid => [key => value]]
     * @param ?\Closure(string, string): void $beforeWrite called with uid and key before a string is
     *        written; a test can throw from it to simulate a failing database
     */
    private function memoryUserConfig(array &$store, ?\Closure $beforeWrite = null): IUserConfig
    {
        $config = $this->createMock(IUserConfig::class);
        $config->method('setValueString')->willReturnCallback(
            static function (string $uid, string $app, string $key, string $value) use (&$store, $beforeWrite): bool {
                if ($beforeWrite !== null) {
                    $beforeWrite($uid, $key);
                }
                $store[$uid][$key] = $value;

                return true;
            },
        );
        $config->method('setValueInt')->willReturnCallback(
            static function (string $uid, string $app, string $key, int $value) use (&$store): bool {
                $store[$uid][$key] = $value;

                return true;
            },
        );
        $config->method('getValueString')->willReturnCallback(
            static function (string $uid, string $app, string $key, string $default = '') use (&$store): string {
                return (string)($store[$uid][$key] ?? $default);
            },
        );
        $config->method('getValueInt')->willReturnCallback(
            static function (string $uid, string $app, string $key, int $default = 0) use (&$store): int {
                return (int)($store[$uid][$key] ?? $default);
            },
        );
        $config->method('deleteUserConfig')->willReturnCallback(
            static function (string $uid, string $app, string $key) use (&$store): void {
                unset($store[$uid][$key]);
            },
        );
        $config->method('getValuesByUsers')->willReturnCallback(
            static function (string $app, string $key, ?ValueType $type = null) use (&$store): array {
                $values = [];
                foreach ($store as $uid => $keys) {
                    if (isset($keys[$key])) {
                        $values[$uid] = $keys[$key];
                    }
                }

                return $values;
            },
        );

        return $config;
    }

    /** Reversible "encryption" without plain text in the result. */
    private function fakeCrypto(): ICrypto
    {
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . strrev(base64_encode($plain)));
        $crypto->method('decrypt')->willReturnCallback(static function (string $cipher): string {
            if (!str_starts_with($cipher, 'enc:')) {
                throw new \Exception('Authentication failed');
            }

            return (string)base64_decode(strrev(substr($cipher, 4)));
        });

        return $crypto;
    }
}
