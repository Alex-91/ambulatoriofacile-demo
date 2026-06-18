<?php

namespace App\Libraries;

final class SystemUserMask
{
    public const SYSTEM_CLIENT_ID = 33;
    public const SYSTEM_USER_LABEL = 'Utente di sistema';

    public static function isMaskedClientId(int $clientId): bool
    {
        return $clientId === self::SYSTEM_CLIENT_ID;
    }

    public static function getMaskedClientDisplayName(int $clientId, ?string $defaultDisplay = ''): string
    {
        if (self::isMaskedClientId($clientId)) {
            return self::SYSTEM_USER_LABEL;
        }

        return trim((string)$defaultDisplay);
    }
}
