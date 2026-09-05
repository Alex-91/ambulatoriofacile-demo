<?php

namespace App\Database\Migrations;

use App\Services\TenantNotificationPolicyService;
use CodeIgniter\Database\Migration;

class AddTenantSmtpCredentials extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists(TenantNotificationPolicyService::TABLE)
            || $this->db->fieldExists('smtp_password_encrypted', TenantNotificationPolicyService::TABLE)) {
            return;
        }

        $this->forge->addColumn(TenantNotificationPolicyService::TABLE, [
            'smtp_password_encrypted' => [
                'type' => 'LONGTEXT',
                'null' => true,
                'after' => 'config_json',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->tableExists(TenantNotificationPolicyService::TABLE)
            && $this->db->fieldExists('smtp_password_encrypted', TenantNotificationPolicyService::TABLE)) {
            $this->forge->dropColumn(TenantNotificationPolicyService::TABLE, 'smtp_password_encrypted');
        }
    }
}
