<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'EntryController::index');
$routes->get('access', 'DemoController::access');
$routes->get('vertical/(:segment)', 'DemoController::vertical/$1');
$routes->get('access/(:segment)', 'DemoController::access/$1');
$routes->get('richiesta', 'DemoController::requestDemo');
$routes->post('richiesta/invia', 'DemoController::submitDemoRequest');
$routes->get('richieste-locali', 'DemoController::requestInbox');
$routes->get('richieste-locali/export', 'DemoController::exportRequestInbox');
$routes->get('demo', 'DemoController::index');
$routes->get('demo/access', 'DemoController::access');
$routes->get('demo/vertical/(:segment)', 'DemoController::vertical/$1');
$routes->get('demo/access/(:segment)', 'DemoController::access/$1');
$routes->get('demo/richiesta', 'DemoController::requestDemo');
$routes->post('demo/richiesta/invia', 'DemoController::submitDemoRequest');
$routes->get('demo/richieste-locali', 'DemoController::requestInbox');
$routes->get('demo/richieste-locali/export', 'DemoController::exportRequestInbox');
$routes->get('app', 'Home::index');
$routes->head('app', 'Home::index');
$routes->set404Override('App\Controllers\Errors::redirectHome');

//LOGIN
//$routes->get('posta', 'Home::posta');
//$routes->get('agenda', 'Home::agenda');
//$routes->get('chat',  'Home::chat');

