<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddTenantManagedFeatureControls extends Migration
{
    public function up()
    {
        $this->addFeatureCatalogColumns();
        $this->createTenantFeaturePreferenceTable();
        $this->backfillSeedFeatureMetadata();
    }

    public function down()
    {
        if ($this->db->tableExists('platform_tenant_feature_preferences')) {
            $this->forge->dropTable('platform_tenant_feature_preferences', true);
        }

        if ($this->db->tableExists('platform_features')) {
            foreach (['sort_order', 'tenant_default_enabled', 'is_tenant_managed', 'icon_class'] as $column) {
                if ($this->db->fieldExists($column, 'platform_features')) {
                    $this->forge->dropColumn('platform_features', $column);
                }
            }
        }
    }

    private function addFeatureCatalogColumns(): void
    {
        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $definitions = [];

        if (!$this->db->fieldExists('icon_class', 'platform_features')) {
            $definitions['icon_class'] = [
                'type' => 'VARCHAR',
                'constraint' => 60,
                'null' => true,
                'after' => 'default_enabled',
            ];
        }

        if (!$this->db->fieldExists('is_tenant_managed', 'platform_features')) {
            $definitions['is_tenant_managed'] = [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'after' => 'icon_class',
            ];
        }

        if (!$this->db->fieldExists('tenant_default_enabled', 'platform_features')) {
            $definitions['tenant_default_enabled'] = [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
                'after' => 'is_tenant_managed',
            ];
        }

        if (!$this->db->fieldExists('sort_order', 'platform_features')) {
            $definitions['sort_order'] = [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
                'after' => 'tenant_default_enabled',
            ];
        }

        if ($definitions !== []) {
            $this->forge->addColumn('platform_features', $definitions);
        }
    }

    private function createTenantFeaturePreferenceTable(): void
    {
        if ($this->db->tableExists('platform_tenant_feature_preferences')) {
            return;
        }

        $this->forge->addField([
            'id_tenant_feature_preference' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'id_feature' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'is_enabled' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'source' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'tenant_master',
            ],
            'updated_by_platform_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id_tenant_feature_preference', true);
        $this->forge->addUniqueKey(['id_tenant', 'id_feature']);
        $this->forge->addKey('id_tenant');
        $this->forge->addKey('id_feature');
        $this->forge->addKey('updated_by_platform_user');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_feature', 'platform_features', 'id_feature', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('updated_by_platform_user', 'platform_users', 'id_platform_user', 'SET NULL', 'CASCADE');
        $this->forge->createTable('platform_tenant_feature_preferences', true);
    }

    private function backfillSeedFeatureMetadata(): void
    {
        if (!$this->db->tableExists('platform_features')) {
            return;
        }

        $rows = [
            'agenda' => [
                'icon_class' => 'fa-calendar',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 10,
            ],
            'posta' => [
                'icon_class' => 'fa-envelope',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 20,
            ],
            'chat' => [
                'icon_class' => 'fa-comments',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 30,
            ],
            'push_notifications' => [
                'icon_class' => 'fa-bell',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 40,
            ],
            'staff_management' => [
                'icon_class' => 'fa-users',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 50,
            ],
            'multi_location' => [
                'icon_class' => 'fa-building',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 60,
            ],
            'vertical_overrides' => [
                'icon_class' => 'fa-sliders',
                'is_tenant_managed' => 0,
                'tenant_default_enabled' => 1,
                'sort_order' => 70,
            ],
            'advanced_reporting' => [
                'icon_class' => 'fa-bar-chart',
                'is_tenant_managed' => 1,
                'tenant_default_enabled' => 1,
                'sort_order' => 80,
            ],
            'custom_branding' => [
                'icon_class' => 'fa-paint-brush',
                'is_tenant_managed' => 0,
                'tenant_default_enabled' => 1,
                'sort_order' => 90,
            ],
        ];

        foreach ($rows as $featureKey => $payload) {
            $this->db->table('platform_features')
                ->where('feature_key', $featureKey)
                ->update($payload);
        }
    }
}
