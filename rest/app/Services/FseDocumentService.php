<?php

namespace App\Services;

use App\Config\Fse2;
use App\Models\FseDocumentModel;
use CodeIgniter\Database\BaseConnection;

class FseDocumentService
{
    private FseTenantDatabaseContextService $contexts;
    private FseProfileService $profiles;
    private FseSecretsService $secrets;
    private FseCdaRsaBuilderService $cda;
    private FsePdfEnvelopeService $pdf;
    private FseStorageService $storage;
    private Fse2 $config;

    public function __construct(?FseTenantDatabaseContextService $contexts = null, ?FseProfileService $profiles = null, ?FseSecretsService $secrets = null, ?FseCdaRsaBuilderService $cda = null, ?FsePdfEnvelopeService $pdf = null, ?FseStorageService $storage = null, ?Fse2 $config = null)
    {
        $this->contexts = $contexts ?? new FseTenantDatabaseContextService();
        $this->profiles = $profiles ?? new FseProfileService();
        $this->secrets = $secrets ?? new FseSecretsService();
        $this->cda = $cda ?? new FseCdaRsaBuilderService();
        $this->pdf = $pdf ?? new FsePdfEnvelopeService();
        $this->storage = $storage ?? new FseStorageService();
        $this->config = $config ?? config(Fse2::class);
    }