$routes->get('login', 'Login\LoginController::index');
$routes->head('login', 'Login\LoginController::index');
$routes->post('login', 'Login\LoginController::login');
$routes->post('login/tenant-select', 'Login\LoginController::selectTenant');
$routes->get('login/recupero', 'Login\PlatformAccessController::recovery');
$routes->post('login/recupero/invia', 'Login\PlatformAccessController::sendRecovery');
$routes->get('login/password-imposta', 'Login\PlatformAccessController::passwordSetup');
$routes->post('login/password-imposta', 'Login\PlatformAccessController::savePassword');
$routes->get('login/piattaforma', 'Login\PlatformTenantSpacesController::index');
$routes->get('login/piattaforma/funzioni', 'Login\PlatformFeaturesController::index');
$routes->post('login/piattaforma/funzioni/save', 'Login\PlatformFeaturesController::save');
$routes->get('login/piattaforma/notifiche-appuntamenti', 'Login\PlatformAppointmentNotificationsController::index');
$routes->post('login/piattaforma/notifiche-appuntamenti/launch', 'Login\PlatformAppointmentNotificationsController::launch');
$routes->get('login/piattaforma/notifiche-appuntamenti/run', 'Login\PlatformAppointmentNotificationsController::run');
$routes->get('login/piattaforma/spazi-clienti', 'Login\PlatformTenantSpacesController::index');
$routes->post('login/piattaforma/spazi-clienti/master-accounts/sync', 'Login\PlatformTenantSpacesController::syncMasterAccounts');
$routes->post('login/piattaforma/spazi-clienti/master-accounts/accesso', 'Login\PlatformTenantSpacesController::sendMasterAccess');
$routes->post('login/piattaforma/spazi-clienti/master-accounts/save', 'Login\PlatformTenantSpacesController::saveMasterAccount');
$routes->post('login/piattaforma/spazi-clienti/master-accounts/revoke', 'Login\PlatformTenantSpacesController::revokeMasterAccount');
$routes->post('login/piattaforma/spazi-clienti/save', 'Login\PlatformTenantSpacesController::save');
$routes->post('login/piattaforma/spazi-clienti/delete', 'Login\PlatformTenantSpacesController::delete');
$routes->post('login/piattaforma/spazi-clienti/members/save', 'Login\PlatformTenantSpacesController::saveMember');
$routes->post('login/piattaforma/spazi-clienti/members/accesso', 'Login\PlatformTenantSpacesController::sendMemberAccess');
$routes->get('login/spazi/cambia/(:num)', 'Login\LoginController::switchTenant/$1');
$routes->get('login/spazio/funzioni', 'Tenant\SpaceFeatures::index');
$routes->post('login/spazio/funzioni/save', 'Tenant\SpaceFeatures::save');
$routes->get('login/spazio/notifiche-appuntamenti', 'Tenant\AppointmentNotifications::index');
$routes->post('login/spazio/notifiche-appuntamenti/save', 'Tenant\AppointmentNotifications::save');
$routes->get('login/spazio/utenti', 'Tenant\SpaceUsers::index');
$routes->post('login/spazio/utenti/save', 'Tenant\SpaceUsers::save');
$routes->post('login/spazio/utenti/accesso', 'Tenant\SpaceUsers::sendAccess');
$routes->get('login/spazio/onboarding', 'Tenant\Onboarding::index');
$routes->post('login/spazio/onboarding/completa', 'Tenant\Onboarding::complete');
$routes->get('tenant/switch/(:num)', 'Login\LoginController::switchTenant/$1');
$routes->get('recupero', 'Login\PlatformAccessController::recovery');
$routes->post('recupero/invia', 'Login\PlatformAccessController::sendRecovery');
$routes->get('password-imposta', 'Login\PlatformAccessController::passwordSetup');
$routes->post('password-imposta', 'Login\PlatformAccessController::savePassword');
$routes->get('piattaforma', 'Login\PlatformTenantSpacesController::index');
$routes->get('piattaforma/funzioni', 'Login\PlatformFeaturesController::index');
$routes->post('piattaforma/funzioni/save', 'Login\PlatformFeaturesController::save');
$routes->get('piattaforma/notifiche-appuntamenti', 'Login\PlatformAppointmentNotificationsController::index');
$routes->post('piattaforma/notifiche-appuntamenti/launch', 'Login\PlatformAppointmentNotificationsController::launch');
$routes->get('piattaforma/notifiche-appuntamenti/run', 'Login\PlatformAppointmentNotificationsController::run');
$routes->get('piattaforma/spazi-clienti', 'Login\PlatformTenantSpacesController::index');
$routes->post('piattaforma/spazi-clienti/master-accounts/sync', 'Login\PlatformTenantSpacesController::syncMasterAccounts');
$routes->post('piattaforma/spazi-clienti/master-accounts/accesso', 'Login\PlatformTenantSpacesController::sendMasterAccess');
$routes->post('piattaforma/spazi-clienti/master-accounts/save', 'Login\PlatformTenantSpacesController::saveMasterAccount');
$routes->post('piattaforma/spazi-clienti/master-accounts/revoke', 'Login\PlatformTenantSpacesController::revokeMasterAccount');
$routes->post('piattaforma/spazi-clienti/save', 'Login\PlatformTenantSpacesController::save');
$routes->post('piattaforma/spazi-clienti/delete', 'Login\PlatformTenantSpacesController::delete');
$routes->post('piattaforma/spazi-clienti/members/save', 'Login\PlatformTenantSpacesController::saveMember');
$routes->post('piattaforma/spazi-clienti/members/accesso', 'Login\PlatformTenantSpacesController::sendMemberAccess');
$routes->get('spazi/cambia/(:num)', 'Login\LoginController::switchTenant/$1');
$routes->get('spazio/funzioni', 'Tenant\SpaceFeatures::index');
$routes->post('spazio/funzioni/save', 'Tenant\SpaceFeatures::save');
$routes->get('spazio/notifiche-appuntamenti', 'Tenant\AppointmentNotifications::index');
$routes->post('spazio/notifiche-appuntamenti/save', 'Tenant\AppointmentNotifications::save');
$routes->get('spazio/utenti', 'Tenant\SpaceUsers::index');
$routes->post('spazio/utenti/save', 'Tenant\SpaceUsers::save');
$routes->post('spazio/utenti/accesso', 'Tenant\SpaceUsers::sendAccess');
$routes->get('spazio/onboarding', 'Tenant\Onboarding::index');
$routes->post('spazio/onboarding/completa', 'Tenant\Onboarding::complete');
$routes->get('logout', 'Login\LoginController::logout');
$routes->get('admin/personale/logout', 'Login\LoginController::logout');

//REGISTER
$routes->get('register', 'Register\RegisterController::index');
$routes->post('/register/salva', 'Register\RegisterController::salva'); // Gestisce il submit
$routes->get('api/doctors', 'DoctorController::getDoctors');

