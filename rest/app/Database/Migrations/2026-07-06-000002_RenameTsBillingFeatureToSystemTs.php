<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameTsBillingFeatureToSystemTs extends Migration
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
            'feature_name' => 'Sistema TS',
            'description' => 'Il master piattaforma attiva il modulo Sistema TS per lo spazio. Lo studio può configurarlo e usarlo anche separatamente dal modulo Fatturazione.',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->table('platform_features')
            ->where('id_feature', $featureId)
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

        $update = [
            'feature_name' => 'Fatturazione TS',
            'description' => 'Il master piattaforma attiva la Fatturazione TS per lo spazio. Una volta concessa, il tenant master dello studio può configurare il modulo Sistema Tessera Sanitaria e usarlo operativamente.',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->table('platform_features')
            ->where('id_feature', $featureId)
            ->update($update);
    }
}
