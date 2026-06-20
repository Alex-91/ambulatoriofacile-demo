<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformUserTenantsModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_user_tenants';
    protected $primaryKey = 'id_platform_user_tenant';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_platform_user',
        'id_tenant',
        'tenant_role',
        'app_user_id',
        'is_default',
        'is_owner',
        'invitation_status',
        'invited_at',
        'accepted_at',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findMembership(int $platformUserId, int $tenantId): ?array
    {
        if ($platformUserId <= 0 || $tenantId <= 0) {
            return null;
        }

        return $this->where('id_platform_user', $platformUserId)
            ->where('id_tenant', $tenantId)
            ->first();
    }
}
