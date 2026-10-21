<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\BackgroundJob;

use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Moves or removes the app's events in stages. If anything is left over, the
 * job queues itself again with the same argument.
 *
 * Argument: `['uid' => string, 'action' => 'move'|'remove', 'from' => int, 'to' => ?int]`, optional
 * `'attempt' => int` (failed runs in a row), `'rest' => int` (remainder after the last run) and
 * `'gaveUp' => true` (the job has given up, only the completion is left).
 * After an error or without progress the job queues itself again up to MAX_ATTEMPTS, then
 * it gives up, releases the `maintenance` lock and records the action in `maintenanceFailed` for the
 * settings page. A successful completion clears this marker.
 *
 * Completion saves under the person's lock. If it is taken, the job queues itself again with
 * the unchanged argument; that does not count as a failed attempt. After giving up, the argument
 * carries `gaveUp`, so the next run only completes and moves or removes nothing more.
 */
final class CalendarMaintenanceJob extends QueuedJob
{
    public const BATCH = 200;
    /** Failed runs in a row after which the job gives up. */
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        ITimeFactory $time,
        private readonly CalendarService $calendars,
        private readonly UserStore $users,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
        private readonly PersonLock $lock,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void
    {
        $uid = is_array($argument) ? ($argument['uid'] ?? null) : null;
        $action = is_array($argument) ? ($argument['action'] ?? null) : null;
        $from = is_array($argument) ? ($argument['from'] ?? null) : null;
        $to = is_array($argument) ? ($argument['to'] ?? null) : null;
        $valid = is_string($uid) && $uid !== '' && is_int($from)
            && (($action === 'move' && is_int($to)) || ($action === 'remove'));
        if (!$valid) {
            $this->logger->warning('Maintenance job skipped, invalid argument');

            return;
        }

        if (($argument['gaveUp'] ?? null) === true) {
            // WHY: Giving up is final. A postponed completion must not run another batch.
            $this->finish($argument, $uid, $action);

            return;
        }

        $attempt = $this->count($argument['attempt'] ?? null) ?? 0;
        $lastRest = $this->count($argument['rest'] ?? null);

        try {
            $rest = $action === 'move'
                ? $this->calendars->moveAppEvents($from, $to, self::BATCH)
                : $this->calendars->removeAppEvents($from, self::BATCH);
        } catch (\Throwable $e) {
            $this->logger->error('Maintenance job failed', [
                'uid' => $uid,
                'action' => $action,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->retryOrGiveUp($argument, $uid, $action, $attempt, $lastRest);

            return;
        }

        if ($rest <= 0) {
            $this->finish($argument, $uid);

            return;
        }
        if ($lastRest !== null && $rest >= $lastRest) {
            $this->retryOrGiveUp($argument, $uid, $action, $attempt, $lastRest);

            return;
        }
        $this->jobList->add(self::class, [...$argument, 'attempt' => 0, 'rest' => $rest]);
    }

    /** Optional counter in the argument: only integers from 0 count, anything else is treated as absent. */
    private function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @param array<string, mixed> $argument */
    private function retryOrGiveUp(array $argument, string $uid, string $action, int $attempt, ?int $lastRest): void
    {
        if ($attempt + 1 > self::MAX_ATTEMPTS) {
            $this->logger->error('Maintenance job gives up', [
                'uid' => $uid,
                'action' => $action,
                'attempts' => $attempt + 1,
            ]);
            $this->finish([...$argument, 'gaveUp' => true], $uid, $action);

            return;
        }
        $retry = [...$argument, 'attempt' => $attempt + 1];
        if ($lastRest !== null) {
            $retry['rest'] = $lastRest;
        } else {
            unset($retry['rest']);
        }
        $this->jobList->add(self::class, $retry);
    }

    /**
     * @param array<string, mixed> $argument
     * @param ?string $failed Action the job gave up on, null on success
     */
    private function finish(array $argument, string $uid, ?string $failed = null): void
    {
        try {
            $this->lock->run($uid, function () use ($uid, $failed): void {
                $setup = $this->users->setup($uid);
                $this->users->saveSetup($uid, new SetupState(
                    $setup->step,
                    $setup->connected,
                    $setup->accountKey,
                    $setup->maskedEmail,
                    $setup->calendarId,
                    $setup->calendarCreatedByApp,
                    null,
                    $failed,
                    $setup->calendarMissing,
                ));
            });
        } catch (PersonBusyException) {
            // WHY: Without the lock, completion would overwrite a concurrent change to the setup.
            $this->logger->debug('Maintenance job completion postponed, another operation holds the lock', ['uid' => $uid]);
            $this->jobList->add(self::class, $argument);
        }
    }
}
