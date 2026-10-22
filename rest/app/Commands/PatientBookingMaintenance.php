<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{TenantCatalogService,TenantDatabaseConnector,PatientBookingSettings,PatientBookingAgenda,PatientBookingNotifications};

class PatientBookingMaintenance extends BaseCommand
{
    protected $group='Prenotazioni';
    protected $name='prenota:scadenze';
    protected $description='Scade richieste e invia comunicazioni dello spazio indicato. Eseguire ogni 5 minuti.';
    protected $options=['--tenant'=>'ID spazio','--patient-url'=>'URL assoluto del percorso interno dello spazio'];
    public function run(array $params)
    {
        $id=(int)CLI::getOption('tenant');$url=(string)CLI::getOption('patient-url');
        if($id<=0 || !filter_var($url,FILTER_VALIDATE_URL)){CLI::error('Specificare --tenant ID e --patient-url URL.');return;}
        $tenant=(new TenantCatalogService())->getTenantById($id);$settings=(new PatientBookingSettings())->get($id);
        if(!$tenant || !$settings['enabled']){CLI::error('Spazio non abilitato.');return;}
        $db=(new TenantDatabaseConnector())->connect($tenant);
        if($db->database==='ambulatoriofacile_demo'){CLI::error('Demo congelata esclusa.');return;}
        $expired=(new PatientBookingAgenda($db,$settings,0))->expire();
        $notifications=new PatientBookingNotifications($db,$tenant,$settings);$notifications->reminders();$result=$notifications->run($url);
        CLI::write(json_encode(['expired'=>$expired]+$result));
    }
}
