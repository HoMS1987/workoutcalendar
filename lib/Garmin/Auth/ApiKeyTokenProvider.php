<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Auth;

/**
 * Tokens for sign-in with the personal API key. There is no refresh token: the key is exchanged
 * again once the stored token runs out within the next hour.
 */
final class ApiKeyTokenProvider implements TokenProvider
{
    /** A token that expires sooner than this is exchanged before the request. */
    private const MIN_REMAINING_SECONDS = 3600;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /** @param (callable(): int)|null $now Current Unix time, replaceable in tests */
    public function __construct(
        private readonly ApiKeyTokenService $service,
        private readonly TokenStore $store,
        #[\SensitiveParameter] private readonly string $apiKey,
        ?callable $now = null,
    ) {
        $this->now = $now !== null ? \Closure::fromCallable($now) : static fn (): int => time();
    }

    public function current(): GarminTokens
    {
        $tokens = $this->store->load();
        $expiresAt = $tokens?->expiresAt();
        // A token without exp gives no hint how long it lasts, so it is exchanged as well.
        if ($tokens === null || $expiresAt === null || $expiresAt - ($this->now)() < self::MIN_REMAINING_SECONDS) {
            return $this->exchange();
        }

        return $tokens;
    }

    public function renew(GarminTokens $rejected): GarminTokens
    {
        return $this->exchange();
    }

    private function exchange(): GarminTokens
    {
        $tokens = $this->service->exchange($this->apiKey);
        $this->store->save($tokens);

        return $tokens;
    }
}
