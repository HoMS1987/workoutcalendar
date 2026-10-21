<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

use OCA\WorkoutCalendar\AppInfo\Application;
use OCA\WorkoutCalendar\Sync\SyncSettings;
use OCA\WorkoutCalendar\Sync\SyncState;
use OCP\Config\IUserConfig;
use OCP\Config\ValueType;

/**
 * A person's settings, sync state and setup progress in IUserConfig.
 * Types per key are fixed: settings, state and setup are JSON strings, next_run_at is an int.
 */
final class UserStore
{
    private const SETTINGS = 'settings';
    private const STATE = 'state';
    private const SETUP = 'setup';
    private const NEXT_RUN_AT = 'next_run_at';
    private const KEYS = [self::SETTINGS, self::STATE, self::SETUP, self::NEXT_RUN_AT];

    public function __construct(private readonly IUserConfig $config)
    {
    }

    public function settings(string $uid): SyncSettings
    {
        return SyncSettings::fromArray($this->readJson($uid, self::SETTINGS));
    }

    public function saveSettings(string $uid, SyncSettings $settings): void
    {
        $this->writeJson($uid, self::SETTINGS, $settings->toArray());
    }

    public function state(string $uid): SyncState
    {
        return SyncState::fromArray($this->readJson($uid, self::STATE));
    }

    /** Also writes next_run_at so dueUsers() does not have to load all states. */
    public function saveState(string $uid, SyncState $state): void
    {
        $this->writeJson($uid, self::STATE, $state->toArray());
        if ($state->nextRunAt === null) {
            $this->config->deleteUserConfig($uid, Application::APP_ID, self::NEXT_RUN_AT);
        } else {
            $this->config->setValueInt($uid, Application::APP_ID, self::NEXT_RUN_AT, $state->nextRunAt, true);
        }
    }

    public function setup(string $uid): SetupState
    {
        return SetupState::fromArray($this->readJson($uid, self::SETUP));
    }

    public function saveSetup(string $uid, SetupState $setup): void
    {
        $this->writeJson($uid, self::SETUP, $setup->toArray());
    }

    /**
     * People whose next run is due, the longest-waiting first.
     *
     * @return list<string>
     */
    public function dueUsers(int $now, int $limit): array
    {
        $values = $this->config->getValuesByUsers(Application::APP_ID, self::NEXT_RUN_AT, ValueType::INT);
        $due = [];
        foreach ($values as $uid => $nextRunAt) {
            if (is_int($nextRunAt) && $nextRunAt <= $now) {
                $due[(string)$uid] = $nextRunAt;
            }
        }
        asort($due);

        return array_slice(array_map('strval', array_keys($due)), 0, max(0, $limit));
    }

    /** Removes all keys of this store for the person. */
    public function clear(string $uid): void
    {
        foreach (self::KEYS as $key) {
            $this->config->deleteUserConfig($uid, Application::APP_ID, $key);
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $uid, string $key): array
    {
        $raw = $this->config->getValueString($uid, Application::APP_ID, $key, '', true);
        $data = $raw === '' ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $uid, string $key, array $data): void
    {
        $this->config->setValueString(
            $uid,
            Application::APP_ID,
            $key,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            true,
        );
    }
}
