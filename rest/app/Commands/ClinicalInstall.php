<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{TenantCatalogService,TenantDatabaseConnector};

class ClinicalInstall extends BaseCommand
{
    protected $group='Clinical';
    protected $name='clinical:install';
    protected $description='Verifica lo schema clinico di un tenant; --apply esegue la migrazione dedicata.';
    protected $usage='clinical:install <tenant-id> [--apply]';
    protected $options=['--apply'=>'Applica la sola migrazione clinica al tenant indicato.'];
    public function run(array $params)
    {
        $id=(int)($params[0] ?? 0);
        if($id<=0) { CLI::error('Specificare un tenant numerico esplicito.');return EXIT_ERROR; }
        $tenant=(new TenantCatalogService())->getTenantById($id);
        if(!$tenant || !(int)($tenant['is_active'] ?? 0)) { CLI::error('Tenant non disponibile.');return EXIT_ERROR; }
        $db=(new TenantDatabaseConnector())->connect($tenant);
        if(!$db->tableExists('dap02_clients')) { CLI::error('Database pazienti non disponibile.');return EXIT_ERROR; }
        $tables=['clinical_entries','clinical_objects','clinical_consent_templates','clinical_consents','clinical_patient_state','clinical_audit'];
        if(CLI::getOption('apply')!==null) {
            require_once APPPATH.'Database/Migrations/2026-09-12-160001_CreateClinicalRecords.php';
            (new \App\Database\Migrations\CreateClinicalRecords(\Config\Database::forge($db)))->up();
        }
        $missing=array_values(array_filter($tables,fn($table)=>!$db->tableExists($table)));
        CLI::write($missing ? 'Schema da inizializzare: '.implode(', ',$missing) : 'Schema clinico presente per tenant '.$id.'.');
        return $missing ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
