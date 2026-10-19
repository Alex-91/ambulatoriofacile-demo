<?php
namespace App\Services;

/** Pure state machine. The port is either tenant data or a side-effect-free preview. */
final class WhatsAppBookingEngine
{
    public function advance(array $c, array $s, string $text, object $port): ?array
    {
        if(str_starts_with($text,'wa:')){
            $action=$s['ui_actions'][$text]??null;
            if($action===null && !$s && $c['start_mode']==='auto')return $this->present($this->step($c,[],'',$port),$c);
            if($action===null)return $this->present(['state'=>$s,'reply'=>'Questa scelta non è più valida. Usa le opzioni del messaggio più recente.'],$c);
            $text=$action;
        }
        $result=$this->step($c,$s,$text,$port);
        return $result===null?null:$this->present($result,$c);
    }

    private function step(array $c,array $s,string $text,object $port): ?array
    {
        $text = trim($text); $command = mb_strtolower($text);
        if(($s['step']??'')==='empty' && !in_array($command,['annulla','segreteria'],true))$s=[];
        if(in_array($s['step']??'',['done','pending','handoff'],true))$s=[];
        $start = !$s && ($c['start_mode']==='auto' || in_array($command,$c['keywords'],true) || ($command==='annulla appuntamento' && $c['cancellation']));
        if (!$s && !$start) return null;
        if($start && $c['start_mode']==='auto')return ['state'=>['step'=>'home','booking_key'=>bin2hex(random_bytes(16)),'patient'=>[]], 'reply'=>$c['welcome']."\n\n1. Prenota un appuntamento".($c['cancellation']?"\n2. Annulla un appuntamento":'')];
        if(($s['step']??'')==='home'){
            if($command==='2' && $c['cancellation']){$s['step']='cancel_cf';return ['state'=>$s,'reply'=>'Per cercare gli appuntamenti associati a questo numero WhatsApp, inserisci il codice fiscale del paziente.'];}
            if($command==='1')$start=true;
            elseif($command!=='segreteria' && $command!=='annulla')return ['state'=>$s,'reply'=>'Scegli Prenota oppure Annulla appuntamento.'];
        }
        if($command==='annulla appuntamento' && $c['cancellation'])return ['state'=>['step'=>'cancel_cf','booking_key'=>bin2hex(random_bytes(16)),'patient'=>[]],'reply'=>'Inserisci il codice fiscale del paziente. La ricerca è limitata agli appuntamenti associati a questo numero WhatsApp.'];
        if ($command === 'annulla') return ['state'=>[], 'reply'=>'Conversazione chiusa. '.($c['start_mode']==='auto'?'Invia un nuovo messaggio per ricominciare.':'Per ricominciare scrivi '.($c['keywords'][0]??'prenota').'.')];
        if ($command === 'segreteria' && $c['handoff']) return $this->handoff($c,$s);
        if(($s['step']??'')==='cancel_cf'){
            $validation=(new FiscalCodeValidator())->validate($text);
            if(!$validation['valid'])return ['state'=>$s,'reply'=>'Il codice fiscale non è valido. Verifica e riprova.'];
            $s['cancel_cf']=$validation['normalized'];$s['step']='cancel_pick';$s['offset']=0;$s['choices']=$port->cancellations($c,$s['cancel_cf']);
            if(!$s['choices'])return ['state'=>['step'=>'done'],'reply'=>'Non risultano appuntamenti futuri annullabili per questi dati e questo numero. Contatta la segreteria se hai bisogno di assistenza.'];
            return ['state'=>$s,'reply'=>$this->menu($s,$c)];
        }
        if(($s['step']??'')==='cancel_confirm'){
            if($command==='2')return ['state'=>['step'=>'done'],'reply'=>'Appuntamento mantenuto. Nessun annullamento effettuato.'];
            if(!in_array($command,['1','conferma'],true))return ['state'=>$s,'reply'=>'Confermi l’annullamento? Scegli Annulla appuntamento oppure Mantieni.'];
            try{$port->cancel($c,$s);}catch(WhatsAppBookingUnavailable $e){return ['state'=>['step'=>'done'],'reply'=>'L’appuntamento non è più annullabile. Contatta la segreteria.'];}
            return ['state'=>['step'=>'done'],'reply'=>'Appuntamento annullato. Lo slot è nuovamente disponibile.'];
        }
        if ($start) {
            $s = ['step'=>'start','booking_key'=>bin2hex(random_bytes(16)),'patient'=>[]];
            return $this->choose($c,$s,$c['single_visit']||$c['order']==='day'?'day':'visit',$port,$c['start_mode']==='keyword'?$c['welcome']."\n\n":'');
        }
        if ($command === 'segreteria' && $c['handoff']) return $this->handoff($c,$s);
        if (in_array($s['step'],['done','pending','handoff'],true)) return ['state'=>$s,'reply'=>'La richiesta è già stata registrata. Per una nuova prenotazione scrivi '.($c['keywords'][0]??'un messaggio').'.'];
        if (in_array($s['step'],['day','visit','time','cancel_pick'],true)) {
            $choices = $s['choices'] ?? [];
            $pageSize=$s['step']==='day'?5:2;
            if ($command === 'altro' || ($s['step']==='day' && $command==='mostra altri giorni')) {
                $next=($s['offset']??0)+$pageSize;
                $s['offset']=$next<count($choices)?$next:($s['step']==='day'?($s['offset']??0):0);
                return ['state'=>$s,'reply'=>$this->menu($s,$c)];
            }
            $index = ctype_digit($text) ? (int)$text-1 : -1;
            $choice = $index>=0 && $index<$pageSize ? ($choices[($s['offset']??0)+$index] ?? null) : null;
            if (!$choice) return ['state'=>$s,'reply'=>"Seleziona un numero tra quelli indicati.\n".$this->menu($s,$c)];
            if ($s['step']==='cancel_pick'){$s['cancel_appointment']=$choice;$s['step']='cancel_confirm';unset($s['choices']);return ['state'=>$s,'reply'=>'Annullare l’appuntamento del '.$choice['label']."?\n1. Annulla appuntamento\n2. Mantieni"]; }
            if ($s['step']==='visit') { $s['visit_id']=(int)$choice['id']; return $this->choose($c,$s,isset($s['day'])?'time':'day',$port); }
            if ($s['step']==='day') { $s['day']=$choice['id']; return $this->choose($c,$s,$c['single_visit']||isset($s['visit_id'])?'time':'visit',$port); }
            $s['slot']=$choice; unset($s['choices'],$s['offset']);
            $s['step']=in_array($c['patient_mode'],['cf','both'],true)?'cf':'name';
            return ['state'=>$s,'reply'=>$s['step']==='cf'?'Inserisci il codice fiscale del paziente.':'Inserisci il nome del paziente.'];
        }
        if ($s['step']==='cf') {
            $validation=(new FiscalCodeValidator())->validate($text);
            if (!$text || !$validation['valid']) return ['state'=>$s,'reply'=>'Il codice fiscale non è valido. Verifica e riprova.'];
            $s['patient']['codice_fiscale']=$validation['normalized'];
            $patient=$port->patient($validation['normalized']);
            // Duplicate tax codes are never associated arbitrarily.
            if (($patient['ambiguous']??false) || (!$patient && $c['unknown_patient']==='staff')) return $this->handoff($c,$s);
            if ($patient) {
                $s['patient']=array_merge($s['patient'],$patient);
                if ($c['patient_mode']==='cf') return $this->summary($c,$s,$port);
            }
            $s['step']='name';
            return ['state'=>$s,'reply'=>'Inserisci il nome del paziente.'];
        }
        if (in_array($s['step'],['name','surname'],true)) {
            if (mb_strlen($text)<2 || mb_strlen($text)>80 || !preg_match("/^[\p{L}\p{M} '\x{2019}-]+$/u",$text)) return ['state'=>$s,'reply'=>'Inserisci un nome valido, senza numeri o simboli.'];
            $s['patient'][$s['step']==='name'?'nome':'cognome']=$text;
            $s['patient']['name_supplied']=true;
            if ($s['step']==='name') { $s['step']='surname'; return ['state'=>$s,'reply'=>'Inserisci il cognome del paziente.']; }
            if ($c['patient_mode']==='birth') { $s['step']='birth';return ['state'=>$s,'reply'=>'Inserisci la data di nascita nel formato GG/MM/AAAA.']; }
            return $this->summary($c,$s,$port);
        }
        if ($s['step']==='birth') {
            $birth=\DateTimeImmutable::createFromFormat('!d/m/Y',$text,new \DateTimeZone('Europe/Rome'));
            if (!$birth || $birth->format('d/m/Y')!==$text || $birth>new \DateTimeImmutable('today') || $birth<new \DateTimeImmutable('-120 years')) return ['state'=>$s,'reply'=>'Verifica la data e usa il formato GG/MM/AAAA.'];
            $s['patient']['data_nascita']=$birth->format('Y-m-d');return $this->summary($c,$s,$port);
        }
        if ($s['step']==='summary') {
            if ($command==='2') { unset($s['day'],$s['slot']);return $this->choose($c,$s,'day',$port); }
            if (!in_array($command,['1','conferma'],true)) return ['state'=>$s,'reply'=>'Rispondi 1 per confermare, 2 per cambiare giorno, oppure annulla.'];
            if ($c['confirmation']==='manual') {
                $s['step']='pending';return ['state'=>$s,'reply'=>'Richiesta inviata alla segreteria. L’appuntamento non è ancora confermato.','request'=>'approval'];
            }
            try { $id=$port->book($c,$s); }
            catch (WhatsAppBookingUnavailable $e) { unset($s['slot']);return $this->choose($c,$s,'time',$port,"L’orario selezionato non è più disponibile.\n"); }
            $s['step']='done';$s['appointment_id']=$id;
            return ['state'=>$s,'reply'=>'Appuntamento confermato per '.date('d/m/Y',strtotime($s['day'])).' alle '.$s['slot']['label'].'.','appointment_id'=>$id];
        }
        return ['state'=>[],'reply'=>'La sessione è terminata. Per ricominciare scrivi '.($c['keywords'][0]??'un messaggio').'.'];
    }

