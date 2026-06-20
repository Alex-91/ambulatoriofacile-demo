<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformTenantFeaturePreferencesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_tenant_feature_preferences';
    protected $primaryKey = 'id_tenant_feature_preference';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_tenant',
        'id_feature',
        'is_enabled',
        'source',
        'updated_by_platform_user',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function setPreference(
        int $tenantId,
        int $featureId,
        bool $enabled,
        ?int $updatedByPlatformUserId = null,
        string $source = 'tenant_master'
    ): bool {
        if ($tenantId <= 0 || $featureId <= 0) {
            return false;
        }

        $row = $this->where('id_tenant', $tenantId)
            ->where('id_feature', $featureId)
            ->first();

        $payload = [
            'id_tenant' => $tenantId,
            'id_feature' => $featureId,
            'is_enabled' => $enabled ? 1 : 0,
            'source' => trim($source) !== '' ? trim($source) : 'tenant_master',
            'updated_by_platform_user' => $updatedByPlatformUserId && $updatedByPlatformUserId > 0
                ? $updatedByPlatformUserId
                : null,
        ];

        if ($row) {
            return $this->update((int) $row['id_tenant_feature_preference'], $payload);
        }

        return $this->insert($payload) !== false;
    }
}
