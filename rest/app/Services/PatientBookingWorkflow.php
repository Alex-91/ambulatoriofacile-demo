<?php
namespace App\Services;

/** Configuration for the authenticated booking journey. */
final class PatientBookingWorkflow
{
    public static function stepEnabled(array $workflow,string $id): bool
    {
        foreach($workflow['passi']??self::defaults()['passi'] as $step)if($step['id']===$id)return !empty($step['attivo']);
        return false;
    }
    public static function defaults(): array
    {
        $c=json_decode(file_get_contents(APPPATH.'Resources/patient-booking/defaults.json'),true,512,JSON_THROW_ON_ERROR);
        $c['verifica']=['richiesta'=>false,'metodi'=>[],'sms_tetto_giornaliero'=>50];
        foreach($c['passi'] as &$step) if($step['id']==='verifica') $step['attivo']=false;
        unset($step);
        $c['accesso']['modalita']='aperta';
        $c['dati']['cellulare']='facoltativo';
        $c['catalogo']=['prestazioni'=>[],'professionisti'=>[]];
        $c['comunicazioni']['nome_nei_messaggi']='';
        $c['comunicazioni']['contatti']=['telefono'=>'','email'=>'','whatsapp'=>''];
        $c['comunicazioni']['visibili']=['telefono'=>true,'email'=>true,'whatsapp'=>false,'sedi'=>true,'orari'=>false];
        $c['comunicazioni']['privacy_url']='';
        $c['aspetto']['logo_alt']='Logo dello spazio';
        return $c;
    }
    public static function resolve(array $settings): array
    {
        $w=$settings['workflow']??[];
        $c=array_replace_recursive(self::defaults(),$w);
        if(isset($w['passi'])) $c['passi']=$w['passi'];
        $c['scelte']['primo_disponibile']=(bool)$settings['first_available'];
        $c['regole']['preavviso_minimo_min']=(int)$settings['min_notice'];
        $c['regole']['giorni_prenotabili']=(int)$settings['days'];
        $c['regole']['preavviso_modifica_ore']=(int)$settings['cancel_hours'];
        $c['comunicazioni']['privacy_url']=$settings['privacy_url'];
        $c['comunicazioni']['contatti']=['telefono'=>$settings['telefono'],'email'=>$settings['email'],'whatsapp'=>''];
        return $c;
    }
    public static function validate(array $w,array $settings): array
    {
        $c=array_replace_recursive(self::defaults(),array_intersect_key($w,self::defaults()));
        if(isset($w['passi'])) $c['passi']=$w['passi'];
        $ids=array_column($c['passi'],'id');$expected=array_column(self::defaults()['passi'],'id');
        $sorted=$ids;$target=$expected;sort($sorted);sort($target);
        if($sorted!==$target) self::fail('Ogni passo deve comparire una sola volta.');
        foreach($c['passi'] as &$step) $step=['id'=>$step['id'],'attivo'=>!empty($step['attivo'])];unset($step);
        $active=array_column(array_filter($c['passi'],fn($p)=>$p['attivo']),'id');
        foreach(['orario','dati','riepilogo'] as $id) if(!in_array($id,$active,true)) self::fail('Orario, I tuoi dati e Riepilogo devono restare attivi.');
        if(end($ids)!=='riepilogo') self::fail('Il riepilogo deve essere l’ultimo passo.');
        foreach(['prestazione','professionista','sede'] as $id) if(in_array($id,$active,true) && array_search($id,$active)>array_search('orario',$active)) self::fail('Data e orario deve seguire Prestazione, Professionista e Sede.');
        $verification=!empty($c['verifica']['richiesta']);
        if(in_array('verifica',$active,true)!==$verification)self::fail('Il passo di verifica e la richiesta di verifica devono essere attivati insieme.');
        if($verification && array_search('verifica',$active)>array_search('dati',$active))self::fail('La verifica deve precedere I tuoi dati.');
        if(!is_array($c['verifica']['metodi']) || array_diff($c['verifica']['metodi'],['sms']))self::fail('Per questo percorso è disponibile la verifica tramite SMS.');
        if($verification && !in_array('sms',$c['verifica']['metodi'],true))self::fail('Attiva il metodo SMS per richiedere la verifica del numero.');
        if(!in_array($c['accesso']['modalita'],['aperta','approvazione','invito'],true)) self::fail('Senza verifica non puoi limitare le prenotazioni ai pazienti in archivio.');
        foreach(['prestazione_predefinita'=>'visits','professionista_predefinito'=>'doctors'] as $key=>$bucket) {
            $id=$c['scelte'][$key];$c['scelte'][$key]=$id===null||$id===''?null:(string)(int)$id;
            if($c['scelte'][$key]!==null && !in_array((int)$id,$settings[$bucket],true)) self::fail('La scelta predefinita deve essere abilitata nel catalogo.');
        }
        foreach(['salta_professionista_se_unico','salta_sede_se_unica'] as $key) $c['scelte'][$key]=!empty($c['scelte'][$key]);
        if(!in_array($c['senza_verifica']['recapito'],['almeno_uno','cellulare','email','facoltativo'],true)) self::fail('Regola dei recapiti non valida.');
        foreach(['cellulare','email','note','nascita','codice_fiscale'] as $key) if(!in_array($c['dati'][$key],['obbligatorio','facoltativo','nascosto'],true)) self::fail('Configurazione dei campi non valida.');
        foreach(['limiti'=>['attive_per_recapito'=>[1,10],'stessa_prestazione'=>[1,5],'assenze_per_approvazione'=>[1,10]],'accesso'=>['ore_scadenza_richieste'=>[1,168]],'protezioni'=>['senza_verifica_per_dispositivo_giorno'=>[1,100],'senza_verifica_per_ip_giorno'=>[1,100],'soglia_anomalia_ora'=>[1,1000],'email_tetto_giornaliero'=>[1,10000]]] as $section=>$fields) foreach($fields as $key=>[$min,$max]) {
            if(filter_var($c[$section][$key],FILTER_VALIDATE_INT)===false || $c[$section][$key]<$min || $c[$section][$key]>$max) self::fail('Controlla i limiti e i tempi: inserisci un numero valido.');
            $c[$section][$key]=(int)$c[$section][$key];
        }
        foreach(['benvenuto_titolo'=>120,'benvenuto_sottotitolo'=>500,'nota_riepilogo'=>1000] as $key=>$max) {
            $value=trim((string)($c['testi'][$key]??''));if(mb_strlen($value)>$max) self::fail('Testo troppo lungo.');$c['testi'][$key]=$value===''?null:$value;
        }
        foreach(['prestazioni'=>'id_tipo_visita','professionisti'=>'id_dot'] as $bucket=>$idKey){
            if(!is_array($c['catalogo'][$bucket]))self::fail('Catalogo non valido.');
            $seen=[];
            foreach($c['catalogo'][$bucket] as &$item){
                if(!is_array($item) || !filter_var($item[$idKey]??null,FILTER_VALIDATE_INT) || isset($seen[$item[$idKey]]))self::fail('Catalogo non valido.');
                $item[$idKey]=(int)$item[$idKey];$seen[$item[$idKey]]=true;$item['online']=!empty($item['online']);
                if(!in_array($item['modalita']??null,[null,'aperta','approvazione'],true))self::fail('Modalità del catalogo non disponibile senza verifica.');
                foreach(['nome_pubblico'=>120,'descrizione'=>1000,'preparazione'=>1000,'prezzo'=>30] as $field=>$max)if(isset($item[$field])){if(!is_scalar($item[$field])||mb_strlen((string)$item[$field])>$max)self::fail('Testo del catalogo non valido.');$item[$field]=trim((string)$item[$field]);}
                foreach(['prestazioni','sedi'] as $field)if(isset($item[$field])){$item[$field]=array_values(array_unique(array_map('intval',(array)$item[$field])));if(array_filter($item[$field],fn($id)=>$id<=0))self::fail('Associazione del catalogo non valida.');}
            }unset($item);
        }
        $c['verifica']['richiesta']=$verification;$c['verifica']['metodi']=array_values(array_unique($c['verifica']['metodi']));
        $cap=$c['verifica']['sms_tetto_giornaliero']??50;
        if(filter_var($cap,FILTER_VALIDATE_INT)===false || $cap<1 || $cap>1000)self::fail('Il tetto giornaliero degli SMS deve essere tra 1 e 1000.');
        $c['verifica']['sms_tetto_giornaliero']=(int)$cap;
        $c['regole']['minuti_riserva']=0;
        $c['comunicazioni']['mostra_prestazione_nei_messaggi']=!empty($c['comunicazioni']['mostra_prestazione_nei_messaggi']);
        $c['aspetto']['logo_url']=PatientBookingLogo::validate($c['aspetto']['logo_url']);
        $c['aspetto']['mostra_nome_con_logo']=!empty($c['aspetto']['mostra_nome_con_logo']);
        foreach($c['comunicazioni']['visibili'] as $key=>$value)$c['comunicazioni']['visibili'][$key]=(bool)$value;
        $c['regole']['spostamento_consentito']=!empty($c['regole']['spostamento_consentito']);
        return $c;
    }
    private static function fail(string $message): never { throw new \InvalidArgumentException($message); }
}
