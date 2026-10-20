<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\BackgroundJob;

use OCA\WorkoutCalendar\CalDav\CalendarService;
use OCA\WorkoutCalendar\Storage\SetupState;
use OCA\WorkoutCalendar\Storage\UserStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Verschiebt oder entfernt die Termine der App in Etappen. Bleibt etwas übrig, reiht sich der
 * Job mit demselben Argument erneut ein.
 *
 * Argument: `['uid' => string, 'action' => 'move'|'remove', 'from' => int, 'to' => ?int]`
 */
final class CalendarMaintenanceJob extends QueuedJob
{
    public const BATCH = 200;

    public function __construct(
        ITimeFactory $time,
        private readonly CalendarService $calendars,
        private readonly UserStore $users,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
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
            $this->logger->warning('Wartungsjob mit ungültigem Argument übersprungen');

            return;
        }

        try {
            $rest = $action === 'move'
                ? $this->calendars->moveAppEvents($from, $to, self::BATCH)
                : $this->calendars->removeAppEvents($from, self::BATCH);
            if ($rest > 0) {
                $this->jobList->add(self::class, $argument);

                return;
            }
            $this->finish($uid);
        } catch (\Throwable $e) {
            // Kein erneutes Einreihen: die Person kann den Vorgang in den Einstellungen wiederholen.
            $this->logger->error('Wartungsjob fehlgeschlagen', [
                'uid' => $uid,
                'action' => $action,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function finish(string $uid): void
    {
        $setup = $this->users->setup($uid);
        $this->users->saveSetup($uid, new SetupState(
            $setup->step,
            $setup->connected,
            $setup->accountKey,
            $setup->maskedEmail,
            $setup->calendarId,
            $setup->calendarCreatedByApp,
            null,
        ));
    }
}
