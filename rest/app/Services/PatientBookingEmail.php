<?php
namespace App\Services;

/** Original exported email markup; only tenant and appointment data are substituted. */
final class PatientBookingEmail
{
    public const TEMPLATES=['P1'=>'P1-richiesta-ricevuta','P2'=>'P2-confermata','P3'=>'P3-non-accolta','P4'=>'P4-promemoria','P5'=>'P5-spostata','P6'=>'P6-annullata','S1'=>'S1-nuova-prenotazione','S2'=>'S2-da-confermare','S3'=>'S3-spostata-dal-paziente','S4'=>'S4-annullata-dal-paziente','S5'=>'S5-attivita-anomala'];
    public static function render(string $type,array $p,array $tenant,array $config,array $catalog,string $managementUrl): array
    {
        if(!isset(self::TEMPLATES[$type]))throw new PatientBookingException('non_trovata',404);
        $html=file_get_contents(APPPATH.'Resources/patient-booking/source/email/'.self::TEMPLATES[$type].'.html');
        $w=PatientBookingWorkflow::resolve($config);$doctors=array_column($catalog['professionisti'],null,'id');$sites=array_column($catalog['sedi'],null,'id');
        if(empty($p['prestazione']) || (str_starts_with($type,'P') && empty($w['comunicazioni']['mostra_prestazione_nei_messaggi'])))$html=preg_replace('/<tr><td[^>]*>Prestazione<\/td><td.*?<\/td><\/tr>/s','',$html);
        $doc=$doctors[$p['professionista']]??[];$site=$sites[$p['sede']]??[];
        $name=$tenant['tenant_name'];$doctor=trim(implode(' ',[$doc['titolo']??'',$doc['nome']??'',$doc['cognome']??'']));
        $date=new \DateTimeImmutable($p['data'].' '.$p['ora'],new \DateTimeZone('Europe/Rome'));
        $fmt=new \IntlDateFormatter('it_IT',\IntlDateFormatter::FULL,\IntlDateFormatter::NONE,'Europe/Rome',null,'EEEE d MMMM');$day=$fmt->format($date);
        $previous=$p['metadata']['precedente']??['data'=>$p['data'],'ora'=>$p['ora']];
        $oldDate=new \DateTimeImmutable($previous['data'].' '.$previous['ora'],new \DateTimeZone('Europe/Rome'));
        $oldDay=$fmt->format($oldDate);$moving=in_array($type,['P5','S3'],true);
        $deadline=new \DateTimeImmutable(($p['scade_il']??gmdate('Y-m-d H:i:s',time()+86400)),new \DateTimeZone('UTC'));
        $deadline=$deadline->setTimezone(new \DateTimeZone('Europe/Rome'));
        $visit=array_column($catalog['prestazioni'],null,'id')[$p['prestazione']]??[];
        $address=trim(implode(', ',array_filter([$site['indirizzo']??'',$site['cap']??'',$site['citta']??''])));
        $replace=[
            'Santa Chiara Servizi Sanitari S.r.l.'=>$name,'Poliambulatorio Santa Chiara'=>$name,
            'segreteria@poliambulatoriosantachiara.it'=>$config['email'],'0571 123 456'=>$config['telefono'],
            '347 812 6650'=>$p['cellulare']?:$p['email'],'rosa.bianchi@email.it'=>$p['email'],
            'Rosa Bianchi'=>$p['paziente']['nome'].' '.$p['paziente']['cognome'],'Ciao Rosa'=>'Ciao '.$p['paziente']['nome'],'Rosa,'=>$p['paziente']['nome'].',',
            'Dott.ssa Elena Conti'=>$doctor,'Dott.ssa Giulia Ferri'=>$doctor,'Via Roma 18, 50053 Empoli (FI)'=>$address,'Via Roma 18, Empoli'=>$address,
            'Dott. Luca Romano'=>$doctor,'Piazza della Libertà 3, 50059 Vinci (FI)'=>$address,'Vinci'=>$site['citta']??$site['nome']??'',
            'Franco Rinaldi'=>$p['paziente']['nome'].' '.$p['paziente']['cognome'],'Davide Rinaldi, per conto del paziente'=>isset($p['metadata']['prenotante'])?implode(' ',$p['metadata']['prenotante']):$p['paziente']['nome'].' '.$p['paziente']['cognome'],
            '338 555 0142'=>$p['cellulare'].' (non verificato)','davide.rinaldi@email.it'=>$p['email'],
            ', nuova (non in archivio)'=>'',', verificato con WhatsApp'=>' (non verificato)',
            'Visita dermatologica'=>$visit['nome']??'','Seduta di fisioterapia'=>$visit['nome']??'',
            'venerdì 13 novembre'=>$day,'Venerdì 13 novembre'=>mb_convert_case($day,MB_CASE_TITLE),'ven 13/11'=>$date->format('d/m'),'15:00'=>$p['ora'],
            'martedì 17 novembre'=>$day,'Martedì 17 novembre'=>mb_convert_case($day,MB_CASE_TITLE),'9:00'=>$p['ora'],
            'giovedì 22 ottobre'=>$fmt->format($deadline),'Giovedì 22 ottobre'=>mb_convert_case($fmt->format($deadline),MB_CASE_TITLE),'21:10'=>$deadline->format('H:i'),
            'Ho un impegno'=>$p['metadata']['motivo']??'',"L'orario non è più disponibile."=>($p['metadata']['motivo']??'')?:"L'orario non è più disponibile.",
            'Via Roma 18'=>$site['indirizzo']??'','Empoli'=>$site['citta']??$site['nome']??'',
            'K7P2QH'=>$p['ref'],'giovedì 12 novembre'=>$moving?$oldDay:$day,'Giovedì 12 novembre'=>mb_convert_case($moving?$oldDay:$day,MB_CASE_TITLE),'17:30'=>$moving?$previous['ora']:$p['ora'],
            'gio 12/11'=>$date->format('d/m'),'12/11 a 17/11'=>$oldDate->format('d/m').' a '.$date->format('d/m'),'24 ore'=>$config['cancel_hours'].' ore',
            '01234560485'=>(string)($tenant['vat_number']??''),
        ];
        $escaped=[];foreach($replace as $key=>$value)$escaped[$key]=htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $html=strtr($html,$escaped);
        if($type==='S5'){
            $stats=$p['stats']??['ultima_ora'=>0,'recapiti_ultima_ora'=>0];$threshold=$w['protezioni']['soglia_anomalia_ora'];
            $html=str_replace(['14 richieste','14 nuove richieste',"14 in un'ora",'soglia di 10','soglia: 10','3 numeri diversi','62 su 100 oggi','Mercoledì 21 ottobre, dalle 20:10 alle 21:10'],[$stats['ultima_ora'].' richieste',$stats['ultima_ora'].' nuove richieste',$stats['ultima_ora']." in un'ora",'soglia di '.$threshold,'soglia: '.$threshold,$stats['recapiti_ultima_ora'].' numeri diversi','0 su '.$w['protezioni']['email_tetto_giornaliero'].' oggi',date('d/m/Y H:i')],$html);
        }
        $html=preg_replace_callback('/href="([^"]+)"/',static function($m)use($managementUrl,$config,$address){
            $url=html_entity_decode($m[1]);
            if(str_contains($url,'/privacy'))$url=$config['privacy_url'];
            elseif(str_starts_with($url,'tel:'))$url='tel:'.preg_replace('/[^+0-9]/','',$config['telefono']);
            elseif(str_starts_with($url,'mailto:'))$url='mailto:'.$config['email'];
            elseif(str_contains($url,'google.com/maps'))$url='https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
            elseif(str_contains($url,'/calendario'))$url=$managementUrl.'&calendario=1';
            elseif(str_contains($url,'/come-arrivare'))$url='https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
            elseif(str_contains($url,'ambulatoriofacile.it/p/'))$url=$managementUrl;
            return 'href="'.htmlspecialchars($url,ENT_QUOTES).'"';
        },$html);
        if($w['aspetto']['logo_url'])$html=str_replace('https://ambulatoriofacile.it/images/email/studio-esempio-logo.png',htmlspecialchars($w['aspetto']['logo_url'],ENT_QUOTES),$html);
        else $html=preg_replace('/<img\b[^>]*src="https:\/\/ambulatoriofacile.it\/images\/email\/studio-esempio-logo.png"[^>]*>/','',$html);
        preg_match('/<!-- OGGETTO: (.*?) -->/s',$html,$subject);
        return ['html'=>$html,'subject'=>html_entity_decode($subject[1]??'La tua prenotazione'),'text'=>html_entity_decode(trim(preg_replace('/\s+/',' ',strip_tags(preg_replace('/<style\b[^>]*>.*?<\/style>/s','',$html)))))];
    }
    public static function deliver(int $tenantId,string $recipient,array $message,?string $ics=null): bool
    {
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new PatientBookingException('recapito_mancante');
        $service=new TenantNotificationPolicyService();$policy=$service->resolve($tenantId);$transport=$service->resolveEmailTransport($tenantId,$policy);
        $mail=\Config\Services::email($transport?:null,false);$mail->clear(true);$mail->setMailType('html');
        $mail->setFrom($policy['email']['from_address']??TenantNotificationPolicyService::NO_REPLY_ADDRESS,$policy['email']['from_name']??'AmbulatorioFacile');
        $mail->setTo($recipient);$mail->setSubject($message['subject']);$mail->setMessage($message['html']);$mail->setAltMessage($message['text']);
        $mail->setHeader('Auto-Submitted','auto-generated');
        if($ics!==null)$mail->attach($ics,'attachment','appuntamento.ics','text/calendar');
        return $mail->send(false);
    }
    public static function calendar(array $p,array $catalog): string
    {
        $visit=array_column($catalog['prestazioni'],null,'id')[$p['prestazione']]??[];
        $start=new \DateTimeImmutable($p['data'].' '.$p['ora'],new \DateTimeZone('Europe/Rome'));$end=$start->modify('+'.(int)($p['durata']??$visit['durata']??30).' minutes');
        $utc=new \DateTimeZone('UTC');
        return implode("\r\n",['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//AmbulatorioFacile//Prenotazione//IT','METHOD:'.(($p['stato']??'')==='annullata'?'CANCEL':'PUBLISH'),'BEGIN:VEVENT','UID:'.$p['ref'].'-'.hash('sha256',$p['created_at']??$p['ref']).'@ambulatoriofacile','DTSTAMP:'.gmdate('Ymd\THis\Z'),'DTSTART:'.$start->setTimezone($utc)->format('Ymd\THis\Z'),'DTEND:'.$end->setTimezone($utc)->format('Ymd\THis\Z'),'SEQUENCE:'.(int)$p['ics_sequence'],'STATUS:'.(($p['stato']??'')==='annullata'?'CANCELLED':'CONFIRMED'),'SUMMARY:Appuntamento','END:VEVENT','END:VCALENDAR','']);
    }
}
