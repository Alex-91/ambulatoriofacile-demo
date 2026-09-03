<?php

namespace App\Commands;

use App\Services\WhatsAppCampaignService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RunWhatsAppCampaigns extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:run';
    protected $description = 'Processa al massimo un invio di una campagna WhatsApp rispettando il limite di cinque minuti per spazio.';

    public function run(array $params)
    {
        $result = (new WhatsAppCampaignService())->runOne();
        CLI::write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}', !empty($result['ok']) ? 'green' : 'red');
    }
}