    private function present(array $result,array $c): array
    {
        if(!$result['state']){$result['buttons']=[];return $result;}
        $s=$result['state'];$step=$s['step']??'';$actions=[];
        if($step==='home'){$actions=['1'=>'Prenota'];if($c['cancellation'])$actions['2']='Annulla appuntamento';}
        elseif(in_array($step,['day','visit','time','cancel_pick'],true)){
            foreach(array_slice($s['choices']??[],$s['offset']??0,$step==='day'?5:2) as $i=>$choice)$actions[(string)($i+1)]=$choice['label'];
            if($step==='day'){
                if(($s['offset']??0)+5<count($s['choices']??[]))$actions['altro']='Mostra altri giorni';
            }elseif(count($s['choices']??[])>2)$actions['altro']='Altre opzioni';
        }elseif($step==='summary')$actions=['1'=>$c['confirmation']==='manual'?'Invia richiesta':'Conferma','2'=>'Cambia giorno'];
        elseif($step==='cancel_confirm')$actions=['1'=>'Annulla appuntamento','2'=>'Mantieni'];
        elseif($step==='empty')$actions=['annulla'=>'Chiudi conversazione'];
        if($c['handoff'] && $step==='home')$actions['segreteria']='Segreteria';
        $nonce=bin2hex(random_bytes(8));$s['ui_actions']=[];$result['buttons']=[];
        foreach($actions as $action=>$label){$id='wa:'.$nonce.':'.count($result['buttons']);$s['ui_actions'][$id]=(string)$action;$result['buttons'][]=['id'=>$id,'title'=>mb_substr($label,0,20),'description'=>mb_substr($label,0,72)];}
        $result['state']=$s;return $result;
    }

