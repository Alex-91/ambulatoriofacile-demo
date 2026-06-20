<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformUserAccessTokens extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_user_access_tokens')) {
            $this->forge->addField([
                'id_platform_user_access_token' => [
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
                    'null' => true,
                ],
                'token_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 40,
                ],
                'token_hash' => [
                    'type' => 'VARCHAR',
                    'constraint' => 128,
                ],
                'email_to' => [
                    'type' => 'VARCHAR',
                    'constraint' => 190,
                ],
                'expires_at' => [
                    'type' => 'DATETIME',
                ],
                'used_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'metadata_json' => [
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
            $this->forge->addKey('id_platform_user_access_token', true);
            $this->forge->addKey(['id_platform_user', 'token_type']);
            $this->forge->addKey(['token_hash']);
            $this->forge->addKey(['expires_at']);
            $this->forge->addForeignKey('id_platform_user', 'platform_users', 'id_platform_user', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'SET NULL', 'CASCADE');
            $this->forge->createTable('platform_user_access_tokens', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('platform_user_access_tokens', true);
    }
}
