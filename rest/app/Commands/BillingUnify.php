<?php
namespace App\Commands;

use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{TenantCatalogService,TenantDatabaseConnector,UnifiedBillingArchive};

class BillingUnify extends BaseCommand
{
    protected $group='Billing';
    protected $name='billing:unify';
    protected $description='Verifica la migrazione verso un archivio fatture unico per il tenant esplicito.';
    protected $usage='billing:unify <tenant-id> [--apply]';
    protected $options=['--apply'=>'Installa gli schemi necessari e migra con conservazione dell’archivio precedente.'];

    public function run(array $params)
    {
        $id=(int)($params[0]??0);
        $tenant=$id>0?(new TenantCatalogService())->getTenantById($id):null;
        if (!$tenant || empty($tenant['is_active'])) { CLI::error('Indicare un tenant attivo esplicito.'); return EXIT_ERROR; }
        try {
            $db=(new TenantDatabaseConnector())->connect($tenant);
            if (!$db->tableExists('dap02_clients')) throw new \RuntimeException('Anagrafica del tenant non disponibile.');
            $apply=CLI::getOption('apply')!==null;
            if ($apply) {
                if (!$db->tableExists('billing_documents')) {
                    foreach (['2026-07-06-000003_CreateBillingDocumentsTable'=>'CreateBillingDocumentsTable','2026-08-03-000001_AddBillingCollectionsAndEmailDelivery'=>'AddBillingCollectionsAndEmailDelivery'] as $file=>$name) {
                        require_once APPPATH.'Database/Migrations/'.$file.'.php'; $class='App\\Database\\Migrations\\'.$name;
                        (new $class(\Config\Database::forge($db)))->up();
                    }
                }
                $required=['payment_status','due_date','paid_at','ts_expense_type_code','ts_opposition_flag','linked_ts_document_id','patient_email','invoice_email_sent_at','last_reminder_sent_at','reminder_count','email_last_recipient','email_last_error'];
                if (array_diff($required,$db->getFieldNames('billing_documents'))) throw new \RuntimeException('Schema fatturazione precedente incompleto: eseguire la riparazione dedicata prima di unificare.');
                require_once APPPATH.'Database/Polyclinic/CreatePolyclinicAdministration.php';
                (new \App\Database\Polyclinic\CreatePolyclinicAdministration(\Config\Database::forge($db)))->up();
            }
            $result=(new UnifiedBillingArchive())->migrate($db,$apply);
            if ($apply) {
                $map=(new \App\Services\TenantFeatureService())->resolveEffectiveFeatureMapForTenant($id);
                if (!empty($map['polyclinic_billing'])) {
                    $features=new \App\Models\PlatformFeaturesModel();
                    $overrides=new \App\Models\PlatformTenantFeaturesModel();
                    foreach (array_merge(['billing'],array_keys(\App\Services\BillingCapabilities::FEATURES)) as $key) {
                        $feature=$features->findByKey($key); $featureId=(int)$feature['id_feature'];
                        if (!$overrides->where('id_tenant',$id)->where('id_feature',$featureId)->first()) {
                            if (!$overrides->setOverride($id,$featureId,true,null,'unified_billing_migration')) throw new \RuntimeException('Archivio migrato, verificare attivazione '.$key.'.');
                        }
                    }
                }
            }
            CLI::write(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            return EXIT_SUCCESS;
        } catch (\Throwable $e) { CLI::error($e->getMessage()); return EXIT_ERROR; }
    }
}
