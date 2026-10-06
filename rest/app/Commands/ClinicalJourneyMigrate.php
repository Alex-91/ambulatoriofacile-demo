<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{ClinicalFeatureService,TenantCatalogService,TenantDatabaseConnector};

/** Only adds journey tables to already prepared, entitled clinical tenant databases. */
final class ClinicalJourneyMigrate extends BaseCommand
{
    protected $group='Clinical';
    protected $name='clinical:journey-migrate';
    protected $description='Check the encounter schema for active clinical spaces; --apply creates missing journey tables.';
    protected $options=['--apply'=>'Apply the additive migration to prepared clinical spaces.'];
    public function run(array $params)
    {
        $platform=\Config\Database::connect('platform');
        if (!$platform->tableExists('platform_tenants')) { CLI::write('No tenant catalog; skipped.'); return EXIT_SUCCESS; }
        $catalog=new TenantCatalogService();$features=new ClinicalFeatureService();$connector=new TenantDatabaseConnector();
        require_once APPPATH.'Database/Migrations/2026-10-06-190001_CreateClinicalJourneys.php';
        foreach ($platform->table('platform_tenants')->select('id_tenant')->where('is_active',1)->get()->getResultArray() as $row) {
            $id=(int)$row['id_tenant'];
            if (!$features->isEnabledForTenant($id)) continue;
            $db=$connector->connect($catalog->getTenantById($id));
            if (!$db->tableExists('clinical_entries') || !$db->tableExists('dap02_clients')) { CLI::write('Tenant '.$id.': clinical setup not initialized; skipped.'); continue; }
            if (CLI::getOption('apply')!==null) {
                (new \App\Database\Migrations\CreateClinicalJourneys(\Config\Database::forge($db)))->up();
                unset($db->dataCache['table_names']);
            }
            $ready=$db->tableExists('clinical_journeys') && $db->tableExists('clinical_journeys_events');
            CLI::write('Tenant '.$id.': '.($ready?'journey schema ready':'journey migration required'));
            if (!$ready && CLI::getOption('apply')!==null) throw new \RuntimeException('Journey schema not ready for tenant '.$id);
        }
        return EXIT_SUCCESS;
    }
}
