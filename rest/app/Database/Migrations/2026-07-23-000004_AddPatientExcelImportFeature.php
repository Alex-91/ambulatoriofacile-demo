<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPatientExcelImportFeature extends Migration
{
    protected $DBGroup = 'platform';

    private const FEATURE_KEY = 'patient_excel_import';

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
            'feature_name' => 'Importazione pazienti da Excel',
            'feature_scope' => 'workflow',
            'description' => 'Il tenant master puo attivare per il singolo studio l importazione guidata dei pazienti da file Excel, con mapping colonne, preview e controlli prima del salvataggio.',
            'default_enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($this->db->fieldExists('icon_class', 'platform_features')) {
            $payload['icon_class'] = 'fa-upload';
        }

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $payload['is_tenant_managed'] = 1;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $payload['tenant_default_enabled'] = 0;
        }

        if ($this->db->fieldExists('sort_order', 'platform_features')) {
            $payload['sort_order'] = 74;
        }

        if (!$feature) {
            $this->db->table('platform_features')->insert($payload);
            return;
        }

        $update = [
            'feature_name' => $payload['feature_name'],
            'feature_scope' => $payload['feature_scope'],
            'description' => $payload['description'],
            'default_enabled' => $payload['default_enabled'],
            'updated_at' => $now,
        ];

        if (array_key_exists('icon_class', $payload)) {
            $update['icon_class'] = $payload['icon_class'];
        }

        if (array_key_exists('is_tenant_managed', $payload)) {
            $update['is_tenant_managed'] = $payload['is_tenant_managed'];
        }

        if (array_key_exists('tenant_default_enabled', $payload)) {
            $update['tenant_default_enabled'] = $payload['tenant_default_enabled'];
        }

        if (array_key_exists('sort_order', $payload)) {
            $update['sort_order'] = $payload['sort_order'];
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