//RESET
$routes->get('reset', 'Reset\ResetController::index');
$routes->post('checkUsername', 'Reset\ResetController::checkUsername');
$routes->get('reset/cambio', 'Reset\ResetController::cambio');
$routes->post('reset/cambioPassword', 'Reset\ResetController::cambioPassword');
$routes->get('otp', 'OtpController::show');
$routes->post('checkMessaggio.php', 'LegacyWhatsappAppointmentController::checkMessaggio');
$routes->post('aggiornaNoteApp.php', 'LegacyWhatsappAppointmentController::aggiornaNoteApp');
$routes->post('checkAppMultiplo.php', 'LegacyWhatsappAppointmentController::checkAppMultiplo');
$routes->post('checkMessaggio', 'LegacyWhatsappAppointmentController::checkMessaggio');
$routes->post('aggiornaNoteApp', 'LegacyWhatsappAppointmentController::aggiornaNoteApp');
$routes->post('checkAppMultiplo', 'LegacyWhatsappAppointmentController::checkAppMultiplo');
//AUTH
$routes->get('auth', 'AuthMFA\AuthenticationController::index');
$routes->get('auth/handoff', 'AuthMFA\AuthenticationController::handoff');
$routes->post('auth/handoff', 'AuthMFA\AuthenticationController::handoff');
//$routes->post('authSMS', 'AuthMFA\AuthenticationController::indexSMS');
$routes->post('checkOtp', 'AuthMFA\AuthenticationController::checkOtp');
//$routes->get('test', 'AuthMFA\AuthenticationController::cryptoCheck');

    $routes->post('posta/send', 'Posta\InvioController::invia');        // nuovo messaggio o reply (decide dal POST 'id_message')
    $routes->post('allegati/sposta', 'Posta\InvioController::spostaAllegati'); // opzionale: utility stand-alone

//COMPOSE
$routes->get('compose', 'Compose\ComposeController::index');
$routes->get('posta/reply/(:segment)', 'Posta\PostaController::reply/$1'); // es. /posta/reply/M:142219
$routes->get('posta/pdf', 'Posta\PostaController::pdfThread', ['filter' => 'auth']);
$routes->group('push', static function($routes) {
    $routes->get('publicKey','PushController::publicKey');
    $routes->post('subscribe','PushController::subscribe');
    $routes->post('sync-permission','PushController::syncPermission');
    $routes->post('test','PushController::test');
});

$routes->group('admin', static function($routes){
    $routes->get('notifiche', 'Admin\NotifyController::form');
    $routes->post('notifiche/send', 'Admin\NotifyController::send');
});

$routes->post('auth/send-otp-wa', 'AuthMFA\AuthenticationController::sendOtpWa');
$routes->post('auth/send-otp-sms', 'AuthMFA\AuthenticationController::sendOtpSms');
$routes->post('auth/send-otp-email', 'AuthMFA\AuthenticationController::sendOtpEmail');
$routes->post('auth/save-email-send-otp', 'AuthMFA\AuthenticationController::saveEmailAndSendOtp');
$routes->get('push/debugUser/(:num)', 'PushController::debugUser/$1');
$routes->get('auth/qrcode', 'AuthMFA\AuthenticationController::qrcode');
$routes->get('auth/link', 'AuthMFA\AuthenticationController::link');
$routes->post('auth/send-otp-push', 'AuthMFA\AuthenticationController::sendOtpPushNow');
$routes->get('agenda/job-run/(:num)/(:segment)', 'Agenda::runRigeneraSlotConfigJob/$1/$2');
$routes->group('', ['filter' => 'auth'], static function($routes) {
    $routes->get('posta', 'Messages\MessageController::inbox', ['filter' => 'auth']);
   // $routes->get('posta/read/(:segment)', 'Posta\PostaController::read/$1');     // es. posta/read/M:123
   $routes->post('posta/read', 'Posta\PostaController::read');   // niente GET con id
    $routes->post('posta/star/(:segment)', 'Posta\PostaController::star/$1');    // es. posta/star/M:123
    $routes->post('posta/bulkDelete', 'Posta\PostaController::bulkDelete');
    $routes->post('posta/bulkGestita', 'Posta\PostaController::bulkGestita');
});
// Endpoint che riceve la PushSubscription + token e collega il device
$routes->post('auth/link-complete', 'AuthMFA\AuthenticationController::linkComplete');

$routes->post('posta/contacts', 'Posta\PostaController::contacts');

    $routes->post('auth/register-device-direct', 'AuthMFA\AuthenticationController::registerDeviceDirect');

$routes->get('posta/forward/(:segment)', 'Posta\PostaController::forward/$1');

