<?php

namespace App\Models;

use CodeIgniter\Model;

class PlatformTenantFeaturesModel extends Model
{
    protected $DBGroup = 'platform';
    protected $table = 'platform_tenant_features';
    protected $primaryKey = 'id_tenant_feature';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_tenant',
        'id_feature',
        'is_enabled',
        'source',
        'config_json',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function setOverride(int $tenantId, int $featureId, bool $enabled, ?array $config = null, string $source = 'override'): bool
    {
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
            'source' => trim($source) !== '' ? trim($source) : 'override',
            'config_json' => $config !== null ? json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];

        if ($row) {
            return $this->update((int) $row['id_tenant_feature'], $payload);
        }

        return $this->insert($payload) !== false;
    }
}
