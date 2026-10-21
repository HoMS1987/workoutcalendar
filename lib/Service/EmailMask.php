<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/** Masks email addresses for display so they do not appear in full in responses and logs. */
final class EmailMask
{
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr(substr($email, 0, $at), 0, 1) . '***' . substr($email, $at);
    }
}
