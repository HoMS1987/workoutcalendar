<?php

declare(strict_types=1);

namespace OCA\GarminCalendar\Garmin\Diagnostics;

/** Fehlerkategorien für das Diagnose-Log (ADR 0004). */
enum FailureCategory: string
{
    case Blocked = 'BLOCKED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case MfaRequired = 'MFA_REQUIRED';
    case ProtocolChanged = 'PROTOCOL_CHANGED';
    case TokenRejected = 'TOKEN_REJECTED';
    case ServerError = 'SERVER_ERROR';
    case Network = 'NETWORK';
}
