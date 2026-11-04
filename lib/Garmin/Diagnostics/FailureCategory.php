<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Diagnostics;

/** Failure categories for the diagnostic log (ADR 0004). */
enum FailureCategory: string
{
    case Blocked = 'BLOCKED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case MfaRequired = 'MFA_REQUIRED';
    case MfaCodeInvalid = 'MFA_CODE_INVALID';
    case ProtocolChanged = 'PROTOCOL_CHANGED';
    case ApiKeyRejected = 'API_KEY_REJECTED';
    case TokenRejected = 'TOKEN_REJECTED';
    case ServerError = 'SERVER_ERROR';
    case Network = 'NETWORK';
}
