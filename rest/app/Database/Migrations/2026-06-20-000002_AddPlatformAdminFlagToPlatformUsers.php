<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPlatformAdminFlagToPlatformUsers extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_users')) {
            return;
        }

        if ($this->db->fieldExists('is_platform_admin', 'platform_users')) {
            return;
        }

        $this->forge->addColumn('platform_users', [
            'is_platform_admin' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'after' => 'last_name',
            ],
        ]);
    }

    public function down()
    {
        if (!$this->db->tableExists('platform_users')) {
            return;
        }

        if (!$this->db->fieldExists('is_platform_admin', 'platform_users')) {
            return;
        }

        $this->forge->dropColumn('platform_users', 'is_platform_admin');
    }
}
