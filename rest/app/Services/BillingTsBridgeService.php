<?php

namespace App\Services;

use App\Models\BillingDocumentModel;
use App\Models\TsDocumentModel;

class BillingTsBridgeService
{
    private BillingTenantDatabaseContextService $billingContext;
    private TsTenantDatabaseContextService $tsContext;
    private BillingDocumentSettingsService $billingSettings;
    private TsProfileService $tsProfiles;
    private TsDocumentValidationService $validation;
    private TsDispatchService $dispatch;
    private TsSecretsService $secrets;
    private TsMigrationSafetyService $tsMigrationSafety;

    public function __construct(
        ?BillingTenantDatabaseContextService $billingContext = null,
        ?TsTenantDatabaseContextService $tsContext = null,
        ?BillingDocumentSettingsService $billingSettings = null,
        ?TsProfileService $tsProfiles = null,
        ?TsDocumentValidationService $validation = null,
        ?TsDispatchService $dispatch = null,
        ?TsSecretsService $secrets = null,
        ?TsMigrationSafetyService $tsMigrationSafety = null
    ) {
        $this->billingContext = $billingContext ?? new BillingTenantDatabaseContextService();
        $this->tsContext = $tsContext ?? new TsTenantDatabaseContextService();
        $this->billingSettings = $billingSettings ?? new BillingDocumentSettingsService();
        $this->tsProfiles = $tsProfiles ?? new TsProfileService();
        $this->validation = $validation ?? new TsDocumentValidationService();
        $this->dispatch = $dispatch ?? new TsDispatchService();
        $this->secrets = $secrets ?? new TsSecretsService();
        $this->tsMigrationSafety = $tsMigrationSafety ?? new TsMigrationSafetyService();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildQueueForTenant(int $tenantId, int $limit = 300): array
    {
        $context = $this->billingContext->resolveTenantContext($tenantId);
        $db = $context['db'];

        if (!$db->tableExists('billing_documents')) {
            return [
                'pending_documents' => [],
                'sent_documents' => [],
                'pending_count' => 0,
                'sent_count' => 0,
            ];
        }

        $rows = $db->table('billing_documents')
            ->select('id_billing_document, id_client, document_number, document_type, issue_date, payment_date, patient_name, patient_tax_code, payment_method, amount_total, vat_rate, vat_nature, ts_sync_enabled, ts_expense_type_code, ts_opposition_flag, linked_ts_document_id, ts_sync_state, local_state, updated_at')
            ->where('local_state', 'issued')
            ->where('ts_sync_enabled', 1)
            ->orderBy('issue_date', 'DESC')
            ->orderBy('id_billing_document', 'DESC')
            ->get(max(1, $limit))
            ->getResultArray();

        if ($rows === []) {
            return [
                'pending_documents' => [],
                'sent_documents' => [],
                'pending_count' => 0,
                'sent_count' => 0,
            ];
        }

        $tsDocumentsByBillingId = $this->loadTsDocumentsByBillingIds($db, $rows);

        $pending = [];
        $sent = [];

        foreach ($rows as $row) {
            $billingId = (int) ($row['id_billing_document'] ?? 0);
            $tsDocument = $tsDocumentsByBillingId[$billingId] ?? null;
            $item = $this->buildQueueItem($row, $tsDocument);

            if ($this->isBillingTsDocumentSent($tsDocument)) {
                $sent[] = $item;
                continue;
            }

            $pending[] = $item;
        }

        return [
            'pending_documents' => $pending,
            'sent_documents' => $sent,
            'pending_count' => count($pending),
            'sent_count' => count($sent),
        ];
    }

    /**
     * @param array<int, mixed> $billingDocumentIds
     * @return array<int, array<string, mixed>>
     */
    public function buildBillingDocumentActionMap(int $tenantId, array $billingDocumentIds): array
    {
        $billingDocumentIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int) $value),
            $billingDocumentIds
        ))));

        if ($tenantId <= 0 || $billingDocumentIds === []) {
            return [];
        }

        $context = $this->billingContext->resolveTenantContext($tenantId);
        $db = $context['db'];
        if (!$db->tableExists('billing_documents')) {
            return [];
        }

        $billingRows = $db->table('billing_documents')
            ->select('id_billing_document, linked_ts_document_id, ts_sync_state, local_state')
            ->whereIn('id_billing_document', $billingDocumentIds)
            ->get()
            ->getResultArray();

        if ($billingRows === []) {
            return [];
        }

        $tsDocumentsByBillingId = $this->loadTsDocumentsByBillingIds($db, $billingRows);
        $actionMap = [];

        foreach ($billingRows as $billingRow) {
            $billingId = (int) ($billingRow['id_billing_document'] ?? 0);
            if ($billingId <= 0) {
                continue;
            }

            $actionMap[$billingId] = $this->buildBillingDocumentActionState(
                $billingRow,
                $tsDocumentsByBillingId[$billingId] ?? null
            );
        }

        return $actionMap;
    }

    /**
     * @return array<string, mixed>
     */
    public function describeBillingDocumentAction(int $tenantId, int $billingDocumentId): array
    {
        $billingDocumentId = max(0, $billingDocumentId);
        if ($tenantId <= 0 || $billingDocumentId <= 0) {
            return $this->defaultBillingDocumentActionState();
        }

        $map = $this->buildBillingDocumentActionMap($tenantId, [$billingDocumentId]);

        return $map[$billingDocumentId] ?? $this->defaultBillingDocumentActionState();
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteBillingDocument(int $tenantId, int $billingDocumentId, int $userId = 0): array
    {
        $billingDocumentId = max(0, $billingDocumentId);
        if ($tenantId <= 0 || $billingDocumentId <= 0) {
            throw new \InvalidArgumentException('Fattura non valida per la cancellazione.');
        }

        $billingContext = $this->billingContext->resolveTenantContext($tenantId);
        $db = $billingContext['db'];
        if (!$db->tableExists('billing_documents')) {
            throw new \RuntimeException('Archivio fatture non disponibile per questo spazio.');
        }

        /** @var BillingDocumentModel $billingDocuments */
        $billingDocuments = $billingContext['documents'];
        $billingDocument = $billingDocuments->find($billingDocumentId);
        if (!is_array($billingDocument)) {
            throw new \RuntimeException('Fattura non trovata.');
        }

        $relatedTsDocuments = $this->loadRelatedTsDocumentsForBilling($db, $billingDocument);
        $primaryTsDocument = $this->resolvePrimaryTsDocument($billingDocument, $relatedTsDocuments);
        $actionState = $this->buildBillingDocumentActionState($billingDocument, $primaryTsDocument);

        if (empty($actionState['can_delete'])) {
            throw new \RuntimeException(trim((string) ($actionState['locked_reason'] ?? 'La fattura non può essere eliminata.')));
        }

        $relatedTsDocumentIds = [];
        foreach ($relatedTsDocuments as $tsDocument) {
            if (!is_array($tsDocument)) {
                continue;
            }

            $tsDocumentId = (int) ($tsDocument['id_ts_document'] ?? 0);
            if ($tsDocumentId <= 0) {
                continue;
            }

            if ($this->isBillingTsDocumentSent($tsDocument) || trim((string) ($tsDocument['local_state'] ?? '')) === 'sending') {
                throw new \RuntimeException('La fattura risulta già inviata o in invio a TS e non può essere eliminata.');
            }

            $relatedTsDocumentIds[] = $tsDocumentId;
        }

        $relatedTsDocumentIds = array_values(array_unique($relatedTsDocumentIds));

        $db->transBegin();

        try {
            if ($relatedTsDocumentIds !== []) {
                $db->table('ts_documents')
                    ->whereIn('id_ts_document', $relatedTsDocumentIds)
                    ->delete();
            }

            $billingDocuments->update($billingDocumentId, [
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
            $billingDocuments->delete($billingDocumentId);

            if (!$db->transStatus()) {
                throw new \RuntimeException('Cancellazione fattura non riuscita.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        return [
            'billing_document_id' => $billingDocumentId,
            'document_number' => trim((string) ($billingDocument['document_number'] ?? '')),
            'deleted_ts_document_ids' => $relatedTsDocumentIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareBillingDocumentForTs(int $tenantId, int $billingDocumentId, int $userId = 0): array
    {
        $billingDocumentId = max(0, $billingDocumentId);
        if ($tenantId <= 0 || $billingDocumentId <= 0) {
            throw new \InvalidArgumentException('Documento fatturazione non valido per la preparazione TS.');
        }

        $tsContext = $this->ensureTsSchemaReady($tenantId);
        $billingContext = $this->billingContext->resolveTenantContext($tenantId);
        /** @var BillingDocumentModel $billingDocuments */
        $billingDocuments = $billingContext['documents'];
        /** @var TsDocumentModel $tsDocuments */
        $tsDocuments = $tsContext['documents'];

        $billingDocument = $billingDocuments->find($billingDocumentId);
        if (!is_array($billingDocument)) {
            throw new \RuntimeException('Documento fatturazione non trovato.');
        }

        $eligibility = $this->validateBillingEligibility($billingDocument);
        if (!empty($eligibility['blocked'])) {
            $this->updateBillingLinkState($billingDocuments, $billingDocumentId, 0, 'error', $userId);

            return [
                'status' => 'blocked',
                'message' => (string) ($eligibility['message'] ?? 'Documento fatturazione non pronto per TS.'),
                'billing_document' => $billingDocuments->find($billingDocumentId) ?? $billingDocument,
                'ts_document' => null,
                'validation' => [
                    'valid' => false,
                    'errors' => [(string) ($eligibility['message'] ?? 'Documento fatturazione non pronto per TS.')],
                    'warnings' => [],
                ],
            ];
        }

        $profile = $this->tsProfiles->getDefaultProfileForTenant($tenantId);
        if (!is_array($profile) || (int) ($profile['id_ts_profile'] ?? 0) <= 0) {
            $this->updateBillingLinkState($billingDocuments, $billingDocumentId, 0, 'error', $userId);

            return [
                'status' => 'blocked',
                'message' => 'Configura prima un profilo TS attivo per questo spazio.',
                'billing_document' => $billingDocuments->find($billingDocumentId) ?? $billingDocument,
                'ts_document' => null,
                'validation' => [
                    'valid' => false,
                    'errors' => ['Configura prima un profilo TS attivo per questo spazio.'],
                    'warnings' => [],
                ],
            ];
        }

        $existingTsDocument = $this->findLinkedTsDocument($billingDocument, $tsDocuments);
        if (is_array($existingTsDocument) && $this->isBillingTsDocumentSent($existingTsDocument)) {
            $this->updateBillingLinkState(
                $billingDocuments,
                $billingDocumentId,
                (int) ($existingTsDocument['id_ts_document'] ?? 0),
                'sent',
                $userId
            );

            return [
                'status' => 'sent',
                'message' => 'La fattura risulta già inviata a TS.',
                'billing_document' => $billingDocuments->find($billingDocumentId) ?? $billingDocument,
                'ts_document' => $existingTsDocument,
                'validation' => [
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                ],
            ];
        }

        $settings = $this->billingSettings->resolveTenantSettings($tenantId);
        $deviceNumber = $this->resolveTsDocumentDevice((array) ($settings['config'] ?? []));
        $identifierHash = $this->buildIdentifierHash(
            trim((string) ($profile['owner_piva'] ?? '')),
            trim((string) ($billingDocument['issue_date'] ?? '')),
            trim((string) ($billingDocument['document_number'] ?? '')),
            (string) $deviceNumber
        );

        $currentTsId = (int) ($existingTsDocument['id_ts_document'] ?? 0);
        $duplicate = $tsDocuments->findByIdentifierHash($identifierHash);
        $duplicateFound = is_array($duplicate) && (int) ($duplicate['id_ts_document'] ?? 0) !== $currentTsId;

        $validationPayload = $this->buildTsValidationPayload($billingDocument, $profile, $deviceNumber);
        $validation = $this->validation->validateDraft($validationPayload, $profile, $duplicateFound, 'manual');
        $message = $duplicateFound
            ? 'Esiste già un documento TS con lo stesso identificativo logico.'
            : trim(implode(' ', (array) ($validation['errors'] ?? [])));

        $record = $this->buildTsDocumentRecord(
            $billingDocument,
            $profile,
            $deviceNumber,
            $identifierHash,
            $validation,
            $userId,
            $existingTsDocument
        );

        $tsDb = $tsContext['db'];
        $tsDb->transBegin();

        try {
            if ($currentTsId > 0) {
                $tsDocuments->update($currentTsId, $record);
                $tsDocumentId = $currentTsId;
            } else {
                $tsDocumentId = (int) $tsDocuments->insert($record);
            }

            if ($tsDocumentId <= 0) {
                throw new \RuntimeException('Preparazione documento TS non riuscita.');
            }

            if (!$tsDb->transStatus()) {
                throw new \RuntimeException('Persistenza documento TS non riuscita.');
            }

            $tsDb->transCommit();
        } catch (\Throwable $e) {
            $tsDb->transRollback();
            $this->updateBillingLinkState($billingDocuments, $billingDocumentId, $currentTsId, 'error', $userId);
            throw $e;
        }

        $savedTsDocument = $tsDocuments->find($tsDocumentId);
        if (!is_array($savedTsDocument)) {
            $this->updateBillingLinkState($billingDocuments, $billingDocumentId, $tsDocumentId, 'error', $userId);
            throw new \RuntimeException('Documento TS preparato ma non più reperibile.');
        }

        $billingState = $this->resolveBillingTsStateFromTsDocument($savedTsDocument);
        if ($duplicateFound || empty($validation['valid'])) {
            $billingState = 'error';
        }

        $this->updateBillingLinkState($billingDocuments, $billingDocumentId, $tsDocumentId, $billingState, $userId);

        return [
            'status' => !empty($validation['valid']) && !$duplicateFound ? 'ready' : 'blocked',
            'message' => !empty($validation['valid']) && !$duplicateFound
                ? 'Fattura preparata correttamente per il Sistema TS.'
                : ($message !== '' ? $message : 'La fattura è stata collegata a TS ma richiede correzioni prima dell’invio.'),
            'billing_document' => $billingDocuments->find($billingDocumentId) ?? $billingDocument,
            'ts_document' => $savedTsDocument,
            'validation' => $validation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendBillingDocument(int $tenantId, int $billingDocumentId, int $userId = 0): array
    {
        $prepared = $this->prepareBillingDocumentForTs($tenantId, $billingDocumentId, $userId);
        if (($prepared['status'] ?? '') === 'blocked' || !is_array($prepared['ts_document'] ?? null)) {
            return $prepared;
        }

        if (($prepared['status'] ?? '') === 'sent') {
            return $prepared;
        }

        $tsDocument = (array) ($prepared['ts_document'] ?? []);
        $tsDocumentId = (int) ($tsDocument['id_ts_document'] ?? 0);
        if ($tsDocumentId <= 0) {
            throw new \RuntimeException('Documento TS collegato non valido per il tentativo di invio.');
        }

        try {
            $dispatchResult = $this->dispatch->dispatchDocument($tenantId, $tsDocumentId, $userId);
        } catch (\Throwable $e) {
            $billingContext = $this->billingContext->resolveTenantContext($tenantId);
            /** @var BillingDocumentModel $billingDocuments */
            $billingDocuments = $billingContext['documents'];
            $this->updateBillingLinkState($billingDocuments, $billingDocumentId, $tsDocumentId, 'error', $userId);
            throw $e;
        }

        $billingContext = $this->billingContext->resolveTenantContext($tenantId);
        /** @var BillingDocumentModel $billingDocuments */
        $billingDocuments = $billingContext['documents'];
        $resultDocument = is_array($dispatchResult['document'] ?? null) ? $dispatchResult['document'] : $tsDocument;
        $this->updateBillingLinkState(
            $billingDocuments,
            $billingDocumentId,
            (int) ($resultDocument['id_ts_document'] ?? $tsDocumentId),
            $this->resolveBillingTsStateFromTsDocument($resultDocument),
            $userId
        );

        return [
            'status' => (string) ($dispatchResult['status'] ?? 'ok'),
            'message' => (string) ($dispatchResult['message'] ?? 'Documento TS inviato correttamente.'),
            'billing_document' => $billingDocuments->find($billingDocumentId) ?? [],
            'ts_document' => $resultDocument,
            'validation' => $dispatchResult['validation'] ?? ($prepared['validation'] ?? []),
            'support_log' => $dispatchResult['support_log'] ?? null,
        ];
    }

    /**
     * @param array<int, mixed> $billingDocumentIds
     * @return array<string, mixed>
     */
    public function sendBillingDocumentsBulk(int $tenantId, array $billingDocumentIds, int $userId = 0): array
    {
        $billingDocumentIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int) $value),
            $billingDocumentIds
        ))));

        if ($tenantId <= 0 || $billingDocumentIds === []) {
            throw new \InvalidArgumentException('Seleziona almeno una fattura da inviare a TS.');
        }

        $results = [];
        $sent = 0;
        $blocked = 0;
        $errors = 0;

        foreach ($billingDocumentIds as $billingDocumentId) {
            try {
                $result = $this->sendBillingDocument($tenantId, $billingDocumentId, $userId);
                $status = trim((string) ($result['status'] ?? 'error'));
                if ($status === 'ok' || $status === 'sent') {
                    $sent++;
                } elseif ($status === 'blocked') {
                    $blocked++;
                } else {
                    $errors++;
                }

                $results[] = [
                    'billing_document_id' => $billingDocumentId,
                    'status' => $status,
                    'message' => trim((string) ($result['message'] ?? '')),
                    'ts_document_id' => (int) (($result['ts_document']['id_ts_document'] ?? 0)),
                ];
            } catch (\Throwable $e) {
                $errors++;
                $results[] = [
                    'billing_document_id' => $billingDocumentId,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'ts_document_id' => 0,
                ];
            }
        }

        return [
            'sent_count' => $sent,
            'blocked_count' => $blocked,
            'error_count' => $errors,
            'results' => $results,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $billingRows
     * @return array<int, array<string, mixed>>
     */
    private function loadTsDocumentsByBillingIds(\CodeIgniter\Database\BaseConnection $db, array $billingRows): array
    {
        if (!$db->tableExists('ts_documents')) {
            return [];
        }

        $billingIds = [];
        $linkedIds = [];

        foreach ($billingRows as $row) {
            $billingId = (int) ($row['id_billing_document'] ?? 0);
            if ($billingId > 0) {
                $billingIds[] = $billingId;
            }

            $linkedId = (int) ($row['linked_ts_document_id'] ?? 0);
            if ($linkedId > 0) {
                $linkedIds[] = $linkedId;
            }
        }

        $billingIds = array_values(array_unique($billingIds));
        $linkedIds = array_values(array_unique($linkedIds));
        if ($billingIds === [] && $linkedIds === []) {
            return [];
        }

        $builder = $db->table('ts_documents')
            ->select('id_ts_document, source_type, source_ref_id, document_number, issue_date, payment_date, amount_total, local_state, ts_state, ts_protocol, ts_sent_at, updated_at');

        if ($linkedIds !== []) {
            $builder->groupStart()
                ->whereIn('id_ts_document', $linkedIds);
        } else {
            $builder->groupStart()
                ->where('1 = 0');
        }

        if ($billingIds !== []) {
            $builder->orGroupStart()
                ->where('source_type', 'billing')
                ->whereIn('source_ref_id', $billingIds)
                ->groupEnd();
        }

        $rows = $builder->groupEnd()->get()->getResultArray();

        $byId = [];
        $byBillingId = [];
        foreach ($rows as $row) {
            $tsId = (int) ($row['id_ts_document'] ?? 0);
            if ($tsId > 0) {
                $byId[$tsId] = $row;
            }

            if (trim((string) ($row['source_type'] ?? '')) === 'billing') {
                $sourceRef = (int) ($row['source_ref_id'] ?? 0);
                if ($sourceRef > 0) {
                    $byBillingId[$sourceRef] = $row;
                }
            }
        }

        $resolved = [];
        foreach ($billingRows as $row) {
            $billingId = (int) ($row['id_billing_document'] ?? 0);
            $linkedId = (int) ($row['linked_ts_document_id'] ?? 0);
            if ($linkedId > 0 && isset($byId[$linkedId])) {
                $resolved[$billingId] = $byId[$linkedId];
                continue;
            }

            if ($billingId > 0 && isset($byBillingId[$billingId])) {
                $resolved[$billingId] = $byBillingId[$billingId];
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @return array<int, array<string, mixed>>
     */
    private function loadRelatedTsDocumentsForBilling(\CodeIgniter\Database\BaseConnection $db, array $billingDocument): array
    {
        if (!$db->tableExists('ts_documents')) {
            return [];
        }

        $billingId = (int) ($billingDocument['id_billing_document'] ?? 0);
        $linkedId = (int) ($billingDocument['linked_ts_document_id'] ?? 0);
        if ($billingId <= 0 && $linkedId <= 0) {
            return [];
        }

        $builder = $db->table('ts_documents')->select(
            'id_ts_document, source_type, source_ref_id, document_number, issue_date, payment_date, amount_total, local_state, ts_state, ts_protocol, ts_sent_at, updated_at'
        );

        if ($linkedId > 0) {
            $builder->groupStart()
                ->where('id_ts_document', $linkedId);
        } else {
            $builder->groupStart()
                ->where('1 = 0');
        }

        if ($billingId > 0) {
            $builder->orGroupStart()
                ->where('source_type', 'billing')
                ->where('source_ref_id', $billingId)
                ->groupEnd();
        }

        return $builder
            ->groupEnd()
            ->orderBy('id_ts_document', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @param array<int, array<string, mixed>> $relatedTsDocuments
     * @return array<string, mixed>|null
     */
    private function resolvePrimaryTsDocument(array $billingDocument, array $relatedTsDocuments): ?array
    {
        $linkedId = (int) ($billingDocument['linked_ts_document_id'] ?? 0);
        if ($linkedId > 0) {
            foreach ($relatedTsDocuments as $tsDocument) {
                if ((int) ($tsDocument['id_ts_document'] ?? 0) === $linkedId) {
                    return $tsDocument;
                }
            }
        }

        foreach ($relatedTsDocuments as $tsDocument) {
            if (trim((string) ($tsDocument['source_type'] ?? '')) === 'billing') {
                return $tsDocument;
            }
        }

        return $relatedTsDocuments[0] ?? null;
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @return array<string, mixed>
     */
    private function buildBillingDocumentActionState(array $billingDocument, ?array $tsDocument): array
    {
        $sent = $this->isBillingDocumentSent($billingDocument, $tsDocument);
        $sending = !$sent && $this->isBillingDocumentSending($billingDocument, $tsDocument);
        $canManage = !$sent && !$sending;
        $linkedTsDocumentId = is_array($tsDocument)
            ? (int) ($tsDocument['id_ts_document'] ?? 0)
            : (int) ($billingDocument['linked_ts_document_id'] ?? 0);

        return [
            'can_edit' => $canManage,
            'can_delete' => $canManage,
            'locked' => !$canManage,
            'locked_reason' => $this->buildBillingDocumentLockMessage($billingDocument, $tsDocument),
            'linked_ts_document_id' => $linkedTsDocumentId,
            'ts_local_state' => trim((string) ($tsDocument['local_state'] ?? '')),
            'ts_state' => trim((string) ($tsDocument['ts_state'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $billingDocument
     */
    private function isBillingDocumentSent(array $billingDocument, ?array $tsDocument): bool
    {
        if (trim((string) ($billingDocument['ts_sync_state'] ?? '')) === 'sent') {
            return true;
        }

        return $this->isBillingTsDocumentSent($tsDocument);
    }

    /**
     * @param array<string, mixed> $billingDocument
     */
    private function isBillingDocumentSending(array $billingDocument, ?array $tsDocument): bool
    {
        if (trim((string) ($billingDocument['ts_sync_state'] ?? '')) === 'sending') {
            return true;
        }

        return is_array($tsDocument) && trim((string) ($tsDocument['local_state'] ?? '')) === 'sending';
    }

    /**
     * @param array<string, mixed> $billingDocument
     */
    private function buildBillingDocumentLockMessage(array $billingDocument, ?array $tsDocument): string
    {
        if ($this->isBillingDocumentSent($billingDocument, $tsDocument)) {
            return 'Questa fattura risulta già inviata a TS: puoi aprirla e scaricare i documenti, ma non modificarla o cancellarla.';
        }

        if ($this->isBillingDocumentSending($billingDocument, $tsDocument)) {
            return 'Questa fattura e attualmente in invio a TS e non può essere modificata o cancellata finché il processo non si conclude.';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultBillingDocumentActionState(): array
    {
        return [
            'can_edit' => true,
            'can_delete' => true,
            'locked' => false,
            'locked_reason' => '',
            'linked_ts_document_id' => 0,
            'ts_local_state' => '',
            'ts_state' => '',
        ];
    }

    /**
     * @param array<string, mixed> $billingRow
     * @param array<string, mixed>|null $tsDocument
     * @return array<string, mixed>
     */
    private function buildQueueItem(array $billingRow, ?array $tsDocument): array
    {
        $billingId = (int) ($billingRow['id_billing_document'] ?? 0);
        $tsDocumentId = is_array($tsDocument) ? (int) ($tsDocument['id_ts_document'] ?? 0) : 0;
        $tsLocalState = trim((string) ($tsDocument['local_state'] ?? ''));
        $tsState = trim((string) ($tsDocument['ts_state'] ?? ''));

        return [
            'id_billing_document' => $billingId,
            'id_client' => (int) ($billingRow['id_client'] ?? 0),
            'document_number' => trim((string) ($billingRow['document_number'] ?? '')),
            'document_type' => trim((string) ($billingRow['document_type'] ?? 'invoice')),
            'issue_date' => trim((string) ($billingRow['issue_date'] ?? '')),
            'payment_date' => trim((string) ($billingRow['payment_date'] ?? '')),
            'patient_name' => trim((string) ($billingRow['patient_name'] ?? '')),
            'patient_tax_code' => trim((string) ($billingRow['patient_tax_code'] ?? '')),
            'payment_method' => trim((string) ($billingRow['payment_method'] ?? '')),
            'amount_total' => (float) ($billingRow['amount_total'] ?? 0),
            'vat_rate' => $billingRow['vat_rate'] ?? null,
            'vat_nature' => trim((string) ($billingRow['vat_nature'] ?? '')),
            'ts_expense_type_code' => trim((string) ($billingRow['ts_expense_type_code'] ?? '')),
            'ts_opposition_flag' => (int) ($billingRow['ts_opposition_flag'] ?? 0) === 1,
            'ts_sync_state' => trim((string) ($billingRow['ts_sync_state'] ?? 'ready')),
            'linked_ts_document_id' => $tsDocumentId,
            'ts_local_state' => $tsLocalState,
            'ts_state' => $tsState,
            'ts_protocol' => trim((string) ($tsDocument['ts_protocol'] ?? '')),
            'ts_sent_at' => trim((string) ($tsDocument['ts_sent_at'] ?? '')),
            'queue_bucket' => $this->isBillingTsDocumentSent($tsDocument) ? 'sent' : 'pending',
            'can_send_now' => !$this->isBillingTsDocumentSent($tsDocument),
        ];
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @return array<string, mixed>
     */
    private function validateBillingEligibility(array $billingDocument): array
    {
        if (trim((string) ($billingDocument['local_state'] ?? 'draft')) !== 'issued') {
            return [
                'blocked' => true,
                'message' => 'Per TS puoi inviare solo fatture definitive.',
            ];
        }

        if ((int) ($billingDocument['ts_sync_enabled'] ?? 0) !== 1) {
            return [
                'blocked' => true,
                'message' => 'La fattura non è marcata per il Sistema TS.',
            ];
        }

        return [
            'blocked' => false,
            'message' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureTsSchemaReady(int $tenantId): array
    {
        $context = $this->tsContext->resolveTenantContext($tenantId);
        $db = $context['db'];
        if ($db->tableExists('ts_documents')) {
            return $context;
        }

        $report = $this->tsMigrationSafety->migrateSafe('tenant', $tenantId, true);
        $results = (array) ($report['results'] ?? []);
        $tenantResult = is_array($results['tenant'] ?? null) ? $results['tenant'] : null;
        $status = trim((string) ($tenantResult['status'] ?? $report['status'] ?? 'error'));

        $context = $this->tsContext->resolveTenantContext($tenantId);
        if ($context['db']->tableExists('ts_documents')) {
            return $context;
        }

        $message = trim((string) ($tenantResult['message'] ?? ''));
        if ($message === '') {
            $message = 'Schema Sistema TS non pronto per questo spazio.';
        }

        throw new \RuntimeException($status === 'blocked'
            ? 'Schema Sistema TS non riallineato: ' . $message
            : $message);
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function buildTsValidationPayload(array $billingDocument, array $profile, int $deviceNumber): array
    {
        return [
            'sender_piva_snapshot' => trim((string) ($profile['owner_piva'] ?? '')),
            'patient_cf_plain' => strtoupper(trim((string) ($billingDocument['patient_tax_code'] ?? ''))),
            'document_number' => trim((string) ($billingDocument['document_number'] ?? '')),
            'document_device' => $deviceNumber,
            'issue_date' => trim((string) ($billingDocument['issue_date'] ?? '')),
            'payment_date' => trim((string) ($billingDocument['payment_date'] ?? '')),
            'document_type' => $this->mapBillingDocumentTypeToTs((string) ($billingDocument['document_type'] ?? 'invoice')),
            'expense_type_code' => $this->resolveExpenseTypeCode($billingDocument, $profile),
            'payment_mode' => $this->mapPaymentMethodToTs((string) ($billingDocument['payment_method'] ?? '')),
            'amount_total' => round((float) ($billingDocument['amount_total'] ?? 0), 2),
            'vat_rate' => $this->normalizeNullableDecimal($billingDocument['vat_rate'] ?? null),
            'vat_nature' => strtoupper(trim((string) ($billingDocument['vat_nature'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $validation
     * @param array<string, mixed>|null $existingTsDocument
     * @return array<string, mixed>
     */
    private function buildTsDocumentRecord(
        array $billingDocument,
        array $profile,
        int $deviceNumber,
        string $identifierHash,
        array $validation,
        int $userId,
        ?array $existingTsDocument
    ): array {
        $patientTaxCode = strtoupper(trim((string) ($billingDocument['patient_tax_code'] ?? '')));
        $patientName = trim((string) ($billingDocument['patient_name'] ?? ''));
        $localState = !empty($validation['valid']) ? 'ready' : 'to_validate';
        $errorMessage = !empty($validation['valid'])
            ? null
            : trim(implode(' ', (array) ($validation['errors'] ?? [])));

        return [
            'id_ts_profile' => (int) ($profile['id_ts_profile'] ?? 0) > 0 ? (int) ($profile['id_ts_profile'] ?? 0) : null,
            'id_client' => (int) ($billingDocument['id_client'] ?? 0) > 0 ? (int) ($billingDocument['id_client'] ?? 0) : null,
            'source_type' => 'billing',
            'source_ref_id' => (int) ($billingDocument['id_billing_document'] ?? 0),
            'document_identifier_hash' => $identifierHash,
            'sender_piva_snapshot' => trim((string) ($profile['owner_piva'] ?? '')),
            'sender_cf_snapshot_enc' => trim((string) ($profile['owner_cf_enc'] ?? '')) !== ''
                ? (string) ($profile['owner_cf_enc'] ?? '')
                : null,
            'sender_type_snapshot' => trim((string) ($profile['sender_type'] ?? '')) !== ''
                ? (string) ($profile['sender_type'] ?? '')
                : null,
            'patient_cf_enc' => $patientTaxCode !== '' ? $this->secrets->encrypt($patientTaxCode) : (string) ($existingTsDocument['patient_cf_enc'] ?? ''),
            'patient_cf_hash' => $patientTaxCode !== '' ? $this->secrets->hashForExactMatch($patientTaxCode) : (string) ($existingTsDocument['patient_cf_hash'] ?? ''),
            'patient_label_snapshot_enc' => $patientName !== ''
                ? $this->secrets->encrypt($patientName)
                : (trim((string) ($existingTsDocument['patient_label_snapshot_enc'] ?? '')) !== '' ? (string) ($existingTsDocument['patient_label_snapshot_enc'] ?? '') : null),
            'document_number' => trim((string) ($billingDocument['document_number'] ?? '')),
            'document_device' => $deviceNumber,
            'issue_date' => trim((string) ($billingDocument['issue_date'] ?? '')),
            'payment_date' => trim((string) ($billingDocument['payment_date'] ?? '')),
            'document_type' => $this->mapBillingDocumentTypeToTs((string) ($billingDocument['document_type'] ?? 'invoice')),
            'expense_type_code' => $this->resolveExpenseTypeCode($billingDocument, $profile),
            'payment_mode' => $this->mapPaymentMethodToTs((string) ($billingDocument['payment_method'] ?? '')),
            'amount_total' => round((float) ($billingDocument['amount_total'] ?? 0), 2),
            'vat_rate' => $this->normalizeNullableDecimal($billingDocument['vat_rate'] ?? null),
            'vat_nature' => trim((string) ($billingDocument['vat_nature'] ?? '')) !== ''
                ? strtoupper(trim((string) ($billingDocument['vat_nature'] ?? '')))
                : null,
            'opposition_flag' => (int) ($billingDocument['ts_opposition_flag'] ?? 0) === 1 ? 1 : 0,
            'notes' => trim((string) ($billingDocument['notes'] ?? '')) !== ''
                ? trim((string) ($billingDocument['notes'] ?? ''))
                : null,
            'local_state' => $localState,
            'ts_state' => null,
            'validation_json' => $this->encodeJson([
                'valid' => (bool) ($validation['valid'] ?? false),
                'errors' => array_values((array) ($validation['errors'] ?? [])),
                'warnings' => array_values((array) ($validation['warnings'] ?? [])),
                'validated_at' => date('Y-m-d H:i:s'),
                'requested_mode' => 'billing_sync',
            ]),
            'request_payload_json' => null,
            'response_payload_json' => null,
            'ts_protocol' => null,
            'ts_sent_at' => null,
            'last_error_code' => !empty($validation['valid']) ? null : 'LOCAL_VALIDATION',
            'last_error_message' => $errorMessage,
            'updated_by' => $userId > 0 ? $userId : null,
        ];
    }

    /**
     * @param array<string, mixed> $billingDocument
     * @return array<string, mixed>|null
     */
    private function findLinkedTsDocument(array $billingDocument, TsDocumentModel $tsDocuments): ?array
    {
        $linkedId = (int) ($billingDocument['linked_ts_document_id'] ?? 0);
        if ($linkedId > 0) {
            $linked = $tsDocuments->find($linkedId);
            if (is_array($linked)) {
                return $linked;
            }
        }

        $billingId = (int) ($billingDocument['id_billing_document'] ?? 0);
        if ($billingId <= 0) {
            return null;
        }

        $linked = $tsDocuments->where('source_type', 'billing')
            ->where('source_ref_id', $billingId)
            ->first();

        return is_array($linked) ? $linked : null;
    }

    private function isBillingTsDocumentSent(?array $tsDocument): bool
    {
        if (!is_array($tsDocument)) {
            return false;
        }

        $localState = trim((string) ($tsDocument['local_state'] ?? 'draft'));
        $tsState = trim((string) ($tsDocument['ts_state'] ?? ''));

        return $localState === 'sent' || in_array($tsState, ['accepted', 'varied', 'cancelled'], true);
    }

    private function resolveBillingTsStateFromTsDocument(array $tsDocument): string
    {
        $localState = trim((string) ($tsDocument['local_state'] ?? 'draft'));
        $tsState = trim((string) ($tsDocument['ts_state'] ?? ''));

        if ($localState === 'sent' || in_array($tsState, ['accepted', 'varied', 'cancelled'], true)) {
            return 'sent';
        }

        if ($localState === 'sending') {
            return 'sending';
        }

        if (in_array($localState, ['to_validate', 'rejected'], true)) {
            return 'error';
        }

        return 'linked';
    }

    private function updateBillingLinkState(
        BillingDocumentModel $billingDocuments,
        int $billingDocumentId,
        int $tsDocumentId,
        string $tsState,
        int $userId
    ): void {
        $payload = [
            'linked_ts_document_id' => $tsDocumentId > 0 ? $tsDocumentId : null,
            'ts_sync_state' => trim($tsState) !== '' ? trim($tsState) : 'ready',
            'updated_by' => $userId > 0 ? $userId : null,
        ];

        $billingDocuments->update($billingDocumentId, $payload);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveTsDocumentDevice(array $config): int
    {
        $integrationTs = is_array($config['integration_ts'] ?? null) ? $config['integration_ts'] : [];
        $value = (int) ($integrationTs['default_document_device'] ?? 1);

        return $value > 0 ? min($value, 999) : 1;
    }

    private function buildIdentifierHash(string $senderPiva, string $issueDate, string $documentNumber, string $documentDevice): string
    {
        $normalizedDevice = preg_replace('/\D+/', '', $documentDevice) ?? '';
        $normalizedDevice = $normalizedDevice !== '' ? (string) ((int) $normalizedDevice) : '';

        return hash('sha256', implode('|', [
            preg_replace('/\D+/', '', $senderPiva) ?? '',
            trim($issueDate),
            strtoupper(trim($documentNumber)),
            $normalizedDevice,
        ]));
    }

    private function mapBillingDocumentTypeToTs(string $billingType): string
    {
        $billingType = trim(strtolower($billingType));

        return $billingType === 'receipt' ? 'D' : 'F';
    }

    private function mapPaymentMethodToTs(string $paymentMethod): string
    {
        $paymentMethod = trim(strtolower($paymentMethod));

        return $paymentMethod === 'cash' ? 'contanti' : 'tracciato';
    }

    /**
     * @param array<string, mixed> $billingDocument
     */
    private function resolveExpenseTypeCode(array $billingDocument, array $profile = []): string
    {
        $lineItems = json_decode((string) ($billingDocument['line_items_json'] ?? ''), true);
        if (is_array($lineItems) && $lineItems !== [] && $profile !== []) {
            $map = $this->tsProfiles->resolveServiceExpenseTypeMap($profile);
            $resolvedCodes = [];
            $allMapped = $map !== [];

            foreach ($lineItems as $lineItem) {
                if (!is_array($lineItem)) {
                    continue;
                }

                $description = preg_replace('/\s+/', ' ', trim((string) ($lineItem['description'] ?? ''))) ?? '';
                if ($description === '') {
                    continue;
                }

                $key = mb_strtolower($description);
                if (!isset($map[$key])) {
                    $allMapped = false;
                    break;
                }

                $resolvedCodes[$map[$key]] = true;
            }

            if ($allMapped && count($resolvedCodes) === 1) {
                return (string) array_key_first($resolvedCodes);
            }
        }

        $value = strtoupper(trim((string) ($billingDocument['ts_expense_type_code'] ?? '')));

        return $value !== '' ? $value : 'SP';
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableDecimal($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): ?string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }
}
