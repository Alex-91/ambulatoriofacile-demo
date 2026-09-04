<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFse2Feature extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $existing = $this->db->table('platform_features')->where('feature_key', 'fse2')->get(1)->getRowArray();
        $payload = [
            'feature_key' => 'fse2',
            'feature_name' => 'FSE 2.0',
            'feature_scope' => 'clinical',
            'description' => 'Preparazione, firma, validazione e pubblicazione dei referti clinici nel Fascicolo Sanitario Elettronico 2.0.',
            'default_enabled' => 0,
            'updated_at' => $now,
        ];
        if (!$existing) {
            $payload['created_at'] = $now;
        }
        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $payload['icon_class'] = 'fa-medkit';
        }
        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $payload['is_tenant_managed'] = 0;
        }
        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $payload['tenant_default_enabled'] = 0;
        }
        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $payload['sort_order'] = 145;
        }

        if ($existing) {
            $this->db->table('platform_features')->where('feature_key', 'fse2')->update($payload);
        } else {
            $this->db->table('platform_features')->insert($payload);
        }
    }

    public function down()
    {
        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $feature = $this->db->table('platform_features')->where('feature_key', 'fse2')->get(1)->getRowArray();
        $featureId = (int) ($feature['id_feature'] ?? 0);
        if ($featureId <= 0) {
            return;
        }
        foreach (['platform_tenant_feature_preferences', 'platform_tenant_features', 'platform_package_features'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->where('id_feature', $featureId)->delete();
            }
        }
        $this->db->table('platform_features')->where('id_feature', $featureId)->delete();
    }
}
