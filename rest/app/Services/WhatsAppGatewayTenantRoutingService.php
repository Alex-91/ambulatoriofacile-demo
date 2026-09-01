<?php

namespace App\Services;

use Config\Database;

class WhatsAppGatewayTenantRoutingService
{
    public const CONFIG_KEY = 'gateway_routing';

    private \CodeIgniter\Database\BaseConnection $platformDb;

    public function __construct(?\CodeIgniter\Database\BaseConnection $platformDb = null)
    {
        $this->platformDb = $platformDb ?? Database::connect('platform');
    }

    public function isEnabledForTenant(int $tenantId): bool
    {
        if ($tenantId <= 0
            || !$this->platformDb->tableExists('platform_features')
            || !$this->platformDb->tableExists('platform_tenant_features')) {
            return false;
        }

        $row = $this->platformDb->table('platform_tenant_features tf')
            ->select('tf.is_enabled, tf.config_json')
            ->join('platform_features f', 'f.id_feature = tf.id_feature')
            ->where('tf.id_tenant', $tenantId)
            ->where('f.feature_key', AppointmentNotificationSettingsService::FEATURE_WHATSAPP)
            ->get(1)
            ->getRowArray();

        if (!$row || (int) ($row['is_enabled'] ?? 0) !== 1) {
            return false;
        }

        return self::isEnabledInFeatureConfig(
            $this->decodeConfig((string) ($row['config_json'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $featureConfig
     * @return array<string, mixed>
     */
    public static function mergeIntoFeatureConfig(array $featureConfig, bool $enabled): array
    {
        $featureConfig[self::CONFIG_KEY] = ['enabled' => $enabled];
        return $featureConfig;
    }

    /** @param array<string, mixed> $featureConfig */
    public static function isEnabledInFeatureConfig(array $featureConfig): bool
    {
        $routing = $featureConfig[self::CONFIG_KEY] ?? [];
        if (!is_array($routing)) {
            return false;
        }

        $enabled = $routing['enabled'] ?? false;
        return $enabled === true || $enabled === 1 || $enabled === '1';
    }

    /** @return array<string, mixed> */
    private function decodeConfig(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
