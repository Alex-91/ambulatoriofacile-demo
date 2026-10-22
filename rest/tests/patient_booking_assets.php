<?php
require __DIR__.'/_support/whatsapp_booking_bootstrap.php';
use App\Services\{PatientBookingLogo,PatientBookingEmail,PatientBookingSettings};
$n=0;function checkAssets($ok,$label){global $n;$n++;if(!$ok)throw new RuntimeException($label);}
$svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>';
checkAssets(str_starts_with(PatientBookingLogo::validate('data:image/svg+xml;base64,'.base64_encode($svg)),'data:image/svg+xml'),'Local SVG accepted');
foreach(['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>','<svg xmlns="http://www.w3.org/2000/svg"><use href="https://example.test/x"/></svg>'] as $bad){try{PatientBookingLogo::validate('data:image/svg+xml;base64,'.base64_encode($bad));throw new RuntimeException('Unsafe SVG accepted');}catch(InvalidArgumentException $e){checkAssets(true,'Active SVG rejected');}}
$c=PatientBookingSettings::defaults();$c['email']='clinic@example.test';$c['telefono']='061111111';$c['privacy_url']='https://clinic.example.test/privacy';
$catalog=['professionisti'=>[['id'=>'10','titolo'=>'Dott.','nome'=>'Mario','cognome'=>'Neri']],'prestazioni'=>[['id'=>'1','nome'=>'Prestazione riservata','durata'=>45]],'sedi'=>[['id'=>'1','nome'=>'Sede nuova','indirizzo'=>'Via nuova 99','citta'=>'Roma']]];
$p=['ref'=>'ABC234','data'=>'2027-04-12','ora'=>'10:45','professionista'=>'10','prestazione'=>'1','sede'=>'1','paziente'=>['nome'=>'Anna <script>','cognome'=>'Verdi'],'cellulare'=>'+393331111111','email'=>'anna@example.test','ics_sequence'=>3,'created_at'=>'2026-10-22 10:00:00','stato'=>'confermata'];
foreach(array_keys(PatientBookingEmail::TEMPLATES) as $type){$m=PatientBookingEmail::render($type,$p,['tenant_name'=>'Studio nuovo'],$c,$catalog,'https://clinic.example.test/internal?ref=ABC234');checkAssets(!str_contains($m['html'],'<script>'),'Email escapes names '.$type);checkAssets(!str_contains($m['html'],'poliambulatoriosantachiara.it'),'No reference clinic contact '.$type);if(str_starts_with($type,'P'))checkAssets(!str_contains($m['html'],'Prestazione riservata'),'Patient service privacy '.$type);}
$ics=PatientBookingEmail::calendar($p,$catalog);checkAssets(str_contains($ics,'DTSTART:20270412T084500Z')&&str_contains($ics,'DTEND:20270412T093000Z'),'ICS Rome timezone and duration');
$p['stato']='annullata';checkAssets(str_contains(PatientBookingEmail::calendar($p,$catalog),'METHOD:CANCEL'),'ICS cancellation');
echo "PASS $n logo, original email and calendar checks.\n";
