<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAppointmentNotificationCenter extends Migration
{
    protected $DBGroup = 'platform';

    private const FEATURE_NOTIFICATIONS = 'appointment_notifications';
    private const FEATURE_SMS = 'appointment_notifications_sms';
    private const FEATURE_WHATSAPP = 'appointment_notifications_whatsapp';

    public function up()
    {
        $this->addPreferenceConfigColumn();

        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $notificationsFeatureId = $this->upsertFeature(
            self::FEATURE_NOTIFICATIONS,
            [
                'feature_name' => 'Centro notifiche appuntamenti',
                'feature_scope' => 'workflow',
                'description' => 'Configura i tre flussi appuntamenti dello spazio: messaggio immediato al paziente, avviso ad altro dottore e reminder prima della visita.',
                'default_enabled' => 0,
                'icon_class' => 'fa-commenting',
                'is_tenant_managed' => 0,
                'tenant_default_enabled' => 1,
                'sort_order' => 68,
            ],
            $now
        );

        $this->upsertFeature(
            self::FEATURE_SMS,
            [
                'feature_name' => 'Canale notifiche SMS',
                'feature_scope' => 'channel',
                'description' => 'Abilita il canale SMS per le notifiche appuntamenti di uno specifico tenant. L attivazione commerciale resta centrale.',
                'default_enabled' => 0,
                'icon_class' => 'fa-comment',
                'is_tenant_managed' => 0,
                'tenant_default_enabled' => 0,
                'sort_order' => 69,
            ],
            $now
        );

        $this->upsertFeature(
            self::FEATURE_WHATSAPP,
            [
                'feature_name' => 'Canale notifiche WhatsApp',
                'feature_scope' => 'channel',
                'description' => 'Abilita il canale WhatsApp per le notifiche appuntamenti di uno specifico tenant. L attivazione commerciale resta centrale.',
                'default_enabled' => 0,
                'icon_class' => 'fa-whatsapp',
                'is_tenant_managed' => 0,
                'tenant_default_enabled' => 0,
                'sort_order' => 70,
            ],
            $now
        );

        if ($notificationsFeatureId > 0) {
            $this->seedPackageFeature($notificationsFeatureId, ['base', 'team', 'enterprise'], true, $now);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('platform_features')) {
            foreach ([self::FEATURE_WHATSAPP, self::FEATURE_SMS, self::FEATURE_NOTIFICATIONS] as $featureKey) {
                $feature = $this->db->table('platform_features')
                    ->select('id_feature')
                    ->where('feature_key', $featureKey)
                    ->get(1)
                    ->getRowArray();

                $featureId = (int) ($feature['id_feature'] ?? 0);
                if ($featureId <= 0) {
                    continue;
                }

                if ($this->db->tableExists('platform_tenant_feature_preferences')) {
                    $this->db->table('platform_tenant_feature_preferences')
                        ->where('id_feature', $featureId)
                        ->delete();
                }

                if ($this->db->tableExists('platform_tenant_features')) {
                    $this->db->table('platform_tenant_features')
                        ->where('id_feature', $featureId)
                        ->delete();
                }

                if ($this->db->tableExists('platform_package_features')) {
                    $this->db->table('platform_package_features')
                        ->where('id_feature', $featureId)
                        ->delete();
                }

                $this->db->table('platform_features')
                    ->where('id_feature', $featureId)
                    ->delete();
            }
        }

        if ($this->hasColumn('platform_tenant_feature_preferences', 'config_json')) {
            $this->forge->dropColumn('platform_tenant_feature_preferences', 'config_json');
        }
    }

    private function addPreferenceConfigColumn(): void
    {
        if ($this->hasColumn('platform_tenant_feature_preferences', 'config_json')) {
            return;
        }

        $this->forge->addColumn('platform_tenant_feature_preferences', [
            'config_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'after' => 'source',
            ],
        ]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!$this->db->tableExists($table)) {
            return false;
        }

        $tableSql = $this->db->protectIdentifiers($table);
        $columnSql = $this->db->escape($column);
        $query = $this->db->query("SHOW COLUMNS FROM {$tableSql} LIKE {$columnSql}");

        return $query->getNumRows() > 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertFeature(string $featureKey, array $payload, string $now): int
    {
        $feature = $this->db->table('platform_features')
            ->where('feature_key', $featureKey)
            ->get(1)
            ->getRowArray();

        $data = [
            'feature_key' => $featureKey,
            'feature_name' => (string) ($payload['feature_name'] ?? $featureKey),
            'feature_scope' => (string) ($payload['feature_scope'] ?? 'workflow'),
            'description' => (string) ($payload['description'] ?? ''),
            'default_enabled' => (int) ($payload['default_enabled'] ?? 0),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $data['icon_class'] = (string) ($payload['icon_class'] ?? 'fa-toggle-on');
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $data['is_tenant_managed'] = (int) ($payload['is_tenant_managed'] ?? 0);
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $data['tenant_default_enabled'] = (int) ($payload['tenant_default_enabled'] ?? 0);
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $data['sort_order'] = (int) ($payload['sort_order'] ?? 0);
        }

        if ($feature) {
            unset($data['created_at']);
            $this->db->table('platform_features')
                ->where('id_feature', (int) ($feature['id_feature'] ?? 0))
                ->update($data);

            return (int) ($feature['id_feature'] ?? 0);
        }

        $this->db->table('platform_features')->insert($data);
        return (int) ($this->db->insertID() ?? 0);
    }

    /**
     * @param array<int, string> $packageCodes
     */
    private function seedPackageFeature(int $featureId, array $packageCodes, bool $enabled, string $now): void
    {
        if ($featureId <= 0 || !$this->db->tableExists('platform_packages') || !$this->db->tableExists('platform_package_features')) {
            return;
        }

        $packageRows = $this->db->table('platform_packages')
            ->select('id_package, package_code')
            ->whereIn('package_code', $packageCodes)
            ->get()
            ->getResultArray();

        foreach ($packageRows as $packageRow) {
            $packageId = (int) ($packageRow['id_package'] ?? 0);
            if ($packageId <= 0) {
                continue;
            }

            $exists = $this->db->table('platform_package_features')
                ->where('id_package', $packageId)
                ->where('id_feature', $featureId)
                ->get(1)
                ->getRowArray();

            if ($exists) {
                $this->db->table('platform_package_features')
                    ->where('id_package_feature', (int) ($exists['id_package_feature'] ?? 0))
                    ->update([
                        'is_enabled' => $enabled ? 1 : 0,
                        'updated_at' => $now,
                    ]);
                continue;
            }

            $this->db->table('platform_package_features')->insert([
                'id_package' => $packageId,
                'id_feature' => $featureId,
                'is_enabled' => $enabled ? 1 : 0,
                'config_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