/* Inoltro: invio (AJAX POST) */
$routes->post('posta/inoltra', 'Posta\PostaController::inoltra');

$routes->post('posta/attachment/upload', 'Posta\PostaController::uploadAttachmentTemp');
$routes->post('posta/attachment/delete', 'Posta\PostaController::deleteAttachmentTemp');
$routes->get('posta/attachment/list',   'Posta\PostaController::listAttachmentTemp');
$routes->get('posta/attachment/(:num)', 'Posta\PostaController::attachment/$1');
$routes->get('inviata', 'Posta\PostaController::sent');

$routes->post('draft/create', 'DraftController::create');  // crea bozza dap10_message
$routes->post('draft/save',   'DraftController::save');    // autosave
$routes->post('draft/delete', 'DraftController::delete');  // elimina bozza + allegati
$routes->get('draft/get/(:num)', 'DraftController::get/$1'); // carica bozza per edit


    // (opzionale) lista bozze
$routes->get('bozze', 'DraftController::index');
$routes->get('chat', 'Chat::index');
$routes->get('chat/thread/(:num)', 'Chat::thread/$1');

    // API
    $routes->get('chat/users', 'Chat::users');
$routes->get('chat/start/(:num)', 'Chat::startThread/$1');
    $routes->post('chat/send', 'Chat::send');
$routes->get('chat/poll', 'Chat::poll');
$routes->post('chat/clear', 'Chat::clear');       // svuota singola
$routes->post('chat/clearAll', 'Chat::clearAll'); // svuota tutte

    // NOTIFICHE (quando non sei in chat)
    $routes->get('chat/unread', 'Chat::unread');          // count totale + threads

$routes->group('', ['filter' => 'auth'], function($routes) {
    $routes->get('profilo', 'Profilo::index');
    $routes->post('profilo/salva', 'Profilo::salva');
        $routes->post('profilo/password/otp', 'Profilo::sendPasswordOtp');
        $routes->post('profilo/password', 'Profilo::salvaPassword');
$routes->post('profilo/device/disconnect', 'Profilo::disconnectDevice');
$routes->post('profilo/device/register-here', 'Profilo::registerDeviceHere'); // endpoint backend per il JS del profilo
  // token

});

$routes->group('admin', ['namespace' => 'App\Controllers\Admin'], function($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('whatsapp-reminders', 'WhatsappReminders::index');
    $routes->post('whatsapp-reminders/launch', 'WhatsappReminders::launch');
    $routes->get('whatsapp-reminders/run', 'WhatsappReminders::run');
    $routes->get('otp-statistiche', 'OtpStats::index');
    $routes->get('otp-statistiche/csv', 'OtpStats::exportLoginEmailCsv');
    $routes->get('anagrafica/sedi', 'AgendaSediController::index');
    $routes->post('anagrafica/sedi/save', 'AgendaSediController::saveAmbulatorio');
    $routes->post('anagrafica/sedi/toggle', 'AgendaSediController::toggleAmbulatorio');
    $routes->post('anagrafica/sedi/stanza/save', 'AgendaSediController::saveStanza');
    $routes->post('anagrafica/sedi/stanza/toggle', 'AgendaSediController::toggleStanza');
       $routes->get('personale/nuovo', 'Personale::create');
$routes->post('personale/salva', 'Personale::store');
$routes->get('personale/modifica_cliente', 'Clienti::index');
$routes->get('personale/visibilita-moduli', 'PersonaleModuleVisibility::index');
$routes->get('personale/visibilita-moduli/search', 'PersonaleModuleVisibility::search');
$routes->get('personale/visibilita-moduli/get/(:num)', 'PersonaleModuleVisibility::get/$1');
$routes->post('personale/visibilita-moduli/update', 'PersonaleModuleVisibility::update');
$routes->get('personale/dap14', 'PersonaleDap14::index');
$routes->post('personale/dap14/update', 'PersonaleDap14::update');
$routes->get('personale/dap15', 'PersonaleDap15::index');
$routes->post('personale/dap15/update', 'PersonaleDap15::update');
$routes->get('clienti/search',  'Clienti::search');      // AJAX: lista risultati
$routes->get('clienti/get/(:num)', 'Clienti::get/$1');    // AJAX: dettaglio
$routes->post('clienti/device/disconnect', 'Clienti::disconnectDevice');
$routes->post('clienti/update', 'Clienti::update');  
$routes->get('personale/modifica_personale', 'PersonaleEdit::index');

$routes->get('personale/search', 'PersonaleEdit::search');      // AJAX
$routes->get('personale/get/(:num)', 'PersonaleEdit::get/$1');  // AJAX
$routes->post('personale/update', 'PersonaleEdit::update');     // POST
$routes->post('personale/elimina-dottore', 'PersonaleEdit::deleteDoctor');
$routes->get('personale/logs', 'Logs::index');
$routes->get('logs/read', 'Logs::read');        // AJAX: contenuto log
$routes->get('logs/download', 'Logs::download'); // download file
$routes->get('logs/list', 'Logs::listDates');   // (opzionale) date disponibili
$routes->get('personale/sostituti', 'Sostituti::index');
$routes->post('sostituti/salva', 'Sostituti::store');
$routes->post('sostituti/elimina/(:num)', 'Sostituti::delete/$1');

});


