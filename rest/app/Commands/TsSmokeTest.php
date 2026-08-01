<?php

namespace App\Commands;

use App\Services\TenantCatalogService;
use App\Services\TsDispatchService;
use App\Services\TsDocumentService;
use App\Services\TsProfileService;
use App\Services\TsReceiptService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TsSmokeTest extends BaseCommand
{
    protected $group = 'TS';
    protected $name = 'ts:smoke-test';
    protected $description = 'Esegue una prova end-to-end del modulo TS usando un preset TEST ufficiale locale.';
    protected $usage = 'ts:smoke-test [options]';
    protected $options = [
        '--tenant-id=' => 'ID tenant da usare. Se omesso usa il tenant runtime corrente risolto dal DB locale.',
        '--preset=' => 'Chiave preset TS TEST da ops/.local/ts-test-presets.json. Default: struttura_accreditata_lazio.',
        '--patient-cf=' => 'Codice Fiscale del paziente da inviare. Default: RSSMRA80A01H501U.',
        '--document-number=' => 'Numero documento da inviare. Se omesso viene generato automaticamente.',
        '--document-device=' => 'Numero dispositivo documento. Default: 11.',
        '--amount=' => 'Importo totale da inviare. Default: 18.40.',
        '--document-type=' => 'Tipo documento TS: F o D. Default: F.',
        '--expense-type=' => 'Tipo spesa da inviare. Default: SR.',
        '--vat-rate=' => 'Aliquota IVA da inviare. Default: 10.00.',
        '--vat-nature=' => 'Natura IVA alternativa, ad esempio N2.2. Default: vuoto.',
        '--fetch-receipt=' => 'Se impostato a 0 salta il recupero della ricevuta PDF TS. Default: 1.',
        '--payment-mode=' => 'Modalità pagamento: tracciato o contanti. Default: tracciato.',
        '--issue-date=' => 'Data emissione Y-m-d. Default: oggi.',
        '--payment-date=' => 'Data pagamento Y-m-d. Default: oggi.',
    ];

    public function run(array $params)
    {
        $tenantCatalog = new TenantCatalogService();
        $profiles = new TsProfileService();
        $documents = new TsDocumentService();
        $dispatch = new TsDispatchService();
        $receipts = new TsReceiptService();

        $tenantId = (int) ($this->readOptionValue($params, '--tenant-id') ?? 0);
        $tenant = $tenantId > 0
            ? $tenantCatalog->getTenantById($tenantId)
            : $tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            CLI::error('Tenant runtime non risolto. Passa --tenant-id oppure controlla il mapping platform_tenants -> db_name.');
            return EXIT_ERROR;
        }

        $tenantId = (int) ($tenant['id_tenant'] ?? 0);
        $presetKey = trim((string) ($this->readOptionValue($params, '--preset') ?? 'struttura_accreditata_lazio'));
        $patientCf = strtoupper(trim((string) ($this->readOptionValue($params, '--patient-cf') ?? 'RSSMRA80A01H501U')));
        $documentNumber = trim((string) ($this->readOptionValue($params, '--document-number') ?? ('TS' . date('ymdHis'))));
        $documentDevice = max(1, (int) ($this->readOptionValue($params, '--document-device') ?? 11));
        $amount = $this->normalizeAmount((string) ($this->readOptionValue($params, '--amount') ?? '18.40'));
        $documentType = strtoupper(trim((string) ($this->readOptionValue($params, '--document-type') ?? 'F')));
        $expenseType = strtoupper(trim((string) ($this->readOptionValue($params, '--expense-type') ?? 'SR')));
        $vatRate = $this->normalizeNullableDecimal((string) ($this->readOptionValue($params, '--vat-rate') ?? '10.00'));
        $vatNature = strtoupper(trim((string) ($this->readOptionValue($params, '--vat-nature') ?? '')));
        $fetchReceiptOption = $this->readOptionValue($params, '--fetch-receipt');
        $fetchReceipt = $fetchReceiptOption === null
            ? true
            : trim((string) $fetchReceiptOption) !== '0';
        $paymentMode = trim((string) ($this->readOptionValue($params, '--payment-mode') ?? 'tracciato'));
        $issueDate = trim((string) ($this->readOptionValue($params, '--issue-date') ?? date('Y-m-d')));
        $paymentDate = trim((string) ($this->readOptionValue($params, '--payment-date') ?? date('Y-m-d')));

        CLI::write('Tenant: ' . (string) ($tenant['tenant_name'] ?? $tenant['tenant_key'] ?? $tenantId), 'green');
        CLI::write('Preset TEST: ' . $presetKey);
        CLI::write('Documento: ' . $documentNumber . ' / dispositivo ' . $documentDevice);

        try {
            $profile = $profiles->saveDefaultProfile($tenantId, [
                'profile_name' => 'TS TEST Smoke',
                'sender_type' => '',
                'owner_piva' => '',
                'owner_cf' => '',
                'region_code' => '',
                'asl_code' => '',
                'ssa_code' => '',
                'auth_username' => '',
                'auth_password' => '',
                'pincode' => '',
                'environment' => 'test',
                'is_enabled' => 1,
                'test_preset_key' => $presetKey,
            ], 0);
        } catch (\Throwable $e) {
            CLI::error('Salvataggio profilo TS fallito: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        CLI::write('Profilo TS pronto: ' . (string) ($profile['profile_name'] ?? 'TS TEST Smoke'));

        try {
            $saved = $documents->saveDraftForTenant($tenantId, [
                'id_client' => 0,
                'patient_cf_plain' => $patientCf,
                'patient_label_plain' => 'Smoke Test TS',
                'document_number' => $documentNumber,
                'document_device' => $documentDevice,
                'issue_date' => $issueDate,
                'payment_date' => $paymentDate,
                'document_type' => $documentType,
                'expense_type_code' => $expenseType,
                'payment_mode' => $paymentMode,
                'amount_total' => number_format($amount, 2, ',', ''),
                'vat_rate' => $vatRate !== null ? number_format($vatRate, 2, ',', '') : '',
                'vat_nature' => $vatNature,
                'opposition_flag' => 0,
                'notes' => 'Smoke test CLI TS ' . date('Y-m-d H:i:s'),
            ], 0, 'validate');
        } catch (\Throwable $e) {
            CLI::error('Creazione documento TS fallita: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        $document = is_array($saved['document'] ?? null) ? $saved['document'] : [];
        $validation = is_array($saved['validation'] ?? null) ? $saved['validation'] : [];
        $documentId = (int) ($document['id_ts_document'] ?? 0);

        CLI::write('Documento TS creato: #' . $documentId . ' stato ' . (string) ($document['local_state'] ?? 'n/d'));

        foreach ((array) ($validation['warnings'] ?? []) as $warning) {
            CLI::write('Avviso: ' . (string) $warning, 'yellow');
        }

        if (empty($validation['valid']) || $documentId <= 0) {
            foreach ((array) ($validation['errors'] ?? []) as $error) {
                CLI::error((string) $error);
            }

            return EXIT_ERROR;
        }

        try {
            $result = $dispatch->dispatchDocument($tenantId, $documentId, 0);
        } catch (\Throwable $e) {
            CLI::error('Dispatch TS fallito: ' . $e->getMessage());
            return EXIT_ERROR;
        }

        $finalDocument = is_array($result['document'] ?? null) ? $result['document'] : [];
        $status = trim((string) ($result['status'] ?? 'error'));
        $message = trim((string) ($result['message'] ?? ''));
        $protocol = trim((string) ($finalDocument['ts_protocol'] ?? ''));
        $localState = trim((string) ($finalDocument['local_state'] ?? ''));
        $tsState = trim((string) ($finalDocument['ts_state'] ?? ''));

        CLI::newLine();
        CLI::write('Esito invio: ' . $status, $status === 'ok' ? 'green' : 'red');
        CLI::write('Messaggio: ' . ($message !== '' ? $message : '(vuoto)'));
        CLI::write('Stato locale finale: ' . ($localState !== '' ? $localState : '(vuoto)'));
        CLI::write('Stato TS finale: ' . ($tsState !== '' ? $tsState : '(vuoto)'));

        if ($protocol !== '') {
            CLI::write('Protocollo TS: ' . $protocol, 'green');
        }

        $responsePayload = $this->decodeJson((string) ($finalDocument['response_payload_json'] ?? ''));
        if ($responsePayload !== []) {
            $responseOutcome = trim((string) ($responsePayload['esito_chiamata'] ?? ''));
            $responseProtocol = trim((string) ($responsePayload['protocollo'] ?? ''));

            if ($responseOutcome !== '') {
                CLI::write('Esito risposta TS: ' . $responseOutcome);
            }

            if ($responseProtocol !== '' && $protocol === '') {
                CLI::write('Protocollo risposta TS: ' . $responseProtocol);
            }

            foreach ((array) ($responsePayload['messages'] ?? []) as $messageRow) {
                if (!is_array($messageRow)) {
                    continue;
                }

                $code = trim((string) ($messageRow['codice'] ?? ''));
                $description = trim((string) ($messageRow['descrizione'] ?? ''));
                $type = trim((string) ($messageRow['tipo'] ?? ''));

                $line = $description !== '' ? $description : '(messaggio vuoto)';
                if ($code !== '') {
                    $line = '[' . $code . '] ' . $line;
                }
                if ($type !== '') {
                    $line .= ' (' . $type . ')';
                }

                CLI::write('Messaggio TS: ' . $line, $type === 'E' ? 'yellow' : 'light_gray');
            }
        }

        if ($status !== 'ok') {
            $lastError = trim((string) ($finalDocument['last_error_message'] ?? ''));
            if ($lastError !== '') {
                CLI::error('Ultimo errore: ' . $lastError);
            }

            $debug = is_array($result['debug'] ?? null) ? $result['debug'] : [];
            if ($debug !== []) {
                CLI::newLine();
                CLI::write('SOAP debug disponibile (estratti):', 'light_gray');

                $requestSnippet = $this->excerpt($this->sanitizeSoapTrace((string) ($debug['last_request'] ?? '')), 1200);
                $responseSnippet = $this->excerpt($this->sanitizeSoapTrace((string) ($debug['last_response'] ?? '')), 1200);

                if ($requestSnippet !== '') {
                    CLI::write('Ultima request SOAP:', 'light_gray');
                    CLI::write($requestSnippet);
                }

                if ($responseSnippet !== '') {
                    CLI::write('Ultima response SOAP:', 'light_gray');
                    CLI::write($responseSnippet);
                }
            }

            return EXIT_ERROR;
        }

        if ($fetchReceipt && $documentId > 0) {
            CLI::newLine();
            CLI::write('Recupero ricevuta PDF TS...', 'light_gray');

            try {
                $receiptResult = $receipts->fetchReceiptPdfForDocument($tenantId, $documentId, 0);
                $receipt = is_array($receiptResult['receipt'] ?? null) ? $receiptResult['receipt'] : [];
                CLI::write('Esito ricevuta: ' . (string) ($receiptResult['status'] ?? 'ok'), 'green');
                CLI::write('Messaggio ricevuta: ' . (string) ($receiptResult['message'] ?? 'Ricevuta recuperata.'));
                CLI::write('File locale: ' . (string) ($receipt['storage_path'] ?? '(n/d)'));
            } catch (\Throwable $e) {
                CLI::error('Recupero ricevuta fallito: ' . $e->getMessage());
                return EXIT_ERROR;
            }
        }

        return EXIT_SUCCESS;
    }

    private function readOptionValue(array $params, string $option): ?string
    {
        $normalizedOption = trim($option);
        $normalizedOption = ltrim($normalizedOption, '-');
        $normalizedOption = rtrim($normalizedOption, '=');

        $cliValue = CLI::getOption($normalizedOption);
        if ($cliValue !== null && $cliValue !== false) {
            return is_string($cliValue) ? $cliValue : (string) $cliValue;
        }

        $prefix = $option . '=';

        foreach ($params as $param) {
            if (str_starts_with((string) $param, $prefix)) {
                return substr((string) $param, strlen($prefix));
            }
        }

        return null;
    }

    private function normalizeAmount(string $value): float
    {
        $normalized = str_replace(' ', '', trim($value));

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float) $normalized, 2);
    }

    private function normalizeNullableDecimal(string $value): ?float
    {
        $normalized = str_replace(' ', '', trim($value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float) $normalized, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $payload): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function excerpt(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength) . '...';
    }

    private function sanitizeSoapTrace(string $xml): string
    {
        $xml = trim($xml);
        if ($xml === '') {
            return '';
        }

        $tagsToRedact = [
            'pincode',
            'cfProprietario',
            'cfCittadino',
            'pIva',
            'numDocumento',
        ];

        foreach ($tagsToRedact as $tag) {
            $pattern = '#(<(?:\w+:)?' . preg_quote($tag, '#') . '>)(.*?)(</(?:\w+:)?' . preg_quote($tag, '#') . '>)#si';
            $xml = preg_replace($pattern, '$1[REDACTED]$3', $xml) ?? $xml;
        }

        return $xml;
    }
}
