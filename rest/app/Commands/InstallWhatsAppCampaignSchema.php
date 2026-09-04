<?php

namespace App\Commands;

use App\Database\Migrations\CreateWhatsappCampaignTables;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InstallWhatsAppCampaignSchema extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:install-schema';
    protected $description = 'Crea esclusivamente le tabelle tecniche delle campagne WhatsApp.';

    public function run(array $params)
    {
        (new CreateWhatsappCampaignTables())->up();
        CLI::write('Schema campagne WhatsApp disponibile.', 'green');
    }
}
