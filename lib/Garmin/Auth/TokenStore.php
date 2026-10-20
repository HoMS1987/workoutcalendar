<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Auth;

/** Ablage der Garmin-Tokens eines Nutzers. Die Nextcloud-Implementierung speichert verschlüsselt (ADR 0003). */
interface TokenStore
{
    public function load(): ?GarminTokens;

    public function save(GarminTokens $tokens): void;
}
