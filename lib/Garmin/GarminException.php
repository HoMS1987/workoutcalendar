<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin;

use OCA\GarminCalendar\Garmin\Diagnostics\FailureCategory;

/** Garmin-Zugriff fehlgeschlagen. Kategorie und Schritt zeigen, wo und warum (ADR 0004). */
final class GarminException extends \RuntimeException
{
    /**
     * @param string $step Ablaufschritt, z. B. "widget.signin-post"
     * @param string $excerpt Bereits bereinigter Auszug der Antwort
     */
    public function __construct(
        public readonly FailureCategory $category,
        public readonly string $step,
        string $message,
        public readonly ?int $status = null,
        public readonly string $excerpt = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
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
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }
}
