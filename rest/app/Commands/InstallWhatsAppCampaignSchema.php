<?php

namespace AppCommands;

use AppDatabaseMigrationsCreateWhatsappCampaignTables;
use CodeIgniterCLIBaseCommand;
use CodeIgniterCLICLI;

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
