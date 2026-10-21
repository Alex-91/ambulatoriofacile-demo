<?php
// Isolated regression: no application bootstrap, database or network access.
namespace CodeIgniter\Database {
    class BaseConnection {
        public function table($name) { return $this; }
        public function select($value) { return $this; }
        public function join(...$args) { return $this; }
        public function where(...$args) { return $this; }
        public function get() { return $this; }
        public function getRowArray() { return ['data_slot'=>'2026-10-22','ora_inizio'=>'10:00','nome'=>'Test','cognome'=>'Synthetic']; }
    }
}
namespace App\Models {
    class AgendaModel {
        public function getAgendaProfessionalByLegacyId($id) {
            return ['id_user'=>$id, 'id_personale'=>$id, 'tipo'=>$id === 99 ? 2 : 1];
        }
    }
    class PersonaleModel {
        public static int $actorType = 4;
        public function getPersonaleDecryptedByUserId($id) {
            return ['id_user'=>$id,'tipo'=>self::$actorType,'nome'=>'Virginia','cognome'=>'Synthetic'];
        }
        public function getPersonaleDecryptedById($id) { return ['nome'=>'Doctor','cognome'=>'Synthetic']; }
    }
}
namespace App\Services {
    class TenantContextService { public function getCurrentTenant() { return (object)['tenantId'=>1]; } }
    class TenantCatalogService { public function getTenantById($id) { return ['id_tenant'=>$id]; } }
    class AppointmentNotificationSettingsService {
        const TYPE_PATIENT_BOOKING='patient_booking', TYPE_DOCTOR_CROSS_BOOKING='doctor_cross_booking';
        const CHANNEL_EMAIL='email', CHANNEL_OTP='otp', CHANNEL_WHATSAPP='whatsapp', CHANNEL_SMS='sms';
        public static bool $enabled=true;
        public function resolveTenantSettings($id) { return ['module'=>['available'=>true]]; }
        public function resolveDispatchPlan($id,$type) {
            return ['enabled'=>self::$enabled && $type===self::TYPE_DOCTOR_CROSS_BOOKING,'channels'=>['otp']];
        }
    }
    class AppointmentMessageTemplateService {}
    class AppointmentNotificationChannelService {
        public static array $sent=[];
        public function describeRecipientForChannel($channel,$recipient) { return 'push:'.$recipient['user_id']; }
        public function providerLabel($channel) { return 'test'; }
        public function send($channel,$recipient,$message,$options) {
            self::$sent[]=compact('channel','recipient','message','options');
            return ['ok'=>true];
        }
    }
    class AppointmentNotificationLogService {
        public static array $entries=[];
        public function append($tenant,$entry) { self::$entries[]=$entry; }
    }
    class WhatsAppChatbotService {}
    class WhatsAppSmsFallbackService {}
    class TenantNotificationPolicyService { public function resolve(...$args) { return []; } }
}
namespace {
    function site_url($path) { return 'https://example.test/app/'.$path; }
    require __DIR__.'/../app/Services/StaffDoctorAccessService.php';
    require __DIR__.'/../app/Services/AgendaAppointmentNotificationService.php';

    use App\Services\AgendaAppointmentNotificationService;
    use App\Services\AppointmentNotificationChannelService as Channel;
    use App\Services\AppointmentNotificationSettingsService as Settings;
    use App\Services\AppointmentNotificationLogService as Logs;
    use App\Models\PersonaleModel;

    function check(bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    $cases = [
        // name, role, actor user, actor doctor, is doctor, target, enabled, sends
        ['Admin without agenda to Anita',4,11,0,false,13,true,1],
        ['Doctor to another doctor',1,12,12,true,13,true,1],
        ['Doctor to self',1,13,13,true,13,true,0],
        ['Admin to same account',4,13,0,false,13,true,0],
        ['Secretary remains excluded',3,11,0,false,13,true,0],
        ['Nurse remains excluded',2,11,0,false,13,true,0],
        ['Disabled flow',4,11,0,false,13,false,0],
        ['Non doctor recipient',4,11,0,false,99,true,0],
        ['Unknown role',0,11,0,false,13,true,0],
        ['Missing actor',4,0,0,false,13,true,0],
    ];
    foreach ($cases as [$name,$role,$actor,$doctor,$isDoctor,$target,$enabled,$expected]) {
        PersonaleModel::$actorType=$role;
        Settings::$enabled=$enabled;
        Channel::$sent=[];
        Logs::$entries=[];
        $service=new AgendaAppointmentNotificationService(new \CodeIgniter\Database\BaseConnection());
        $service->handleBookedAppointment(100,$target,$actor,$doctor,$isDoctor);
        check(count(Channel::$sent)===$expected,$name.': incorrect delivery count');
        if ($expected) {
            $sent=Channel::$sent[0];
            check($sent['recipient']['user_id']===$target,$name.': wrong recipient');
            check($sent['options']['push_data']['doctor_id']===$target,$name.': wrong agenda');
            check($sent['options']['push_data']['appointment_id']===100,$name.': wrong appointment');
            check(Logs::$entries[1]['actor_user_id']===$actor,$name.': incorrect audit actor');
            if ($role===4) {
                check(str_contains($sent['message'],'Virginia Synthetic'),$name.': missing Admin name');
            }
        }
        echo 'PASS '.$name.PHP_EOL;
    }
}
