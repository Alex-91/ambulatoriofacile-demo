<?php

namespace App\Services;

use App\Models\FseDocumentEventModel;

class FseAuditService
{
    private FseDocumentEventModel $events;

    public function __construct(FseDocumentEventModel $events)
    {
        $this->events = $events;
    }

    /** @param array<string,mixed> $context */
    public function record(int $documentId, string $type, string $message, array $context = [], int $userId = 0, string $level = 'info'): void
    {
        if ($documentId <= 0) {
            return;
        }
        $safeContext = $this->redact($context);
        $this->events->insert([
            'id_fse_document' => $documentId,
            'event_type' => substr($type, 0, 50),
            'event_level' => substr($level, 0, 16),
            'message' => $message,
            'context_json' => $safeContext === [] ? null : json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function redact(array $value): array
    {
        foreach ($value as $key => &$item) {
            if (preg_match('/token|authorization|passphrase|private.?key|patient|codice.?fiscale|\bcf\b/i', (string) $key)) {
                $item = '[REDACTED]';
            } elseif (is_array($item)) {
                $item = $this->redact($item);
            }
        }
        unset($item);
        return $value;
    }
}
