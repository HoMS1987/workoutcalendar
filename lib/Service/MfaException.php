<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** A code request or verification that cannot go ahead, without asking Garmin. */
final class MfaException extends \RuntimeException
{
    public const RATE_LIMITED = 'MFA_RATE_LIMITED';
    public const EXPIRED = 'MFA_EXPIRED';
    public const NOT_PENDING = 'MFA_NOT_PENDING';

    /**
     * @param string $error One of the constants above
     * @param ?int $retryAfter Seconds until the next code request is allowed (only with MFA_RATE_LIMITED)
     */
    public function __construct(
        public readonly string $error,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($error);
    }
}
