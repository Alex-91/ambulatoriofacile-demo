<?php

namespace App\Commands;

use App\Database\Migrations\CreateMassCampaignTables;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class InstallMassCampaignSchema extends BaseCommand
{
    protected $group = 'Comunicazioni';
    protected $name = 'mass-campaigns:install-schema';
    protected $description = 'Crea esclusivamente la coda separata delle campagne email/SMS.';

    public function run(array $params)
    {
        require_once APPPATH . 'Database/Migrations/2026-09-22-100001_CreateMassCampaignTables.php';
        (new CreateMassCampaignTables())->up();
        CLI::write('Schema campagne email/SMS disponibile.', 'green');
    }
}
