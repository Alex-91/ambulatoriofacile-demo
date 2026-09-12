<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\Pacs\{PacsProfiles,PacsFeatureService,PacsException};

class PacsCheck extends BaseCommand
{
    protected $group='PACS';
    protected $name='pacs:check';
    protected $description='Verifica locale della configurazione PACS, senza traffico remoto né stampa di credenziali.';
    protected $usage='pacs:check <tenant-id>';
    public function run(array $params)
    {
        $id=(int)($params[0] ?? 0);
        try {
            $rows=(new PacsProfiles())->forTenant($id);
            CLI::write((new PacsFeatureService())->isEnabledForTenant($id) ? 'Modulo abilitato.' : 'Modulo disabilitato o cartella clinica non abilitata.');
            if (!$rows) { CLI::write('Nessun collegamento configurato.'); return EXIT_ERROR; }
            $ok=true;
            foreach ($rows as $p) {
                $ready=true;
                foreach ($p['auth']==='basic' ? ['username_env','password_env'] : ($p['auth']==='bearer' ? ['token_env'] : []) as $k) {
                    if (!getenv($p[$k])) $ready=false;
                }
                $ok=$ok && $ready;
                CLI::write($p['id'].': '.($p['enabled'] ? 'attivo' : 'disattivo').' · credenziali '.($ready ? 'presenti/non richieste' : 'da configurare'));
            }
            return $ok ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (PacsException $e) { CLI::error($e->getMessage()); return EXIT_ERROR; }
    }
}
