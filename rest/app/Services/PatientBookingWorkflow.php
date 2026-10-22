<?php
namespace App\Services;

/** Configuration for the authenticated, manual-contact booking journey. */
final class PatientBookingWorkflow
{
    public static function defaults(): array
    {
        $c=json_decode(file_get_contents(APPPATH.'Resources/patient-booking/defaults.json'),true,512,JSON_THROW_ON_ERROR);
        $c['verifica']=['richiesta'=>false,'metodi'=>[]];
        foreach($c['passi'] as &$step) if($step['id']==='verifica') $step['attivo']=false;
        unset($step);
        $c['accesso']['modalita']='aperta';
        $c['dati']['cellulare']='facoltativo';
        $c['catalogo']=['prestazioni'=>[],'professionisti'=>[]];
        $c['comunicazioni']['nome_nei_messaggi']='';
        $c['comunicazioni']['contatti']=['telefono'=>'','email'=>'','whatsapp'=>''];
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
        if(in_array('verifica',$active,true) || !empty($c['verifica']['richiesta']) || $c['accesso']['modalita']!=='aperta') self::fail('Questa versione usa la prenotazione interna senza verifica e con conferma immediata.');
        foreach(['prestazione_predefinita'=>'visits','professionista_predefinito'=>'doctors'] as $key=>$bucket) {
            $id=$c['scelte'][$key];$c['scelte'][$key]=$id===null||$id===''?null:(string)(int)$id;
            if($c['scelte'][$key]!==null && !in_array((int)$id,$settings[$bucket],true)) self::fail('La scelta predefinita deve essere abilitata nel catalogo.');
        }
        if(!in_array('prestazione',$active,true) && !$c['scelte']['prestazione_predefinita']) self::fail('Scegli una prestazione predefinita prima di nascondere il passo.');
        if(!in_array('professionista',$active,true) && !$settings['first_available'] && !$c['scelte']['professionista_predefinito'] && count($settings['doctors'])>1) self::fail('Scegli un professionista predefinito oppure abilita Il primo disponibile.');
        foreach(['salta_professionista_se_unico','salta_sede_se_unica'] as $key) $c['scelte'][$key]=!empty($c['scelte'][$key]);
        if(!in_array($c['senza_verifica']['recapito'],['almeno_uno','cellulare','email','facoltativo'],true)) self::fail('Regola dei recapiti non valida.');
        foreach(['cellulare','email','note'] as $key) if(!in_array($c['dati'][$key],['obbligatorio','facoltativo','nascosto'],true)) self::fail('Configurazione dei campi non valida.');
        // These fields require a dedicated patient-data persistence flow before enabling them.
        if($c['dati']['nascita']!=='nascosto'||$c['dati']['codice_fiscale']!=='nascosto') self::fail('Data di nascita e codice fiscale non sono disponibili nel percorso interno.');
        foreach(['benvenuto_titolo'=>120,'benvenuto_sottotitolo'=>500,'nota_riepilogo'=>1000] as $key=>$max) {
            $value=trim((string)($c['testi'][$key]??''));if(mb_strlen($value)>$max) self::fail('Testo troppo lungo.');$c['testi'][$key]=$value===''?null:$value;
        }
        $logo=$c['aspetto']['logo_url'];
        if($logo!==null && $logo!=='') {
            if(!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#D',$logo,$m)) self::fail('Il logo deve essere PNG o JPEG.');
            $bytes=base64_decode($m[2],true);$info=$bytes===false?false:@getimagesizefromstring($bytes);
            if(!$info || strlen($bytes)>1048576 || $info[0]>4096 || $info[1]>4096 || $info['mime']!=='image/'.$m[1]) self::fail('Logo non valido: massimo 1 MB e 4096 pixel.');
        } else $c['aspetto']['logo_url']=null;
        $c['aspetto']['mostra_nome_con_logo']=!empty($c['aspetto']['mostra_nome_con_logo']);
        $c['regole']['spostamento_consentito']=!empty($c['regole']['spostamento_consentito']);
        return $c;
    }
    private static function fail(string $message): never { throw new \InvalidArgumentException($message); }
}
