<?php

namespace App\Models;

use CodeIgniter\Model;

class FseDocumentModel extends Model
{
    protected $table = 'fse_documents';
    protected $primaryKey = 'id_fse_document';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'id_fse_profile', 'id_client', 'source_type', 'source_ref_id', 'local_state', 'document_type',
        'document_title', 'loinc_code', 'loinc_display_name', 'document_unique_id', 'set_id', 'submission_id',
        'version_number', 'patient_cf_enc', 'patient_cf_hash', 'patient_first_name_enc', 'patient_last_name_enc',
        'patient_birth_date_enc', 'patient_gender', 'patient_email_enc', 'patient_address_enc', 'patient_city_enc',
        'author_cf_enc', 'author_cf_hash', 'author_first_name_enc', 'author_last_name_enc', 'service_start',
        'service_end', 'reason_text_enc', 'history_text_enc', 'findings_text_enc', 'report_text_enc',
        'diagnosis_text_enc', 'conclusions_text_enc', 'patient_consent', 'access_rules_json',
        'administrative_request', 'cda_path', 'cda_sha256', 'unsigned_pdf_path', 'unsigned_pdf_sha256',
        'signed_pdf_path', 'signed_pdf_sha256', 'trace_id', 'span_id', 'workflow_instance_id', 'gateway_state',
        'gateway_http_status', 'last_gateway_message', 'last_response_json', 'validated_at', 'published_at', 'deleted_at',
        'created_by', 'updated_by', 'created_at', 'updated_at',
    ];
}
