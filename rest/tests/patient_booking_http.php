<?php
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);});
$checks=0;
function bookingHttpCheck($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$filters=config(\Config\Filters::class);
foreach(['','login/'] as $prefix) {
    foreach(['spazio/prenotazione-online/*','piattaforma/prenotazione-online/*'] as $path) {
        foreach(['before','after'] as $stage) bookingHttpCheck(in_array($prefix.$path,$filters->filters['patientbookingcsrf'][$stage],true),'CSRF coverage '.$prefix.$path.' '.$stage);
    }
}
$request=new \CodeIgniter\HTTP\IncomingRequest(config(\Config\App::class),new \CodeIgniter\HTTP\URI('http://booking-test.invalid/'),null,new \CodeIgniter\HTTP\UserAgent());
$request->setMethod('POST');$request->setGlobal('post',[]);
$filter=new \App\Filters\PatientBookingCsrfFilter();
try{$filter->before($request);bookingHttpCheck(false,'Missing token accepted');}catch(\CodeIgniter\Security\Exceptions\SecurityException $e){bookingHttpCheck(true,'Missing token rejected');}
$security=service('security');$old=$security->getHash();
$request->setGlobal('post',[$security->getTokenName()=>$old]);$filter->before($request);
bookingHttpCheck($security->getHash()!==$old,'Token rotated');
$redirect=redirect()->to('/done');$filter->after($request,$redirect);
bookingHttpCheck(count($redirect->getCookies())>0,'Rotated cookie retained');
echo "PASS $checks internal booking CSRF checks.\n";
