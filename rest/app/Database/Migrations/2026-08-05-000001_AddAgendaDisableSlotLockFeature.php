<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaDisableSlotLockFeature extends Migration
{
    private const FEATURE_KEY = 'agenda_disable_slot_lock';

    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $feature = $this->db->table('platform_features')
            ->where('feature_key', self::FEATURE_KEY)
            ->get(1)
            ->getRowArray();

        $payload = [
            'feature_name' => 'Disattiva blocco temporaneo slot',
            'feature_scope' => 'workflow',
            'description' => 'Il master piattaforma può disattivare per questo singolo spazio il blocco temporaneo all’apertura del popup appuntamento. Il salvataggio continua a verificare che gli slot non siano già prenotati.',
            'default_enabled' => 0,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $payload['icon_class'] = 'fa-unlock-alt';
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $payload['is_tenant_managed'] = 0;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $payload['tenant_default_enabled'] = 0;
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $payload['sort_order'] = 80;
        }

        if ($feature) {
            $this->db->table('platform_features')
                ->where('feature_key', self::FEATURE_KEY)
                ->update($payload);
            return;
        }

        $payload['feature_key'] = self::FEATURE_KEY;
        $payload['created_at'] = $now;
        $this->db->table('platform_features')->insert($payload);
    }

    public function down()
    {
        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $feature = $this->db->table('platform_features')
            ->select('id_feature')
            ->where('feature_key', self::FEATURE_KEY)
            ->get(1)
            ->getRowArray();
        $featureId = (int) ($feature['id_feature'] ?? 0);
        if ($featureId <= 0) {
            return;
        }

        foreach ([
            'platform_tenant_feature_preferences',
            'platform_tenant_features',
            'platform_package_features',
        ] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->where('id_feature', $featureId)->delete();
            }
        }

        $this->db->table('platform_features')
            ->where('id_feature', $featureId)
            ->delete();
    }
}
