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
    public function record(int $documentId, string $type, string $message, array $context = [], int $userId = 0, string $level = 'info', bool $required = false): void
    {
        if ($documentId <= 0) {
            if ($required) throw new \RuntimeException('Documento richiesto per registrare l’operazione FSE.');
            return;
        }
        $safeContext = $this->redact($context);
        $id = $this->events->insert([
            'id_fse_document' => $documentId,
            'event_type' => substr($type, 0, 50),
            'event_level' => substr($level, 0, 16),
            'message' => $message,
            'context_json' => $safeContext === [] ? null : json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($required ? JSON_THROW_ON_ERROR : 0)),
            'created_by' => $userId > 0 ? $userId : null,
        ]);
        if ($required && (int) $id <= 0) throw new \RuntimeException('Registrazione dell’operazione FSE non riuscita.');
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
