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
        'config_json',
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
        string $source = 'tenant_master',
        ?array $config = null,
        bool $preserveExistingConfig = true
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
            'config_json' => $this->resolveConfigJson($row, $config, $preserveExistingConfig),
            'updated_by_platform_user' => $updatedByPlatformUserId && $updatedByPlatformUserId > 0
                ? $updatedByPlatformUserId
                : null,
        ];

        if ($row) {
            return $this->update((int) $row['id_tenant_feature_preference'], $payload);
        }

        return $this->insert($payload) !== false;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function resolveConfigJson(?array $row, ?array $config, bool $preserveExistingConfig): ?string
    {
        if ($config !== null) {
            $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $json === false ? null : $json;
        }

        if ($preserveExistingConfig && is_array($row)) {
            $existing = $row['config_json'] ?? null;
            return is_string($existing) && trim($existing) !== '' ? $existing : null;
        }

        return null;
    }
}
