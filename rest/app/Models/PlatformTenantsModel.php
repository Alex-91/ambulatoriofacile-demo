<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformTenantsModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_tenants';
    protected $primaryKey = 'id_tenant';
    protected $returnType = 'array';
    protected $allowedFields = [
        'tenant_key',
        'tenant_name',
        'legal_name',
        'status',
        'id_package',
        'onboarding_status',
        'login_hint',
        'db_host',
        'db_port',
        'db_name',
        'db_username',
        'db_password_ref',
        'db_driver',
        'db_prefix',
        'storage_key',
        'feature_profile',
        'metadata_json',
        'is_active',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByTenantKey(string $tenantKey): ?array
    {
        $tenantKey = trim(strtolower($tenantKey));
        if ($tenantKey === '') {
            return null;
        }

        return $this->where('LOWER(tenant_key)', $tenantKey)->first();
    }
}