    private function choose(array $c,array $s,string $step,object $port,string $prefix=''): array
    {
        $s['step']=$step;$s['offset']=0;$s['choices']=$port->choices($c,$s,$step);
        if (!$s['choices']) {
            unset($s['day'],$s['slot']);$s['step']='empty';
            return ['state'=>$s,'reply'=>$prefix.$c['no_availability'].' '.($c['start_mode']==='auto'?'Invia un nuovo messaggio per ricominciare.':'Per ricominciare scrivi '.($c['keywords'][0]??'prenota').'.').($c['handoff']?' Per assistenza scrivi segreteria.':'')];
        }
        return ['state'=>$s,'reply'=>$prefix.$this->menu($s,$c)];
    }

    private function menu(array $s,array $c): string
    {
        $lines=[['cancel_pick'=>'Quale appuntamento vuoi annullare?', 'day'=>'Quale giorno preferisci?','visit'=>'Quale prestazione vuoi prenotare?','time'=>'Seleziona un orario disponibile.'][$s['step']]];
        foreach(array_slice($s['choices'],$s['offset']??0,$s['step']==='day'?5:2) as $i=>$choice) $lines[]=($i+1).'. '.$choice['label'];
        if($s['step']==='day'){
            $lines[]=($s['offset']??0)+5<count($s['choices'])?'Seleziona Mostra altri giorni oppure scrivi altro per le prossime 5 date disponibili.':'Non ci sono altri giorni disponibili in agenda.';
        }elseif(count($s['choices'])>2)$lines[]='Scrivi altro per le altre opzioni.';
        $lines[]='Scrivi annulla per interrompere.'.($c['handoff']?' Per assistenza scrivi segreteria.':'');
        return implode("\n",$lines);
    }

    private function summary(array $c,array $s,object $port): array
    {
        $s['step']='summary';$s['summary']=$port->summary($c,$s);
        return ['state'=>$s,'reply'=>$s['summary']."\n\n1. ".($c['confirmation']==='manual'?'Invia richiesta alla segreteria':'Conferma appuntamento')."\n2. Cambia giorno\nScrivi annulla per interrompere."];
    }

    private function handoff(array $c,array $s): array
    {
        $s['step']='handoff';return ['state'=>$s,'reply'=>$c['handoff_message'],'request'=>'handoff'];
    }
}
