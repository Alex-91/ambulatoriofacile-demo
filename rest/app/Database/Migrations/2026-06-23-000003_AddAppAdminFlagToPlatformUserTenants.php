<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAppAdminFlagToPlatformUserTenants extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_user_tenants')) {
            return;
        }

        if ($this->db->fieldExists('is_app_admin', 'platform_user_tenants')) {
            return;
        }

        $this->forge->addColumn('platform_user_tenants', [
            'is_app_admin' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
                'after' => 'app_user_id',
            ],
        ]);
    }

    public function down()
    {
        if (!$this->db->tableExists('platform_user_tenants')) {
            return;
        }

        if (!$this->db->fieldExists('is_app_admin', 'platform_user_tenants')) {
            return;
        }

        $this->forge->dropColumn('platform_user_tenants', 'is_app_admin');
    }
}
