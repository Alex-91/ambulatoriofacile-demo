<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class WhatsAppCampaignPrioritySchema
{
    public static function ready(BaseConnection $db): bool
    {
        return $db->fieldExists('send_order', 'platform_whatsapp_campaign_recipients')
            && $db->fieldExists('priority_plan_json', 'platform_whatsapp_campaigns');
    }

    /** Shared by the versioned migration and the narrowly scoped ops command. */
    public static function install(BaseConnection $db): void
    {
        $forge = Database::forge($db);
        foreach (['platform_whatsapp_campaigns', 'platform_whatsapp_campaign_recipients'] as $table) {
            if (!$db->tableExists($table)) { throw new \RuntimeException('Installare prima lo schema base delle campagne WhatsApp.'); }
        }
        if (!$db->fieldExists('send_order', 'platform_whatsapp_campaign_recipients')) {
            $forge->addColumn('platform_whatsapp_campaign_recipients', [
                'send_order' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            ]);
        }
        if (!$db->fieldExists('priority_plan_json', 'platform_whatsapp_campaigns')) {
            $forge->addColumn('platform_whatsapp_campaigns', [
                'priority_plan_json' => ['type' => 'TEXT', 'null' => true],
            ]);
        }
        $db->resetDataCache();
        if (!self::ready($db)) { throw new \RuntimeException('Schema priorità campagne non disponibile.'); }
    }
}