$routes->get('sostituzioni', 'SostituzioniController::index');
$routes->post('sostituzioni/choose', 'SostituzioniController::choose');
$routes->get('password/scaduta', 'PasswordController::index');
$routes->post('password/scaduta', 'PasswordController::update');
$routes->group('admin', ['filter' => 'auth'], function($routes) {
    $routes->get('personale/schede-utenti', 'Admin\SchedeUtenti::index');
    $routes->post('schede-utenti/cerca', 'Admin\SchedeUtenti::cercaUtente');
    $routes->get('schede-utenti/lista', 'Admin\SchedeUtenti::schedeUtente');
    $routes->post('schede-utenti/toggle', 'Admin\SchedeUtenti::toggle');
});
$routes->group('', ['filter' => 'auth'], static function($routes) {

    // ...

    $routes->get('prenotazioni/mmg', 'Prenotazioni\MedicoFamigliaController::index');
    $routes->get('prenotazioni/mmg/nuova', 'Prenotazioni\MedicoFamigliaController::nuova');
    $routes->get('prenotazioni/mmg/gestisci', 'Prenotazioni\MedicoFamigliaController::gestisci');

    // (facoltativi ora, ma ti lascio già lo scheletro)
    $routes->post('prenotazioni/mmg/prenota', 'Prenotazioni\MedicoFamigliaController::prenota');
    $routes->post('prenotazioni/mmg/cancella', 'Prenotazioni\MedicoFamigliaController::cancella');


     $routes->group('messaggi', ['namespace' => 'App\Controllers\Messages'], function($routes) {
        $routes->get('inbox', 'MessageController::inbox');
        $routes->get('inviati', 'MessageController::sent');
        $routes->get('bozze', 'MessageController::drafts');
        $routes->get('scrivi', 'MessageController::compose');
        $routes->get('thread/(:num)', 'MessageController::thread/$1');
        // STAMPA THREAD (PDF)
$routes->get('thread/(:num)/stampa', 'MessageController::printThread/$1');

$routes->get('allegato/(:num)', 'MessageController::attachment/$1');
        $routes->post('invia', 'MessageController::sendDraft');
        $routes->post('rispondi/(:num)', 'MessageController::reply/$1');
        $routes->post('inoltra/(:num)', 'MessageController::forward/$1');
        $routes->post('elimina/(:num)', 'MessageController::delete/$1');
$routes->post('elimina-multiplo', 'MessageController::bulkDelete');
        // API
        $routes->post('gestita/(:num)', 'MessageController::setHandled/$1');
$routes->post('gestita-multiplo', 'MessageController::bulkHandled');

        $routes->post('api/bozza/salva', 'MessageController::apiDraftSave');
        $routes->get('api/bozza/(:num)', 'MessageController::apiDraftLoad/$1');
        $routes->get('api/pazienti', 'MessageController::apiPatients');

        $routes->post('api/allegati/bozza/upload', 'MessageController::apiUploadDraftAttachment');
        $routes->delete('api/allegati/bozza/(:num)', 'MessageController::apiDeleteDraftAttachment/$1');
    });
});

