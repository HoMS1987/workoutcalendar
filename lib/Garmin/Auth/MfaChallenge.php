<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

/** What a pending MFA code request needs to continue in the same SSO session: the method and the SSO cookies. */
final class MfaChallenge
{
    /**
     * @param string|null $method Last used MFA method reported by Garmin, null if unknown
     * @param list<string> $cookies Netscape cookie lines of the SSO session
     */
    public function __construct(
        public readonly ?string $method,
        public readonly array $cookies,
    ) {
    }

    /** @return array{method: string|null, cookies: list<string>} */
    public function toArray(): array
    {
        return ['method' => $this->method, 'cookies' => $this->cookies];
    }

    /**
     * @param array<mixed> $data
     * @throws \InvalidArgumentException if the data does not have the expected shape
     */
    public static function fromArray(array $data): self
    {
        $method = $data['method'] ?? null;
        if ($method !== null && !is_string($method)) {
            throw new \InvalidArgumentException('MFA challenge method must be a string or null');
        }
        $cookies = $data['cookies'] ?? null;
        if (!is_array($cookies) || !array_is_list($cookies)) {
            throw new \InvalidArgumentException('MFA challenge cookies must be a list of strings');
        }
        foreach ($cookies as $cookie) {
            if (!is_string($cookie)) {
                throw new \InvalidArgumentException('MFA challenge cookies must be a list of strings');
            }
        }

        return new self($method, $cookies);
    }
}
