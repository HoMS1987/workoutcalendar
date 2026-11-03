<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Storage;

/** What triggered a pending MFA code request. */
enum MfaOrigin: string
{
    case Setup = 'setup';
    case Relogin = 'relogin';
    case Sync = 'sync';
}
