<?php

namespace App\Services;

/** National Gateway validation lifetime; regional workflows have separate gates. */
final class FseValidationWindow
{
    public const SECONDS = 5 * 24 * 60 * 60;

    public static function isFresh(array $document, ?int $now = null): bool
    {
        $value = $document['validated_at'] ?? null;
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) return false;
        $age = ($now ?? time()) - $date->getTimestamp();
        return $age >= 0 && $age < self::SECONDS;
    }

    public static function canRevalidate(array $document, ?int $now = null): bool
    {
        return ($document['local_state'] ?? '') === 'signed'
            && !empty($document['signed_pdf_path'])
            && empty($document['published_at']) && empty($document['deleted_at'])
            && empty($document['previous_document_id']) && !self::isFresh($document, $now);
    }

    public static function assertFresh(array $document, ?int $now = null): void
    {
        if (!self::isFresh($document, $now)) {
            throw new \RuntimeException('Validazione Gateway scaduta (5 giorni), mancante o non verificabile. Rivalida il PDF firmato prima della pubblicazione.');
        }
    }
}
