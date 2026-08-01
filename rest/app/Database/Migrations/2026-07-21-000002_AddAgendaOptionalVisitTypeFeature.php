<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaOptionalVisitTypeFeature extends Migration
{
    protected $DBGroup = 'platform';

    private const FEATURE_KEY = 'agenda_visit_type_optional';

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
            'feature_key' => self::FEATURE_KEY,
            'feature_name' => 'Tipo visita facoltativo in appuntamento',
            'feature_scope' => 'workflow',
            'description' => 'Il master piattaforma può attivare per il singolo studio l’uso facoltativo del tipo visita nel popup appuntamento. Se il campo resta vuoto, l’appuntamento occupa solo lo slot cliccato.',
            'default_enabled' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $payload['icon_class'] = 'fa-toggle-off';
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $payload['is_tenant_managed'] = 0;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $payload['tenant_default_enabled'] = 0;
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $payload['sort_order'] = 69;
        }

        if (!$feature) {
            $this->db->table('platform_features')->insert($payload);
            return;
        }

        $update = [
            'feature_name' => 'Tipo visita facoltativo in appuntamento',
            'feature_scope' => 'workflow',
            'description' => 'Il master piattaforma può attivare per il singolo studio l’uso facoltativo del tipo visita nel popup appuntamento. Se il campo resta vuoto, l’appuntamento occupa solo lo slot cliccato.',
            'default_enabled' => 0,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $update['icon_class'] = 'fa-toggle-off';
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $update['is_tenant_managed'] = 0;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $update['tenant_default_enabled'] = 0;
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $update['sort_order'] = 69;
        }

        $this->db->table('platform_features')
            ->where('feature_key', self::FEATURE_KEY)
            ->update($update);
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
