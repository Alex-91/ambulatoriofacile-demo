<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaTextColorThemeFeature extends Migration
{
    protected $DBGroup = 'platform';

    private const FEATURE_KEY = 'agenda_text_color_theme';

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
            'feature_name' => 'Palette testo agenda',
            'feature_scope' => 'appearance',
            'description' => 'Salva per il singolo studio una palette testo per appuntamenti, slot liberi e vista Giorno Team dell’agenda.',
            'default_enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $payload['icon_class'] = 'fa-font';
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $payload['is_tenant_managed'] = 0;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $payload['tenant_default_enabled'] = 0;
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $payload['sort_order'] = 72;
        }

        if (!$feature) {
            $this->db->table('platform_features')->insert($payload);
            return;
        }

        $update = [
            'feature_name' => 'Palette testo agenda',
            'feature_scope' => 'appearance',
            'description' => 'Salva per il singolo studio una palette testo per appuntamenti, slot liberi e vista Giorno Team dell’agenda.',
            'default_enabled' => 1,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $update['icon_class'] = 'fa-font';
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $update['is_tenant_managed'] = 0;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $update['tenant_default_enabled'] = 0;
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $update['sort_order'] = 72;
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
