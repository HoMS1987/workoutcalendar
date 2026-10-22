<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Diagnostics;

/**
 * Removes secrets (passwords, tokens, tickets, email addresses) from texts before they are logged.
 *
 * New fields containing secrets must be added here (ADR 0004).
 */
final class Redactor
{
    private const MASK = '***';

    private const SECRET_KEYS = 'password|username|email|access_token|refresh_token|id_token|di_token|'
        . 'di_refresh_token|serviceTicketId|service_ticket|ticket|_csrf|captchaToken';

    /** @var list<string> */
    private array $knownSecrets;

    /** @param list<string> $knownSecrets Concrete values that are always masked (e.g. the password). */
    public function __construct(array $knownSecrets = [])
    {
        $this->knownSecrets = array_values(array_filter($knownSecrets, static fn (string $s): bool => $s !== ''));
    }

    public function redact(string $text): string
    {
        foreach ($this->knownSecrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        $keys = self::SECRET_KEYS;
        $patterns = [
            // JSON: "password":"value"
            '/("(?:' . $keys . ')"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i' => '$1' . self::MASK . '$2',
            // Form/query: password=value
            '/(?<![\w-])((?:' . $keys . ')=)[^&\s"\'<>]+/i' => '$1' . self::MASK,
            // HTML: name="_csrf" value="value"
            '/(name="_csrf"\s+value=")[^"]*(")/i' => '$1' . self::MASK . '$2',
            // Authorization header values
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/' => '$1 ' . self::MASK,
            // CAS service tickets
            '/\bST-[A-Za-z0-9._-]+/' => 'ST-' . self::MASK,
            // JWTs, i.e. access and refresh tokens
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]*)?/' => self::MASK,
            // Email addresses
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? self::MASK;
    }

    /** Sanitized excerpt shortened to $maxLength characters, e.g. from an HTTP response. */
    public function excerpt(string $text, int $maxLength = 300): string
    {
        $clean = $this->redact($text);
        if (!mb_check_encoding($clean, 'UTF-8')) {
            $clean = mb_convert_encoding($clean, 'UTF-8', 'UTF-8');
        }
        if (mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxLength) . '…';
    }
}
