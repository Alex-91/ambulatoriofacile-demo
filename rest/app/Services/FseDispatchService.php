<?php

namespace App\Services;

use App\Models\FseDocumentModel;

class FseDispatchService
{
    private FseTenantDatabaseContextService $contexts;
    private FseProfileService $profiles;
    private FseGatewayClient $gateway;
    private FseArtifactValidationService $validation;

    public function __construct(?FseTenantDatabaseContextService $contexts = null, ?FseProfileService $profiles = null, ?FseGatewayClient $gateway = null, ?FseArtifactValidationService $validation = null)
    {
        $this->contexts = $contexts ?? new FseTenantDatabaseContextService();
        $this->profiles = $profiles ?? new FseProfileService();
        $this->gateway = $gateway ?? new FseGatewayClient();
        $this->validation = $validation ?? new FseArtifactValidationService();
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
        if (!empty($document['previous_document_id'])) throw new \RuntimeException('Revisione locale: sostituzione sul FSE non ancora abilitata. Non inviare come nuovo documento.');
        $profile = $this->profiles->runtimeProfileForDocument($tenantId, $document);
        if (empty($document['profile_snapshot_json'])) throw new \RuntimeException('Configurazione storica del referto mancante: invio bloccato, verificare il documento esistente.');
        if (empty($profile['is_enabled'])) throw new \RuntimeException('Profilo FSE disattivato.');
        if (($profile['access_mode'] ?? '') === 'toscana_privati') throw new \RuntimeException('Workflow Toscana in preparazione: non ancora abilitato per gli invii operativi.');
        $runtime = $this->runtime($document);
        $previousState = (string) ($document['local_state'] ?? 'draft');
        $stateLocked = false;
        $requestStarted = false;
        try {
        if ($operation === 'validation') {
            if (FseDocumentLifecycle::isSealed($document)) throw new \RuntimeException('Il referto consolidato non può essere rivalidato o sovrascritto: creare una correzione.');
            if (!in_array($previousState, ['ready_to_validate', 'rejected'], true)) throw new \RuntimeException('Il PDF/CDA non è nello stato previsto per la validazione.');
            $file = (string) ($document['unsigned_pdf_path'] ?? '');
            if (!is_file($file)) throw new \RuntimeException('Genera prima il PDF con CDA da validare.');
            $this->lockState($db, $documentId, [$previousState], 'validating');
            $stateLocked = true;
            $this->validation->assertForDispatch($tenantId, $documentId, $runtime, false);
            $requestStarted = true;
            $result = $this->gateway->validate($profile, $runtime, $file);
            $successState = 'validated';
        } elseif ($operation === 'publish') {
            $file = (string) ($document['signed_pdf_path'] ?? '');
            if (!is_file($file)) throw new \RuntimeException('Carica prima il PDF firmato PAdES.');
            if ((string) $document['local_state'] !== 'signed' || empty($document['validated_at'])) throw new \RuntimeException('Il referto deve essere validato e poi firmato prima della pubblicazione.');
            $this->lockState($db, $documentId, ['signed'], 'publishing');
            $stateLocked = true;
            $this->validation->assertForDispatch($tenantId, $documentId, $runtime, true);
            $requestStarted = true;
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
            $requestStarted = true;
            $result = $this->gateway->delete($profile, $runtime, $file);
            $successState = 'deleting';
        }
        } catch (\Throwable $e) {
            if ($stateLocked) {
                if ($requestStarted) {
                    $message = 'Esecuzione Gateway interrotta: esito da riconciliare, nessun reinvio automatico.';
                    $documents->update($documentId, ['workflow_instance_id'=>null, 'trace_id'=>null, 'span_id'=>null, 'gateway_http_status'=>0, 'gateway_state'=>strtoupper($operation) . '_UNCERTAIN',
                        'last_gateway_message'=>$message, 'last_response_json'=>json_encode(['transport'=>['outcome_uncertain'=>true]])]);
                    $audit->record($documentId, 'gateway_' . $operation, $message, [], $userId, 'error');
                    throw new \RuntimeException($message);
                }
                $documents->update($documentId, ['local_state' => $previousState, 'last_gateway_message' => 'Controllo locale non superato; nessun invio eseguito.']);
                $audit->record($documentId, 'gateway_' . $operation, 'Controllo locale non superato; nessun invio eseguito.', [], $userId, 'error');
            }
            throw $e;
        }

        $payload = is_array($result['payload'] ?? null) ? FseGatewayResponse::technicalPayload($result['payload']) : [];
        // A publication must never inherit the preceding validation's workflow.
        $workflow = trim((string) ($operation === 'status' ? ($document['workflow_instance_id'] ?? '') : ($payload['workflowInstanceId'] ?? '')));
        $gatewayState = strtoupper(trim((string) ($payload['eventStatus'] ?? $payload['status'] ?? '')));
        if ($operation === 'status' && !empty($result['ok']) && in_array($previousState, ['publishing', 'deleting'], true)) {
            $resolved = (new FseGatewayStatusService())->resolve($document, $payload);
            $successState = $resolved['state']; $gatewayState = $resolved['gateway_state'];
        }
        if ($operation === 'delete' && !empty($result['ok'])) $gatewayState = 'DELETE_ACCEPTED';
        $uncertain = !empty($result['outcome_uncertain']);
        // This operator workflow calls VALIDATION, not preparatory VERIFICA (HTTP 200).
        if ($operation === 'validation' && !empty($result['ok']) && (int) ($result['http_status'] ?? 0) !== 201) {
            $uncertain = true; $result['ok'] = false; $result['outcome_uncertain'] = true;
        }
        if ($stateLocked && !empty($result['ok']) && $workflow === '') {
            $uncertain = true; $result['ok'] = false; $result['outcome_uncertain'] = true;
        }
        $result['payload'] = $payload;
        $feedback = FseGatewayFeedback::describe($result, $operation, $successState, $gatewayState);
        $result['message'] = $feedback['message'];
        $result['feedback'] = $feedback;
        $diagnostics = [
            'x_cart_id' => $result['x_cart_id'] ?? null,
            'outcome_uncertain' => $uncertain,
            'feedback_code' => $feedback['code'], 'feedback_severity' => $feedback['severity'],
        ];
        if ($uncertain && $operation !== 'status') $gatewayState = strtoupper($operation) . '_UNCERTAIN';
        $record = [
            'local_state' => !empty($result['ok']) ? $successState : ($operation === 'status' ? $previousState : ($operation === 'delete' ? 'published' : 'rejected')),
            'workflow_instance_id' => $workflow ?: null,
            'trace_id' => $payload['traceID'] ?? $payload['traceId'] ?? ($operation === 'status' ? ($document['trace_id'] ?? null) : null),
            'span_id' => $payload['spanID'] ?? $payload['spanId'] ?? ($operation === 'status' ? ($document['span_id'] ?? null) : null),
            'gateway_state' => $gatewayState ?: ($operation . '_' . (!empty($result['ok']) ? 'accepted' : 'failed')),
            'gateway_http_status' => (int) ($result['http_status'] ?? 0), 'last_gateway_message' => (string) ($result['message'] ?? ''),
            'last_response_json' => json_encode(['payload' => $payload, 'transport' => $diagnostics], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by' => $userId ?: null,
        ];
        // Keep in-flight writes locked until their actual outcome has been reconciled.
        if ($uncertain && $stateLocked) {
            $record['local_state'] = ['validation' => 'validating', 'publish' => 'publishing', 'delete' => 'deleting'][$operation];
        }
        if ($record['local_state'] === 'published') $record['published_at'] = $document['published_at'] ?? date('Y-m-d H:i:s');
        if ($operation === 'validation' && !empty($result['ok'])) $record['validated_at'] = date('Y-m-d H:i:s');
        if ($record['local_state'] === 'deleted') $record['deleted_at'] = date('Y-m-d H:i:s');
        if ($operation === 'status') {
            // A delayed poll must not overwrite a newer operation started on another request.
            $record['updated_at'] = date('Y-m-d H:i:s');
            $db->table('fse_documents')->where('id_fse_document', $documentId)->where('local_state', $previousState)
                ->where('workflow_instance_id', $document['workflow_instance_id'] ?? null)->update($record);
            if ($db->affectedRows() !== 1) throw new \RuntimeException('Stato FSE aggiornato da un’altra richiesta: ricaricare la pagina.');
        } else {
            $documents->update($documentId, $record);
        }
        $audit->record($documentId, 'gateway_' . $operation, (string) $record['last_gateway_message'], ['http_status' => $record['gateway_http_status'], 'workflow_instance_id' => $workflow, 'gateway_state' => $record['gateway_state']] + $diagnostics, $userId, $feedback['severity'] === 'success' ? 'info' : $feedback['severity']);
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
