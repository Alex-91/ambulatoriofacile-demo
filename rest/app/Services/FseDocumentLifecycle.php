<?php

namespace App\Services;

/** Local lifecycle policy. Never equate a local signature or validation with publication. */
final class FseDocumentLifecycle
{
    public static function isSealed(array $document): bool
    {
        return !empty($document['signed_pdf_path']) || !empty($document['signed_pdf_sha256'])
            || !empty($document['validated_at']) || !empty($document['published_at'])
            || in_array($document['local_state'] ?? '', ['validated', 'signed', 'published', 'deleted'], true);
    }

    public static function isEditable(array $document): bool
    {
        return in_array($document['local_state'] ?? 'draft', ['draft', 'ready_to_validate', 'rejected'], true)
            && !self::isSealed($document);
    }

    public static function canRevise(array $document): bool
    {
        return self::isSealed($document)
            && in_array($document['local_state'] ?? '', ['validated', 'signed', 'published', 'rejected'], true);
    }
}
