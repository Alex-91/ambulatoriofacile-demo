<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformTenantFseProfilesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_tenant_fse_profiles';
    protected $primaryKey = 'id_fse_profile';
    protected $returnType = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'id_tenant', 'profile_name', 'access_mode', 'environment', 'gateway_base_url', 'region_code',
        'organization_id', 'organization_name', 'facility_name', 'facility_code', 'facility_oid', 'locality',
        'facility_type', 'organizational_setting', 'clinical_activity', 'repository_id', 'document_oid_root',
        'submission_oid_root', 'subject_role',
        'author_cf_enc', 'author_cf_hash', 'author_first_name', 'author_last_name', 'app_vendor', 'app_id',
        'app_version', 'auth_certificate_path', 'auth_private_key_path', 'auth_private_key_passphrase_enc',
        'signature_certificate_path', 'signature_private_key_path', 'signature_private_key_passphrase_enc',
        'is_default', 'is_enabled', 'last_check_status', 'last_check_message', 'last_check_at', 'metadata_json',
        'created_by_platform_user', 'updated_by_platform_user', 'created_at', 'updated_at',
    ];

    public function findDefaultProfileForTenant(int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        return $this->where('id_tenant', $tenantId)
            ->where('is_default', 1)
            ->orderBy('is_enabled', 'DESC')
            ->orderBy('id_fse_profile', 'ASC')
            ->first();
    }
}
