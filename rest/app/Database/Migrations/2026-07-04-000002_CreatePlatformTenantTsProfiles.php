<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformTenantTsProfiles extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if ($this->db->tableExists('platform_tenant_ts_profiles')) {
            return;
        }

        $this->forge->addField([
            'id_ts_profile' => [
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
            'profile_name' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
            ],
            'sender_type' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'owner_piva' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => true,
            ],
            'owner_cf_enc' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'owner_cf_hash' => [
                'type' => 'CHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'region_code' => [
                'type' => 'VARCHAR',
                'constraint' => 3,
                'null' => true,
            ],
            'asl_code' => [
                'type' => 'VARCHAR',
                'constraint' => 3,
                'null' => true,
            ],
            'ssa_code' => [
                'type' => 'VARCHAR',
                'constraint' => 6,
                'null' => true,
            ],
            'auth_username' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'auth_password_enc' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'pincode_enc' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'environment' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'default' => 'test',
            ],
            'is_default' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'is_enabled' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'last_check_status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
            ],
            'last_check_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'last_check_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'metadata_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'created_by_platform_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
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
        $this->forge->addKey('id_ts_profile', true);
        $this->forge->addUniqueKey(['id_tenant', 'profile_name'], 'uq_platform_tenant_ts_profile_name');
        $this->forge->addKey('id_tenant');
        $this->forge->addKey(['id_tenant', 'is_enabled'], false, false, 'idx_platform_tenant_ts_profile_enabled');
        $this->forge->addKey(['id_tenant', 'is_default'], false, false, 'idx_platform_tenant_ts_profile_default');
        $this->forge->addKey(['id_tenant', 'environment'], false, false, 'idx_platform_tenant_ts_profile_env');
        $this->forge->addKey('owner_piva');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey(
            'created_by_platform_user',
            'platform_users',
            'id_platform_user',
            'SET NULL',
            'CASCADE',
            'fk_platform_tenant_ts_profiles_created_by'
        );
        $this->forge->addForeignKey(
            'updated_by_platform_user',
            'platform_users',
            'id_platform_user',
            'SET NULL',
            'CASCADE',
            'fk_platform_tenant_ts_profiles_updated_by'
        );
        $this->forge->createTable('platform_tenant_ts_profiles', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_tenant_ts_profiles')) {
            $this->forge->dropTable('platform_tenant_ts_profiles', true);
        }
    }
}
