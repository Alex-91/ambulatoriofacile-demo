<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{ClinicalSetupService,TenantCatalogService,TenantDatabaseConnector};
class ClinicalCheck extends BaseCommand
{
    protected $group='Clinical';
    protected $name='clinical:check';
    protected $description='Collaudo tecnico dello spazio esplicito con contenuto temporaneo sintetico; nessun paziente creato.';
    protected $usage='clinical:check <tenant-id> [--profile <profile-id>]';
    protected $options=['--profile'=>'Prova anche QIDO del collegamento già configurato.'];
    public function run(array $params)
    {
        try {
            if (!preg_match('/^[1-9][0-9]*$/D',(string)($params[0] ?? ''))) throw new \RuntimeException('Specificare lo spazio.');
            $id=(int)$params[0]; $tenant=(new TenantCatalogService())->getTenantById($id);
            if (!$tenant || empty($tenant['is_active'])) throw new \RuntimeException('Spazio non disponibile.');
            $result=(new ClinicalSetupService((new TenantDatabaseConnector())->connect($tenant),$id))->acceptance(CLI::getOption('profile'));
            CLI::write(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return $result['ready'] ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (\Throwable) { CLI::error('Collaudo non completato. Verificare spazio, abilitazioni e configurazione.'); return EXIT_ERROR; }
    }
}
