<?php

namespace App\Commands;

use App\Services\WhatsAppCampaignService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class RunWhatsAppCampaigns extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:run';
    protected $description = 'Processa al massimo un invio di una campagna WhatsApp rispettando il limite di cinque minuti per spazio.';

    public function run(array $params)
    {
        if (in_array('--diagnose-schema', $params, true)) {
            $db = Database::connect('platform');
            $tables = [
                'campaigns' => $db->tableExists('platform_whatsapp_campaigns'),
                'recipients' => $db->tableExists('platform_whatsapp_campaign_recipients'),
                'rate_limits' => $db->tableExists('platform_whatsapp_campaign_rate_limits'),
            ];

            CLI::write(json_encode(['ok' => !in_array(false, $tables, true), 'tables' => $tables], JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !in_array(false, $tables, true) ? 'green' : 'red');
            return;
        }

        $result = (new WhatsAppCampaignService())->runOne();
        CLI::write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !empty($result['ok']) ? 'green' : 'red');
    }
}
