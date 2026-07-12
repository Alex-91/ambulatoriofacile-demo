<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Libraries\DatabaseConfig;
use App\Models\BillingDocumentModel;
use Config\Database;

class BillingDocumentService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private BillingDocumentSettingsService $settings;
    private BillingTenantDatabaseContextService $tenantDbContext;
    private TsFeatureService $tsFeatures;
    private TsBilling $tsConfig;
    private BillingTenantSchemaService $schema;
    private DatabaseConfig $databaseConfig;

    public function __construct(
        ?BillingDocumentSettingsService $settings = null,
        ?BillingTenantDatabaseContextService $tenantDbContext = null,
        ?TsFeatureService $tsFeatures = null,
        ?TsBilling $tsConfig = null,
        ?BillingTenantSchemaService $schema = null,
        ?DatabaseConfig $databaseConfig = null
    ) {
        $this->db = Database::connect();
        $this->settings = $settings ?? new BillingDocumentSettingsService();
        $this->tenantDbContext = $tenantDbContext ?? new BillingTenantDatabaseContextService();
        $this->tsFeatures = $tsFeatures ?? new TsFeatureService();
        $this->tsConfig = $tsConfig ?? config(TsBilling::class);
        $this->schema = $schema ?? new BillingTenantSchemaService();
        $this->databaseConfig = $databaseConfig ?? new DatabaseConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDashboardForTenant(int $tenantId, int $recentLimit = 6): array
    {
        $schemaStatus = $this->schema->ensureTenantSchemaReady($tenantId);
        if (!$this->schemaIsReady($schemaStatus)) {
            return [
                'table_available' => false,
                'summary' => $this->emptySummary(),
                'recent_documents' => [],
                'schema_message' => $this->documentsUnavailableMessage($schemaStatus),
            ];
        }

        $context = $this->resolveTenantDocumentContext($tenantId);
        $db = $context['db'];
        $summary = $this->emptySummary();
        $rows = $db->table('billing_documents')
            ->select('local_state, COUNT(*) AS total_count')
            ->groupBy('local_state')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $state = trim((string) ($row['local_state'] ?? ''));
            $count = (int) ($row['total_count'] ?? 0);
            if ($state === '') {
                continue;
            }

            $summary['total_documents'] += $count;
            $summary['by_state'][$state] = $count;
        }

        foreach ($this->localStateLabels() as $state => $label) {
            $summary[$state . '_count'] = (int) ($summary['by_state'][$state] ?? 0);
        }

        $recent = $db->table('billing_documents')
            ->select('id_billing_document, document_number, patient_name, issue_date, amount_total, local_state')
            ->orderBy('issue_date', 'DESC')
            ->orderBy('id_billing_document', 'DESC')
            ->get(max(1, $recentLimit))
            ->getResultArray();

        foreach ($recent as &$row) {
            $row['local_state_label'] = $this->localStateLabels()[(string) ($row['local_state'] ?? '')] ?? (string) ($row['local_state'] ?? '');
        }
        unset($row);

        return [
            'table_available' => true,
            'summary' => $summary,
            'recent_documents' => $recent,
            'schema_message' => $this->schemaStatusMessage($schemaStatus),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listDocumentsForTenant(int $tenantId, int $limit = 50): array
    {
        $dashboard = $this->buildDashboardForTenant($tenantId, 6);
        if (!$dashboard['table_available']) {
            return [
                'table_available' => false,
                'summary' => $dashboard['summary'],
                'documents' => [],
                'local_state_labels' => $this->localStateLabels(),
                'document_type_labels' => $this->documentTypeLabels(),
                'payment_method_labels' => $this->paymentMethodLabels(),
                'ts_sync_labels' => $this->tsSyncStateLabels(),
                'schema_message' => $dashboard['schema_message'] ?? '',
            ];
        }

        $context = $this->resolveTenantDocumentContext($tenantId);
        $db = $context['db'];
        $documents = $db->table('billing_documents')
            ->select('id_billing_document, document_number, document_type, issue_date, payment_date, patient_name, payment_method, amount_total, local_state, ts_sync_state, linked_ts_document_id, updated_at')
            ->orderBy('issue_date', 'DESC')
            ->orderBy('id_billing_document', 'DESC')
            ->get(max(1, $limit))
            ->getResultArray();

        return [
            'table_available' => true,
            'summary' => $dashboard['summary'],
            'documents' => $documents,
            'local_state_labels' => $this->localStateLabels(),
            'document_type_labels' => $this->documentTypeLabels(),
            'payment_method_labels' => $this->paymentMethodLabels(),
            'ts_sync_labels' => $this->tsSyncStateLabels(),
            'schema_message' => $dashboard['schema_message'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFormContext(int $tenantId, int $documentId = 0): array
    {
        $settings = $this->settings->resolveTenantSettings($tenantId);
        $template = is_array($settings['config'] ?? null) ? $settings['config'] : [];
        $tsEnabled = $this->tsFeatures->isEnabledForTenant($tenantId);
        $schemaStatus = $this->schema->ensureTenantSchemaReady($tenantId);

        if (!$this->schemaIsReady($schemaStatus)) {
            return [
                'document' => $this->buildDefaultDocument($template, $tsEnabled),
                'line_items' => [],
                'template' => $template,
                'ts_enabled' => $tsEnabled,
                'document_type_labels' => $this->documentTypeLabels(),
                'payment_method_labels' => $this->paymentMethodLabels(),
                'local_state_labels' => $this->localStateLabels(),
                'ts_sync_labels' => $this->tsSyncStateLabels(),
                'ts_expense_types' => $this->tsConfig->supportedExpenseTypes,
                'table_available' => false,
                'schema_message' => $this->documentsUnavailableMessage($schemaStatus),
            ];
        }

        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var BillingDocumentModel $documents */
        $documents = $context['documents'];
        $document = $documentId > 0 ? $documents->find($documentId) : null;
        $lineItems = $this->decodeLineItems((string) ($document['line_items_json'] ?? ''));

        if ($document === null) {
            $document = $this->buildDefaultDocument($template, $tsEnabled, $documents);
        }

        return [
            'document' => $document,
            'line_items' => $lineItems,
            'template' => $template,
            'ts_enabled' => $tsEnabled,
            'document_type_labels' => $this->documentTypeLabels(),
            'payment_method_labels' => $this->paymentMethodLabels(),
            'local_state_labels' => $this->localStateLabels(),
            'ts_sync_labels' => $this->tsSyncStateLabels(),
            'ts_expense_types' => $this->tsConfig->supportedExpenseTypes,
            'table_available' => true,
            'schema_message' => $this->schemaStatusMessage($schemaStatus),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveDraftForTenant(int $tenantId, array $payload, int $userId = 0, string $saveMode = 'draft'): array
    {
        $this->assertDocumentsSchemaReady($tenantId);
        $context = $this->resolveTenantDocumentContext($tenantId);
        $db = $context['db'];
        /** @var BillingDocumentModel $documents */
        $documents = $context['documents'];

        if (!$this->isDocumentsTableAvailable($db)) {
            throw new \RuntimeException($this->documentsUnavailableMessage());
        }

        $documentId = (int) ($payload['id_billing_document'] ?? 0);
        $current = $documentId > 0 ? $documents->find($documentId) : null;
        if ($documentId > 0 && !is_array($current)) {
            throw new \RuntimeException('Documento fatturazione non trovato.');
        }

        $template = (array) (($this->settings->resolveTenantSettings($tenantId))['config'] ?? []);
        $normalized = $this->normalizePayload($payload, $template, $tenantId);
        $validationErrors = $this->validatePayload($normalized, $template, $tenantId);
        if ($validationErrors !== []) {
            throw new \RuntimeException(implode(' ', $validationErrors));
        }

        $existing = $documents->findByDocumentNumberAndDate(
            (string) ($normalized['document_number'] ?? ''),
            (string) ($normalized['issue_date'] ?? '')
        );
        if (is_array($existing) && (int) ($existing['id_billing_document'] ?? 0) !== $documentId) {
            throw new \RuntimeException('Esiste gia un documento fatturazione con lo stesso numero e la stessa data.');
        }

        $normalizedSaveMode = trim(strtolower($saveMode));
        $localState = str_starts_with($normalizedSaveMode, 'final') ? 'issued' : 'draft';

        $record = [
            'id_client' => (int) ($normalized['id_client'] ?? 0) > 0 ? (int) ($normalized['id_client'] ?? 0) : null,
            'document_number' => $normalized['document_number'],
            'document_type' => $normalized['document_type'],
            'issue_date' => $normalized['issue_date'],
            'payment_date' => $normalized['payment_date'] !== '' ? $normalized['payment_date'] : null,
            'patient_name' => $normalized['patient_name'],
            'patient_tax_code' => $normalized['patient_tax_code'] !== '' ? $normalized['patient_tax_code'] : null,
            'payment_method' => $normalized['payment_method'],
            'line_items_json' => json_encode($normalized['line_items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'subtotal_amount' => $normalized['subtotal_amount'],
            'stamp_duty_amount' => $normalized['stamp_duty_amount'],
            'vat_rate' => $normalized['vat_rate'],
            'vat_nature' => $normalized['vat_nature'] !== '' ? $normalized['vat_nature'] : null,
            'amount_total' => $normalized['amount_total'],
            'notes' => $normalized['notes'] !== '' ? $normalized['notes'] : null,
            'template_snapshot_json' => json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ts_sync_enabled' => $normalized['ts_sync_enabled'] ? 1 : 0,
            'ts_expense_type_code' => $normalized['ts_expense_type_code'] !== '' ? $normalized['ts_expense_type_code'] : null,
            'ts_opposition_flag' => $normalized['ts_opposition_flag'] ? 1 : 0,
            'linked_ts_document_id' => is_array($current) && (int) ($current['linked_ts_document_id'] ?? 0) > 0
                ? (int) ($current['linked_ts_document_id'] ?? 0)
                : null,
            'ts_sync_state' => $normalized['ts_sync_state'],
            'local_state' => $localState,
            'updated_by' => $userId > 0 ? $userId : null,
        ];

        if (!is_array($current)) {
            $record['created_by'] = $userId > 0 ? $userId : null;
        }

        $db->transBegin();

        try {
            if (is_array($current)) {
                $documents->update($documentId, $record);
                $savedId = $documentId;
            } else {
                $savedId = (int) $documents->insert($record);
            }

            if ($savedId <= 0) {
                throw new \RuntimeException('Salvataggio documento fatturazione non riuscito.');
            }

            if ((int) ($normalized['id_client'] ?? 0) > 0) {
                $this->syncLinkedPatientFromDocument($db, (int) ($normalized['id_client'] ?? 0), $normalized);
            }

            if (!$db->transStatus()) {
                throw new \RuntimeException('Persistenza documento fatturazione non riuscita.');
            }

            $db->transCommit();

            return [
                'document' => $documents->find($savedId) ?? [],
                'line_items' => $normalized['line_items'],
                'local_state' => $localState,
            ];
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPreviewContext(int $tenantId, int $documentId): array
    {
        $this->assertDocumentsSchemaReady($tenantId);
        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var BillingDocumentModel $documents */
        $documents = $context['documents'];

        $document = $documents->find($documentId);
        if (!is_array($document)) {
            throw new \RuntimeException('Documento fatturazione non trovato.');
        }

        $template = $this->decodeJsonArray((string) ($document['template_snapshot_json'] ?? ''));
        if ($template === []) {
            $template = (array) (($this->settings->resolveTenantSettings($tenantId))['config'] ?? []);
        }

        $lineItems = $this->decodeLineItems((string) ($document['line_items_json'] ?? ''));
        $tenantContext = $this->tenantDbContext->resolveTenantContext($tenantId);
        $tenant = is_array($tenantContext['tenant'] ?? null) ? $tenantContext['tenant'] : [];

        return [
            'tenant' => $tenant,
            'document' => $document,
            'line_items' => $lineItems,
            'template' => $template,
            'document_type_label' => $this->documentTypeLabels()[(string) ($document['document_type'] ?? '')] ?? (string) ($document['document_type'] ?? ''),
            'payment_method_label' => $this->paymentMethodLabels()[(string) ($document['payment_method'] ?? '')] ?? (string) ($document['payment_method'] ?? ''),
            'local_state_label' => $this->localStateLabels()[(string) ($document['local_state'] ?? '')] ?? (string) ($document['local_state'] ?? ''),
            'ts_sync_label' => $this->tsSyncStateLabels()[(string) ($document['ts_sync_state'] ?? '')] ?? (string) ($document['ts_sync_state'] ?? ''),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public function markPdfGenerated(int $tenantId, int $documentId): void
    {
        $this->assertDocumentsSchemaReady($tenantId);
        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var BillingDocumentModel $documents */
        $documents = $context['documents'];
        $documents->update($documentId, [
            'pdf_generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTenantDocumentContext(int $tenantId): array
    {
        return $this->tenantDbContext->resolveTenantContext($tenantId);
    }

    private function isDocumentsTableAvailable(\CodeIgniter\Database\BaseConnection $db): bool
    {
        return $db->tableExists('billing_documents');
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function buildDefaultDocument(array $template, bool $tsEnabled, ?BillingDocumentModel $documents = null): array
    {
        return [
            'id_billing_document' => 0,
            'id_client' => 0,
            'document_number' => $documents instanceof BillingDocumentModel
                ? $this->suggestDocumentNumber($documents, $template)
                : $this->fallbackDocumentNumber($template),
            'document_type' => 'invoice',
            'issue_date' => date('Y-m-d'),
            'payment_date' => date('Y-m-d'),
            'patient_name' => '',
            'patient_last_name' => '',
            'patient_first_name' => '',
            'patient_tax_code' => '',
            'patient_phone' => '',
            'patient_mobile' => '',
            'patient_email' => '',
            'patient_address' => '',
            'patient_city' => '',
            'payment_method' => 'bank_transfer',
            'subtotal_amount' => 0,
            'stamp_duty_amount' => 0,
            'vat_rate' => 0,
            'vat_nature' => '',
            'amount_total' => 0,
            'notes' => '',
            'ts_sync_enabled' => !empty($template['integration_ts']['enabled_when_available']) ? 1 : 0,
            'ts_expense_type_code' => 'SP',
            'ts_opposition_flag' => 0,
            'ts_sync_state' => !empty($template['integration_ts']['enabled_when_available'])
                ? ($tsEnabled ? 'ready' : 'waiting_module')
                : 'not_requested',
            'local_state' => 'draft',
        ];
    }

    /**
     * @return array<string, int|array<string, int>>
     */
    private function emptySummary(): array
    {
        return [
            'total_documents' => 0,
            'by_state' => [],
            'draft_count' => 0,
            'issued_count' => 0,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function localStateLabels(): array
    {
        return [
            'draft' => 'Bozza',
            'issued' => 'Definitivo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function documentTypeLabels(): array
    {
        return [
            'invoice' => 'Fattura',
            'receipt' => 'Ricevuta',
            'service_note' => 'Parcella',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function paymentMethodLabels(): array
    {
        return [
            'cash' => 'Contanti',
            'pos' => 'POS',
            'bank_transfer' => 'Bonifico',
            'mixed' => 'Misto',
            'other' => 'Altro',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function tsSyncStateLabels(): array
    {
        return [
            'not_requested' => 'Non richiesto',
            'waiting_module' => 'In attesa modulo TS',
            'ready' => 'Pronto per TS',
            'linked' => 'In coda TS',
            'sending' => 'In invio TS',
            'sent' => 'Inviato a TS',
            'error' => 'Da correggere per TS',
        ];
    }

    /**
     * @param array<string, mixed> $template
     */
    private function suggestDocumentNumber(BillingDocumentModel $documents, array $template): string
    {
        $prefix = trim((string) ($template['document_code_prefix'] ?? 'FT'));
        if ($prefix === '') {
            $prefix = 'FT';
        }

        $today = date('Y-m-d');
        $countToday = (int) $documents->where('issue_date', $today)->countAllResults();

        return strtoupper($prefix) . '-' . date('Ymd') . '-' . str_pad((string) ($countToday + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string, mixed> $template
     */
    private function fallbackDocumentNumber(array $template): string
    {
        $prefix = trim((string) ($template['document_code_prefix'] ?? 'FT'));
        if ($prefix === '') {
            $prefix = 'FT';
        }

        return strtoupper($prefix) . '-' . date('Ymd') . '-01';
    }

    /**
     * @param array<string, mixed> $schemaStatus
     */
    private function schemaIsReady(array $schemaStatus): bool
    {
        return !empty($schemaStatus['ready']);
    }

    /**
     * @param array<string, mixed>|null $schemaStatus
     */
    private function documentsUnavailableMessage(?array $schemaStatus = null): string
    {
        $message = $this->schemaStatusMessage($schemaStatus);
        if ($message !== '') {
            return $message;
        }

        return 'La tabella billing_documents non e disponibile nel database corrente.';
    }

    /**
     * @param array<string, mixed>|null $schemaStatus
     */
    private function schemaStatusMessage(?array $schemaStatus = null): string
    {
        return trim((string) ($schemaStatus['message'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function assertDocumentsSchemaReady(int $tenantId): array
    {
        $schemaStatus = $this->schema->ensureTenantSchemaReady($tenantId);
        if ($this->schemaIsReady($schemaStatus)) {
            return $schemaStatus;
        }

        throw new \RuntimeException($this->documentsUnavailableMessage($schemaStatus));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, array $template, int $tenantId): array
    {
        $documentNumber = trim((string) ($payload['document_number'] ?? ''));
        $documentType = trim((string) ($payload['document_type'] ?? 'invoice'));
        if (!array_key_exists($documentType, $this->documentTypeLabels())) {
            $documentType = 'invoice';
        }

        $paymentMethod = trim((string) ($payload['payment_method'] ?? 'bank_transfer'));
        if (!array_key_exists($paymentMethod, $this->paymentMethodLabels())) {
            $paymentMethod = 'bank_transfer';
        }

        $patientLastName = trim((string) ($payload['patient_last_name'] ?? ''));
        $patientFirstName = trim((string) ($payload['patient_first_name'] ?? ''));
        $patientName = trim((string) (preg_replace('/\s+/', ' ', $patientLastName . ' ' . $patientFirstName) ?? ''));
        if ($patientName === '') {
            $patientName = trim((string) ($payload['patient_name'] ?? ''));
        }

        $vatNature = strtoupper(trim((string) ($payload['vat_nature'] ?? '')));
        $lineItems = $this->normalizeLineItems(
            $payload['item_description'] ?? [],
            $payload['item_qty'] ?? [],
            $payload['item_unit_amount'] ?? []
        );
        $subtotal = 0.0;
        foreach ($lineItems as $item) {
            $subtotal += (float) ($item['line_total'] ?? 0);
        }

        $stampDuty = $this->normalizeMoney($payload['stamp_duty_amount'] ?? 0);
        $amountTotal = round($subtotal + $stampDuty, 2);
        $tsSyncEnabled = $this->toBool($payload['ts_sync_enabled'] ?? false);
        $tsEnabled = $this->tsFeatures->isEnabledForTenant($tenantId);

        return [
            'id_billing_document' => (int) ($payload['id_billing_document'] ?? 0),
            'id_client' => (int) ($payload['id_client'] ?? 0),
            'document_number' => $documentNumber,
            'document_type' => $documentType,
            'issue_date' => trim((string) ($payload['issue_date'] ?? '')),
            'payment_date' => trim((string) ($payload['payment_date'] ?? '')),
            'patient_name' => $patientName,
            'patient_last_name' => $patientLastName,
            'patient_first_name' => $patientFirstName,
            'patient_tax_code' => strtoupper(trim((string) ($payload['patient_tax_code'] ?? ''))),
            'patient_phone' => trim((string) ($payload['patient_phone'] ?? '')),
            'patient_mobile' => trim((string) ($payload['patient_mobile'] ?? '')),
            'patient_email' => trim((string) ($payload['patient_email'] ?? '')),
            'patient_address' => trim((string) ($payload['patient_address'] ?? '')),
            'patient_city' => trim((string) ($payload['patient_city'] ?? '')),
            'payment_method' => $paymentMethod,
            'line_items' => $lineItems,
            'subtotal_amount' => round($subtotal, 2),
            'stamp_duty_amount' => $stampDuty,
            'vat_rate' => $this->normalizeMoney($payload['vat_rate'] ?? 0),
            'vat_nature' => $vatNature,
            'amount_total' => $amountTotal,
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'ts_sync_enabled' => $tsSyncEnabled,
            'ts_expense_type_code' => strtoupper(trim((string) ($payload['ts_expense_type_code'] ?? ''))),
            'ts_opposition_flag' => $this->toBool($payload['ts_opposition_flag'] ?? false),
            'ts_sync_state' => $this->resolveTsSyncState($tsSyncEnabled, $tsEnabled),
            'template' => $template,
        ];
    }

    /**
     * @param mixed $descriptions
     * @param mixed $quantities
     * @param mixed $unitAmounts
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLineItems($descriptions, $quantities, $unitAmounts): array
    {
        $descriptions = is_array($descriptions) ? $descriptions : [];
        $quantities = is_array($quantities) ? $quantities : [];
        $unitAmounts = is_array($unitAmounts) ? $unitAmounts : [];
        $items = [];
        $rowCount = max(count($descriptions), count($quantities), count($unitAmounts));

        for ($index = 0; $index < $rowCount; $index++) {
            $description = trim((string) ($descriptions[$index] ?? ''));
            $quantity = $this->normalizeQuantity($quantities[$index] ?? 0);
            $unitAmount = $this->normalizeMoney($unitAmounts[$index] ?? 0);

            if ($description === '' && $unitAmount <= 0) {
                continue;
            }

            if ($quantity <= 0) {
                $quantity = 1.0;
            }

            $items[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
                'line_total' => round($quantity * $unitAmount, 2),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $template
     * @return array<int, string>
     */
    private function validatePayload(array $normalized, array $template, int $tenantId): array
    {
        $errors = [];
        if (trim((string) ($normalized['document_number'] ?? '')) === '') {
            $errors[] = 'Numero documento obbligatorio.';
        }
        if (trim((string) ($normalized['issue_date'] ?? '')) === '') {
            $errors[] = 'Data emissione obbligatoria.';
        }
        if (trim((string) ($normalized['patient_name'] ?? '')) === '') {
            $errors[] = 'Nome cliente o paziente obbligatorio.';
        }
        if ((array) ($normalized['line_items'] ?? []) === []) {
            $errors[] = 'Inserisci almeno una riga nel documento.';
        }
        if ((float) ($normalized['amount_total'] ?? 0) <= 0) {
            $errors[] = 'Il totale del documento deve essere maggiore di zero.';
        }

        $tsSyncEnabled = !empty($normalized['ts_sync_enabled']);
        $tsEnabled = $this->tsFeatures->isEnabledForTenant($tenantId);
        $integrationTs = is_array($template['integration_ts'] ?? null) ? $template['integration_ts'] : [];

        if ($tsSyncEnabled && trim((string) ($normalized['patient_tax_code'] ?? '')) === '') {
            $errors[] = 'Per preparare il documento al Sistema TS devi indicare il codice fiscale del paziente.';
        }

        if ($tsSyncEnabled && !empty($integrationTs['require_expense_type']) && trim((string) ($normalized['ts_expense_type_code'] ?? '')) === '') {
            $errors[] = 'Il template documento richiede il tipo spesa TS quando l integrazione e attiva.';
        }

        if ($tsSyncEnabled && trim((string) ($normalized['ts_expense_type_code'] ?? '')) !== '') {
            $code = trim((string) ($normalized['ts_expense_type_code'] ?? ''));
            if (!array_key_exists($code, $this->tsConfig->supportedExpenseTypes)) {
                $errors[] = 'Tipo spesa TS non riconosciuto.';
            }
        }

        if ($tsSyncEnabled && !$tsEnabled && !empty($integrationTs['enabled_when_available'])) {
            // Nessun errore bloccante: il documento resta solo in attesa del modulo TS.
        }

        return $errors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeLineItems(string $json): array
    {
        $decoded = $this->decodeJsonArray($json);
        $items = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            $quantity = $this->normalizeQuantity($row['quantity'] ?? 0);
            $unitAmount = $this->normalizeMoney($row['unit_amount'] ?? 0);
            $lineTotal = $this->normalizeMoney($row['line_total'] ?? ($quantity * $unitAmount));

            if ($description === '' && $lineTotal <= 0) {
                continue;
            }

            $items[] = [
                'description' => $description,
                'quantity' => $quantity > 0 ? $quantity : 1.0,
                'unit_amount' => $unitAmount,
                'line_total' => $lineTotal,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function syncLinkedPatientFromDocument(\CodeIgniter\Database\BaseConnection $db, int $idClient, array $normalized): void
    {
        $idClient = max(0, $idClient);
        if ($idClient <= 0) {
            return;
        }

        if (!$db->tableExists('dap02_clients')) {
            throw new \RuntimeException('Anagrafica pazienti non disponibile nello spazio corrente.');
        }

        $patientExists = $db->table('dap02_clients')
            ->select('id_client')
            ->where('id_client', $idClient)
            ->get()
            ->getRowArray();

        if (!is_array($patientExists)) {
            throw new \RuntimeException('Paziente collegato non trovato nello spazio corrente.');
        }

        $this->databaseConfig->setEncryptionConfig($db, 'utf8mb4');
        $db->query('SET @sync_vector = RANDOM_BYTES(16)');

        $fieldMap = [
            'nome' => trim((string) ($normalized['patient_first_name'] ?? '')),
            'cognome' => trim((string) ($normalized['patient_last_name'] ?? '')),
            'codice_fiscale' => strtoupper(trim((string) ($normalized['patient_tax_code'] ?? ''))),
            'telefono' => trim((string) ($normalized['patient_phone'] ?? '')),
            'cellulare' => trim((string) ($normalized['patient_mobile'] ?? '')),
            'email' => trim((string) ($normalized['patient_email'] ?? '')),
            'indirizzo' => trim((string) ($normalized['patient_address'] ?? '')),
            'citta' => trim((string) ($normalized['patient_city'] ?? '')),
        ];

        $set = ['vector_id = COALESCE(vector_id, @sync_vector)'];
        foreach ($fieldMap as $column => $value) {
            $set[] = $column . ' = ' . $this->encryptWithTenantVectorSql($db, $value);
        }

        $sql = 'UPDATE dap02_clients SET '
            . implode(', ', $set)
            . ' WHERE id_client = ' . $idClient
            . ' LIMIT 1';

        $db->query($sql);
    }

    private function encryptWithTenantVectorSql(\CodeIgniter\Database\BaseConnection $db, string $value): string
    {
        return 'HEX(AES_ENCRYPT('
            . $db->escape($value)
            . ', @key_str, COALESCE(vector_id, @sync_vector)))';
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param mixed $value
     */
    private function normalizeMoney($value): float
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    /**
     * @param mixed $value
     */
    private function normalizeQuantity($value): float
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    private function resolveTsSyncState(bool $enabled, bool $tsEnabled): string
    {
        if (!$enabled) {
            return 'not_requested';
        }

        return $tsEnabled ? 'ready' : 'waiting_module';
    }
}
