<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Diagnostics;

/**
 * Entfernt Geheimnisse (Passwörter, Tokens, Tickets, E-Mail-Adressen) aus Texten, bevor sie geloggt werden.
 *
 * Neue Felder mit Geheimnissen müssen hier ergänzt werden (ADR 0004).
 */
final class Redactor
{
    private const MASK = '***';

    private const SECRET_KEYS = 'password|username|email|access_token|refresh_token|id_token|di_token|'
        . 'di_refresh_token|serviceTicketId|service_ticket|ticket|_csrf|captchaToken';

    /** @var list<string> */
    private array $knownSecrets;

    /** @param list<string> $knownSecrets Konkrete Werte, die immer maskiert werden (z. B. das Passwort). */
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
            // JSON: "password":"wert"
            '/("(?:' . $keys . ')"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i' => '$1' . self::MASK . '$2',
            // Formular/Query: password=wert
            '/(?<![\w-])((?:' . $keys . ')=)[^&\s"\'<>]+/i' => '$1' . self::MASK,
            // HTML: name="_csrf" value="wert"
            '/(name="_csrf"\s+value=")[^"]*(")/i' => '$1' . self::MASK . '$2',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/' => '$1 ' . self::MASK,
            '/\bST-[A-Za-z0-9._-]+/' => 'ST-' . self::MASK,
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]*)?/' => self::MASK,
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? self::MASK;
    }

    /** Bereinigter, auf $maxLength Zeichen gekürzter Auszug, z. B. aus einer HTTP-Antwort. */
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
