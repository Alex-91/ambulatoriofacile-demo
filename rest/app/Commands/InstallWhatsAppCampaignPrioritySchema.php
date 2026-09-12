<?php

namespace App\Commands;

use App\Services\WhatsAppCampaignPrioritySchema;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class InstallWhatsAppCampaignPrioritySchema extends BaseCommand
{
    protected $group = 'WhatsApp';
    protected $name = 'whatsapp-campaigns:install-priority-schema';
    protected $description = 'Applica solo lo schema della migration AddWhatsAppCampaignPriority.';

    public function run(array $params)
    {
        WhatsAppCampaignPrioritySchema::install(Database::connect('platform'));
        CLI::write('Schema priorità campagne WhatsApp disponibile.', 'green');
    }
}
