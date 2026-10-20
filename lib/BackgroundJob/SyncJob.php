<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\BackgroundJob;

use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Alle 5 Minuten: arbeitet die fälligen Personen ab, höchstens `MAX_USERS_PER_RUN` pro Aufruf. */
final class SyncJob extends TimedJob
{
    public const MAX_USERS_PER_RUN = 10;

    public function __construct(
        ITimeFactory $time,
        private readonly UserStore $users,
        private readonly SyncRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    protected function run($argument): void
    {
        $now = \DateTimeImmutable::createFromMutable($this->time->getDateTime());
        foreach ($this->users->dueUsers($this->time->getTime(), self::MAX_USERS_PER_RUN) as $uid) {
            try {
                $this->runner->run($uid, $now);
            } catch (\Throwable $e) {
                // Nur uid sowie Klasse und Meldung der Exception: Garmin-Meldungen sind in den unteren Schichten bereits bereinigt.
                $this->logger->error('Sync-Lauf fehlgeschlagen', [
                    'uid' => $uid,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
