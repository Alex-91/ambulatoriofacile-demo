<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformMultiTenantFoundation extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        $this->createPlatformPackagesTable();
        $this->createPlatformFeaturesTable();
        $this->createPlatformTenantsTable();
        $this->createPlatformUsersTable();
        $this->createPlatformPackageFeaturesTable();
        $this->createPlatformTenantFeaturesTable();
        $this->createPlatformUserTenantsTable();
        $this->seedFoundationCatalog();
    }

    public function down()
    {
        $tables = [
            'platform_user_tenants',
            'platform_tenant_features',
            'platform_package_features',
            'platform_users',
            'platform_tenants',
            'platform_features',
            'platform_packages',
        ];

        foreach ($tables as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    private function createPlatformPackagesTable(): void
    {
        if ($this->db->tableExists('platform_packages')) {
            return;
        }

        $this->forge->addField([
            'id_package' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'package_code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'package_name' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'max_users' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
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
        $this->forge->addKey('id_package', true);
        $this->forge->addUniqueKey('package_code');
        $this->forge->createTable('platform_packages', true);
    }

    private function createPlatformFeaturesTable(): void
    {
        if ($this->db->tableExists('platform_features')) {
            return;
        }

        $this->forge->addField([
            'id_feature' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'feature_key' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
            ],
            'feature_name' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
            ],
            'feature_scope' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'module',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'default_enabled' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
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
        $this->forge->addKey('id_feature', true);
        $this->forge->addUniqueKey('feature_key');
        $this->forge->createTable('platform_features', true);
    }

    private function createPlatformTenantsTable(): void
    {
        if ($this->db->tableExists('platform_tenants')) {
            return;
        }

        $this->forge->addField([
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_key' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
            ],
            'tenant_name' => [
                'type' => 'VARCHAR',
                'constraint' => 160,
            ],
            'legal_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'draft',
            ],
            'id_package' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'onboarding_status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'draft',
            ],
            'login_hint' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'db_host' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'db_port' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 3306,
            ],
            'db_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'db_username' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'db_password_ref' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'db_driver' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'MySQLi',
            ],
            'db_prefix' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => '',
            ],
            'storage_key' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'feature_profile' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
            'metadata_json' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
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
        $this->forge->addKey('id_tenant', true);
        $this->forge->addUniqueKey('tenant_key');
        $this->forge->addUniqueKey('storage_key');
        $this->forge->addKey('id_package');
        $this->forge->addForeignKey('id_package', 'platform_packages', 'id_package', 'SET NULL', 'CASCADE');
        $this->forge->createTable('platform_tenants', true);
    }

    private function createPlatformUsersTable(): void
    {
        if ($this->db->tableExists('platform_users')) {
            return;
        }

        $this->forge->addField([
            'id_platform_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
            ],
            'password_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'first_name' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'last_name' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'invited',
            ],
            'must_reset_password' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'email_verified_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_login_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addKey('id_platform_user', true);
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('platform_users', true);
    }

    private function createPlatformPackageFeaturesTable(): void
    {
        if ($this->db->tableExists('platform_package_features')) {
            return;
        }

        $this->forge->addField([
            'id_package_feature' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_package' => [
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
            'config_json' => [
                'type' => 'TEXT',
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
        $this->forge->addKey('id_package_feature', true);
        $this->forge->addUniqueKey(['id_package', 'id_feature']);
        $this->forge->addKey('id_package');
        $this->forge->addKey('id_feature');
        $this->forge->addForeignKey('id_package', 'platform_packages', 'id_package', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_feature', 'platform_features', 'id_feature', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_package_features', true);
    }

    private function createPlatformTenantFeaturesTable(): void
    {
        if ($this->db->tableExists('platform_tenant_features')) {
            return;
        }

        $this->forge->addField([
            'id_tenant_feature' => [
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
                'default' => 'override',
            ],
            'config_json' => [
                'type' => 'TEXT',
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
        $this->forge->addKey('id_tenant_feature', true);
        $this->forge->addUniqueKey(['id_tenant', 'id_feature']);
        $this->forge->addKey('id_tenant');
        $this->forge->addKey('id_feature');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_feature', 'platform_features', 'id_feature', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_tenant_features', true);
    }

    private function createPlatformUserTenantsTable(): void
    {
        if ($this->db->tableExists('platform_user_tenants')) {
            return;
        }

        $this->forge->addField([
            'id_platform_user_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_platform_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'tenant_role' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'tenant_staff',
            ],
            'app_user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'is_default' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'is_owner' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'invitation_status' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'pending',
            ],
            'invited_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'accepted_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addKey('id_platform_user_tenant', true);
        $this->forge->addUniqueKey(['id_platform_user', 'id_tenant']);
        $this->forge->addKey('id_platform_user');
        $this->forge->addKey('id_tenant');
        $this->forge->addForeignKey('id_platform_user', 'platform_users', 'id_platform_user', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_user_tenants', true);
    }

    private function seedFoundationCatalog(): void
    {
        if (
            !$this->db->tableExists('platform_packages')
            || !$this->db->tableExists('platform_features')
            || !$this->db->tableExists('platform_package_features')
        ) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $packages = [
            [
                'package_code' => 'base',
                'package_name' => 'Base',
                'description' => 'Pacchetto standard per il nucleo comune del prodotto.',
                'max_users' => 5,
            ],
            [
                'package_code' => 'team',
                'package_name' => 'Team',
                'description' => 'Pacchetto per studi con piu operatori e sedi leggere.',
                'max_users' => 25,
            ],
            [
                'package_code' => 'enterprise',
                'package_name' => 'Enterprise',
                'description' => 'Pacchetto per organizzazioni con feature avanzate e verticalizzazioni estese.',
                'max_users' => null,
            ],
        ];

        foreach ($packages as $package) {
            $exists = $this->db->table('platform_packages')
                ->where('package_code', $package['package_code'])
                ->get(1)
                ->getRowArray();

            if ($exists) {
                continue;
            }

            $package['is_active'] = 1;
            $package['created_at'] = $now;
            $package['updated_at'] = $now;

            $this->db->table('platform_packages')->insert($package);
        }

        $features = [
            [
                'feature_key' => 'agenda',
                'feature_name' => 'Agenda',
                'feature_scope' => 'module',
                'description' => 'Modulo agenda e pianificazione visite.',
            ],
            [
                'feature_key' => 'posta',
                'feature_name' => 'Posta',
                'feature_scope' => 'module',
                'description' => 'Messaggistica e posta interna.',
            ],
            [
                'feature_key' => 'chat',
                'feature_name' => 'Chat',
                'feature_scope' => 'module',
                'description' => 'Chat interna tra operatori.',
            ],
            [
                'feature_key' => 'push_notifications',
                'feature_name' => 'Push Notifications',
                'feature_scope' => 'channel',
                'description' => 'Notifiche push e PWA.',
            ],
            [
                'feature_key' => 'staff_management',
                'feature_name' => 'Gestione Staff',
                'feature_scope' => 'module',
                'description' => 'Gestione personale, ruoli e permessi base.',
            ],
            [
                'feature_key' => 'multi_location',
                'feature_name' => 'Multi Sede',
                'feature_scope' => 'module',
                'description' => 'Supporto sedi e stanze multiple.',
            ],
            [
                'feature_key' => 'vertical_overrides',
                'feature_name' => 'Vertical Overrides',
                'feature_scope' => 'feature-flag',
                'description' => 'Abilita personalizzazioni dedicate a singoli clienti.',
            ],
            [
                'feature_key' => 'advanced_reporting',
                'feature_name' => 'Report Avanzati',
                'feature_scope' => 'module',
                'description' => 'Reportistica e statistiche avanzate.',
            ],
            [
                'feature_key' => 'custom_branding',
                'feature_name' => 'Branding Custom',
                'feature_scope' => 'feature-flag',
                'description' => 'Branding dedicato per singolo cliente.',
            ],
        ];

        foreach ($features as $feature) {
            $exists = $this->db->table('platform_features')
                ->where('feature_key', $feature['feature_key'])
                ->get(1)
                ->getRowArray();

            if ($exists) {
                continue;
            }

            $feature['default_enabled'] = 0;
            $feature['created_at'] = $now;
            $feature['updated_at'] = $now;

            $this->db->table('platform_features')->insert($feature);
        }

        $packageIds = [];
        foreach ($this->db->table('platform_packages')->select('id_package, package_code')->get()->getResultArray() as $row) {
            $packageIds[(string) $row['package_code']] = (int) $row['id_package'];
        }

        $featureIds = [];
        foreach ($this->db->table('platform_features')->select('id_feature, feature_key')->get()->getResultArray() as $row) {
            $featureIds[(string) $row['feature_key']] = (int) $row['id_feature'];
        }

        $packageFeatureMap = [
            'base' => ['agenda', 'posta', 'chat', 'push_notifications', 'staff_management'],
            'team' => ['agenda', 'posta', 'chat', 'push_notifications', 'staff_management', 'multi_location'],
            'enterprise' => ['agenda', 'posta', 'chat', 'push_notifications', 'staff_management', 'multi_location', 'vertical_overrides', 'advanced_reporting', 'custom_branding'],
        ];

        foreach ($packageFeatureMap as $packageCode => $featureKeys) {
            $packageId = (int) ($packageIds[$packageCode] ?? 0);
            if ($packageId <= 0) {
                continue;
            }

            foreach ($featureKeys as $featureKey) {
                $featureId = (int) ($featureIds[$featureKey] ?? 0);
                if ($featureId <= 0) {
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
    }
}