$routes->group('prenotazioni/specialisti', ['namespace' => 'App\Controllers\Prenotazioni'], function($routes) {
    $routes->get('/', 'MedicoSpecialistaController::index');

    $routes->get('nuova', 'MedicoSpecialistaController::nuova');
    $routes->get('medici/(:num)', 'MedicoSpecialistaController::medici/$1');
    $routes->get('slot/(:num)', 'MedicoSpecialistaController::slot/$1');

    $routes->post('prenota', 'MedicoSpecialistaController::prenota');
    $routes->get('gestisci', 'MedicoSpecialistaController::gestisci');
    $routes->post('cancella', 'MedicoSpecialistaController::cancella');
});


$routes->get('chat/attachment/(:num)', 'Chat::attachment/$1');

$routes->group('agenda', function($routes) {
    $routes->get('/', 'Agenda::index');
    $routes->get('calendario', 'Agenda::calendario');
    $routes->get('calendario-team-day', 'Agenda::calendarioTeamDay');
    $routes->get('disponibilita-mese', 'Agenda::disponibilitaMese');
    $routes->get('refresh', 'Agenda::refresh');
    $routes->get('domiciliari', 'Agenda::domiciliari');
    $routes->get('note', 'Agenda::note');

    $routes->post('lock-slot', 'Agenda::lockSlot');
    $routes->post('refresh-lock', 'Agenda::refreshLock');
    $routes->post('unlock-slot', 'Agenda::unlockSlot');

    $routes->get('cerca-pazienti', 'Agenda::cercaPazienti');
    $routes->get('appuntamenti-paziente', 'Agenda::appuntamentiPaziente');
    $routes->get('paziente/(:num)', 'Agenda::getPaziente/$1');
    $routes->get('get-nota', 'Agenda::getNota');
    $routes->post('salva-paziente', 'Agenda::salvaPaziente');

    $routes->post('salva-appuntamento', 'Agenda::salvaAppuntamento');
    $routes->post('aggiorna-appuntamento', 'Agenda::aggiornaAppuntamento');
    $routes->post('elimina-appuntamento', 'Agenda::eliminaAppuntamento');

    $routes->post('salva-nota', 'Agenda::salvaNota');
    $routes->post('elimina-nota', 'Agenda::eliminaNota');

    $routes->post('genera-slot-periodo', 'Agenda::generaSlotPeriodo');
        $routes->get('config-slot', 'Agenda::configSlot');
    $routes->post('salva-config-slot', 'Agenda::salvaConfigSlot');
    $routes->post('rigenera-slot-config', 'Agenda::rigeneraSlotConfig');
    $routes->get('rigenera-slot-config-status', 'Agenda::rigeneraSlotConfigStatus');
    $routes->get('gestione-sedi', 'Admin\AgendaSediController::index');
    $routes->post('gestione-sedi/save', 'Admin\AgendaSediController::saveAmbulatorio');
    $routes->post('gestione-sedi/toggle', 'Admin\AgendaSediController::toggleAmbulatorio');
    $routes->post('gestione-sedi/stanza/save', 'Admin\AgendaSediController::saveStanza');
    $routes->post('gestione-sedi/stanza/toggle', 'Admin\AgendaSediController::toggleStanza');
    $routes->get('repair-recurring-extra-slots', 'Agenda::repairRecurringExtraSlots');
    $routes->post('repair-recurring-extra-slots', 'Agenda::eseguiRepairRecurringExtraSlots');
    $routes->get('visibilita-operatori', 'Agenda::visibilitaOperatori');
    $routes->post('salva-visibilita-operatori', 'Agenda::salvaVisibilitaOperatori');
    $routes->get('menu-ruoli', 'Agenda::menuRuoli');
$routes->get('menu-ruoli-dati', 'Agenda::menuRuoliDati');
$routes->post('salva-menu-ruoli', 'Agenda::salvaMenuRuoli');
$routes->get('storico-memo', 'Agenda::storicoMemo');
$routes->get('slot-bloccati', 'Agenda::slotBloccati');
$routes->post('sblocca-slot-bloccato', 'Agenda::sbloccaSlotBloccato');
$routes->get('gestione-slot-extra', 'Agenda::gestioneSlotExtra');
$routes->post('esegui-slot-extra-periodo', 'Agenda::eseguiSlotExtraPeriodo');
});
$routes->get('agenda/elimina-slot-extra', 'Agenda::eliminaSlotExtraView');
$routes->get('agenda/lista-slot-extra', 'Agenda::listaSlotExtra');
$routes->post('agenda/elimina-slot-extra-selezionati', 'Agenda::eliminaSlotExtraSelezionati');
$routes->post('agenda/elimina-appuntamento', 'Agenda::eliminaAppuntamento');
$routes->post('agenda/salva-appuntamento', 'Agenda::salvaAppuntamento');
$routes->get('visite-domiciliari/lista/(:num)', 'VisiteDomiciliari::lista/$1');
$routes->get('visite-domiciliari/dettaglio/(:num)', 'VisiteDomiciliari::dettaglio/$1');
$routes->post('visite-domiciliari/salva', 'VisiteDomiciliari::salva');
$routes->post('visite-domiciliari/aggiorna', 'VisiteDomiciliari::aggiorna');
$routes->post('visite-domiciliari/elimina', 'VisiteDomiciliari::elimina');
$routes->post('agenda/segna-nota-fatta', 'Agenda::segnaNotaFatta');
$routes->get('agenda/stato-giorno', 'Agenda::statoGiorno');
$routes->post('agenda/blocca-giorno', 'Agenda::bloccaGiorno');
$routes->post('agenda/blocca-domiciliari-giorno', 'Agenda::bloccaDomiciliariGiorno');
$routes->post('agenda/aggiungi-slot-extra', 'Agenda::aggiungiSlotExtra');
$routes->get('agenda/stampa-pdf-giorno', 'Agenda::stampaPdfGiorno');
$routes->get('agenda/stampa-pdf-memo', 'Agenda::stampaPdfMemo');
$routes->get('agenda/stampa-ticket-appuntamento/(:num)', 'Agenda::stampaTicketAppuntamento/$1');
$routes->get('auth/device-status', 'AuthMFA\AuthenticationController::deviceStatus');
$routes->post('agenda/sblocca-giorno', 'Agenda::sbloccaGiorno');
$routes->post('agenda/sblocca-domiciliari-giorno', 'Agenda::sbloccaDomiciliariGiorno');
$routes->get('agenda/get-nota-giorno', 'Agenda::getNotaGiorno');
$routes->post('agenda/salva-nota-giorno', 'Agenda::salvaNotaGiorno');
$routes->get('agenda/gestione-pazienti', 'Agenda::gestionePazienti');
$routes->get('agenda/lista-pazienti', 'Agenda::listaPazienti');
$routes->post('agenda/salva-paziente-gestione', 'Agenda::salvaPazienteGestione');
$routes->post('agenda/elimina-paziente', 'Agenda::eliminaPaziente');
$routes->get('agenda/get-paziente/(:num)', 'Agenda::getPaziente/$1');
$routes->get('agenda/copia-appuntamenti', 'Agenda::copiaAppuntamenti');
$routes->post('agenda/esegui-copia-appuntamenti', 'Agenda::eseguiCopiaAppuntamenti');
$routes->get('agenda/orari-giorno-copia', 'Agenda::orariGiornoCopia');
$routes->get('agenda/copia-appuntamenti-periodo', 'Agenda::copiaAppuntamentiPeriodo');
$routes->post('agenda/esegui-copia-appuntamenti-periodo', 'Agenda::eseguiCopiaAppuntamentiPeriodo');
$routes->get('agenda/copia-appuntamenti-settimanali', 'Agenda::copiaAppuntamentiSettimanali');
$routes->post('agenda/esegui-copia-appuntamenti-settimanali', 'Agenda::eseguiCopiaAppuntamentiSettimanali');
$routes->get('agenda/gestione-ferie', 'Agenda::gestioneFerie');
$routes->post('agenda/salva-ferie-periodo', 'Agenda::salvaFeriePeriodo');

$routes->get('agenda/elenco-ferie', 'Agenda::elencoFerie');
$routes->post('agenda/elimina-giorno-ferie', 'Agenda::eliminaGiornoFerie');
$routes->post('agenda/elimina-giorni-ferie-selezionati', 'Agenda::eliminaGiorniFerieSelezionati');
// =============================
// SMS APPUNTAMENTI
// =============================

$routes->get('agenda/gestione-sms-appuntamenti', 'Agenda::gestioneSmsAppuntamenti');
$routes->post('agenda/salva-sms-appuntamenti', 'Agenda::salvaSmsAppuntamenti');
$routes->post('agenda/disattiva-sms-appuntamenti', 'Agenda::disattivaSmsAppuntamenti');
