<?php

namespace App\Services;

final class AppointmentReminderRetry
{
    public const MAX_ATTEMPTS = 3;

    public static function delivered(array $states, string $channel, int $id): bool
    {
        return isset($states[$channel]['sent'][(string) $id])
            || ($channel === 'wa' && isset($states['sms']['sent'][(string) $id]));
    }

    public static function exhausted(array $failure): bool
    {
        return (int) ($failure['attempts'] ?? 0) >= self::MAX_ATTEMPTS;
    }

    public static function ready(array $failure, ?int $now = null): bool
    {
        return !self::exhausted($failure) && (int) ($failure['retry_at'] ?? 0) <= ($now ?? time());
    }

    public static function failed(array $previous, ?int $now = null): array
    {
        $attempts = (int) ($previous['attempts'] ?? 0) + 1;
        return ['attempts' => $attempts, 'retry_at' => ($now ?? time()) + 900 * min(4, $attempts)];
    }
}
