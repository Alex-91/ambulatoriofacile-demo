<?php
namespace App\Services\Pacs;

/** Persistent training orders. Never reads/writes clinical tables or sends to a device. */
final class PacsTrialService
{
    public function __construct(private string $directory, private int $tenantId, private int $userId)
    {
        if ($tenantId!==4 || $userId<=0) throw new PacsException('Spazio di prova non disponibile.');
    }
    public static function patients(): array
    {
        return [
            'demo-pacs'=>['patient_last_name'=>'DEMO','patient_first_name'=>'PACS SINTETICO','patient_birth_date'=>'1980-01-01','pacs_id'=>'AF-DEMO-20261001'],
            'demo-alfa'=>['patient_last_name'=>'DEMO','patient_first_name'=>'ALFA','patient_birth_date'=>'1975-06-15','pacs_id'=>'AF-DEMO-ALFA'],
            'demo-beta'=>['patient_last_name'=>'DEMO','patient_first_name'=>'BETA','patient_birth_date'=>'1990-11-20','pacs_id'=>'AF-DEMO-BETA'],
        ];
    }
    public static function catalog(): array
    {
        return [
            'us-abdomen'=>['label'=>'Ecografia addominale','code'=>'DEMO-US-ADD','modality'=>'US'],
            'us-thyroid'=>['label'=>'Ecografia tiroide','code'=>'DEMO-US-TIR','modality'=>'US'],
            'dx-chest'=>['label'=>'Radiografia torace','code'=>'DEMO-DX-TOR','modality'=>'DX'],
            'ct-head'=>['label'=>'TC cranio','code'=>'DEMO-CT-CRA','modality'=>'CT'],
            'mr-knee'=>['label'=>'Risonanza ginocchio','code'=>'DEMO-MR-GIN','modality'=>'MR'],
        ];
    }
    public static function stations(): array
    {
        return [
            'DEMO_US_1'=>['label'=>'Ecografo 1 · sala ecografia','modality'=>'US'],
            'DEMO_US_2'=>['label'=>'Ecografo 2 · sala visite','modality'=>'US'],
            'DEMO_DX_1'=>['label'=>'Radiografo · sala radiologia','modality'=>'DX'],
            'DEMO_CT_1'=>['label'=>'TC · sala tomografia','modality'=>'CT'],
            'DEMO_MR_1'=>['label'=>'Risonanza · sala RM','modality'=>'MR'],
        ];
    }
    private function store(callable $callback, bool $write=false): mixed
    {
        $dir=rtrim($this->directory,'/\\').'/tenant-'.$this->tenantId.'/master-'.$this->userId;
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new PacsException('Archivio di prova non disponibile.');
        $lock=fopen($dir.'/orders.lock','c');
        if (!$lock || !flock($lock,$write?LOCK_EX:LOCK_SH)) throw new PacsException('Archivio di prova occupato.');
        try {
            $path=$dir.'/orders.json';
            $data=is_file($path)?json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR):['version'=>1,'orders'=>[]];
            if (($data['version']??0)!==1 || !is_array($data['orders']??null)) throw new PacsException('Archivio di prova non valido.');
            $result=$callback($data['orders']);
            if ($write) {
                $tmp=tempnam($dir,'orders-');
                try {
                    if (file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))===false || !rename($tmp,$path)) throw new PacsException('Salvataggio non riuscito.');
                    chmod($path,0600);
                } finally { if (is_file($tmp)) unlink($tmp); }
            }
            return $result;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    public function listing(): array
    { return $this->store(static fn(array &$rows): array=>array_reverse(array_values($rows))); }
    public function read(string $id): array
    {
        return $this->store(function(array &$rows) use($id): array {
            if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !isset($rows[$id])) throw new PacsException('Richiesta di prova non disponibile.');
            return $rows[$id];
        });
    }
    private function event(array &$row,string $action): void
    { $row['history'][]=['action'=>$action,'at'=>gmdate('c'),'user'=>$this->userId]; }
    public function listingForPatient(int $patientId): array
    { return array_values(array_filter($this->listing(),static fn($row)=>(int)($row['source_patient_id']??0)===$patientId && $patientId>0)); }
    public function readForPatient(int $patientId,string $id): array
    {
        $row=$this->read($id);
        if ($patientId<=0 || (int)($row['source_patient_id']??0)!==$patientId) throw new PacsException('Richiesta di prova non disponibile per questo paziente.');
        return $row;
    }
    public function changeForPatient(int $patientId,string $id,int $revision,string $action,string $report=''): array
    { $this->readForPatient($patientId,$id);return $this->change($id,$revision,$action,$report); }
    public function exportForPatient(int $patientId,string $id,int $revision,string $format): string
    { $this->readForPatient($patientId,$id);return $this->export($id,$revision,$format); }
    public function create(array $input,string $key,int $sourcePatientId=0): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new PacsException('Riaprire il modulo di prova.');
        $exam=self::catalog()[(string)($input['exam']??'')]??null;
        $station=self::stations()[(string)($input['station']??'')]??null;
        if (!$exam || !$station || $exam['modality']!==$station['modality']) throw new PacsException('Scegliere un esame e un’apparecchiatura compatibili.');
        $patient=self::patients()[(string)($input['patient']??'demo-pacs')]??null;
        if (!$patient) throw new PacsException('Selezionare un paziente di prova valido.');
        if ($sourcePatientId<0) throw new PacsException('Paziente di prova non valido.');
        // Only a local administrative ID is retained. Clinical demographics never enter the demo export.
        if ($sourcePatientId>0) $patient=['patient_last_name'=>'DEMO','patient_first_name'=>'PAZIENTE '.$sourcePatientId,'patient_birth_date'=>'1980-01-01','pacs_id'=>'AF-DEMO-CHART-'.$sourcePatientId];
        $payload=ModalityWorklist::payload([
            'description'=>$exam['label'].' DEMO','procedure_code'=>$exam['code'],'coding_scheme'=>'AF-DEMO',
            'modality'=>$exam['modality'],'station_ae'=>(string)$input['station'],
            'scheduled_at'=>$input['scheduled_at']??'','reason'=>$input['reason']??'',
        ],$patient);
        $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
        return $this->store(function(array &$rows) use($payload,$hash,$key,$patient,$sourcePatientId): string {
            foreach ($rows as $row) if ($row['request_key']===$key) {
                if (!hash_equals($row['input_hash'],$hash)) throw new PacsException('Modulo già salvato con dati diversi.');
                return $row['id'];
            }
            if (count($rows)>=200) throw new PacsException('Archivio di prova completo: contattare l’assistenza.');
            $id=bin2hex(random_bytes(16));
            $row=['source_patient_id'=>$sourcePatientId,'id'=>$id,'request_key'=>$key,'input_hash'=>$hash,'accession'=>'DM'.strtoupper(bin2hex(random_bytes(7))),
                'study_uid'=>ModalityWorklist::uid($id),'payload'=>$payload,'state'=>'draft','revision'=>1,'history'=>[],
                'identity'=>['patient_id'=>$patient['pacs_id'],'issuer'=>'AF-DEMO'],'report'=>''];
            $this->event($row,'Bozza salvata');$rows[$id]=$row;return $id;
        },true);
    }
    public function change(string $id,int $revision,string $action,string $report=''): array
    {
        return $this->store(function(array &$rows) use($id,$revision,$action,$report): array {
            if (!isset($rows[$id]) || $rows[$id]['revision']!==$revision) throw new PacsException('Richiesta aggiornata in un’altra scheda. Ricaricare la pagina.');
            $row=&$rows[$id];
            $next=['confirm'=>['draft','ready','Richiesta confermata'],'accept'=>['ready','accepted','Accettazione registrata'],
                'start'=>['accepted','in_progress','Esecuzione avviata'],'complete'=>['in_progress','performed','Esecuzione conclusa']];
            if ($action==='cancel') {
                if (!in_array($row['state'],['draft','ready','accepted'],true)) throw new PacsException('Annullamento non disponibile in questo stato.');
                $row['state']='cancelled';$label='Richiesta annullata';
            } elseif ($action==='images') {
                if ($row['state']!=='performed' || !empty($row['sample_images'])) throw new PacsException('Immagini campione disponibili una sola volta dopo l’esecuzione.');
                $row['sample_images']=true;$label='Immagini campione aggiunte (simulazione, non risultato clinico)';
            } elseif ($action==='report') {
                if ($row['state']!=='performed' || mb_strlen(trim($report))<1 || mb_strlen($report)>5000) throw new PacsException('Compilare il referto di prova dopo l’esecuzione (massimo 5000 caratteri).');
                $row['report']=trim($report);$label='Referto dimostrativo salvato';
            } else {
                if (!isset($next[$action]) || $row['state']!==$next[$action][0]) throw new PacsException('Passaggio non consentito nello stato attuale.');
                $row['state']=$next[$action][1];$label=$next[$action][2];
            }
            $row['revision']++;$this->event($row,$label);return $row;
        },true);
    }
    public function export(string $id,int $revision,string $format): string
    {
        if (!in_array($format,['json','wl'],true)) throw new PacsException('Formato non disponibile.');
        return $this->store(function(array &$rows) use($id,$revision,$format): string {
            if (!isset($rows[$id]) || $rows[$id]['revision']!==$revision) throw new PacsException('Ricaricare la richiesta prima di esportare.');
            $row=&$rows[$id];
            if (!in_array($row['state'],['ready','accepted'],true)) throw new PacsException('Esportare la worklist dopo la conferma e prima dell’esecuzione.');
            $data=ModalityWorklist::dataset($row,$row['payload'],$row['identity']);
            $bytes=$format==='json'?json_encode($data,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR):ModalityWorklist::file($data,$row['study_uid']);
            $row['revision']++;$this->event($row,'Worklist '.$format.' esportata (non inviata)');return $bytes;
        },true);
    }
}
