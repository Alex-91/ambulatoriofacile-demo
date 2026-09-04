<?php

namespace App\Services;

use App\Models\FseDocumentModel;

class FseDispatchService
{
    private FseTenantDatabaseContextService $contexts;
    private FseProfileService $profiles;
    private FseGatewayClient $gateway;

    public function __construct(?FseTenantDatabaseContextService $contexts = null, ?FseProfileService $profiles = null, ?FseGatewayClient $gateway = null)
    {
        $this->contexts = $contexts ?? new FseTenantDatabaseContextService();
        $this->profiles = $profiles ?? new FseProfileService();
        $this->gateway = $gateway ?? new FseGatewayClient();
    }

    /** @return array<string,mixed> */
    public function validate(int $tenantId, int $documentId, int $userId = 0): array
    {
        return $this->execute($tenantId, $documentId, $userId, 'validation');
    }

    /** @return array<string,mixed> */
    public function publish(int $tenantId, int $documentId, int $userId = 0): array
    {
        return $this->execute($tenantId, $documentId, $userId, 'publish');
    }

    /** @return array<string,mixed> */
    public function refreshStatus(int $tenantId, int $documentId, int $userId = 0): array
    {
        return $this->execute($tenantId, $documentId, $userId, 'status');
    }

    /** @return array<string,mixed> */
    public function delete(int $tenantId, int $documentId, int $userId = 0): array
    {
        return $this->execute($tenantId, $documentId, $userId, 'delete');
    }

