<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin;

use OCA\WorkoutCalendar\Garmin\Diagnostics\FailureCategory;

/** Garmin-Zugriff fehlgeschlagen. Kategorie und Schritt zeigen, wo und warum (ADR 0004). */
final class GarminException extends \RuntimeException
{
    /**
     * @param string $step Ablaufschritt, z. B. "widget.signin-post"
     * @param string $excerpt Bereits bereinigter Auszug der Antwort
     * @param array<string, string> $attempts Login-Versuche je Strategie, z. B. ["mobile" => "BLOCKED @ mobile.login"]
     */
    public function __construct(
        public readonly FailureCategory $category,
        public readonly string $step,
        string $message,
        public readonly ?int $status = null,
        public readonly string $excerpt = '',
        ?\Throwable $previous = null,
        public readonly array $attempts = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, string> $attempts */
    public function withAttempts(array $attempts): self
    {
        return new self($this->category, $this->step, $this->getMessage(), $this->status, $this->excerpt, $this, $attempts);
    }

    /** @return array<string, mixed> Kontext für das Log, enthält keine Geheimnisse */
    public function logContext(): array
    {
        return array_filter([
            'category' => $this->category->value,
            'step' => $this->step,
            'status' => $this->status,
            'reason' => $this->getMessage(),
            'excerpt' => $this->excerpt,
            'attempts' => $this->attempts,
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }
}
