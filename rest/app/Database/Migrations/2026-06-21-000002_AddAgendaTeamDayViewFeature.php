<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaTeamDayViewFeature extends Migration
{
    protected $DBGroup = 'platform';

    private const FEATURE_KEY = 'agenda_team_day_view';

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

        if (!$feature) {
            $payload = [
                'feature_key' => self::FEATURE_KEY,
                'feature_name' => 'Vista agenda giorno team',
                'feature_scope' => 'workflow',
                'description' => 'Il tenant master decide se abilitare una vista giornaliera unica che mostra in parallelo le agende di tutti i professionisti visibili nello spazio.',
                'default_enabled' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($this->db->fieldExists('icon_class', 'platform_features')) {
                $payload['icon_class'] = 'fa-columns';
            }

            if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
                $payload['is_tenant_managed'] = 1;
            }

            if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
                $payload['tenant_default_enabled'] = 0;
            }

            if ($this->db->fieldExists('sort_order', 'platform_features')) {
                $payload['sort_order'] = 66;
            }

            $this->db->table('platform_features')->insert($payload);

            $feature = $this->db->table('platform_features')
                ->where('feature_key', self::FEATURE_KEY)
                ->get(1)
                ->getRowArray();
        } else {
            $update = ['updated_at' => $now];

            if ($this->db->fieldExists('icon_class', 'platform_features')) {
                $update['icon_class'] = 'fa-columns';
            }

            if ($this->db->fieldExists('is_tenant_managed', 'platform_features')) {
                $update['is_tenant_managed'] = 1;
            }

            if ($this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
                $update['tenant_default_enabled'] = 0;
            }

            if ($this->db->fieldExists('sort_order', 'platform_features')) {
                $update['sort_order'] = 66;
            }

            $this->db->table('platform_features')
                ->where('feature_key', self::FEATURE_KEY)
                ->update($update);
        }

        $featureId = (int)($feature['id_feature'] ?? 0);
        if ($featureId <= 0 || !$this->db->tableExists('platform_packages') || !$this->db->tableExists('platform_package_features')) {
            return;
        }

        $packageCodes = ['base', 'team', 'enterprise'];
        $packageRows = $this->db->table('platform_packages')
            ->select('id_package, package_code')
            ->whereIn('package_code', $packageCodes)
            ->get()
            ->getResultArray();

        foreach ($packageRows as $packageRow) {
            $packageId = (int)($packageRow['id_package'] ?? 0);
            if ($packageId <= 0) {
                continue;
            }

            $exists = $this->db->table('platform_package_features')
                ->where('id_package', $packageId)
                ->where('id_feature', $featureId)
                ->get(1)
                ->getRowArray();

            if ($exists) {
                continue;
            }

            $this->db->table('platform_package_features')->insert([
                'id_package' => $packageId,
                'id_feature' => $featureId,
                'is_enabled' => 1,
                'config_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
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

        $featureId = (int)($feature['id_feature'] ?? 0);
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
