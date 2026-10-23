<?php
namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand, CLI};
use App\Services\{PatientBookingSchema, TenantCatalogService, TenantDatabaseConnector};

class InstallPatientBooking extends BaseCommand
{
    protected $group = 'Prenotazioni';
    protected $name = 'prenota:install';
    protected $description = 'Installa il modulo interno: --platform oppure --tenant ID. Non abilita spazi.';
    protected $options = ['--platform' => 'Schema piattaforma', '--tenant' => 'ID dello spazio da aggiornare'];

    public function run(array $params)
    {
        if (CLI::getOption('platform')) {
            PatientBookingSchema::platform(\Config\Database::connect('platform'));
            CLI::write('Schema piattaforma pronto. Nessuno spazio abilitato.', 'green');
            return;
        }
        $id = (int) CLI::getOption('tenant');
        if ($id <= 0) { CLI::error('Specificare --platform oppure --tenant ID.'); return; }
        $tenant = (new TenantCatalogService())->getTenantById($id);
        if (!$tenant) { CLI::error('Spazio inesistente.'); return; }
        $db = (new TenantDatabaseConnector())->connect($tenant);
        if (PatientBookingSchema::isFrozenDemoDatabase($db)) {
            CLI::error('Database della demo congelata escluso.'); return;
        }
        PatientBookingSchema::tenant($db);
        CLI::write('Schema prenotazioni pronto per lo spazio '.$id.'.', 'green');
    }
}
