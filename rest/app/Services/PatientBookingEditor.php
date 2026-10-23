<?php
namespace App\Services;

final class PatientBookingEditor
{
    /** Remove legacy forced defaults without publishing unrelated draft changes. */
    public static function removeHiddenDefaults(array $config,int $actor=0): array
    {
        $published=PatientBookingWorkflow::resolve($config);
        foreach(['prestazione','professionista','sede'] as $step)if(PatientBookingWorkflow::stepEnabled($published,$step))throw new \InvalidArgumentException('I passaggi di scelta devono essere già disattivati.');
        $draft=$config['editor']['draft']??$published;$changed=false;
        foreach(['prestazione','professionista'] as $step){
            $key=$step==='prestazione'?'prestazione_predefinita':'professionista_predefinito';
            $changed=$changed || !empty($published['scelte'][$key]);$published['scelte'][$key]=null;
            if(!PatientBookingWorkflow::stepEnabled($draft,$step))$draft['scelte'][$key]=null;
        }
        if(!$changed)return $config;
        $config=self::transition($config,'save',['draft'=>$published],$actor);
        $config=self::transition($config,'publish',['note'=>'Rimozione dei valori predefiniti imposti dal precedente vincolo sui passaggi spenti.'],$actor);
        $config['editor']['draft']=$draft;
        return $config;
    }
    public static function state(array $state): array
    {
        $c=$state['config'];$editor=$c['editor']??[];
        return ['etag'=>$state['revision'],'published'=>PatientBookingWorkflow::resolve($c),
            'draft'=>$editor['draft']??PatientBookingWorkflow::resolve($c),'history'=>$editor['history']??[],
            'publishedRevision'=>$editor['published_revision']??1,'suspended'=>!empty($c['suspended']),'configured'=>PatientBookingSettings::configured($c)];
    }
    public static function transition(array $config,string $action,array $payload,int $actor,bool $requireConfigured=true): array
    {
        $editor=$config['editor']??[];$published=PatientBookingWorkflow::resolve($config);
        if($action==='suspend') {
            $config['suspended']=!empty($payload['suspended']);return $config;
        } elseif($action==='save') {
            if(!is_array($payload['draft']??null) || strlen(json_encode($payload['draft']))>1600000) throw new \InvalidArgumentException('Bozza non valida.');
            $editor['draft']=$payload['draft'];
        } elseif($action==='discard') $editor['draft']=$published;
        elseif($action==='restore') {
            $found=null;foreach($editor['history']??[] as $item) if($item['revision']===(int)($payload['revision']??0))$found=$item['config'];
            if(!$found) throw new PatientBookingException('non_trovata',404);
            $editor['draft']=$found;
        } elseif($action==='publish') {
            $draft=$editor['draft']??$published;
            $config['first_available']=!empty($draft['scelte']['primo_disponibile']);
            foreach(['preavviso_minimo_min'=>'min_notice','giorni_prenotabili'=>'days','preavviso_modifica_ore'=>'cancel_hours'] as $from=>$to) $config[$to]=$draft['regole'][$from];
            $config['privacy_url']=$draft['comunicazioni']['privacy_url'];
            foreach(['telefono','email'] as $key) $config[$key]=trim((string)($draft['comunicazioni']['contatti'][$key]??''));
            $config['workflow']=PatientBookingWorkflow::validate($draft,$config);
            if ($requireConfigured && !PatientBookingSettings::configured($config)) throw new \InvalidArgumentException('Seleziona almeno un professionista, completa le scelte richieste dal percorso e l’informativa privacy prima di pubblicare.');
            $rev=(int)($editor['published_revision']??1);
            $editor['history'][]=['revision'=>$rev,'config'=>$published,'author'=>$actor,'date'=>gmdate('c'),'note'=>mb_substr((string)($payload['note']??''),0,500)];
            $editor['history']=array_slice($editor['history'],-30);
            $editor['published_revision']=$rev+1;$editor['draft']=$config['workflow'];
        } else throw new PatientBookingException('non_trovata',404);
        $config['editor']=$editor;return $config;
    }
}
