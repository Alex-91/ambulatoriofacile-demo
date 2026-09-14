<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{TenantCatalogService,TenantDatabaseConnector};

class PersonnelAccessInstall extends BaseCommand
{
    protected $group='Accessi';
    protected $name='personnel:install-access';
    protected $description='Verifica lo schema di disattivazione utenti per un tenant esplicito.';
    protected $usage='personnel:install-access <tenant-id> [--apply]';
    protected $options=['--apply'=>'Applica solo la migration dei blocchi accesso.'];
    public function run(array $params)
    {
        if (!preg_match('/^[1-9][0-9]*$/D',(string)($params[0] ?? ''))) { CLI::error('Specificare un tenant numerico.'); return EXIT_ERROR; }
        $tenant=(new TenantCatalogService())->getTenantById((int)$params[0]);
        if (!$tenant || empty($tenant['is_active'])) { CLI::error('Tenant non disponibile.'); return EXIT_ERROR; }
        $db=(new TenantDatabaseConnector())->connect($tenant);
        if (CLI::getOption('apply')!==null) {
            require_once APPPATH.'Database/Migrations/2026-09-14-090001_CreatePersonnelAccessBlocks.php';
            (new \App\Database\Migrations\CreatePersonnelAccessBlocks(\Config\Database::forge($db)))->up();
        }
        $ready=$db->tableExists('personnel_access_blocks');
        CLI::write($ready ? 'Gestione blocchi accesso pronta.' : 'Schema da inizializzare.');
        return $ready ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
