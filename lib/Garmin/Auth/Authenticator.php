<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/**
 * Probiert die Login-Strategien der Reihe nach. Einzelversuche werden auf Stufe debug geloggt, die
 * Zusammenfassung steckt in der geworfenen Exception und wird gedrosselt vom Sync-Ablauf geloggt (ADR 0004).
 */
final class Authenticator
{
    /** Bei diesen Fehlern hilft ein anderer Login-Weg nicht, weitere Versuche könnten das Konto sperren. */
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
                $this->logger->info('Garmin-Login erfolgreich', [
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
            $this->logger->debug('Garmin-Login-Versuch fehlgeschlagen', ['strategy' => $strategy->name()] + self::redactedContext($last, $redactor));
            if (in_array($last->category, self::FINAL_CATEGORIES, true)) {
                throw $last->withAttempts($attempts);
            }
        }

        throw $last?->withAttempts($attempts)
            ?? new GarminException(FailureCategory::ProtocolChanged, 'login', 'Keine Login-Strategie konfiguriert');
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
