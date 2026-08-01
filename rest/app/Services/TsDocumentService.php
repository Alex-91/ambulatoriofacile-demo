<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Models\TsDocumentEventModel;
use App\Models\TsDocumentModel;
use App\Models\TsDocumentReceiptModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class TsDocumentService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private TsDocumentModel $documents;
    private TsDocumentEventModel $events;
    private TsDocumentReceiptModel $receipts;
    private TsSecretsService $secrets;
    private TsProfileService $profiles;
    private TsDocumentValidationService $validation;
    private TsAuditService $audit;
    private TsTenantDatabaseContextService $tenantDbContext;
    private TsBilling $config;

    public function __construct(
        ?TsDocumentModel $documents = null,
        ?TsDocumentEventModel $events = null,
        ?TsDocumentReceiptModel $receipts = null,
        ?TsSecretsService $secrets = null,
        ?TsProfileService $profiles = null,
        ?TsDocumentValidationService $validation = null,
        ?TsAuditService $audit = null,
        ?TsTenantDatabaseContextService $tenantDbContext = null,
        ?TsBilling $config = null
    ) {
        $this->db = Database::connect();
        $this->documents = $documents ?? new TsDocumentModel();
        $this->events = $events ?? new TsDocumentEventModel();
        $this->receipts = $receipts ?? new TsDocumentReceiptModel();
        $this->secrets = $secrets ?? new TsSecretsService();
        $this->profiles = $profiles ?? new TsProfileService();
        $this->validation = $validation ?? new TsDocumentValidationService();
        $this->audit = $audit ?? new TsAuditService($this->events);
        $this->tenantDbContext = $tenantDbContext ?? new TsTenantDatabaseContextService();
        $this->config = $config ?? config(TsBilling::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDashboard(int $recentLimit = 8): array
    {
        return $this->buildDashboardForTenant(0, $recentLimit);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDashboardForTenant(int $tenantId, int $recentLimit = 8): array
    {
        $context = $this->resolveTenantDocumentContext($tenantId);
        $db = $context['db'];

        if (!$this->isDocumentsTableAvailable($db)) {
            return [
                'table_available' => false,
                'summary' => $this->emptySummary(),
                'recent_documents' => [],
                'available_local_states' => $this->config->localStates,
                'ui_state_labels' => $this->config->uiStateLabels,
            ];
        }

        $summary = $this->emptySummary();
        $rows = $db->table('ts_documents')
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

        foreach (['draft', 'ready', 'sent', 'rejected'] as $state) {
            $summary[$state . '_count'] = (int) ($summary['by_state'][$state] ?? 0);
        }

        return [
            'table_available' => true,
            'summary' => $summary,
            'recent_documents' => $this->fetchRecentDocuments($db, $recentLimit),
            'available_local_states' => $this->config->localStates,
            'ui_state_labels' => $this->config->uiStateLabels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listDocuments(int $limit = 50): array
    {
        return $this->listDocumentsForTenant(0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function listDocumentsForTenant(int $tenantId, int $limit = 50): array
    {
        $dashboard = $this->buildDashboardForTenant($tenantId, max(5, min($limit, 12)));
        $context = $this->resolveTenantDocumentContext($tenantId);
        $db = $context['db'];

        if (!$dashboard['table_available']) {
            return [
                'table_available' => false,
                'summary' => $dashboard['summary'],
                'documents' => [],
                'ui_state_labels' => $dashboard['ui_state_labels'],
            ];
        }

        return [
            'table_available' => true,
            'summary' => $dashboard['summary'],
            'documents' => $db->table('ts_documents')
                ->select('id_ts_document, source_type, source_ref_id, document_number, issue_date, payment_date, document_type, expense_type_code, payment_mode, amount_total, vat_rate, vat_nature, local_state, ts_state, ts_protocol, created_at, updated_at')
                ->orderBy('issue_date', 'DESC')
                ->orderBy('id_ts_document', 'DESC')
                ->get($limit)
                ->getResultArray(),
            'source_type_labels' => $this->config->sourceTypeLabels,
            'ui_state_labels' => $dashboard['ui_state_labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFormContext(int $tenantId, int $documentId = 0): array
    {
        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var TsDocumentModel $documents */
        $documents = $context['documents'];
        /** @var TsDocumentEventModel $events */
        $events = $context['events'];
        /** @var TsDocumentReceiptModel $receipts */
        $receipts = $context['receipts'];

        $document = $documentId > 0 ? $documents->find($documentId) : null;
        $profile = is_array($document) && (int) ($document['id_ts_profile'] ?? 0) > 0
            ? $this->profiles->findProfileById((int) ($document['id_ts_profile'] ?? 0), $tenantId)
            : null;

        if (!is_array($profile)) {
            $profile = $this->profiles->getDefaultProfileForTenant($tenantId);
        }

        return [
            'profile' => $profile,
            'document' => $this->buildEditableDocument($document, $this->resolveProfileDocumentDefaults($profile)),
            'parent_document' => $this->resolveParentDocumentSummary($documents, $document),
            'related_operations' => $this->listRelatedOperations($documents, $document),
            'validation' => $this->decodeValidationJson((string) ($document['validation_json'] ?? '')),
            'request_snapshot' => $this->decodeValidationJson((string) ($document['request_payload_json'] ?? '')),
            'response_snapshot' => $this->decodeValidationJson((string) ($document['response_payload_json'] ?? '')),
            'events' => $documentId > 0 ? $events->listForDocument($documentId) : [],
            'receipts' => $documentId > 0 ? $receipts->listForDocument($documentId) : [],
            'source_type_labels' => $this->config->sourceTypeLabels,
            'supported_expense_types' => $this->config->supportedExpenseTypes,
            'supported_expense_details' => $this->config->resolveExpenseTypeDetails(),
            'supported_document_types' => $this->config->supportedDocumentTypes,
            'payment_modes' => $this->config->paymentModes,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveDraftForTenant(int $tenantId, array $payload, int $userId = 0, string $saveMode = 'draft'): array
    {
        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var BaseConnection $db */
        $db = $context['db'];
        /** @var TsDocumentModel $documents */
        $documents = $context['documents'];
        /** @var TsAuditService $audit */
        $audit = $context['audit'];

        if (!$this->isDocumentsTableAvailable($db)) {
            throw new \RuntimeException('La tabella ts_documents non è disponibile nel database corrente.');
        }

        $currentId = (int) ($payload['id_ts_document'] ?? 0);
        $current = $currentId > 0 ? $documents->find($currentId) : null;
        if ($currentId > 0 && !$current) {
            throw new \RuntimeException('Documento TS non trovato.');
        }

        $profile = is_array($current) && (int) ($current['id_ts_profile'] ?? 0) > 0
            ? $this->profiles->findProfileById((int) ($current['id_ts_profile'] ?? 0), $tenantId)
            : null;

        if (!is_array($profile)) {
            $profile = $this->profiles->getDefaultProfileForTenant($tenantId);
        }

        if (!is_array($profile) || (int) ($profile['id_ts_profile'] ?? 0) <= 0) {
            throw new \RuntimeException('Configura prima un profilo TS attivo per lo spazio.');
        }

        if (trim((string) ($current['local_state'] ?? '')) === 'sent') {
            throw new \RuntimeException('Il documento risulta già inviato e non può essere modificato da questa schermata.');
        }

        $sourceType = trim((string) ($current['source_type'] ?? 'manual'));
        $normalized = $this->normalizeDraftPayload($payload, $profile, $current);
        $duplicateFound = false;
        $existing = null;
        if ($this->isPrimarySourceType($sourceType)) {
            $existing = $documents->findByIdentifierHash((string) ($normalized['document_identifier_hash'] ?? ''));
            $duplicateFound = $existing !== null && (int) ($existing['id_ts_document'] ?? 0) !== $currentId;
        }

        $validation = $this->validation->validateDraft($normalized, $profile, $duplicateFound, $sourceType);

        $requestedSaveMode = trim(strtolower($saveMode));
        $localState = match ($requestedSaveMode) {
            'validate', 'send' => !empty($validation['valid']) ? 'ready' : 'to_validate',
            default => 'draft',
        };

        if ($duplicateFound) {
            return [
                'document' => is_array($current) ? $current : [],
                'validation' => $validation,
                'local_state' => $localState,
                'save_mode' => $requestedSaveMode,
                'blocked' => true,
                'message' => $this->buildDuplicateConflictMessage($existing),
            ];
        }

        $record = [
            'id_ts_profile' => (int) ($profile['id_ts_profile'] ?? 0),
            'id_client' => (int) ($normalized['id_client'] ?? 0) > 0 ? (int) ($normalized['id_client'] ?? 0) : null,
            'source_type' => $sourceType,
            'source_ref_id' => (int) ($current['source_ref_id'] ?? 0) > 0 ? (int) ($current['source_ref_id'] ?? 0) : null,
            'document_identifier_hash' => $normalized['document_identifier_hash'],
            'sender_piva_snapshot' => $normalized['sender_piva_snapshot'],
            'sender_cf_snapshot_enc' => $normalized['sender_cf_snapshot_enc'],
            'sender_type_snapshot' => $normalized['sender_type_snapshot'],
            'patient_cf_enc' => $normalized['patient_cf_enc'],
            'patient_cf_hash' => $normalized['patient_cf_hash'],
            'patient_label_snapshot_enc' => $normalized['patient_label_snapshot_enc'],
            'document_number' => $normalized['document_number'],
            'document_device' => $normalized['document_device'],
            'issue_date' => $normalized['issue_date'],
            'payment_date' => $normalized['payment_date'],
            'document_type' => $normalized['document_type'],
            'expense_type_code' => $normalized['expense_type_code'],
            'payment_mode' => $normalized['payment_mode'],
            'amount_total' => $normalized['amount_total'],
            'vat_rate' => $normalized['vat_rate'],
            'vat_nature' => $normalized['vat_nature'],
            'opposition_flag' => $normalized['opposition_flag'],
            'notes' => $normalized['notes'],
            'local_state' => $localState,
            'ts_state' => null,
            'validation_json' => json_encode([
                'valid' => (bool) ($validation['valid'] ?? false),
                'errors' => array_values((array) ($validation['errors'] ?? [])),
                'warnings' => array_values((array) ($validation['warnings'] ?? [])),
                'validated_at' => date('Y-m-d H:i:s'),
                'requested_mode' => $requestedSaveMode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_error_code' => !empty($validation['valid']) ? null : 'LOCAL_VALIDATION',
            'last_error_message' => !empty($validation['valid'])
                ? null
                : implode(' ', (array) ($validation['errors'] ?? [])),
            'updated_by' => $userId > 0 ? $userId : null,
        ];

        if (!$current) {
            $record['created_by'] = $userId > 0 ? $userId : null;
        }

        $db->transBegin();

        try {
            if ($current) {
                $documentId = $currentId;
                $documents->update($documentId, $record);
            } else {
                $documentId = (int) $documents->insert($record);
            }

            if ($documentId <= 0) {
                throw new \RuntimeException('Salvataggio documento TS non riuscito.');
            }

            $baseEvent = $current ? 'draft_updated' : 'draft_created';
            $baseMessage = $current
                ? 'Bozza documento TS aggiornata.'
                : 'Bozza documento TS creata.';
            $audit->record($documentId, $baseEvent, $baseMessage, 'info', [
                'save_mode' => $requestedSaveMode,
                'local_state' => $localState,
            ], $userId);

            if ($requestedSaveMode === 'validate') {
                $audit->record(
                    $documentId,
                    !empty($validation['valid']) ? 'validated_ok' : 'validated_error',
                    !empty($validation['valid'])
                        ? 'Validazione locale TS completata con esito positivo.'
                        : 'Validazione locale TS completata con errori.',
                    !empty($validation['valid']) ? 'info' : 'error',
                    [
                        'errors' => array_values((array) ($validation['errors'] ?? [])),
                        'warnings' => array_values((array) ($validation['warnings'] ?? [])),
                    ],
                    $userId
                );
            }

            if (!$db->transStatus()) {
                throw new \RuntimeException('Persistenza documento TS non riuscita.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $saved = $documents->find($documentId);
        if (!is_array($saved)) {
            throw new \RuntimeException('Documento TS salvato ma non più reperibile.');
        }

        return [
            'document' => $saved,
            'validation' => $validation,
            'local_state' => $localState,
            'save_mode' => $requestedSaveMode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createVariationDraftFromDocument(int $tenantId, int $documentId, int $userId = 0): array
    {
        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var BaseConnection $db */
        $db = $context['db'];
        /** @var TsDocumentModel $documents */
        $documents = $context['documents'];
        /** @var TsAuditService $audit */
        $audit = $context['audit'];

        if (!$this->isDocumentsTableAvailable($db)) {
            throw new \RuntimeException('La tabella ts_documents non è disponibile nel database corrente.');
        }

        $source = $documents->find($documentId);
        $this->assertSourceDocumentEligibleForOperation($source, 'ts_variation');

        $record = $this->buildOperationRecordFromSource($source, 'ts_variation', $userId);
        $record['local_state'] = 'draft';

        $db->transBegin();

        try {
            $operationId = (int) $documents->insert($record);
            if ($operationId <= 0) {
                throw new \RuntimeException('Creazione variazione TS non riuscita.');
            }

            $audit->record(
                $operationId,
                'variation_draft_created',
                'Bozza variazione TS creata a partire dal documento inviato.',
                'info',
                [
                    'source_document_id' => (int) ($source['id_ts_document'] ?? 0),
                ],
                $userId
            );
            $audit->record(
                (int) ($source['id_ts_document'] ?? 0),
                'variation_child_created',
                'Creata una nuova bozza di variazione TS collegata al documento.',
                'info',
                [
                    'child_document_id' => $operationId,
                ],
                $userId
            );

            if (!$db->transStatus()) {
                throw new \RuntimeException('Persistenza variazione TS non riuscita.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $created = $documents->find($operationId);
        if (!is_array($created)) {
            throw new \RuntimeException('Variazione TS creata ma non reperibile.');
        }

        return [
            'document' => $created,
            'source_document' => $source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createCancellationOperationFromDocument(int $tenantId, int $documentId, int $userId = 0): array
    {
        $context = $this->resolveTenantDocumentContext($tenantId);
        /** @var BaseConnection $db */
        $db = $context['db'];
        /** @var TsDocumentModel $documents */
        $documents = $context['documents'];
        /** @var TsAuditService $audit */
        $audit = $context['audit'];

        if (!$this->isDocumentsTableAvailable($db)) {
            throw new \RuntimeException('La tabella ts_documents non è disponibile nel database corrente.');
        }

        $source = $documents->find($documentId);
        $this->assertSourceDocumentEligibleForOperation($source, 'ts_cancellation');

        $existing = $this->findReusableCancellationOperation($documents, $documentId);
        if (is_array($existing)) {
            return [
                'document' => $existing,
                'source_document' => $source,
                'reused' => true,
            ];
        }

        $record = $this->buildOperationRecordFromSource($source, 'ts_cancellation', $userId);
        $record['local_state'] = 'ready';
        $record['validation_json'] = json_encode([
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'validated_at' => date('Y-m-d H:i:s'),
            'requested_mode' => 'cancellation_prepare',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $db->transBegin();

        try {
            $operationId = (int) $documents->insert($record);
            if ($operationId <= 0) {
                throw new \RuntimeException('Creazione cancellazione TS non riuscita.');
            }

            $audit->record(
                $operationId,
                'cancellation_created',
                'Operazione di cancellazione TS preparata sul documento inviato.',
                'info',
                [
                    'source_document_id' => (int) ($source['id_ts_document'] ?? 0),
                ],
                $userId
            );
            $audit->record(
                (int) ($source['id_ts_document'] ?? 0),
                'cancellation_child_created',
                'Preparata una cancellazione TS collegata al documento.',
                'warning',
                [
                    'child_document_id' => $operationId,
                ],
                $userId
            );

            if (!$db->transStatus()) {
                throw new \RuntimeException('Persistenza cancellazione TS non riuscita.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $created = $documents->find($operationId);
        if (!is_array($created)) {
            throw new \RuntimeException('Cancellazione TS creata ma non reperibile.');
        }

        return [
            'document' => $created,
            'source_document' => $source,
            'reused' => false,
        ];
    }

    /**
     * @param array<string, mixed>|null $existing
     */
    private function buildDuplicateConflictMessage(?array $existing): string
    {
        $documentId = is_array($existing) ? (int) ($existing['id_ts_document'] ?? 0) : 0;
        $suffix = $documentId > 0 ? ' (documento #' . $documentId . ')' : '';

        return 'Esiste già un documento TS con lo stesso identificativo logico' . $suffix . '. Cambia numero, data o dispositivo, oppure modifica quello già presente.';
    }

    /**
     * @param array<string, mixed>|null $document
     * @return array<string, mixed>|null
     */
    private function resolveParentDocumentSummary(TsDocumentModel $documents, ?array $document): ?array
    {
        if (!is_array($document)) {
            return null;
        }

        $sourceType = trim((string) ($document['source_type'] ?? 'manual'));
        if (!in_array($sourceType, ['ts_variation', 'ts_cancellation'], true)) {
            return null;
        }

        $parentId = (int) ($document['source_ref_id'] ?? 0);
        if ($parentId <= 0) {
            return null;
        }

        $parent = $documents->find($parentId);

        return is_array($parent) ? $this->buildDocumentSummary($parent) : null;
    }

    /**
     * @param array<string, mixed>|null $document
     * @return array<int, array<string, mixed>>
     */
    private function listRelatedOperations(TsDocumentModel $documents, ?array $document): array
    {
        if (!is_array($document)) {
            return [];
        }

        if (!$this->isPrimarySourceType((string) ($document['source_type'] ?? 'manual'))) {
            return [];
        }

        $documentId = (int) ($document['id_ts_document'] ?? 0);
        if ($documentId <= 0) {
            return [];
        }

        $rows = $documents->where('source_ref_id', $documentId)
            ->orderBy('id_ts_document', 'DESC')
            ->findAll();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $items[] = $this->buildDocumentSummary($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function buildDocumentSummary(array $document): array
    {
        $sourceType = trim((string) ($document['source_type'] ?? 'manual'));

        return [
            'id_ts_document' => (int) ($document['id_ts_document'] ?? 0),
            'source_type' => $sourceType,
            'source_type_label' => (string) ($this->config->sourceTypeLabels[$sourceType] ?? $sourceType),
            'source_ref_id' => (int) ($document['source_ref_id'] ?? 0),
            'document_number' => trim((string) ($document['document_number'] ?? '')),
            'issue_date' => trim((string) ($document['issue_date'] ?? '')),
            'payment_date' => trim((string) ($document['payment_date'] ?? '')),
            'document_type' => trim((string) ($document['document_type'] ?? 'F')),
            'amount_total' => (float) ($document['amount_total'] ?? 0),
            'local_state' => trim((string) ($document['local_state'] ?? 'draft')),
            'ts_state' => trim((string) ($document['ts_state'] ?? '')),
            'ts_protocol' => trim((string) ($document['ts_protocol'] ?? '')),
            'updated_at' => trim((string) ($document['updated_at'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed>|null $source
     */
    private function assertSourceDocumentEligibleForOperation(?array $source, string $operationType): void
    {
        if (!is_array($source) || (int) ($source['id_ts_document'] ?? 0) <= 0) {
            throw new \RuntimeException('Documento TS di origine non trovato.');
        }

        if (!$this->isPrimarySourceType((string) ($source['source_type'] ?? 'manual'))) {
            throw new \RuntimeException('Puoi creare variazioni o cancellazioni solo a partire dal documento principale inviato.');
        }

        $localState = trim((string) ($source['local_state'] ?? 'draft'));
        $tsState = trim((string) ($source['ts_state'] ?? ''));
        if ($localState !== 'sent' && !in_array($tsState, ['accepted', 'varied'], true)) {
            throw new \RuntimeException('Il documento deve risultare già inviato a TS prima di creare una nuova operazione collegata.');
        }

        if ($tsState === 'cancelled') {
            throw new \RuntimeException('Il documento risulta già annullato su TS.');
        }

        if ($operationType === 'ts_cancellation' && trim((string) ($source['ts_protocol'] ?? '')) === '') {
            throw new \RuntimeException('Per annullare su TS serve prima un protocollo di invio valido sul documento originale.');
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function buildOperationRecordFromSource(array $source, string $operationType, int $userId): array
    {
        $identifierSeed = trim((string) ($source['document_identifier_hash'] ?? ''));
        if ($identifierSeed === '') {
            $identifierSeed = $this->buildDocumentIdentifierHash(
                trim((string) ($source['sender_piva_snapshot'] ?? '')),
                trim((string) ($source['issue_date'] ?? '')),
                trim((string) ($source['document_number'] ?? '')),
                (string) ($source['document_device'] ?? '')
            );
        }

        return [
            'id_ts_profile' => (int) ($source['id_ts_profile'] ?? 0) > 0 ? (int) ($source['id_ts_profile'] ?? 0) : null,
            'id_client' => (int) ($source['id_client'] ?? 0) > 0 ? (int) ($source['id_client'] ?? 0) : null,
            'source_type' => $operationType,
            'source_ref_id' => (int) ($source['id_ts_document'] ?? 0),
            'document_identifier_hash' => $this->generateOperationIdentifierHash($operationType, (int) ($source['id_ts_document'] ?? 0), $identifierSeed),
            'sender_piva_snapshot' => trim((string) ($source['sender_piva_snapshot'] ?? '')),
            'sender_cf_snapshot_enc' => trim((string) ($source['sender_cf_snapshot_enc'] ?? '')) !== ''
                ? (string) ($source['sender_cf_snapshot_enc'] ?? '')
                : null,
            'sender_type_snapshot' => trim((string) ($source['sender_type_snapshot'] ?? '')) !== ''
                ? (string) ($source['sender_type_snapshot'] ?? '')
                : null,
            'patient_cf_enc' => (string) ($source['patient_cf_enc'] ?? ''),
            'patient_cf_hash' => trim((string) ($source['patient_cf_hash'] ?? '')),
            'patient_label_snapshot_enc' => trim((string) ($source['patient_label_snapshot_enc'] ?? '')) !== ''
                ? (string) ($source['patient_label_snapshot_enc'] ?? '')
                : null,
            'document_number' => trim((string) ($source['document_number'] ?? '')),
            'document_device' => (int) ($source['document_device'] ?? 0) > 0 ? (int) ($source['document_device'] ?? 0) : null,
            'issue_date' => trim((string) ($source['issue_date'] ?? '')),
            'payment_date' => trim((string) ($source['payment_date'] ?? '')),
            'document_type' => trim((string) ($source['document_type'] ?? 'F')) !== ''
                ? trim((string) ($source['document_type'] ?? 'F'))
                : 'F',
            'expense_type_code' => trim((string) ($source['expense_type_code'] ?? 'SP')),
            'payment_mode' => trim((string) ($source['payment_mode'] ?? '')),
            'amount_total' => round((float) ($source['amount_total'] ?? 0), 2),
            'vat_rate' => $source['vat_rate'] !== null && $source['vat_rate'] !== ''
                ? round((float) $source['vat_rate'], 2)
                : null,
            'vat_nature' => trim((string) ($source['vat_nature'] ?? '')) !== ''
                ? trim((string) ($source['vat_nature'] ?? ''))
                : null,
            'opposition_flag' => (int) ($source['opposition_flag'] ?? 0) === 1 ? 1 : 0,
            'notes' => trim((string) ($source['notes'] ?? '')),
            'local_state' => 'draft',
            'ts_state' => null,
            'validation_json' => null,
            'request_payload_json' => null,
            'response_payload_json' => null,
            'ts_protocol' => null,
            'ts_sent_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'created_by' => $userId > 0 ? $userId : null,
            'updated_by' => $userId > 0 ? $userId : null,
        ];
    }

    private function findReusableCancellationOperation(TsDocumentModel $documents, int $documentId): ?array
    {
        $existing = $documents->where('source_type', 'ts_cancellation')
            ->where('source_ref_id', $documentId)
            ->orderBy('id_ts_document', 'DESC')
            ->first();

        if (!is_array($existing)) {
            return null;
        }

        $localState = trim((string) ($existing['local_state'] ?? 'draft'));
        if ($localState === 'sending') {
            throw new \RuntimeException('Esiste già una cancellazione TS in corso per questo documento.');
        }

        if ($localState === 'sent') {
            throw new \RuntimeException('Esiste già una cancellazione TS inviata per questo documento.');
        }

        return $existing;
    }

    private function generateOperationIdentifierHash(string $operationType, int $sourceDocumentId, string $identifierSeed): string
    {
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $nonce = uniqid('tsop', true);
        }

        return hash('sha256', implode('|', [
            $operationType,
            (string) $sourceDocumentId,
            $identifierSeed,
            $nonce,
        ]));
    }

    private function isDocumentsTableAvailable(BaseConnection $db): bool
    {
        return $db->tableExists('ts_documents');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentDocuments(BaseConnection $db, int $limit): array
    {
        return $db->table('ts_documents')
            ->select('id_ts_document, document_number, issue_date, document_type, amount_total, expense_type_code, vat_rate, vat_nature, local_state, ts_state, ts_protocol, updated_at')
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id_ts_document', 'DESC')
            ->get($limit)
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTenantDocumentContext(int $tenantId): array
    {
        return $this->tenantDbContext->resolveTenantContext($tenantId);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function normalizeDraftPayload(array $payload, array $profile, ?array $current): array
    {
        $sourceType = trim((string) ($current['source_type'] ?? 'manual'));
        $lockIdentity = in_array($sourceType, ['ts_variation', 'ts_cancellation', 'billing'], true);
        $lockAllFields = in_array($sourceType, ['ts_cancellation', 'billing'], true);

        $documentNumber = $lockIdentity
            ? trim((string) ($current['document_number'] ?? ''))
            : trim((string) ($payload['document_number'] ?? ''));
        $documentDevice = $lockIdentity
            ? trim((string) ($current['document_device'] ?? ''))
            : trim((string) ($payload['document_device'] ?? ''));
        $issueDate = $lockIdentity
            ? trim((string) ($current['issue_date'] ?? ''))
            : trim((string) ($payload['issue_date'] ?? ''));
        $paymentDate = $lockAllFields
            ? trim((string) ($current['payment_date'] ?? ''))
            : trim((string) ($payload['payment_date'] ?? ''));
        $patientCf = $lockAllFields
            ? strtoupper($this->safeDecrypt((string) ($current['patient_cf_enc'] ?? '')))
            : strtoupper(trim((string) ($payload['patient_cf_plain'] ?? '')));
        $patientLabel = $lockAllFields
            ? $this->safeDecrypt((string) ($current['patient_label_snapshot_enc'] ?? ''))
            : trim((string) ($payload['patient_label_plain'] ?? ''));
        $senderCfSnapshotEnc = null;
        $documentType = strtoupper(trim((string) ($lockAllFields
            ? ($current['document_type'] ?? 'F')
            : (
                array_key_exists('document_type', $payload)
                    ? $payload['document_type']
                    : ($current['document_type'] ?? 'F')
            )
        )));
        $vatRate = $this->normalizeNullableDecimal(
            $lockAllFields
                ? ($current['vat_rate'] ?? null)
                : (
                    array_key_exists('vat_rate', $payload)
                        ? $payload['vat_rate']
                        : ($current['vat_rate'] ?? null)
                )
        );
        $vatNature = strtoupper(trim((string) (
            $lockAllFields
                ? ($current['vat_nature'] ?? '')
                : (
                    array_key_exists('vat_nature', $payload)
                        ? $payload['vat_nature']
                        : ($current['vat_nature'] ?? '')
                )
        )));

        if ($sourceType !== 'manual') {
            $senderCfSnapshotEnc = trim((string) ($current['sender_cf_snapshot_enc'] ?? '')) !== ''
                ? (string) ($current['sender_cf_snapshot_enc'] ?? '')
                : null;
        }

        $profileOwnerCfEnc = trim((string) ($profile['owner_cf_enc'] ?? ''));
        if ($profileOwnerCfEnc !== '') {
            try {
                $ownerCf = $this->secrets->decrypt($profileOwnerCfEnc);
                if (is_string($ownerCf) && trim($ownerCf) !== '') {
                    $senderCfSnapshotEnc = $sourceType === 'manual'
                        ? $this->secrets->encrypt(strtoupper(trim($ownerCf)))
                        : $senderCfSnapshotEnc;
                }
            } catch (\Throwable $e) {
                if ($sourceType === 'manual') {
                    $senderCfSnapshotEnc = null;
                }
            }
        }

        $senderPiva = $sourceType === 'manual'
            ? trim((string) ($profile['owner_piva'] ?? ''))
            : trim((string) ($current['sender_piva_snapshot'] ?? ''));
        $senderType = $sourceType === 'manual'
            ? trim((string) ($profile['sender_type'] ?? ''))
            : trim((string) ($current['sender_type_snapshot'] ?? ''));
        $paymentMode = $lockAllFields
            ? trim((string) ($current['payment_mode'] ?? ''))
            : trim((string) ($payload['payment_mode'] ?? ''));
        $amountTotal = $lockAllFields
            ? $this->normalizeAmount($current['amount_total'] ?? 0)
            : $this->normalizeAmount($payload['amount_total'] ?? 0);
        $expenseTypeCode = strtoupper(trim((string) ($lockAllFields
            ? ($current['expense_type_code'] ?? 'SP')
            : ($payload['expense_type_code'] ?? 'SP')
        )));
        $oppositionFlag = $lockAllFields
            ? (((int) ($current['opposition_flag'] ?? 0) === 1) ? 1 : 0)
            : (((int) ($payload['opposition_flag'] ?? 0) === 1) ? 1 : 0);
        $notes = $lockAllFields
            ? trim((string) ($current['notes'] ?? ''))
            : trim((string) ($payload['notes'] ?? ''));
        $identifierHash = $sourceType === 'manual'
            ? $this->buildDocumentIdentifierHash($senderPiva, $issueDate, $documentNumber, $documentDevice)
            : trim((string) ($current['document_identifier_hash'] ?? ''));

        return [
            'id_client' => $lockAllFields ? (int) ($current['id_client'] ?? 0) : (int) ($payload['id_client'] ?? 0),
            'document_identifier_hash' => $identifierHash,
            'sender_piva_snapshot' => $senderPiva,
            'sender_cf_snapshot_enc' => $senderCfSnapshotEnc,
            'sender_type_snapshot' => $senderType,
            'patient_cf_plain' => $patientCf,
            'patient_cf_enc' => $patientCf !== '' ? $this->secrets->encrypt($patientCf) : (string) ($current['patient_cf_enc'] ?? ''),
            'patient_cf_hash' => $patientCf !== '' ? $this->secrets->hashForExactMatch($patientCf) : (string) ($current['patient_cf_hash'] ?? ''),
            'patient_label_plain' => $patientLabel,
            'patient_label_snapshot_enc' => $patientLabel !== ''
                ? $this->secrets->encrypt($patientLabel)
                : (trim((string) ($current['patient_label_snapshot_enc'] ?? '')) !== '' ? (string) ($current['patient_label_snapshot_enc'] ?? '') : null),
            'document_number' => $documentNumber,
            'document_device' => $documentDevice !== '' ? (int) $documentDevice : null,
            'issue_date' => $issueDate,
            'payment_date' => $paymentDate,
            'document_type' => $documentType !== '' ? $documentType : 'F',
            'expense_type_code' => $expenseTypeCode,
            'payment_mode' => $paymentMode,
            'amount_total' => $amountTotal,
            'vat_rate' => $vatRate,
            'vat_nature' => $vatNature,
            'opposition_flag' => $oppositionFlag,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed>|null $document
     * @return array<string, mixed>
     */
    private function buildEditableDocument(?array $document, array $defaults = []): array
    {
        if (!is_array($document)) {
            $documentType = trim((string) ($defaults['document_type'] ?? 'F'));
            $expenseType = trim((string) ($defaults['expense_type_code'] ?? 'SP'));
            $paymentMode = trim((string) ($defaults['payment_mode'] ?? 'tracciato'));

            return [
                'id_ts_document' => 0,
                'id_client' => 0,
                'source_type' => 'manual',
                'source_ref_id' => 0,
                'patient_cf_plain' => '',
                'patient_label_plain' => '',
                'document_number' => '',
                'document_device' => '',
                'issue_date' => date('Y-m-d'),
                'payment_date' => date('Y-m-d'),
                'document_type' => array_key_exists($documentType, $this->config->supportedDocumentTypes) ? $documentType : 'F',
                'expense_type_code' => array_key_exists($expenseType, $this->config->supportedExpenseTypes) ? $expenseType : 'SP',
                'payment_mode' => array_key_exists($paymentMode, $this->config->paymentModes) ? $paymentMode : 'tracciato',
                'amount_total' => '0,00',
                'vat_rate' => '',
                'vat_nature' => '',
                'opposition_flag' => !empty($defaults['opposition_flag']) ? 1 : 0,
                'notes' => '',
                'local_state' => 'draft',
                'ts_state' => '',
                'ts_protocol' => '',
                'ts_sent_at' => '',
                'last_error_code' => '',
                'last_error_message' => '',
            ];
        }

        return [
            'id_ts_document' => (int) ($document['id_ts_document'] ?? 0),
            'id_client' => (int) ($document['id_client'] ?? 0),
            'source_type' => trim((string) ($document['source_type'] ?? 'manual')),
            'source_ref_id' => (int) ($document['source_ref_id'] ?? 0),
            'patient_cf_plain' => $this->safeDecrypt((string) ($document['patient_cf_enc'] ?? '')),
            'patient_label_plain' => $this->safeDecrypt((string) ($document['patient_label_snapshot_enc'] ?? '')),
            'document_number' => trim((string) ($document['document_number'] ?? '')),
            'document_device' => (string) ($document['document_device'] ?? ''),
            'issue_date' => trim((string) ($document['issue_date'] ?? '')),
            'payment_date' => trim((string) ($document['payment_date'] ?? '')),
            'document_type' => trim((string) ($document['document_type'] ?? 'F')),
            'expense_type_code' => trim((string) ($document['expense_type_code'] ?? 'SP')),
            'payment_mode' => trim((string) ($document['payment_mode'] ?? 'tracciato')),
            'amount_total' => number_format((float) ($document['amount_total'] ?? 0), 2, ',', '.'),
            'vat_rate' => $document['vat_rate'] !== null && $document['vat_rate'] !== ''
                ? number_format((float) $document['vat_rate'], 2, ',', '.')
                : '',
            'vat_nature' => trim((string) ($document['vat_nature'] ?? '')),
            'opposition_flag' => (int) ($document['opposition_flag'] ?? 0),
            'notes' => trim((string) ($document['notes'] ?? '')),
            'local_state' => trim((string) ($document['local_state'] ?? 'draft')),
            'ts_state' => trim((string) ($document['ts_state'] ?? '')),
            'ts_protocol' => trim((string) ($document['ts_protocol'] ?? '')),
            'ts_sent_at' => trim((string) ($document['ts_sent_at'] ?? '')),
            'last_error_code' => trim((string) ($document['last_error_code'] ?? '')),
            'last_error_message' => trim((string) ($document['last_error_message'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed>|null $profile
     * @return array<string, mixed>
     */
    private function resolveProfileDocumentDefaults(?array $profile): array
    {
        $metadata = json_decode((string) ($profile['metadata_json'] ?? ''), true);
        $defaults = is_array($metadata) && is_array($metadata['document_defaults'] ?? null)
            ? $metadata['document_defaults']
            : [];

        return [
            'document_type' => trim((string) ($defaults['document_type'] ?? 'F')),
            'expense_type_code' => trim((string) ($defaults['expense_type_code'] ?? 'SP')),
            'payment_mode' => trim((string) ($defaults['payment_mode'] ?? 'tracciato')),
            'opposition_flag' => !empty($defaults['opposition_flag']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeValidationJson(string $payload): array
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

    private function safeDecrypt(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        try {
            return trim((string) $this->secrets->decrypt($payload));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function buildDocumentIdentifierHash(string $senderPiva, string $issueDate, string $documentNumber, string $documentDevice): string
    {
        $normalizedDevice = preg_replace('/\D+/', '', $documentDevice) ?? '';
        $normalizedDevice = $normalizedDevice !== '' ? (string) ((int) $normalizedDevice) : '';

        $parts = [
            preg_replace('/\D+/', '', $senderPiva) ?? '',
            trim($issueDate),
            strtoupper(trim($documentNumber)),
            $normalizedDevice,
        ];

        return hash('sha256', implode('|', $parts));
    }

    private function isPrimarySourceType(string $sourceType): bool
    {
        return in_array(trim(strtolower($sourceType)), ['manual', 'billing'], true);
    }

    /**
     * @param mixed $value
     */
    private function normalizeAmount($value): float
    {
        if (is_float($value) || is_int($value)) {
            return round((float) $value, 2);
        }

        $normalized = str_replace([' ', '.'], ['', ''], (string) $value);
        $normalized = str_replace(',', '.', $normalized);

        return round((float) $normalized, 2);
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableDecimal($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return round((float) $value, 2);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);

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
    private function emptySummary(): array
    {
        return [
            'total_documents' => 0,
            'draft_count' => 0,
            'ready_count' => 0,
            'sent_count' => 0,
            'rejected_count' => 0,
            'by_state' => [],
        ];
    }
}
