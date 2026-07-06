<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformTenantTsProfilesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_tenant_ts_profiles';
    protected $primaryKey = 'id_ts_profile';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_tenant',
        'profile_name',
        'sender_type',
        'owner_piva',
        'owner_cf_enc',
        'owner_cf_hash',
        'region_code',
        'asl_code',
        'ssa_code',
        'auth_username',
        'auth_password_enc',
        'pincode_enc',
        'environment',
        'is_default',
        'is_enabled',
        'last_check_status',
        'last_check_message',
        'last_check_at',
        'metadata_json',
        'created_by_platform_user',
        'updated_by_platform_user',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProfilesForTenant(int $tenantId, bool $enabledOnly = false): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        $builder = $this->where('id_tenant', $tenantId);
        if ($enabledOnly) {
            $builder = $builder->where('is_enabled', 1);
        }

        return $builder
            ->orderBy('is_default', 'DESC')
            ->orderBy('profile_name', 'ASC')
            ->findAll();
    }

    public function findDefaultProfileForTenant(int $tenantId, ?string $environment = null): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        $environment = trim(strtolower((string) $environment));

        $builder = $this->where('id_tenant', $tenantId)
            ->where('is_enabled', 1)
            ->where('is_default', 1);

        if ($environment !== '') {
            $builder = $builder->where('LOWER(environment)', $environment);
        }

        $profile = $builder->first();
        if ($profile !== null) {
            return $profile;
        }

        $fallback = $this->where('id_tenant', $tenantId)
            ->where('is_enabled', 1);

        if ($environment !== '') {
            $fallback = $fallback->where('LOWER(environment)', $environment);
        }

        return $fallback
            ->orderBy('is_default', 'DESC')
            ->orderBy('id_ts_profile', 'ASC')
            ->first();
    }
}
