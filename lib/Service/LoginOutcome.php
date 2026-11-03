<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** Result of a sign-in the person triggered. */
enum LoginOutcome: string
{
    /** Credentials and tokens are stored. */
    case SignedIn = 'signedIn';
    /** Garmin asks for a code; the request waits as PendingMfa. */
    case CodeRequired = 'codeRequired';
}
