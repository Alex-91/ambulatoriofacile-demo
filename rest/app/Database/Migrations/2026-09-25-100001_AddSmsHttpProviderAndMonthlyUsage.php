<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSmsHttpProviderAndMonthlyUsage extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->fieldExists('http_config_encrypted', 'platform_sms_provider_settings')) {
            $this->forge->addColumn('platform_sms_provider_settings', [
                'http_config_encrypted' => ['type' => 'LONGTEXT', 'null' => true],
            ]);
        }
        $this->forge->addField([
            'id_tenant' => ['type' => 'INT', 'unsigned' => true],
            'month_key' => ['type' => 'VARCHAR', 'constraint' => 7],
            'attempts' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey(['id_tenant', 'month_key'], true);
        $this->forge->createTable('platform_sms_monthly_usage', true);
    }

    public function down()
    {
        $this->forge->dropTable('platform_sms_monthly_usage', true);
        if ($this->db->fieldExists('http_config_encrypted', 'platform_sms_provider_settings')) {
            $this->forge->dropColumn('platform_sms_provider_settings', 'http_config_encrypted');
        }
    }
}
