<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Tries the login strategies in order. Single attempts are logged at debug level; the
 * summary is carried by the thrown exception and logged, throttled, by the sync flow (ADR 0004).
 */
final class Authenticator
{
    /**
     * For these errors another login path does not help, and further attempts could lock the account.
     * Blocked is not final: like python-garminconnect, the next strategy is tried, which Garmin limits separately.
     */
    private const FINAL_CATEGORIES = [FailureCategory::InvalidCredentials, FailureCategory::MfaRequired, FailureCategory::Network];

    /** @param list<LoginStrategy> $strategies */
    public function __construct(
        private readonly array $strategies,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws GarminException */
    public function login(Credentials $credentials): GarminTokens
    {
        $redactor = new Redactor([$credentials->password, $credentials->email]);
        $attempts = [];
        $last = null;

        foreach ($this->strategies as $strategy) {
            try {
                $tokens = $strategy->login($credentials);
                $this->logger->info('Garmin login succeeded', [
                    'strategy' => $strategy->name(),
                    'failedBefore' => array_keys($attempts),
                ]);

                return $tokens;
            } catch (NetworkException $e) {
                $last = new GarminException(FailureCategory::Network, $strategy->name() . '.login', $redactor->redact($e->getMessage()), previous: $e);
            } catch (GarminException $e) {
                $last = $e;
            }

            $attempts[$strategy->name()] = "{$last->category->value} @ {$last->step}";
            $this->logger->debug('Garmin login attempt failed', ['strategy' => $strategy->name()] + self::redactedContext($last, $redactor));
            if (in_array($last->category, self::FINAL_CATEGORIES, true)) {
                throw $last->withAttempts($attempts);
            }
        }

        throw $last?->withAttempts($attempts)
            ?? new GarminException(FailureCategory::ProtocolChanged, 'login', 'No login strategy configured');
    }

    /** @return array<string, mixed> */
    private static function redactedContext(GarminException $e, Redactor $redactor): array
    {
        return array_map(
            static fn (mixed $v): mixed => is_string($v) ? $redactor->redact($v) : $v,
            $e->logContext(),
        );
    }
}
