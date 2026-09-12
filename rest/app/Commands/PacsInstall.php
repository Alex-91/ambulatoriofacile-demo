<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{TenantCatalogService,TenantDatabaseConnector};

class PacsInstall extends BaseCommand
{
    protected $group='PACS';
    protected $name='pacs:install';
    protected $description='Verifica lo schema PACS del tenant esplicito; --apply applica la sola migrazione PACS.';
    protected $usage='pacs:install <tenant-id> [--apply]';
    protected $options=['--apply'=>'Crea le tabelle PACS per lo spazio indicato. Non abilita il modulo.'];
    public function run(array $params)
    {
        if (!preg_match('/^[1-9][0-9]*$/D',(string)($params[0] ?? ''))) { CLI::error('Specificare un tenant numerico.'); return EXIT_ERROR; }
        $id=(int)$params[0];
        $tenant=(new TenantCatalogService())->getTenantById($id);
        if (!$tenant || empty($tenant['is_active'])) { CLI::error('Tenant non disponibile.'); return EXIT_ERROR; }
        $db=(new TenantDatabaseConnector())->connect($tenant);
        if (!$db->tableExists('dap02_clients')) { CLI::error('Archivio pazienti non disponibile.'); return EXIT_ERROR; }
        if (CLI::getOption('apply')!==null) {
            require_once APPPATH.'Database/Migrations/2026-09-13-100001_CreatePacsIntegration.php';
            (new \App\Database\Migrations\CreatePacsIntegration(\Config\Database::forge($db)))->up();
        }
        $missing=array_filter(['pacs_patient_bindings','pacs_study_links','pacs_audit'],fn($t)=>!$db->tableExists($t));
        CLI::write($missing ? 'Schema PACS da inizializzare: '.implode(', ',$missing) : 'Schema PACS presente per tenant '.$id.'.');
        return $missing ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
