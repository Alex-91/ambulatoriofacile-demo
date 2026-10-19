<?php
namespace App\Services;

/** Pure state machine. The port is either tenant data or a side-effect-free preview. */
final class WhatsAppBookingEngine
{
    public function advance(array $c, array $s, string $text, object $port): ?array
    {
        $text = trim($text); $command = mb_strtolower($text);
        $start = in_array($command,$c['keywords'],true);
        if (!$s && !$start) return null;
        if ($command === 'annulla') return ['state'=>[], 'reply'=>'Conversazione annullata. Per ricominciare scrivi '.$c['keywords'][0].'.'];
        if ($start) {
            $s = ['step'=>'start','booking_key'=>bin2hex(random_bytes(16)),'patient'=>[]];
            if ($c['single_visit']) $s['visit_id'] = $c['default_visit_id'];
            return $this->choose($c,$s,$c['single_visit']||$c['order']==='day'?'day':'visit',$port,$c['welcome']."\n\n");
        }
        if ($command === 'segreteria' && $c['handoff']) return $this->handoff($c,$s);
        if (in_array($s['step'],['done','pending','handoff'],true)) return ['state'=>$s,'reply'=>'La richiesta è già stata registrata. Per una nuova prenotazione scrivi '.$c['keywords'][0].'.'];
        if (in_array($s['step'],['day','visit','time'],true)) {
            $choices = $s['choices'] ?? [];
            if ($command === 'altro') {
                $s['offset'] = (($s['offset'] ?? 0)+8) < count($choices) ? ($s['offset'] ?? 0)+8 : 0;
                return ['state'=>$s,'reply'=>$this->menu($s,$c)];
            }
            $index = ctype_digit($text) ? (int)$text-1 : -1;
            $choice = $index>=0 && $index<8 ? ($choices[($s['offset']??0)+$index] ?? null) : null;
            if (!$choice) return ['state'=>$s,'reply'=>"Seleziona un numero tra quelli indicati.\n".$this->menu($s,$c)];
            if ($s['step']==='visit') { $s['visit_id']=(int)$choice['id']; return $this->choose($c,$s,isset($s['day'])?'time':'day',$port); }
            if ($s['step']==='day') { $s['day']=$choice['id']; return $this->choose($c,$s,isset($s['visit_id'])?'time':'visit',$port); }
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
        return ['state'=>[],'reply'=>'La sessione è terminata. Per ricominciare scrivi '.$c['keywords'][0].'.'];
    }

    private function choose(array $c,array $s,string $step,object $port,string $prefix=''): array
    {
        $s['step']=$step;$s['offset']=0;$s['choices']=$port->choices($c,$s,$step);
        if (!$s['choices']) {
            unset($s['day'],$s['slot']);$s['step']='empty';
            return ['state'=>$s,'reply'=>$prefix.$c['no_availability'].' Per ricominciare scrivi '.$c['keywords'][0].($c['handoff']?'; per assistenza scrivi segreteria.':'.')];
        }
        return ['state'=>$s,'reply'=>$prefix.$this->menu($s,$c)];
    }

    private function menu(array $s,array $c): string
    {
        $lines=[['day'=>'Quale giorno preferisci?','visit'=>'Quale prestazione vuoi prenotare?','time'=>'Seleziona un orario disponibile.'][$s['step']]];
        foreach(array_slice($s['choices'],$s['offset']??0,8) as $i=>$choice) $lines[]=($i+1).'. '.$choice['label'];
        if(count($s['choices'])>8)$lines[]='Scrivi altro per le altre opzioni.';
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
