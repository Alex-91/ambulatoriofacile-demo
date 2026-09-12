<?php

namespace App\Database\Migrations;

use App\Services\WhatsAppCampaignPrioritySchema;
use CodeIgniter\Database\Migration;

class AddWhatsAppCampaignPriority extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        WhatsAppCampaignPrioritySchema::install($this->db);
    }

    public function down()
    {
        if ($this->db->fieldExists('priority_plan_json', 'platform_whatsapp_campaigns')) {
            $this->forge->dropColumn('platform_whatsapp_campaigns', 'priority_plan_json');
        }
        if ($this->db->fieldExists('send_order', 'platform_whatsapp_campaign_recipients')) {
            $this->forge->dropColumn('platform_whatsapp_campaign_recipients', 'send_order');
        }
    }
}
