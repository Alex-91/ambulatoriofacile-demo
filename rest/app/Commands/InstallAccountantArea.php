<?php
namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand, CLI};
use App\Database\Accountant\AddAccountantDelegations;

class InstallAccountantArea extends BaseCommand
{
    protected $group = 'Billing';
    protected $name = 'billing:install-accountant';
    protected $description = 'Verifica lo schema deleghe nel DB platform; --apply crea le tre tabelle dedicate.';
    protected $options = ['--apply' => 'Applica la migration dedicata solo al database platform.'];

    public function run(array $params)
    {
        $db = \Config\Database::connect('platform');
        if (!$db->tableExists('platform_tenants')) { CLI::error('Database platform non inizializzato.'); return EXIT_ERROR; }
        if (CLI::getOption('apply') !== null) {
            (new AddAccountantDelegations(\Config\Database::forge($db)))->up();
        }
        $ready = (new \App\Services\AccountantDelegationService($db))->ready();
        CLI::write($ready ? 'Schema deleghe disponibile su platform.' : 'Schema assente: eseguire con --apply per attivare.');
        return $ready ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
