<?php

namespace App\Services;

/** Business identity is kept with the immutable document template, not in patient records. */
final class BillingBusinessRecipient
{
    public const FIELDS = [
        'business_vat_number', 'business_postal_code', 'business_province',
        'business_country', 'business_recipient_code', 'business_pec',
    ];

    public static function isBusiness(array $document): bool
    {
        $snapshot = json_decode((string) ($document['template_snapshot_json'] ?? '{}'), true);
        return ($document['recipient_type'] ?? $snapshot['recipient_type'] ?? 'patient') === 'business';
    }

    public static function details(array $payload): array
    {
        $details = ['recipient_type' => 'business'];
        foreach (self::FIELDS as $field) {
            $details[$field] = strtoupper(trim((string) ($payload[$field] ?? '')));
        }
        $details['business_country'] = $details['business_country'] ?: 'IT';
        $details['business_pec'] = strtolower($details['business_pec']);
        return $details;
    }

    public static function errors(array $data): array
    {
        $errors = [];
        $vat = $data['business_vat_number'] ?? '';
        $cf = $data['patient_tax_code'] ?? '';
        if ($vat === '' && $cf === '') $errors[] = 'Inserisci la partita IVA o il codice fiscale della struttura.';
        if ($vat !== '' && !preg_match(($data['business_country'] ?? 'IT') === 'IT' ? '/^\d{11}$/' : '/^[A-Z0-9]{2,28}$/', $vat)) $errors[] = 'Partita IVA non valida.';
        if ($cf !== '' && !preg_match('/^(?:\d{11}|[A-Z0-9]{16})$/', $cf)) $errors[] = 'Codice fiscale della struttura non valido.';
        if (!preg_match('/^[A-Z]{2}$/', $data['business_country'] ?? '')) $errors[] = 'Indica il codice paese di due lettere.';
        if (($data['business_postal_code'] ?? '') !== '' && ($data['business_country'] ?? '') === 'IT' && !preg_match('/^\d{5}$/', $data['business_postal_code'])) $errors[] = 'CAP non valido.';
        if (($data['business_recipient_code'] ?? '') !== '' && !preg_match('/^[A-Z0-9]{6,7}$/', $data['business_recipient_code'])) $errors[] = 'Codice destinatario non valido.';
        if (($data['business_pec'] ?? '') !== '' && !filter_var($data['business_pec'], FILTER_VALIDATE_EMAIL)) $errors[] = 'PEC non valida.';
        if ((float) ($data['vat_rate'] ?? 0) > 100) $errors[] = 'Aliquota IVA non valida.';
        return $errors;
    }
}
