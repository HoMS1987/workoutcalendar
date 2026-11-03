<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\BackgroundJob;

use OCA\WorkoutCalendar\Service\PersonBusyException;
use OCA\WorkoutCalendar\Service\PersonLock;
use OCA\WorkoutCalendar\Storage\SecretStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Deletes a person's pending code request once it has expired, so the encrypted SSO session and
 * credentials do not stay in the database. Argument: `['uid' => string]`.
 * A newer request that is still valid stays; it has scheduled its own job.
 */
final class MfaCleanupJob extends QueuedJob
{
    /** Wait before trying again while the person's lock is taken. */
    public const RETRY_SECONDS = 300;

    public function __construct(
        ITimeFactory $time,
        private readonly SecretStore $secrets,
        private readonly PersonLock $lock,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void
    {
        $uid = is_array($argument) ? ($argument['uid'] ?? null) : null;
        if (!is_string($uid) || $uid === '') {
            $this->logger->warning('MFA cleanup job skipped, invalid argument');

            return;
        }

        try {
            // WHY under the lock: otherwise a code request saved in between could be deleted along with the expired one.
            $this->lock->run($uid, function () use ($uid): void {
                // Reading deletes an expired request.
                $this->secrets->pendingMfa($uid, $this->time->getTime());
            });
        } catch (PersonBusyException) {
            $this->logger->debug('MFA cleanup postponed, another operation holds the lock', ['uid' => $uid]);
            $this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::RETRY_SECONDS, ['uid' => $uid]);
        }
    }
}
