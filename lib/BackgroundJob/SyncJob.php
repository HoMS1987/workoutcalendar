<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\BackgroundJob;

use OCA\WorkoutCalendar\Service\SyncRunner;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Every 5 minutes: processes the people who are due, at most `MAX_USERS_PER_RUN` per call. */
final class SyncJob extends TimedJob
{
    /** Limits one cron run: every person means several Garmin requests, a widget login alone waits 3-8 s. */
    public const MAX_USERS_PER_RUN = 10;

    public function __construct(
        ITimeFactory $time,
        private readonly UserStore $users,
        private readonly SyncRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // Below the 5-minute cron period: TimedJob needs strictly more than the interval, so 300 would skip every second tick.
        $this->setInterval(240);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    protected function run($argument): void
    {
        $now = \DateTimeImmutable::createFromMutable($this->time->getDateTime());
        foreach ($this->users->dueUsers($this->time->getTime(), self::MAX_USERS_PER_RUN) as $uid) {
            try {
                $this->runner->run($uid, $now);
            } catch (\Throwable $e) {
                // Only the uid plus the exception's class and message: Garmin messages are already sanitized in the lower layers.
                $this->logger->error('Sync run failed', [
                    'uid' => $uid,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
