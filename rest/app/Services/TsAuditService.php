<?php

namespace App\Services;

use App\Models\TsDocumentEventModel;

class TsAuditService
{
    private TsDocumentEventModel $events;

    public function __construct(?TsDocumentEventModel $events = null)
    {
        $this->events = $events ?? new TsDocumentEventModel();
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function record(
        int $documentId,
        string $eventType,
        string $message,
        string $eventLevel = 'info',
        ?array $context = null,
        int $createdBy = 0
    ): bool {
        if ($documentId <= 0 || trim($eventType) === '' || trim($message) === '') {
            return false;
        }

        return $this->events->insert([
            'id_ts_document' => $documentId,
            'event_type' => trim($eventType),
            'event_level' => trim($eventLevel) !== '' ? trim($eventLevel) : 'info',
            'message' => trim($message),
            'context_json' => $context !== null
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'created_by' => $createdBy > 0 ? $createdBy : null,
        ]) !== false;
    }
}
