<?php
namespace App\Commands;
use CodeIgniter\CLI\{BaseCommand,CLI};
use App\Services\{ClinicalJourneyService,TenantCatalogService,TenantDatabaseConnector,AgendaDoctorIdService};

final class ClinicalJourneySeed extends BaseCommand
{
    protected $group='Testing';
    protected $name='clinical:seed-journey-test';
    protected $description='Idempotent synthetic agenda for the isolated clinical test only.';
    public function run(array $params)
    {
        ClinicalJourneyService::assertTest();
        $password=(string)getenv('AF_JOURNEY_FIXTURE_PASSWORD');
        if (strlen($password)<24) throw new \RuntimeException('Set a dedicated fixture password (24+ characters).');
        $tenant=(new TenantCatalogService())->getTenantById(4);
        $connector=new TenantDatabaseConnector();$config=$connector->buildConnectionConfig($tenant);
        if ($config['hostname']!=='uapmyovmgml4ov24y4d94tao' || $config['database']!=='af_ambiente_di_test') throw new \RuntimeException('Wrong test destination.');
        $db=$connector->connect($tenant);$platform=\Config\Database::connect('platform');
        (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($db);
        require_once APPPATH.'Database/Migrations/2026-10-06-180001_CreateClinicalJourneyTest.php';
        (new \App\Database\Migrations\CreateClinicalJourneyTest(\Config\Database::forge($db)))->up();
        require_once APPPATH.'Database/Migrations/2026-09-12-160001_CreateClinicalRecords.php';
        (new \App\Database\Migrations\CreateClinicalRecords(\Config\Database::forge($db)))->up();
        $encrypt=static function(array $fields) use($db): array {
            $iv=random_bytes(16);$out=['vector_id'=>$iv];
            foreach($fields as $k=>$v) $out[$k]=$db->query('SELECT HEX(AES_ENCRYPT(?,@key_str,?)) AS v',[(string)$v,$iv])->getRowArray()['v'];
            return $out;
        };
        $actors=[];$now=date('Y-m-d H:i:s');
        foreach([['giuseppe','Giuseppe','Radiologo TEST',1],['anna','Anna','Ecografista TEST',1],['segreteria','Sara','Segreteria TEST',3]] as [$slug,$name,$surname,$role]) {
            $email=$slug.'.percorso@example.test';
            $u=$db->table('dap01_users')->where('username',$email)->get()->getRowArray();
            if (!$u) {
                $db->table('dap01_users')->insert($encrypt(['password'=>$password])+['username'=>$email,'tipo_user'=>2,'is_active'=>1,'privacy'=>1]);
                $uid=(int)$db->insertID();
                $db->table('dap03_personale')->insert($encrypt(['nome'=>$name,'cognome'=>$surname,'qualifica'=>$role===1?'Dott.':'','email'=>$email,'cellulare'=>''])+['id_user'=>$uid,'tipo'=>$role,'luogo'=>0,'is_active'=>1,'is_dot'=>$role===1?0:1,'show_in_agenda'=>1]);
            } else { $uid=(int)$u['id_user']; }
            $staff=$db->table('dap03_personale')->where('id_user',$uid)->get()->getRowArray();
            $agenda=$role===1?(new AgendaDoctorIdService($db))->ensureForPersonale((int)$staff['id_personale'],1):0;
            $pu=$platform->table('platform_users')->where('email',$email)->get()->getRowArray();
            if (!$pu) {
                $platform->table('platform_users')->insert(['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'first_name'=>$name,'last_name'=>$surname,'status'=>'active','is_platform_admin'=>0,'must_reset_password'=>0,'email_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
                $pid=(int)$platform->insertID();
            } else { $pid=(int)$pu['id_platform_user']; }
            if (!$platform->table('platform_user_tenants')->where('id_platform_user',$pid)->where('id_tenant',4)->countAllResults()) {
                $platform->table('platform_user_tenants')->insert(['id_platform_user'=>$pid,'id_tenant'=>4,'app_user_id'=>$uid,'tenant_role'=>'tenant_staff','is_app_admin'=>0,'is_default'=>1,'is_owner'=>0,'invitation_status'=>'accepted','accepted_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
            }
            $actors[$slug]=['user'=>$uid,'staff'=>(int)$staff['id_personale'],'agenda'=>$agenda,'email'=>$email];
        }
        foreach(['giuseppe','anna'] as $slug) {
            $link=['id_seg'=>$actors['segreteria']['staff'],'id_dot'=>$actors[$slug]['staff']];
            if (!$db->table('dap14_seg_dot')->where($link)->countAllResults()) $db->table('dap14_seg_dot')->insert($link);
        }
        $patientIds=[];
        foreach([['Mario','Rossi TEST'],['Lucia','Verdi TEST'],['Paolo','Bianchi TEST'],['Elena','Neri TEST'],['Carlo','Blu TEST'],['Maria','Viola TEST']] as $i=>[$name,$surname]) {
            $tag='CLINICAL-JOURNEY-20261006-'.($i+1);
            $u=$db->table('dap01_users')->where('username',$tag)->get()->getRowArray();
            if (!$u) {
                $db->table('dap01_users')->insert($encrypt(['password'=>bin2hex(random_bytes(24))])+['username'=>$tag,'tipo_user'=>3,'is_active'=>0]);$uid=(int)$db->insertID();
                $db->table('dap02_clients')->insert($encrypt(['nome'=>$name,'cognome'=>$surname,'email'=>strtolower($name).'.percorso@example.test','cellulare'=>'','codice_fiscale'=>'','data_nascita'=>'1980-01-01','indirizzo'=>'Via Esempio 1','citta'=>'Vicenza'])+['id_user'=>$uid,'id_personale'=>$actors['giuseppe']['staff'],'avviso_mail'=>0,'appointment_reminder_sms_enabled'=>0]);
                $client=(int)$db->insertID();
            } else { $client=(int)$db->table('dap02_clients')->where('id_user',$u['id_user'])->get()->getRowArray()['id_client']; }
            foreach(['giuseppe','anna'] as $slug) {
                $link=['id_client'=>$client,'id_dot'=>$actors[$slug]['staff']];
                if (!$db->table('dap09_client_doctor')->where($link)->countAllResults()) $db->table('dap09_client_doctor')->insert($link);
            }
            $patientIds[]=['id'=>$client,'nome'=>$name,'cognome'=>$surname];
        }
        $type=$db->table('dap44_agenda_tipi_visita')->where('nome','Ecografia addominale TEST')->get()->getRowArray();
        if (!$type) {$db->table('dap44_agenda_tipi_visita')->insert(['nome'=>'Ecografia addominale TEST','durata_minuti'=>30,'colore'=>'#1597a6','attivo'=>1,'created_at'=>$now]);$typeId=(int)$db->insertID();} else $typeId=(int)$type['id_tipo_visita'];
        $count=0;$booked=0;$firstDay=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Rome')));
        foreach(['giuseppe','anna'] as $di=>$slug) {
            for($day=0;$day<10;$day++) {
                $date=$firstDay->modify('+'.$day.' days')->format('Y-m-d');
                for($slot=0;$slot<12;$slot++) {
                    $from=$date.' '.sprintf('%02d:%02d:00',9+intdiv($slot,2),($slot%2)*30);$to=date('Y-m-d H:i:s',strtotime($from.' +30 minutes'));
                    $exists=$db->table('dap11_agenda_slot')->where('id_dot',$actors[$slug]['agenda'])->where('ora_inizio',$from)->countAllResults();
                    if ($exists) continue;
                    $reserve=$slot<3;
                    $db->table('dap11_agenda_slot')->insert(['id_dot'=>$actors[$slug]['agenda'],'data_slot'=>$date,'ora_inizio'=>$from,'ora_fine'=>$to,'tipo_slot'=>'AMBULATORIO','stato'=>$reserve?'PRENOTATO':'LIBERO','origine_slot'=>'EXTRA','ambulatorio'=>'Studio ecografico TEST','note_interne'=>'CLINICAL-JOURNEY-TEST']);$sid=(int)$db->insertID();$count++;
                    if($reserve) {
                        $p=$patientIds[$di*3+$slot];
                        $db->table('dap12_agenda_appuntamenti')->insert(['id_slot'=>$sid,'id_dot'=>$actors[$slug]['agenda'],'id_client'=>$p['id'],'id_tipo_visita'=>$typeId,'tipo_visita_label'=>'Ecografia addominale TEST','durata_minuti'=>30,'ora_inizio_appuntamento'=>$from,'ora_fine_appuntamento'=>$to,'cognome'=>$p['cognome'],'nome'=>$p['nome'],'stato'=>'CONFERMATO','created_by'=>$actors['segreteria']['user'],'note'=>'Dati fittizi per collaudo percorso esame']);$booked++;
                    }
                }
            }
        }
        CLI::write(json_encode(['actors'=>$actors,'patients'=>$patientIds,'new_slots'=>$count,'new_appointments'=>$booked,'from'=>$firstDay->format('Y-m-d'),'until'=>$firstDay->modify('+9 days')->format('Y-m-d')],JSON_PRETTY_PRINT));
        return EXIT_SUCCESS;
    }
}
