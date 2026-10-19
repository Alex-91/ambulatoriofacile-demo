<?php
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
$checks=0;function httpCheck($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
use App\Services\WhatsAppBookingAccess;
$member=['tenant_is_active'=>1,'invitation_status'=>'accepted','tenant_role'=>'tenant_master'];
httpCheck(WhatsAppBookingAccess::canManage($member),'Master access');
httpCheck(WhatsAppBookingAccess::canManage(array_replace($member,['tenant_role'=>'tenant_admin'])),'Tenant admin access');
httpCheck(!WhatsAppBookingAccess::canManage(array_replace($member,['tenant_role'=>'doctor'])),'Doctor without admin permission denied');
httpCheck(!WhatsAppBookingAccess::canManage(array_replace($member,['tenant_is_active'=>0])),'Inactive tenant denied');
httpCheck(!WhatsAppBookingAccess::canManage(array_replace($member,['invitation_status'=>'invited'])),'Unaccepted invitation denied');
httpCheck(!WhatsAppBookingAccess::canManage(null),'Deleted membership denied');
httpCheck(WhatsAppBookingAccess::canManage(array_replace($member,['tenant_role'=>'doctor','is_app_admin'=>1])),'App administrator access');
$routes=service('routes');$routes->loadRoutes();
foreach(['login/',''] as $prefix)foreach(['spazio/chatbot-whatsapp/save','spazio/chatbot-whatsapp/preview','spazio/chatbot-whatsapp/resolve','piattaforma/chatbot-whatsapp/save'] as $path){
    $options=$routes->getRoutesOptions($prefix.$path,'POST');httpCheck(in_array('whatsappbookingcsrf',(array)($options['filter']??[]),true),'CSRF route '.$prefix.$path);
}
$request=new \CodeIgniter\HTTP\IncomingRequest(config(\Config\App::class),new \CodeIgniter\HTTP\URI('http://booking-test.invalid/'),null,new \CodeIgniter\HTTP\UserAgent());$request->setMethod('POST');$request->setGlobal('post',[]);
$filter=new \App\Filters\WhatsAppBookingCsrfFilter();
try{$filter->before($request);httpCheck(false,'Missing token accepted');}catch(\CodeIgniter\Security\Exceptions\SecurityException $expected){httpCheck(true,'Missing token rejected');}
$security=service('security');$oldHash=$security->getHash();$request->setGlobal('post',[$security->getTokenName()=>$oldHash]);$filter->before($request);
httpCheck($security->getHash()!==$oldHash,'Token rotated');
$redirect=redirect()->to('/done');$filter->after($request,$redirect);httpCheck(count($redirect->getCookies())>0,'Rotated cookie retained on redirect');
echo "PASS $checks HTTP/policy checks: role membership, protected routes, CSRF rejection and rotation.\n";
