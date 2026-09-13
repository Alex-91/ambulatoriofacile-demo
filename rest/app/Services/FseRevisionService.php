<?php

namespace App\Services;

/** Immutable local corrections. No Gateway calls and no production-enabling flags. */
class FseRevisionService
{
    public function __construct(
        private ?FseTenantDatabaseContextService $contexts = null,
        private ?FseTenantSchemaService $schema = null,
        private ?FseSecretsService $secrets = null,
        private ?FseArtifactValidationService $validation = null
    ) {
        $this->contexts ??= new FseTenantDatabaseContextService();
        $this->schema ??= new FseTenantSchemaService();
        $this->secrets ??= new FseSecretsService();
        $this->validation ??= new FseArtifactValidationService();
    }

    public function create(int $tenantId, int $sourceId, string $reason, int $userId = 0): int
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) throw new \RuntimeException('Indicare il motivo della correzione (massimo 2000 caratteri).');
        $status = $this->schema->ensureTenantSchemaReady($tenantId, true);
        if (!$this->schema->isReady($status)) throw new \RuntimeException('Installare le migration FSE prima di creare revisioni.');
        $context = $this->contexts->resolveTenantContext($tenantId);
        $db = $context['db'];
        $source = $context['documents']->find($sourceId);
        if (!is_array($source) || !FseDocumentLifecycle::canRevise($source)) {
            throw new \RuntimeException('La correzione richiede un originale consolidato, non eliminato né in elaborazione.');
        }
        $existing = $db->table('fse_documents')->select('id_fse_document')->where('previous_document_id', $sourceId)->get()->getRowArray();
        if ($existing) return (int) $existing['id_fse_document'];
        $identity = $this->identity($tenantId, $source);
        $row = $source;
        unset($row['id_fse_document']);
        // Preserve encrypted clinical content, but never inherit artifacts or remote transaction evidence.
        foreach (['cda_path', 'cda_sha256', 'unsigned_pdf_path', 'unsigned_pdf_sha256', 'signed_pdf_path', 'signed_pdf_sha256',
            'workflow_instance_id', 'trace_id', 'span_id', 'gateway_state', 'gateway_http_status', 'last_gateway_message',
            'last_response_json', 'validated_at', 'published_at', 'deleted_at', 'created_at', 'updated_at'] as $field) $row[$field] = null;
        $row['previous_document_id'] = $sourceId;
        $row['document_oid_root'] = $identity['document_oid_root'];
        $row['document_unique_id'] = 'AF.' . $tenantId . '.' . strtoupper(bin2hex(random_bytes(16)));
        $row['submission_id'] = 'SUB.' . $tenantId . '.' . strtoupper(bin2hex(random_bytes(16)));
        $row['version_number'] = (int) $source['version_number'] + 1;
        $row['local_state'] = 'draft';
        $row['revision_reason_enc'] = $this->secrets->encrypt($reason);
        $row['edit_token'] = bin2hex(random_bytes(16));
        $row['created_by'] = $row['updated_by'] = $userId ?: null;
        try {
            return FseLocalPersistenceService::write($db, function () use ($context, $row, $sourceId, $userId): int {
            $id = (int) $context['documents']->insert($row);
            if ($id <= 0) throw new \RuntimeException('Creazione revisione non riuscita.');
            $context['audit']->record($id, 'revision_created', 'Creata correzione locale: originale conservato, nessuna sostituzione sul FSE.',
                ['previous_document_id' => $sourceId, 'version_number' => $row['version_number']], $userId, required: true);
            return $id;
            });
        } catch (\Throwable $e) {
            // A concurrent request may have won the unique predecessor constraint.
            $existing = $db->table('fse_documents')->select('id_fse_document')->where('previous_document_id', $sourceId)->get()->getRowArray();
            if ($existing) return (int) $existing['id_fse_document'];
            throw new \RuntimeException('Creazione revisione non riuscita; nessun originale è stato modificato.');
        }
    }

    public function parentForCda(int $tenantId, array $document): ?array
    {
        if (empty($document['previous_document_id'])) {
            if ((int) ($document['version_number'] ?? 1) !== 1) throw new \RuntimeException('Storico FSE incompleto: manca la versione precedente.');
            return null;
        }
        $parent = $this->contexts->resolveTenantContext($tenantId)['documents']->find((int) $document['previous_document_id']);
        if (!is_array($parent) || !FseDocumentLifecycle::isSealed($parent)
            || ($parent['patient_cf_hash'] ?? '') !== ($document['patient_cf_hash'] ?? '')
            || ($parent['set_id'] ?? '') !== ($document['set_id'] ?? '')
            || (int) ($parent['version_number'] ?? 0) + 1 !== (int) ($document['version_number'] ?? 0)) {
            throw new \RuntimeException('Collegamento alla versione precedente non coerente.');
        }
        return $this->identity($tenantId, $parent);
    }

    /** Verify the actual immutable CDA; a changed profile must not rewrite its identifiers. */
    private function identity(int $tenantId, array $document): array
    {
        $cda = $this->validation->storedArtifact($tenantId, (int) $document['id_fse_document'], $document, 'cda');
        $xml = new \DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if (!$xml->loadXML($cda, LIBXML_NONET) || $xml->doctype !== null) throw new \RuntimeException('CDA originale non verificabile.');
            $xp = new \DOMXPath($xml); $xp->registerNamespace('h', 'urn:hl7-org:v3');
            $root = (string) $xp->evaluate('string(/h:ClinicalDocument/h:id/@root)');
            if ($root === '' || $xp->evaluate('string(/h:ClinicalDocument/h:id/@extension)') !== $document['document_unique_id']
                || $xp->evaluate('string(/h:ClinicalDocument/h:setId/@extension)') !== $document['set_id']
                || $xp->evaluate('string(/h:ClinicalDocument/h:setId/@root)') !== $root
                || (int) $xp->evaluate('string(/h:ClinicalDocument/h:versionNumber/@value)') !== (int) $document['version_number']
                || (!empty($document['document_oid_root']) && $document['document_oid_root'] !== $root)) {
                throw new \RuntimeException('Identificativi del CDA originale non coerenti con lo storico.');
            }
            return ['document_oid_root' => $root, 'document_unique_id' => $document['document_unique_id'],
                'set_id' => $document['set_id'], 'version_number' => (int) $document['version_number']];
        } finally {
            libxml_clear_errors(); libxml_use_internal_errors($previousErrors);
        }
    }

    public function history(int $tenantId, array $document): array
    {
        if (empty($document['set_id'])) return [];
        $db = $this->contexts->resolveTenantContext($tenantId)['db'];
        return $db->table('fse_documents')->select('id_fse_document, previous_document_id, version_number, local_state, created_at')
            ->where('set_id', $document['set_id'])->orderBy('version_number', 'ASC')->get(200)->getResultArray();
    }
}
