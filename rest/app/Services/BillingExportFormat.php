<?php
namespace App\Services;

final class BillingExportFormat
{
    /** A fresh URL also bypasses copies cached before PDF responses used no-store. */
    public static function pdfUrl(int $documentId): string
    {
        return site_url('admin/fatturazione-documenti/pdf/' . $documentId)
            . '?render=' . bin2hex(random_bytes(8));
    }

    public static function filename(array $document, array &$used = []): string
    {
        $number = trim((string) ($document['document_number'] ?? ''));
        $safe = trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $number) ?? '', '-_');
        $base = $safe !== '' ? $safe : 'fattura-' . (int) ($document['id_billing_document'] ?? 0);
        $name = $base . '.pdf';
        $suffix = 1;
        while (isset($used[strtolower($name)])) {
            $name = $base . '-' . ++$suffix . '.pdf';
        }
        $used[strtolower($name)] = true;
        return $name;
    }

    public static function patientName(array $document, array $snapshot): string
    {
        $details = (array) ($snapshot['patient_details'] ?? []);
        if (($details['recipient_type'] ?? 'patient') === 'business') return (string) ($document['patient_name'] ?? '');
        $first = trim((string) ($details['patient_first_name'] ?? ''));
        $last = trim((string) ($details['patient_last_name'] ?? ''));
        if ($first === '' || $last === '') {
            // Historical free-form names cannot be split reliably, especially compound surnames.
            return (string) ($document['patient_name'] ?? '');
        }
        return mb_convert_case($first . ' ' . $last, MB_CASE_TITLE, 'UTF-8');
    }
}
