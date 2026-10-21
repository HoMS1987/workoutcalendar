<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Support;

use Psr\Log\AbstractLogger;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function byLevel(string $level): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => $r['level'] === $level));
    }

    /** Everything that was logged as one text - for assertions about leaked secrets. */
    public function dump(): string
    {
        return json_encode($this->records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
