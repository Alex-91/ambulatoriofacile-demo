<?php
namespace App\Services;

/** Read-only bridge from the isolated synthetic application's signed artifacts to a separate rehearsal. */
class FseToscanaDocumentLab
{
    public function __construct(
        private ?FseSyntheticAppBoundary $boundary = null,
        private ?FseTenantDatabaseContextService $contexts = null,
        private ?FseArtifactValidationService $validation = null,
        private ?FseToscanaLabStore $store = null,
        private ?FseSecretsService $secrets = null
    ) {}

    public function import(int $tenant, int $documentId, int $actor): array
    {
        $this->boundary ??= new FseSyntheticAppBoundary();
        // Fail before constructing any tenant connector, opening a DB or reading clinical data.
        if (!$this->boundary->isActive() || $documentId <= 0 || $actor <= 0) throw new \RuntimeException('LAB_BOUNDARY');
        $this->contexts ??= new FseTenantDatabaseContextService();
        $context = $this->contexts->resolveTenantContext($tenant);
        $this->boundary->assertDatabase($tenant, $context['db']);
        $document = $context['documents']->find($documentId);
        if (!is_array($document)) throw new \RuntimeException('LAB_DOCUMENT');
        $proof = $this->proof($document);
        $this->secrets ??= new FseSecretsService();
        $document['author_cf'] = $this->secrets->decrypt((string) ($document['author_cf_enc'] ?? ''));
        $this->validation ??= new FseArtifactValidationService();
        $this->validation->assertForDispatch($tenant, $documentId, $document, true);
        // The private lab is single-host; detect intervening document changes during worker execution.
        $latest = $context['documents']->find($documentId);
        if (!is_array($latest) || $this->proof($latest) !== $proof) throw new \RuntimeException('LAB_SOURCE_CHANGED');
        $this->store ??= new FseToscanaLabStore();
        return $this->store->importSignedSnapshot($tenant, $actor, $proof);
    }

    private function proof(array $doc): array
    {
        if (($doc['local_state'] ?? '') !== 'signed' || !empty($doc['published_at']) || !empty($doc['deleted_at'])
            || !empty($doc['workflow_instance_id'])) throw new \RuntimeException('LAB_SOURCE_STATE');
        $snapshot = (string) ($doc['profile_snapshot_json'] ?? '');
        try { $profile = json_decode($snapshot, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { throw new \RuntimeException('LAB_PROFILE'); }
        if (!is_array($profile) || ($profile['access_mode'] ?? '') !== 'toscana_privati'
            || ($profile['environment'] ?? '') !== 'test'
            || (int) ($profile['id_fse_profile'] ?? 0) <= 0
            || (int) $profile['id_fse_profile'] !== (int) ($doc['id_fse_profile'] ?? 0)) throw new \RuntimeException('LAB_PROFILE');
        if (empty($doc['set_id']) || empty($doc['document_oid_root'])) throw new \RuntimeException('LAB_SOURCE_IDENTITY');
        return ['document_id' => (int) $doc['id_fse_document'], 'previous_id' => (int) ($doc['previous_document_id'] ?? 0),
            'version' => (int) ($doc['version_number'] ?? 0), 'profile_sha256' => hash('sha256', $snapshot),
            'set_sha256' => hash('sha256', $doc['document_oid_root'] . '|' . $doc['set_id']),
            'cda_sha256' => (string) ($doc['cda_sha256'] ?? ''),
            'signed_pdf_sha256' => (string) ($doc['signed_pdf_sha256'] ?? '')];
    }
}
