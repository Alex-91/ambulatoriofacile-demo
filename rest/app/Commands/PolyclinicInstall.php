<?php
namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand, CLI};
use App\Services\{TenantCatalogService, TenantDatabaseConnector};
use App\Database\Polyclinic\CreatePolyclinicAdministration;

class PolyclinicInstall extends BaseCommand
{
    protected $group = 'Polyclinic';
    protected $name = 'polyclinic:install';
    protected $description = 'Verifica amministrazione poliambulatorio; --apply installa solo sul tenant indicato.';
    protected $usage = 'polyclinic:install <tenant-id> [--apply]';
    protected $options = ['--apply'=>'Applica la migrazione amministrativa dedicata.'];

    public function run(array $params)
    {
        $id=(int)($params[0]??0);
        if ($id<=0) { CLI::error('Indicare un tenant esplicito.'); return EXIT_ERROR; }
        $tenant=(new TenantCatalogService())->getTenantById($id);
        if (!$tenant || !(int)($tenant['is_active']??0)) { CLI::error('Tenant non disponibile.'); return EXIT_ERROR; }
        $db=(new TenantDatabaseConnector())->connect($tenant);
        if (!$db->tableExists('dap02_clients')) {
            CLI::error('Installare prima anagrafica sul tenant selezionato.'); return EXIT_ERROR;
        }
        require_once APPPATH.'Database/Polyclinic/CreatePolyclinicAdministration.php';
        if (CLI::getOption('apply')!==null) (new CreatePolyclinicAdministration(\Config\Database::forge($db)))->up();
        $missing=array_filter(CreatePolyclinicAdministration::TABLES, fn($table)=>!$db->tableExists($table));
        CLI::write($missing ? 'Tabelle mancanti: '.implode(', ',$missing) : 'Schema amministrativo presente per tenant '.$id.'.');
        return $missing ? EXIT_ERROR : EXIT_SUCCESS;
    }
}
