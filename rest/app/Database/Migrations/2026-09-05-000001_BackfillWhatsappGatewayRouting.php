<?php

namespace App\Database\Migrations;

use App\Services\AppointmentNotificationSettingsService;
use App\Services\WhatsAppGatewayTenantRoutingService;
use CodeIgniter\Database\Migration;

class BackfillWhatsappGatewayRouting extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        foreach (['platform_tenants', 'platform_features', 'platform_package_features', 'platform_tenant_features'] as $table) {
            if (!$this->db->tableExists($table)) {
                return;
            }
        }

        $rows = $this->db->query(
            'SELECT t.id_tenant, f.id_feature, tf.id_tenant_feature,
                    tf.config_json, pf.config_json AS package_config_json
             FROM platform_tenants t
             INNER JOIN platform_features f ON f.feature_key = ?
             LEFT JOIN platform_package_features pf
               ON pf.id_package = t.id_package AND pf.id_feature = f.id_feature
             LEFT JOIN platform_tenant_features tf
               ON tf.id_tenant = t.id_tenant AND tf.id_feature = f.id_feature
             WHERE t.is_active = 1
               AND COALESCE(tf.is_enabled, pf.is_enabled, f.default_enabled, 0) = 1',
            [AppointmentNotificationSettingsService::FEATURE_WHATSAPP]
        )->getResultArray();

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $tenantId = (int) ($row['id_tenant'] ?? 0);
            $featureId = (int) ($row['id_feature'] ?? 0);
            if ($tenantId <= 0 || $featureId <= 0) {
                continue;
            }

            $configSource = $row['id_tenant_feature'] !== null
                ? (string) ($row['config_json'] ?? '')
                : (string) ($row['package_config_json'] ?? '');
            $config = $this->decodeConfig($configSource);
            if (array_key_exists(WhatsAppGatewayTenantRoutingService::CONFIG_KEY, $config)) {
                continue;
            }

            $config = WhatsAppGatewayTenantRoutingService::mergeIntoFeatureConfig($config, true);
            $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                continue;
            }

            $tenantFeatureId = (int) ($row['id_tenant_feature'] ?? 0);
            if ($tenantFeatureId > 0) {
                $this->db->table('platform_tenant_features')
                    ->where('id_tenant_feature', $tenantFeatureId)
                    ->update(['config_json' => $encoded, 'updated_at' => $now]);
                continue;
            }

            $this->db->table('platform_tenant_features')->insert([
                'id_tenant' => $tenantId,
                'id_feature' => $featureId,
                'is_enabled' => 1,
                'source' => 'gateway_backfill',
                'config_json' => $encoded,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        // Data backfill: preserve explicit routing choices made after this migration.
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
