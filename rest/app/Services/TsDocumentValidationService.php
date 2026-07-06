<?php

namespace App\Services;

use App\Config\TsBilling;

class TsDocumentValidationService
{
    private TsBilling $config;

    public function __construct(?TsBilling $config = null)
    {
        $this->config = $config ?? config(TsBilling::class);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $profile
     * @return array<string, mixed>
     */
    public function validateDraft(array $payload, ?array $profile, bool $duplicateFound = false, string $sourceType = 'manual'): array
    {
        $errors = [];
        $warnings = [];
        $isOfficialTestPreset = $this->isOfficialTestPreset($profile);
        $sourceType = trim(strtolower($sourceType));
        $isCancellation = $sourceType === 'ts_cancellation';

        if (!is_array($profile) || (int) ($profile['id_ts_profile'] ?? 0) <= 0) {
            $errors[] = 'Nessun profilo TS disponibile per questo spazio.';
        } elseif ((int) ($profile['is_enabled'] ?? 0) !== 1) {
            $errors[] = 'Il profilo TS dello spazio non e attivo.';
        }

        $senderPiva = trim((string) ($payload['sender_piva_snapshot'] ?? ''));
        if ($senderPiva === '') {
            $errors[] = 'Partita IVA erogatore mancante.';
        } elseif (!$this->isValidPartitaIva($senderPiva) && !$isOfficialTestPreset) {
            $errors[] = 'La Partita IVA erogatore non e formalmente valida.';
        }

        $documentNumber = trim((string) ($payload['document_number'] ?? ''));
        if ($documentNumber === '') {
            $errors[] = 'Inserisci il numero documento.';
        } elseif (mb_strlen($documentNumber) > 32) {
            $errors[] = 'Il numero documento supera la lunghezza massima prevista.';
        }

        $documentDevice = (int) ($payload['document_device'] ?? 0);
        if ($documentDevice <= 0) {
            $errors[] = 'Inserisci il numero dispositivo del documento.';
        } elseif ($documentDevice > 999) {
            $errors[] = 'Il numero dispositivo deve essere compreso tra 1 e 999.';
        }

        $issueDate = trim((string) ($payload['issue_date'] ?? ''));
        if (!$this->isValidDate($issueDate)) {
            $errors[] = 'La data emissione non e valida.';
        }

        if ($isCancellation) {
            return [
                'valid' => $errors === [],
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $paymentDate = trim((string) ($payload['payment_date'] ?? ''));
        if (!$this->isValidDate($paymentDate)) {
            $errors[] = 'La data pagamento non e valida.';
        }

        if ($this->isValidDate($issueDate) && $this->isValidDate($paymentDate) && $paymentDate < $issueDate) {
            $warnings[] = 'La data pagamento e precedente alla data emissione: verifica se e corretto.';
        }

        $documentType = strtoupper(trim((string) ($payload['document_type'] ?? '')));
        if ($documentType === '') {
            $errors[] = 'Seleziona il tipo documento TS.';
        } elseif (!array_key_exists($documentType, $this->config->supportedDocumentTypes)) {
            $errors[] = 'Il tipo documento selezionato non e supportato dal modulo.';
        }

        $patientCf = strtoupper(trim((string) ($payload['patient_cf_plain'] ?? '')));
        if ($patientCf === '') {
            $errors[] = 'Inserisci il Codice Fiscale del paziente.';
        } elseif (!$this->isPlausibleCodiceFiscale($patientCf)) {
            $errors[] = 'Il Codice Fiscale del paziente non e formalmente valido.';
        }

        $expenseTypeCode = strtoupper(trim((string) ($payload['expense_type_code'] ?? '')));
        if ($expenseTypeCode === '') {
            $errors[] = 'Seleziona il tipo spesa.';
        } elseif (!array_key_exists($expenseTypeCode, $this->config->supportedExpenseTypes)) {
            $errors[] = 'Il tipo spesa selezionato non e supportato dal modulo.';
        }

        $paymentMode = trim((string) ($payload['payment_mode'] ?? ''));
        if ($paymentMode === '') {
            $errors[] = 'Seleziona la modalita di pagamento.';
        } elseif (!array_key_exists($paymentMode, $this->config->paymentModes)) {
            $errors[] = 'La modalita di pagamento selezionata non e valida.';
        }

        $amountTotal = (float) ($payload['amount_total'] ?? 0);
        if ($amountTotal <= 0) {
            $errors[] = 'L importo deve essere maggiore di zero.';
        }

        $vatRateRaw = $payload['vat_rate'] ?? null;
        $vatRatePresent = $vatRateRaw !== null && $vatRateRaw !== '';
        $vatRate = $vatRatePresent ? (float) $vatRateRaw : null;
        $vatNature = strtoupper(trim((string) ($payload['vat_nature'] ?? '')));

        if ($vatRatePresent && $vatNature !== '') {
            $errors[] = 'Compila solo uno tra aliquota IVA e natura IVA.';
        }

        if ($vatRatePresent) {
            if ($vatRate === null || $vatRate < 0 || $vatRate > 100) {
                $errors[] = 'L aliquota IVA deve essere compresa tra 0,00 e 100,00.';
            } elseif (round($vatRate, 2) !== $vatRate) {
                $errors[] = 'L aliquota IVA puo avere al massimo due decimali.';
            }
        }

        if ($vatNature !== '' && !preg_match('/^[A-Z0-9.]{2,10}$/', $vatNature)) {
            $errors[] = 'La natura IVA deve usare un codice sintetico valido, ad esempio N2.2.';
        }

        if ($documentType === 'F' && !$vatRatePresent && $vatNature === '') {
            $errors[] = 'Per il tipo documento F devi indicare una aliquota IVA oppure una natura IVA.';
        }

        if ($duplicateFound && $sourceType === 'manual') {
            $errors[] = 'Esiste gia un documento TS con lo stesso identificativo logico.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function isValidDate(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isValidPartitaIva(string $value): bool
    {
        if (!preg_match('/^\d{11}$/', $value)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $value[$i];
            if (($i % 2) === 0) {
                $sum += $digit;
                continue;
            }

            $double = $digit * 2;
            $sum += $double > 9 ? $double - 9 : $double;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $checkDigit === (int) $value[10];
    }

    private function isPlausibleCodiceFiscale(string $value): bool
    {
        return (bool) preg_match('/^(?:[A-Z0-9]{16}|\d{11})$/', strtoupper(trim($value)));
    }

    /**
     * @param array<string, mixed>|null $profile
     */
    private function isOfficialTestPreset(?array $profile): bool
    {
        if (!is_array($profile)) {
            return false;
        }

        $environment = trim((string) ($profile['environment'] ?? ''));
        if ($environment !== 'test') {
            return false;
        }

        $metadataJson = trim((string) ($profile['metadata_json'] ?? ''));
        if ($metadataJson === '') {
            return false;
        }

        try {
            $metadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return false;
        }

        return is_array($metadata)
            && trim((string) ($metadata['credential_mode'] ?? '')) === 'official_test_preset';
    }
}
