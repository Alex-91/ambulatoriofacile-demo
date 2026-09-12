<?php

namespace App\Services;

/** Conservative national status handling. Regional terminal contracts remain disabled. */
final class FseGatewayStatusService
{
    public function resolve(array $document, array $payload): array
    {
        $state = (string) ($document['local_state'] ?? 'draft');
        $pending = ['state'=>$state, 'gateway_state'=>'STATUS_NOT_FINAL'];
        $workflow = (string) ($document['workflow_instance_id'] ?? '');
        if (!in_array($state, ['publishing', 'deleting'], true) || $workflow === '') return $pending;
        if (isset($payload['workflowInstanceId']) && $payload['workflowInstanceId'] !== $workflow) return $pending;
        $events = array_is_list($payload) ? $payload : ($payload['transactionData'] ?? $payload['events'] ?? []);
        if (!is_array($events) || !$events) return $pending;
        foreach ($events as $event) {
            // Every terminal assertion must be tied to the pending workflow, not a preceding validation.
            if (!is_array($event) || ($event['workflowInstanceId'] ?? $payload['workflowInstanceId'] ?? null) !== $workflow) return $pending;
        }
        $terminal = $state === 'deleting' ? 'INI_DELETE' : 'UAR_FINAL_STATUS';
        $matching = array_values(array_filter($events, static fn($e) => ($e['eventType'] ?? '') === $terminal));
        if (count($matching) !== 1) return $pending;
        $status = $matching[0]['eventStatus'] ?? '';
        if ($status === 'SUCCESS') {
            // Do not hide another failure in the same transaction behind a successful final event.
            foreach ($events as $event) if (($event['eventStatus'] ?? '') === 'BLOCKING_ERROR') return $pending;
            return ['state'=>$state === 'deleting' ? 'deleted' : 'published', 'gateway_state'=>$terminal . '_SUCCESS'];
        }
        if ($state === 'deleting' && $status === 'BLOCKING_ERROR') return ['state'=>'published', 'gateway_state'=>'INI_DELETE_BLOCKING_ERROR'];
        // A partial publication can require reconciliation even after a blocking error.
        return $pending;
    }
}
