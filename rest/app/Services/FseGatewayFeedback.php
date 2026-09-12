<?php
namespace App\Services;

/** Operator guidance from fixed codes only: no remote detail, patient data or retry side effects. */
final class FseGatewayFeedback
{
    public static function describe(array $result, string $operation, string $state = '', string $gatewayState = ''): array
    {
        $status = (int) ($result['http_status'] ?? 0);
        $code = 'GATEWAY_REJECTED'; $severity = 'error';
        if (!empty($result['outcome_uncertain'])) {
            $code = 'OUTCOME_UNCERTAIN'; $severity = 'warning';
            $message = 'Esito Gateway da riconciliare. Non ripetere l’invio: richiedere assistenza con gli identificativi della transazione.';
        } elseif (empty($result['ok'])) {
            if (in_array($status, [401, 403], true)) {
                $code = 'ACCESS_DENIED';
                $message = 'Autenticazione o autorizzazione FSE rifiutata. Il referente tecnico deve verificare certificati, token e abilitazioni. Non modificare il referto per risolvere questo errore.';
            } elseif ($status === 422) {
                $code = 'CDA_SEMANTIC';
                $message = 'Il documento non supera i controlli di conformità CDA. Verificare i dati e le sezioni richieste prima di un nuovo tentativo.';
            } elseif ($status === 400 && ($result['error_category'] ?? '') === 'VOCABULARY') {
                $code = 'CDA_VOCABULARY';
                $message = 'Il Gateway non riconosce una codifica del documento. Richiedere la verifica delle terminologie; non sostituire codici clinici per tentativi.';
            } elseif ($status === 400) {
                $code = 'INVALID_REQUEST';
                $message = 'Richiesta FSE non valida. Il referente tecnico deve controllare struttura del documento e metadati; il testo remoto non viene mostrato per proteggere i dati.';
            } elseif ($status === 409) {
                $code = 'IDENTIFIER_CONFLICT';
                $message = 'Conflitto sugli identificativi FSE. Verificare la transazione esistente con l’assistenza prima di un nuovo invio; non creare identificativi sostitutivi per aggirare l’errore.';
            } elseif ($status === 429) {
                $code = 'RATE_LIMITED';
                $message = 'Il servizio ha limitato le richieste. Nessun reinvio automatico: attendere e verificare con il referente tecnico prima di riprovare.';
            } else {
                $message = 'Operazione FSE non accettata. Richiedere assistenza indicando stato HTTP e identificativi tecnici, senza inviare dati clinici o credenziali.';
            }
            $message .= ' HTTP ' . $status . '.';
        } elseif ($operation === 'status' && str_ends_with($gatewayState, '_BLOCKING_ERROR')) {
            $code = 'REMOTE_OPERATION_REJECTED';
            $message = 'Il Gateway segnala che l’operazione non è riuscita. Il documento originale resta conservato; verificare l’esito con il referente tecnico.';
        } elseif (in_array($state, ['publishing', 'deleting', 'validating'], true)) {
            $code = 'REMOTE_PENDING'; $severity = 'warning';
            $message = 'Richiesta ricevuta; esito finale ancora da confermare. Non ripetere l’invio e non considerare conclusa l’operazione sul FSE.';
        } else {
            $code = 'GATEWAY_ACCEPTED'; $severity = 'success';
            $message = match ($operation) {
                'validation' => 'Validazione Gateway completata. Il documento non è ancora pubblicato nel FSE.',
                'status' => $state === 'published' ? 'Pubblicazione confermata dal Gateway per la transazione verificata.'
                    : ($state === 'deleted' ? 'Cancellazione confermata dal Gateway; originale locale conservato.' : 'Stato Gateway verificato; nessuna nuova pubblicazione eseguita.'),
                default => 'Operazione Gateway accettata; verificare lo stato del documento.',
            };
        }
        return ['code' => $code, 'severity' => $severity, 'message' => $message, 'automatic_retry' => false];
    }
}
