<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Service;

/**
 * Every code request is a full sign-in with the password. The limit protects the Garmin account from
 * being locked: at most one request per minute and PER_HOUR requests per hour and person.
 */
final class MfaRequestLimit
{
    public const MIN_GAP_SECONDS = 60;
    public const PER_HOUR = 5;
    private const HOUR = 3600;

    /**
     * @param list<int> $requests Unix times of earlier requests
     * @return ?int Unix time of the next allowed request, null if one is allowed now
     */
    public static function nextAllowedAt(array $requests, int $now): ?int
    {
        $recent = self::recent($requests, $now);
        if ($recent === []) {
            return null;
        }

        $next = max($recent) + self::MIN_GAP_SECONDS;
        if (count($recent) >= self::PER_HOUR) {
            // The oldest of the last PER_HOUR requests has to leave the hour first.
            $lastAllowed = array_slice($recent, -self::PER_HOUR);
            $next = max($next, min($lastAllowed) + self::HOUR);
        }

        return $next > $now ? $next : null;
    }

    /**
     * Requests from the last hour, oldest first; older ones no longer count.
     *
     * @param list<int> $requests
     * @return list<int>
     */
    public static function recent(array $requests, int $now): array
    {
        $recent = array_values(array_filter($requests, static fn (int $t): bool => $t > $now - self::HOUR));
        sort($recent);

        return $recent;
    }
}
