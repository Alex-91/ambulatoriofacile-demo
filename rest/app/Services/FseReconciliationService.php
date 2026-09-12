<?php

namespace App\Services;

/** Read-only diagnosis. Deliberately offers no unlock/retry/set-published operation. */
final class FseReconciliationService
{
    public function inspect(array $document, ?int $now = null): array
    {
        $state = (string) ($document['local_state'] ?? 'draft');
        $response = json_decode((string) ($document['last_response_json'] ?? ''), true);
        $uncertain = !empty($response['transport']['outcome_uncertain']) || str_contains((string) ($document['gateway_state'] ?? ''), 'UNCERTAIN');
        $updated = strtotime((string) ($document['updated_at'] ?? ''));
        $age = $updated === false ? null : max(0, ($now ?? time()) - $updated);
        $code = 'NO_PENDING_OPERATION'; $level = 'info';
        $message = 'Nessuna operazione bloccata da riconciliare.';
        if (in_array($state, ['preparing', 'checking_signature'], true)) {
            $code = $age === null || $age > 300 ? 'LOCAL_CHECK_STALE' : 'LOCAL_CHECK_RUNNING';
            $level = $code === 'LOCAL_CHECK_STALE' ? 'warning' : 'info';
            $message = $level === 'warning' ? 'Controllo locale fermo o senza data verificabile: richiedere assistenza e verificare processo, artefatti e audit. Non sbloccare modificando il database.' : 'Controllo locale in corso. Attendere e ricaricare la pagina.';
        } elseif (in_array($state, ['validating', 'publishing', 'deleting'], true)) {
            $code = $uncertain ? 'REMOTE_OUTCOME_UNCERTAIN' : 'REMOTE_OPERATION_PENDING';
            $level = 'warning';
            $message = 'Esito remoto da confermare. Non ripetere l’invio né assumere che il referto non sia arrivato. Conservare gli identificativi tecnici e verificare tramite il canale di assistenza previsto.';
        } elseif ($state === 'rejected' && FseDocumentLifecycle::isSealed($document)) {
            $code = 'SEALED_REJECTION'; $level = 'warning';
            $message = 'Referto consolidato scartato: l’originale resta immutabile. Una correzione crea una nuova versione locale.';
        }
        return [
            'code' => $code, 'level' => $level, 'message' => $message, 'retry_allowed' => false,
            'technical' => [
                'document_id' => (int) ($document['id_fse_document'] ?? 0),
                'workflow_id' => $this->identifier($document['workflow_instance_id'] ?? null),
                'x_cart_id' => $this->identifier($response['transport']['x_cart_id'] ?? null),
                'trace_id' => $this->identifier($document['trace_id'] ?? null),
                'http_status' => (int) ($document['gateway_http_status'] ?? 0),
            ],
        ];
    }

    private function identifier($value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._:^&=+@\/#-]{1,500}$/D', $value) ? $value : null;
    }
}
