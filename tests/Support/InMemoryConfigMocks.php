<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use OCP\Config\IUserConfig;
use OCP\Config\ValueType;
use OCP\Security\ICrypto;

/** Mocks für IUserConfig und ICrypto, die Werte im Speicher halten. Nur in TestCase-Klassen verwendbar. */
trait InMemoryConfigMocks
{
    /**
     * @param array<string, array<string, string|int>> $store [uid => [key => value]]
     */
    private function memoryUserConfig(array &$store): IUserConfig
    {
        $config = $this->createMock(IUserConfig::class);
        $config->method('setValueString')->willReturnCallback(
            static function (string $uid, string $app, string $key, string $value) use (&$store): bool {
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

    /** Reversible "Verschlüsselung" ohne Klartext im Ergebnis. */
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
