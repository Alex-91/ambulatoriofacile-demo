<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformTenantFseProfiles extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if ($this->db->tableExists('platform_tenant_fse_profiles')) {
            return;
        }

        $this->forge->addField([
            'id_fse_profile' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'profile_name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'access_mode' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'gateway'],
            'environment' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'test'],
            'gateway_base_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'region_code' => ['type' => 'VARCHAR', 'constraint' => 3],
            'organization_id' => ['type' => 'VARCHAR', 'constraint' => 10],
            'organization_name' => ['type' => 'VARCHAR', 'constraint' => 160],
            'facility_name' => ['type' => 'VARCHAR', 'constraint' => 180],
            'facility_code' => ['type' => 'VARCHAR', 'constraint' => 30],
            'facility_oid' => ['type' => 'VARCHAR', 'constraint' => 120],
            'locality' => ['type' => 'VARCHAR', 'constraint' => 500],
            'facility_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'Territorio'],
            'organizational_setting' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'clinical_activity' => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'ERP'],
            'repository_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'document_oid_root' => ['type' => 'VARCHAR', 'constraint' => 120],
            'submission_oid_root' => ['type' => 'VARCHAR', 'constraint' => 120],
            'subject_role' => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'DRS'],
            'author_cf_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'author_cf_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'author_first_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'author_last_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'app_vendor' => ['type' => 'VARCHAR', 'constraint' => 120],
            'app_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'app_version' => ['type' => 'VARCHAR', 'constraint' => 40],
            'auth_certificate_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'auth_private_key_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'auth_private_key_passphrase_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'signature_certificate_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'signature_private_key_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'signature_private_key_passphrase_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'is_default' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'is_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'last_check_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'last_check_message' => ['type' => 'TEXT', 'null' => true],
            'last_check_at' => ['type' => 'DATETIME', 'null' => true],
            'metadata_json' => ['type' => 'LONGTEXT', 'null' => true],
            'created_by_platform_user' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by_platform_user' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id_fse_profile', true);
        $this->forge->addUniqueKey(['id_tenant', 'profile_name'], 'uq_platform_tenant_fse_profile_name');
        $this->forge->addKey('id_tenant');
        $this->forge->addKey(['id_tenant', 'is_enabled', 'is_default'], false, false, 'idx_platform_tenant_fse_profile_active');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_tenant_fse_profiles', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_tenant_fse_profiles')) {
            $this->forge->dropTable('platform_tenant_fse_profiles', true);
        }
    }
}
