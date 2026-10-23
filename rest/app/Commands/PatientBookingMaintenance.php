<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\PatientBookingMaintenanceRunner;

class PatientBookingMaintenance extends BaseCommand
{
    protected $group='Prenotazioni';
    protected $name='prenota:scadenze';
    protected $description='Scade richieste e invia comunicazioni dello spazio indicato. Eseguire ogni 5 minuti.';
    protected $options=['--tenant'=>'ID spazio','--all-enabled'=>'Tutti gli spazi attivi abilitati, inclusi quelli futuri','--patient-url'=>'URL HTTPS del percorso interno'];
    public function run(array $params)
    {
        $id = (int)CLI::getOption('tenant');
        $all = (bool)CLI::getOption('all-enabled');
        $url = (string)CLI::getOption('patient-url');
        $parts = parse_url($url);
        if (($all && $id > 0) || (!$all && $id <= 0) || !filter_var($url, FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? '') !== 'https' || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])) {
            CLI::error('Specificare --tenant ID oppure --all-enabled e --patient-url URL_HTTPS.');
            return EXIT_ERROR;
        }
        try {
            $runner = new PatientBookingMaintenanceRunner();
            $result = $all ? $runner->runEnabled($url) : $runner->runTenant($id, $url);
            CLI::write(json_encode($result, JSON_THROW_ON_ERROR));
            return ($all ? $result['failed'] > 0 : $result['status'] === 'failed') ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (\Throwable $e) {
            CLI::error('Manutenzione prenotazioni fallita: '.get_class($e));
            return EXIT_ERROR;
        }
    }
}
