<?php
namespace App\Services;

/** Encrypted presentation drafts, scoped to the generated patients and the current master.
 * They never enter clinical_entries, cannot be finalized, signed or sent to FSE.
 */
final class ClinicalFixtureDrafts
{
    public function __construct(private ClinicalFixturePresentation $presentation, private ClinicalVault $vault,
        private int $userId, private ?string $root = null)
    { $this->root ??= WRITEPATH.'clinical-fixture-drafts'; }

    private function authorize(int $patientId): array
    {
        $chart=$this->presentation->read($patientId);
        if (!$chart || empty($chart['read_only'])) throw new \RuntimeException('Episodio non disponibile per questo profilo.');
        return $chart;
    }
    private function path(int $patientId): string
    {
        if ($patientId<=0 || $this->userId<=0) throw new \RuntimeException('Contesto non valido.');
        return $this->root.'/4-'.$this->userId.'-'.$patientId.'.bin';
    }
    private function load(int $patientId): array
    {
        $path=$this->path($patientId);
        if (is_link($path) || is_link($this->root)) throw new \RuntimeException('Archivio non disponibile.');
        if (!is_file($path)) return [];
        $cipher=file_get_contents($path);
        return json_decode($this->vault->open('fixture-drafts:'.$this->userId.':'.$patientId,$cipher),true,512,JSON_THROW_ON_ERROR);
    }
    public function listing(int $patientId): array
    { $this->authorize($patientId); return array_values($this->load($patientId)); }
    public function entry(int $patientId,int $id): array
    {
        foreach($this->listing($patientId) as $entry) if ($entry['id']===$id) return $entry;
        throw new \RuntimeException('Bozza non disponibile.');
    }
    public function save(int $patientId,array $input): int
    {
        $chart=$this->authorize($patientId);
        if (!empty($input['previous_entry_id'])) throw new \RuntimeException('Correzione non disponibile.');
        $content=[];
        foreach(['title'=>160,'body'=>40000,'anamnesis'=>20000,'findings'=>20000,'diagnosis'=>20000,'therapy'=>20000,'follow_up'=>20000] as $key=>$max) {
            if (isset($input[$key]) && !is_scalar($input[$key])) throw new \InvalidArgumentException('Campo non valido.');
            $content[$key]=trim((string)($input[$key] ?? ''));
            if (mb_strlen($content[$key])>$max) throw new \InvalidArgumentException('Testo troppo lungo.');
        }
        if ($content['title']==='' || $content['body']==='') throw new \InvalidArgumentException('Compilare titolo e motivo della visita.');
        $kind=(string)($input['kind'] ?? 'encounter');
        if (!isset(ClinicalRecordService::KINDS[$kind])) throw new \InvalidArgumentException('Tipo di episodio non valido.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($input['occurred_at'] ?? ''),new \DateTimeZone('Europe/Rome'));
        if (!$date || $date->format('Y-m-d\TH:i')!==($input['occurred_at'] ?? '')) throw new \InvalidArgumentException('Data non valida.');
        $appointment=(int)($input['appointment_id'] ?? 0);
        if ($appointment && !in_array($appointment,array_map('intval',array_column($chart['appointments'],'id_appuntamento')),true)) throw new \RuntimeException('Appuntamento non disponibile.');
        if (!is_dir($this->root) && !mkdir($this->root,0700,true) && !is_dir($this->root)) throw new \RuntimeException('Archivio non disponibile.');
        $path=$this->path($patientId);
        if (is_link($this->root) || is_link($path.'.lock')) throw new \RuntimeException('Archivio non disponibile.');
        $lock=fopen($path.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Salvataggio non disponibile.');
        try {
            $rows=$this->load($patientId); $id=(int)($input['id'] ?? 0);
            if ($id) {
                if (!isset($rows[$id]) || $rows[$id]['revision']!==(int)($input['revision'] ?? 0)) throw new \RuntimeException('Bozza aggiornata in un’altra finestra. Riaprire la cartella.');
                $revision=$rows[$id]['revision']+1;
            } else {
                if (count($rows)>=200) throw new \RuntimeException('Limite bozze raggiunto.');
                do { $id=random_int(1000000000,2000000000); } while(isset($rows[$id]));
                $revision=1;
            }
            $rows[$id]=['id'=>$id,'state'=>'draft','revision'=>$revision,'fixture_draft'=>true,'author_user_id'=>$this->userId,
                'occurred_at'=>$date->format('Y-m-d H:i:s'),'kind'=>$kind,'appointment_id'=>$appointment,'content'=>$content,
                'previous_entry_id'=>null,'pdf_object_id'=>null,'signed_object_id'=>null];
            $cipher=$this->vault->seal('fixture-drafts:'.$this->userId.':'.$patientId,json_encode($rows,JSON_THROW_ON_ERROR));
            $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
            if (file_put_contents($temp,$cipher)!==strlen($cipher)) throw new \RuntimeException('Salvataggio incompleto.');
            chmod($temp,0600);
            if (!rename($temp,$path)) { @unlink($temp); throw new \RuntimeException('Salvataggio non completato.'); }
            return $id;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