    /** @return array<string,mixed> */
    private function execute(int $tenantId, int $documentId, int $userId, string $operation): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var FseDocumentModel $documents */ $documents = $context['documents'];
        /** @var FseAuditService $audit */ $audit = $context['audit'];
        $db = $context['db'];
        $document = $documents->find($documentId);
        if (!is_array($document)) throw new \RuntimeException('Referto FSE non trovato.');
        $profile = $this->profiles->runtimeProfileForTenant($tenantId);
        if (empty($profile['is_enabled'])) throw new \RuntimeException('Profilo FSE disattivato.');
        $runtime = $this->runtime($document);
        $previousState = (string) ($document['local_state'] ?? 'draft');
        $stateLocked = false;
        try {
        if ($operation === 'validation') {
            if (!in_array($previousState, ['ready_to_validate', 'rejected'], true)) throw new \RuntimeException('Il PDF/CDA non è nello stato previsto per la validazione.');
            $file = (string) ($document['unsigned_pdf_path'] ?? '');
            if (!is_file($file)) throw new \RuntimeException('Genera prima il PDF con CDA da validare.');
            $this->lockState($db, $documentId, [$previousState], 'validating');
            $stateLocked = true;
            $result = $this->gateway->validate($profile, $runtime, $file);
            $successState = 'validated';
        } elseif ($operation === 'publish') {
            $file = (string) ($document['signed_pdf_path'] ?? '');
            if (!is_file($file)) throw new \RuntimeException('Carica prima il PDF firmato PAdES.');
            if ((string) $document['local_state'] !== 'signed' || empty($document['validated_at'])) throw new \RuntimeException('Il referto deve essere validato e poi firmato prima della pubblicazione.');
            $this->lockState($db, $documentId, ['signed'], 'publishing');
            $stateLocked = true;
            $result = $this->gateway->create($profile, $runtime, $file);
            $successState = 'publishing';
        } elseif ($operation === 'status') {
            $file = (string) ($document['signed_pdf_path'] ?? $document['unsigned_pdf_path'] ?? '');
            if (!is_file($file)) throw new \RuntimeException('Artefatto FSE non disponibile.');
            $result = $this->gateway->status($profile, $runtime, $file);
            $successState = (string) $document['local_state'];
        } else {
            $file = (string) ($document['signed_pdf_path'] ?? '');
            if (!is_file($file)) throw new \RuntimeException('PDF FSE pubblicato non disponibile.');
            if ((string) $document['local_state'] !== 'published') throw new \RuntimeException('Solo un referto pubblicato può essere eliminato dal FSE.');
            $this->lockState($db, $documentId, ['published'], 'deleting');
            $stateLocked = true;
            $result = $this->gateway->delete($profile, $runtime, $file);
            $successState = 'deleting';
        }
        } catch (\Throwable $e) {
            if ($stateLocked) {
                $documents->update($documentId, ['local_state' => $previousState, 'last_gateway_message' => $e->getMessage()]);
                $audit->record($documentId, 'gateway_' . $operation, $e->getMessage(), [], $userId, 'error');
            }
            throw $e;
        }

        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $workflow = trim((string) ($payload['workflowInstanceId'] ?? $document['workflow_instance_id'] ?? ''));
        $gatewayState = strtoupper(trim((string) ($payload['eventStatus'] ?? $payload['status'] ?? '')));
        if ($operation === 'status' && is_array($payload)) {
            $events = is_array($payload[0] ?? null) ? $payload : (is_array($payload['transactionData'] ?? null) ? $payload['transactionData'] : []);
            $last = is_array(end($events)) ? end($events) : [];
            $gatewayState = strtoupper(trim((string) ($last['eventStatus'] ?? $last['status'] ?? $gatewayState)));
            if (in_array($gatewayState, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'OK'], true)) {
                $successState = str_contains(strtoupper((string) ($document['gateway_state'] ?? '')), 'DELETE') ? 'deleted' : 'published';
            } elseif (in_array($gatewayState, ['ERROR', 'KO', 'FAILED', 'BLOCKING_ERROR'], true)) {
                $successState = 'rejected';
            }
        }
        if ($operation === 'delete' && !empty($result['ok'])) $gatewayState = 'DELETE_ACCEPTED';
        $record = [
            'local_state' => !empty($result['ok']) ? $successState : ($operation === 'status' ? $previousState : ($operation === 'delete' ? 'published' : 'rejected')),
            'workflow_instance_id' => $workflow ?: null,
            'trace_id' => $payload['traceID'] ?? $document['trace_id'] ?? null, 'span_id' => $payload['spanID'] ?? $document['span_id'] ?? null,
            'gateway_state' => $gatewayState ?: ($operation . '_' . (!empty($result['ok']) ? 'accepted' : 'failed')),
            'gateway_http_status' => (int) ($result['http_status'] ?? 0), 'last_gateway_message' => (string) ($result['message'] ?? ''),
            'last_response_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => $userId ?: null,
        ];
        if ($record['local_state'] === 'published') $record['published_at'] = $document['published_at'] ?? date('Y-m-d H:i:s');
        if ($operation === 'validation' && !empty($result['ok'])) $record['validated_at'] = date('Y-m-d H:i:s');
        if ($record['local_state'] === 'deleted') $record['deleted_at'] = date('Y-m-d H:i:s');
        $documents->update($documentId, $record);
        $audit->record($documentId, 'gateway_' . $operation, (string) $record['last_gateway_message'], ['http_status' => $record['gateway_http_status'], 'workflow_instance_id' => $workflow, 'gateway_state' => $record['gateway_state']], $userId, !empty($result['ok']) ? 'info' : 'error');
        $result['document'] = $documents->find($documentId);
        return $result;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function runtime(array $document): array
    {
        $secrets = new FseSecretsService();
        foreach (['patient_cf', 'author_cf'] as $field) {
            $document[$field] = (string) ($secrets->decrypt((string) ($document[$field . '_enc'] ?? '')) ?? '');
        }
        return $document;
    }

    /** @param list<string> $allowed */
    private function lockState(\CodeIgniter\Database\BaseConnection $db, int $documentId, array $allowed, string $next): void
    {
        $db->table('fse_documents')->where('id_fse_document', $documentId)->whereIn('local_state', $allowed)
            ->update(['local_state' => $next, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($db->affectedRows() !== 1) throw new \RuntimeException('Il referto è già in elaborazione da un’altra richiesta.');
    }
}
