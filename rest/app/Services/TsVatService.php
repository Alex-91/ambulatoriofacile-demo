<?php

namespace App\Services;

class TsVatService
{
    public static function normalizeRate($value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $rate = (float) str_replace(',', '.', $value);
        return $rate == 0.0 ? null : $rate;
    }

    public static function isNatureCode(string $value): bool
    {
        return preg_match('/^N[1-7](?:\.[1-9])?$/', $value) === 1;
    }

    public static function profileNatureCode(array $profile): string
    {
        $metadata = json_decode((string) ($profile['metadata_json'] ?? ''), true);
        return strtoupper(trim((string) ($metadata['document_defaults']['vat_nature_code'] ?? '')));
    }

    public static function refreshPendingBillingDocument(array $document, array $profile): array
    {
        if (($document['source_type'] ?? '') !== 'billing'
            || !in_array(($document['local_state'] ?? ''), ['draft', 'to_validate', 'ready', 'rejected'], true)
            || trim((string) ($document['ts_protocol'] ?? '')) !== ''
            || trim((string) ($document['ts_sent_at'] ?? '')) !== ''
            || in_array(($document['ts_state'] ?? ''), ['accepted', 'varied', 'cancelled'], true)) {
            return $document;
        }
        $document['vat_rate'] = self::normalizeRate($document['vat_rate'] ?? null);
        $document['vat_nature'] = self::billingNatureCode($document, $profile) ?: null;
        return $document;
    }

    public static function billingNatureCode(array $document, array $profile = []): string
    {
        // Printed invoice text and old invoice defaults never determine TS VAT.
        return self::normalizeRate($document['vat_rate'] ?? null) === null
            ? self::profileNatureCode($profile)
            : '';
    }
}
