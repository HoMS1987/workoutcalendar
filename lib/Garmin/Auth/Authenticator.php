<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Auth;

use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;
use OCA\GarminCalendar\Garmin\Diagnostics\Redactor;
use OCA\GarminCalendar\Garmin\GarminException;
use OCA\GarminCalendar\Garmin\Http\NetworkException;
use Psr\Log\LoggerInterface;

/** Probiert die Login-Strategien der Reihe nach und protokolliert jeden Versuch (ADR 0004). */
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
            $this->logger->warning('Garmin-Login fehlgeschlagen', ['strategy' => $strategy->name()] + self::redactedContext($last, $redactor));
            if (in_array($last->category, self::FINAL_CATEGORIES, true)) {
                throw $last;
            }
        }

        $this->logger->error('Garmin-Login mit allen Strategien fehlgeschlagen', ['attempts' => $attempts]);

        throw $last ?? new GarminException(FailureCategory::ProtocolChanged, 'login', 'Keine Login-Strategie konfiguriert');
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
