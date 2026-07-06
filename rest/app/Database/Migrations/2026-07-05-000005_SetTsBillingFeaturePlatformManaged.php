<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SetTsBillingFeaturePlatformManaged extends Migration
{
    protected $DBGroup = 'platform';

    private const FEATURE_KEY = 'ts_billing';

    public function up()
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

        $update = [
            'description' => 'Il master piattaforma attiva la Fatturazione TS per lo spazio. Una volta concessa, il tenant master dello studio puo configurare il modulo Sistema Tessera Sanitaria e usarlo operativamente.',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $update['is_tenant_managed'] = 0;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $update['tenant_default_enabled'] = 0;
        }

        $this->db->table('platform_features')
            ->where('id_feature', $featureId)
            ->update($update);

        if ($this->db->tableExists('platform_tenant_feature_preferences')) {
            $this->db->table('platform_tenant_feature_preferences')
                ->where('id_feature', $featureId)
                ->delete();
        }
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

        $update = [
            'description' => 'Il tenant puo configurare il modulo Sistema Tessera Sanitaria, preparare documenti di spesa sanitaria e inviarli ai servizi TS.',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $update['is_tenant_managed'] = 1;
        }

        if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $update['tenant_default_enabled'] = 0;
        }

        $this->db->table('platform_features')
            ->where('id_feature', $featureId)
            ->update($update);
    }
}
