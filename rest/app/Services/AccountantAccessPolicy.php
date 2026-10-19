<?php
namespace App\Services;

class AccountantAccessPolicy
{
    public static function filters(array $input, string $fromDate): array
    {
        $from = self::date((string) ($input['date_from'] ?? '')) ?: $fromDate;
        $to = self::date((string) ($input['date_to'] ?? '')) ?: date('Y-m-d');
        $from = max($from, $fromDate);
        if ($to < $from) { throw new \InvalidArgumentException('Il periodo selezionato precede l’anno autorizzato o ha date invertite.'); }
        return ['period' => 'custom', 'date_from' => $from, 'date_to' => $to,
            'patient' => mb_substr(trim((string) ($input['patient'] ?? '')), 0, 120),
            'document_number' => mb_substr(trim((string) ($input['document_number'] ?? '')), 0, 80),
            'payment_status' => (string) ($input['payment_status'] ?? ''),
            'document_type' => (string) ($input['document_type'] ?? ''),
            'payment_method' => (string) ($input['payment_method'] ?? '')];
    }

    public static function documentAllowed(array $document, int $doctor, string $fromDate): bool
    {
        return (int) ($document['_export_tenant_id'] ?? 0) === $doctor
            && ($document['local_state'] ?? '') === 'issued'
            && self::date((string) ($document['issue_date'] ?? '')) !== null
            && $document['issue_date'] >= $fromDate;
    }

    private static function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}