    /** @return array<string,mixed> */
    public function buildDashboardForTenant(int $tenantId): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var BaseConnection $db */ $db = $context['db'];
        if (!$db->tableExists('fse_documents')) {
            return ['table_available' => false, 'total' => 0, 'by_state' => [], 'recent' => []];
        }
        $byState = [];
        $total = 0;
        foreach ($db->table('fse_documents')->select('local_state, COUNT(*) total')->groupBy('local_state')->get()->getResultArray() as $row) {
            $byState[(string) $row['local_state']] = (int) $row['total'];
            $total += (int) $row['total'];
        }
        return ['table_available' => true, 'total' => $total, 'by_state' => $byState, 'recent' => $this->listRows($db, 8)];
    }

    /** @return array<string,mixed> */
    public function listForTenant(int $tenantId, int $limit = 80): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var BaseConnection $db */ $db = $context['db'];
        return [
            'table_available' => $db->tableExists('fse_documents'),
            'documents' => $db->tableExists('fse_documents') ? $this->listRows($db, $limit) : [],
            'state_labels' => $this->config->stateLabels,
        ];
    }

    /** @return array<string,mixed> */
    public function buildFormContext(int $tenantId, int $documentId = 0): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var FseDocumentModel $documents */ $documents = $context['documents'];
        $document = $documentId > 0 ? $documents->find($documentId) : null;
        return [
            'document' => $this->editableDocument(is_array($document) ? $document : []),
            'events' => $documentId > 0 ? $context['events']->listForDocument($documentId) : [],
            'profile' => $this->profiles->getDefaultProfileForTenant($tenantId),
            'state_labels' => $this->config->stateLabels,
            'administrative_requests' => $this->config->administrativeRequests,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveDraftForTenant(int $tenantId, array $payload, int $userId = 0): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var BaseConnection $db */ $db = $context['db'];
        /** @var FseDocumentModel $documents */ $documents = $context['documents'];
        /** @var FseAuditService $audit */ $audit = $context['audit'];
        if (!$db->tableExists('fse_documents')) {
            throw new \RuntimeException('Esegui le migration FSE prima di creare referti.');
        }
        $id = max(0, (int) ($payload['id_fse_document'] ?? 0));
        $current = $id > 0 ? $documents->find($id) : null;
        if ($id > 0 && !is_array($current)) {
            throw new \RuntimeException('Referto FSE non trovato.');
        }
        if (in_array((string) ($current['local_state'] ?? ''), ['validating', 'publishing', 'deleting', 'published', 'deleted'], true)) {
            throw new \RuntimeException('Un referto in elaborazione, pubblicato o eliminato non può essere sovrascritto.');
        }
        $profile = $this->profiles->runtimeProfileForTenant($tenantId);
        $plain = $this->normalizeClinicalPayload($payload, $profile);
        $errors = $this->validateClinicalPayload($plain);
        if ($errors !== []) {
            throw new \RuntimeException(implode(' ', $errors));
        }

        $unique = (string) ($current['document_unique_id'] ?? '');
        if ($unique === '') {
            $unique = 'AF.' . $tenantId . '.' . gmdate('YmdHis') . '.' . strtoupper(bin2hex(random_bytes(4)));
        }
        $record = [
            'id_fse_profile' => (int) ($profile['id_fse_profile'] ?? 0),
            'id_client' => (int) ($payload['id_client'] ?? 0) ?: null,
            'source_type' => 'manual', 'local_state' => 'draft', 'document_type' => 'RSA',
            'document_title' => $plain['document_title'], 'loinc_code' => $plain['loinc_code'],
            'loinc_display_name' => $plain['loinc_display_name'], 'document_unique_id' => $unique,
            'set_id' => (string) ($current['set_id'] ?? $unique),
            'submission_id' => 'SUB.' . $tenantId . '.' . gmdate('YmdHis') . '.' . strtoupper(bin2hex(random_bytes(3))),
            'version_number' => max(1, (int) ($current['version_number'] ?? 1)),
            'patient_cf_enc' => $this->enc($plain['patient_cf']), 'patient_cf_hash' => hash('sha256', $plain['patient_cf']),
            'patient_first_name_enc' => $this->enc($plain['patient_first_name']), 'patient_last_name_enc' => $this->enc($plain['patient_last_name']),
            'patient_birth_date_enc' => $this->enc($plain['patient_birth_date']), 'patient_gender' => $plain['patient_gender'],
            'patient_email_enc' => $this->enc($plain['patient_email']), 'patient_address_enc' => $this->enc($plain['patient_address']),
            'patient_city_enc' => $this->enc($plain['patient_city']), 'author_cf_enc' => $this->enc($plain['author_cf']),
            'author_cf_hash' => hash('sha256', $plain['author_cf']), 'author_first_name_enc' => $this->enc($plain['author_first_name']),
            'author_last_name_enc' => $this->enc($plain['author_last_name']), 'service_start' => $plain['service_start'],
            'service_end' => $plain['service_end'] ?: null, 'reason_text_enc' => $this->enc($plain['reason_text']),
            'history_text_enc' => $this->enc($plain['history_text']), 'findings_text_enc' => $this->enc($plain['findings_text']),
            'report_text_enc' => $this->enc($plain['report_text']), 'diagnosis_text_enc' => $this->enc($plain['diagnosis_text']),
            'conclusions_text_enc' => $this->enc($plain['conclusions_text']), 'patient_consent' => $plain['patient_consent'] ? 1 : 0,
            'access_rules_json' => null, 'administrative_request' => $plain['administrative_request'],
            'cda_path' => null, 'cda_sha256' => null, 'unsigned_pdf_path' => null, 'unsigned_pdf_sha256' => null,
            'signed_pdf_path' => null, 'signed_pdf_sha256' => null, 'workflow_instance_id' => null,
            'gateway_state' => null, 'gateway_http_status' => null, 'last_gateway_message' => null,
            'last_response_json' => null, 'validated_at' => null, 'updated_by' => $userId > 0 ? $userId : null,
        ];
        if (!$current) {
            $record['created_by'] = $userId > 0 ? $userId : null;
            $id = (int) $documents->insert($record);
        } else {
            $documents->update($id, $record);
        }
        if ($id <= 0) {
            throw new \RuntimeException('Salvataggio referto FSE non riuscito.');
        }
        $audit->record($id, 'draft_saved', 'Bozza clinica FSE salvata.', [], $userId);
        return $this->editableDocument($documents->find($id) ?? []);
    }

    /** @return array<string,mixed> */
    public function prepareForSignature(int $tenantId, int $documentId, int $userId = 0): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var FseDocumentModel $documents */ $documents = $context['documents'];
        /** @var FseAuditService $audit */ $audit = $context['audit'];
        $stored = $documents->find($documentId);
        if (!is_array($stored) || (string) ($stored['local_state'] ?? '') === 'deleted') {
            throw new \RuntimeException('Referto FSE non disponibile.');
        }
        $profile = $this->profiles->runtimeProfileForTenant($tenantId);
        $data = $this->editableDocument($stored) + [
            'document_oid_root' => $profile['document_oid_root'] ?? '', 'facility_name' => $profile['facility_name'] ?? '',
            'facility_code' => $profile['facility_code'] ?? '', 'facility_oid' => $profile['facility_oid'] ?? '',
        ];
        $cda = $this->cda->build($data);
        $xml = new \DOMDocument();
        if (!$xml->loadXML($cda, LIBXML_NONET)) {
            throw new \RuntimeException('Il CDA generato non è XML valido.');
        }
        $pdf = $this->pdf->build($cda, $data);
        $cdaPath = $this->storage->store($tenantId, $documentId, 'cda.xml', $cda);
        $pdfPath = $this->storage->store($tenantId, $documentId, 'referto-da-firmare.pdf', $pdf);
        $documents->update($documentId, [
            'local_state' => 'ready_to_validate', 'cda_path' => $cdaPath, 'cda_sha256' => hash('sha256', $cda),
            'unsigned_pdf_path' => $pdfPath, 'unsigned_pdf_sha256' => hash('sha256', $pdf), 'updated_by' => $userId ?: null,
            'validated_at' => null,
        ]);
        $audit->record($documentId, 'artifacts_prepared', 'CDA RSA e PDF con CDA allegato generati; firma PAdES richiesta.', ['cda_sha256' => hash('sha256', $cda), 'pdf_sha256' => hash('sha256', $pdf)], $userId);
        return $this->editableDocument($documents->find($documentId) ?? []);
    }

    /** @return array<string,mixed> */
    public function acceptSignedPdf(int $tenantId, int $documentId, string $contents, int $userId = 0): array
    {
        if (strlen($contents) < 100 || strlen($contents) > $this->config->maxPdfBytes || !str_starts_with($contents, '%PDF-')) {
            throw new \RuntimeException('Il file caricato non è un PDF valido o supera il limite configurato.');
        }
        if (strpos($contents, '/ByteRange') === false || strpos($contents, '/Contents') === false) {
            throw new \RuntimeException('Firma digitale non rilevata: carica il PDF firmato PAdES.');
        }
        if (strpos($contents, 'cda.xml') === false && strpos($contents, '/EmbeddedFiles') === false) {
            throw new \RuntimeException('Il PDF firmato non contiene il CDA cda.xml allegato.');
        }
        $context = $this->contexts->resolveTenantContext($tenantId);
        /** @var FseDocumentModel $documents */ $documents = $context['documents'];
        /** @var FseAuditService $audit */ $audit = $context['audit'];
        $document = $documents->find($documentId);
        if (!is_array($document)) {
            throw new \RuntimeException('Referto FSE non trovato.');
        }
        if (empty($document['validated_at']) || (string) ($document['local_state'] ?? '') !== 'validated') {
            throw new \RuntimeException('Valida prima il PDF/CDA sul Gateway, poi applica la firma PAdES.');
        }
        $path = $this->storage->store($tenantId, $documentId, 'referto-firmato.pdf', $contents);
        $documents->update($documentId, ['local_state' => 'signed', 'signed_pdf_path' => $path, 'signed_pdf_sha256' => hash('sha256', $contents), 'updated_by' => $userId ?: null]);
        $audit->record($documentId, 'signed_pdf_uploaded', 'PDF firmato PAdES acquisito.', ['sha256' => hash('sha256', $contents)], $userId);
        return $this->editableDocument($documents->find($documentId) ?? []);
    }

    /** @return array{path:string,name:string,mime:string} */
    public function downloadArtifact(int $tenantId, int $documentId, string $kind): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        $document = $context['documents']->find($documentId);
        $map = ['cda' => ['cda_path', 'cda.xml', 'application/xml'], 'unsigned' => ['unsigned_pdf_path', 'referto-da-firmare.pdf', 'application/pdf'], 'signed' => ['signed_pdf_path', 'referto-firmato.pdf', 'application/pdf']];
        if (!is_array($document) || !isset($map[$kind])) {
            throw new \RuntimeException('Artefatto FSE non trovato.');
        }
        [$field, $name, $mime] = $map[$kind];
        return ['path' => $this->storage->assertStoredPath((string) ($document[$field] ?? ''), $tenantId, $documentId), 'name' => $name, 'mime' => $mime];
    }

    /** @return array<string,mixed> */
    public function runtimeDocument(int $tenantId, int $documentId): array
    {
        $context = $this->contexts->resolveTenantContext($tenantId);
        $document = $context['documents']->find($documentId);
        if (!is_array($document)) {
            throw new \RuntimeException('Referto FSE non trovato.');
        }
        return $this->editableDocument($document);
    }

    /** @return array<int,array<string,mixed>> */
    private function listRows(BaseConnection $db, int $limit): array
    {
        $rows = $db->table('fse_documents')->select('id_fse_document, local_state, document_type, document_title, service_start, patient_first_name_enc, patient_last_name_enc, workflow_instance_id, gateway_state, published_at, created_at, updated_at')->orderBy('id_fse_document', 'DESC')->get(max(1, min(200, $limit)))->getResultArray();
        foreach ($rows as &$row) {
            $row['patient_name'] = trim($this->dec((string) ($row['patient_last_name_enc'] ?? '')) . ' ' . $this->dec((string) ($row['patient_first_name_enc'] ?? '')));
            unset($row['patient_first_name_enc'], $row['patient_last_name_enc']);
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $profile @return array<string,mixed> */
    private function normalizeClinicalPayload(array $payload, array $profile): array
    {
        $s = static fn(string $key, int $max = 20000): string => substr(trim((string) ($payload[$key] ?? '')), 0, $max);
        return [
            'document_title' => $s('document_title', 200) ?: 'Referto di Specialistica Ambulatoriale',
            'loinc_code' => $s('loinc_code', 30) ?: '11488-4', 'loinc_display_name' => $s('loinc_display_name', 160) ?: 'Nota di consulto',
            'patient_cf' => strtoupper(preg_replace('/\s+/', '', $s('patient_cf', 16)) ?? ''), 'patient_first_name' => $s('patient_first_name', 100),
            'patient_last_name' => $s('patient_last_name', 100), 'patient_birth_date' => $s('patient_birth_date', 10),
            'patient_gender' => strtoupper($s('patient_gender', 2)), 'patient_email' => $s('patient_email', 190),
            'patient_address' => $s('patient_address', 250), 'patient_city' => $s('patient_city', 120),
            'author_cf' => (string) ($profile['author_cf'] ?? ''), 'author_first_name' => (string) ($profile['author_first_name'] ?? ''),
            'author_last_name' => (string) ($profile['author_last_name'] ?? ''), 'service_start' => $s('service_start', 30),
            'service_end' => $s('service_end', 30), 'reason_text' => $s('reason_text'), 'history_text' => $s('history_text'),
            'findings_text' => $s('findings_text'), 'report_text' => $s('report_text'), 'diagnosis_text' => $s('diagnosis_text'),
            'conclusions_text' => $s('conclusions_text'), 'patient_consent' => !empty($payload['patient_consent']),
            'administrative_request' => strtoupper($s('administrative_request', 20)) ?: 'NOSSN',
        ];
    }

    /** @param array<string,mixed> $data @return list<string> */
    private function validateClinicalPayload(array $data): array
    {
        $errors = [];
        foreach (['patient_cf', 'patient_first_name', 'patient_last_name', 'patient_birth_date', 'patient_gender', 'author_cf', 'author_first_name', 'author_last_name', 'service_start', 'report_text'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') $errors[] = 'Campo clinico obbligatorio mancante: ' . $field . '.';
        }
        if (!preg_match('/^[A-Z0-9]{11,16}$/', (string) $data['patient_cf'])) $errors[] = 'Codice fiscale paziente non valido.';
        if (!in_array((string) $data['patient_gender'], ['M', 'F', 'UN'], true)) $errors[] = 'Sesso amministrativo non valido.';
        if (!in_array((string) $data['administrative_request'], array_keys($this->config->administrativeRequests), true)) $errors[] = 'Regime amministrativo non supportato.';
        return $errors;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function editableDocument(array $row): array
    {
        foreach (['patient_cf', 'patient_first_name', 'patient_last_name', 'patient_birth_date', 'patient_email', 'patient_address', 'patient_city', 'author_cf', 'author_first_name', 'author_last_name', 'reason_text', 'history_text', 'findings_text', 'report_text', 'diagnosis_text', 'conclusions_text'] as $field) {
            $row[$field] = $this->dec((string) ($row[$field . '_enc'] ?? ''));
            unset($row[$field . '_enc']);
        }
        return $row;
    }

    private function enc(string $value): ?string { return $this->secrets->encrypt($value); }
    private function dec(string $value): string
    {
        try { return (string) ($this->secrets->decrypt($value) ?? ''); } catch (\Throwable $e) { return ''; }
    }
}
