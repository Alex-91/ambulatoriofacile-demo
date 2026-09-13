<?php
namespace App\Services\Pacs;

use App\Services\ClinicalRecordService;

/** Local operational progress, distinct from DICOM delivery and remote procedure status. */
trait PacsOrderWorkflow
{
    public const STAGES=['awaiting'=>'Da accettare','accepted'=>'Accettato','in_progress'=>'In esecuzione','performed'=>'Eseguito'];

    public function appointmentDraft(int $patientId,int $appointmentId): array
    {
        $this->guard($patientId,true);
        $appointment=$this->appointment($patientId,$appointmentId);
        $actor=$this->access->actor();
        if (!in_array((int)$appointment['id_dot'],$this->access->agendaDoctorIds([$actor['staff_id']]),true)) {
            throw new PacsException('Creazione riservata al medico dell’appuntamento.');
        }
        $description=trim((string)($appointment['tipo_visita_label'] ?? ''));
        if ($description==='') throw new PacsException('Indicare prima la prestazione nell’appuntamento.');
        return ['appointment_id'=>$appointmentId,'appointment_hash'=>$this->appointmentHash($appointment),
            'description'=>mb_substr($description,0,64),'scheduled_at'=>$appointment['data_slot'].'T'.substr($appointment['ora_inizio'],0,5)];
    }
    private function appointment(int $patientId,int $id,bool $lock=false): array
    {
        if ($id<=0 || !$this->db->tableExists('dap12_agenda_appuntamenti') || !$this->db->tableExists('dap11_agenda_slot')) {
            throw new PacsException('Appuntamento non disponibile.');
        }
        if ($lock && $this->db->DBDriver==='MySQLi') {
            $this->db->query('SELECT id_appuntamento FROM dap12_agenda_appuntamenti WHERE id_appuntamento = ? AND id_client = ? FOR UPDATE',[$id,$patientId]);
        }
        $a=$this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',$id)->where('id_client',$patientId)->get()->getRowArray();
        if (!$a || strtoupper((string)$a['stato'])==='ANNULLATO') throw new PacsException('Appuntamento annullato o non disponibile.');
        if ($lock && $this->db->DBDriver==='MySQLi') $this->db->query('SELECT id_slot FROM dap11_agenda_slot WHERE id_slot = ? FOR UPDATE',[$a['id_slot']]);
        $slot=$this->db->table('dap11_agenda_slot')->where('id_slot',$a['id_slot'])->get()->getRowArray();
        if (!$slot) throw new PacsException('Orario dell’appuntamento non disponibile.');
        return $a+['data_slot'=>$slot['data_slot'],'ora_inizio'=>$slot['ora_inizio']];
    }
    private function appointmentHash(array $a): string
    {
        $snapshot=[];
        foreach (['id_appuntamento','id_client','id_dot','id_slot','id_tipo_visita','tipo_visita_label','data_slot','ora_inizio'] as $key) $snapshot[$key]=(string)($a[$key] ?? '');
        return hash_hmac('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR),hex2bin((string)config(\App\Config\Crypto::class)->keyHex));
    }
    private function checkAppointment(array $row,bool $lock=false): void
    {
        if (empty($row['appointment_id'])) return;
        $current=$this->appointment((int)$row['patient_id'],(int)$row['appointment_id'],$lock);
        if (!hash_equals((string)$row['appointment_hash'],$this->appointmentHash($current))) {
            throw new PacsException('Appuntamento modificato: aggiornare la bozza oppure annullare e ricreare la richiesta confermata.');
        }
    }
    /** Staff may see the operational queue of their doctors, never clinical payloads through this method. */
    private function operationalOwners(): array
    {
        $this->features->assertEnabled($this->tenantId);
        if (!self::schemaReady($this->db)) throw new PacsException('Percorso diagnostico da inizializzare.');
        $actor=$this->access->actor();
        $doctors=[$actor['staff_id']];
        if ($actor['role']!==1) {
            $table=$actor['role']===3 ? 'dap14_seg_dot' : 'dap15_inf_dot';
            $field=$actor['role']===3 ? 'id_seg' : 'id_inf';
            $doctors=$this->db->tableExists($table) ? array_column($this->db->table($table)->where($field,$actor['staff_id'])->get()->getResultArray(),'id_dot') : [];
        }
        if (!$doctors) return [];
        $q=$this->db->table('dap03_personale')->select('id_user')->whereIn('id_personale',$doctors)->where('tipo',1);
        if ($this->db->fieldExists('is_active','dap03_personale')) $q->where('is_active',1);
        return array_map('intval',array_column($q->get()->getResultArray(),'id_user'));
    }
    private function operationalRow(int $patientId,string $id): array
    {
        $owners=$this->operationalOwners();
        $this->access->assertPatient($patientId,false);
        if (!$owners || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new PacsException('Richiesta operativa non disponibile.');
        $row=$this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->where('patient_id',$patientId)
            ->where('id',$id)->whereIn('owner_user_id',$owners)->get()->getRowArray();
        if (!$row || $row['state']!=='ready') throw new PacsException('Richiesta operativa non disponibile.');
        return $row;
    }
    public function queue(string $date,int $page=1,bool $completed=false): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$date,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new PacsException('Data non valida.');
        $owners=$this->operationalOwners(); $actor=$this->access->actor();
        $page=max(1,min(10000,$page)); $rows=[]; $more=false;
        if ($owners) {
            $query=$this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->whereIn('owner_user_id',$owners)->where('state','ready')
                ->groupStart()->where('scheduled_date',$date)->orWhere('scheduled_date',null)->groupEnd();
            if (!$completed) $query->where('workflow_stage !=','performed');
            $found=$query->orderBy('scheduled_date','ASC')->orderBy('created_at','ASC')->orderBy('id','ASC')->get(26,($page-1)*25)->getResultArray();
            $more=count($found)>25;
            foreach (array_slice($found,0,25) as $row) {
                try { $this->access->assertPatient((int)$row['patient_id'],false); } catch (\RuntimeException) { continue; }
                $payload=$this->open($row)['payload'];
                if (substr($payload['scheduled_at'],0,10)!==$date) continue;
                $patient=$this->patients->getPatientByIdForTenant($this->tenantId,(int)$row['patient_id']);
                if (!$patient) continue;
                $problem='';
                try { $this->checkAppointment($row); } catch (PacsException $e) { $problem=$e->getMessage(); }
                $rows[]=['id'=>$row['id'],'patient_id'=>(int)$row['patient_id'],
                    'patient_name'=>$patient['patient_name'] ?? trim(($patient['patient_last_name'] ?? '').' '.($patient['patient_first_name'] ?? '')),
                    'appointment_id'=>$row['appointment_id'],'description'=>$payload['description'],'scheduled_at'=>$payload['scheduled_at'],
                    'accession'=>$row['accession'],'stage'=>$row['workflow_stage'],'revision'=>(int)$row['revision'],
                    'clinical_owner'=>$actor['role']===1 && (int)$row['owner_user_id']===$this->userId,'problem'=>$problem];
            }
        }
        $this->audit(0,'pacs_queue_read','');
        return ['rows'=>$rows,'date'=>$date,'page'=>$page,'more'=>$more,'completed'=>$completed,'role'=>$actor['role']];
    }
    public function advance(int $patientId,string $id,int $revision,string $next): void
    {
        $row=$this->operationalRow($patientId,$id);
        $actor=$this->access->actor();
        $expected=['accepted'=>'awaiting','in_progress'=>'accepted','performed'=>'in_progress'];
        if (!isset($expected[$next]) || $row['workflow_stage']!==$expected[$next] || ($actor['role']===3 && $next!=='accepted')) {
            throw new PacsException('Passaggio non consentito per lo stato o il profilo corrente.');
        }
        $this->transaction(function () use ($row,$revision,$next) {
            // No remote I/O: reception records arrival, not PACS connectivity or execution acknowledgments.
            $this->operationalRow((int)$row['patient_id'],$row['id']);
            $this->checkAppointment($row,true);
            $this->db->table('pacs_orders')->where('tenant_id',$this->tenantId)->where('id',$row['id'])->where('revision',$revision)
                ->where('state','ready')->where('workflow_stage',$row['workflow_stage'])
                ->update(['workflow_stage'=>$next,'revision'=>$revision+1,'updated_at'=>gmdate('Y-m-d H:i:s')]);
            if ($this->db->affectedRows()!==1) throw new PacsException('Richiesta aggiornata. Riaprire la lista.');
            $this->audit((int)$row['patient_id'],'pacs_stage_'.$next,$row['id'],$revision+1);
        });
    }
    public function history(int $patientId,string $id,int $page=1): array
    {
        $this->row($patientId,$id);
        $page=max(1,min(10000,$page));
        $rows=$this->db->table('pacs_audit')->select('event,actor_user_id,recorded_at,order_revision')->where('tenant_id',$this->tenantId)
            ->where('patient_id',$patientId)->where('entity_id',$id)->whereNotIn('event',['pacs_order_read'])
            ->orderBy('recorded_at','DESC')->orderBy('order_revision','DESC')->orderBy('id','DESC')->get(26,($page-1)*25)->getResultArray();
        return ['rows'=>array_slice($rows,0,25),'more'=>count($rows)>25,'page'=>$page];
    }
    public function report(int $patientId,string $id): ?array
    {
        $row=$this->row($patientId,$id);
        if (empty($row['report_entry_id'])) return null;
        $service=new ClinicalRecordService($this->db,$this->tenantId,$this->userId);
        $entry=$service->entry($patientId,(int)$row['report_entry_id']);
        // Follow the existing immutable correction chain. Never advertise a draft as signed.
        $seen=[];
        while (true) {
            if (isset($seen[$entry['id']]) || count($seen)>=100) throw new PacsException('Catena delle revisioni non valida.');
            $seen[$entry['id']]=true;
            $child=$this->db->table('clinical_entries')->select('id')->where('previous_entry_id',$entry['id'])->where('id_client',$patientId)->get()->getRowArray();
            if (!$child) break;
            try { $entry=$service->entry($patientId,(int)$child['id']); }
            catch (\RuntimeException) { break; } // A colleague cannot read an unpublished correction.
        }
        if ($entry['kind']!=='report' || (int)$entry['appointment_id']!==(int)$row['appointment_id'] || (int)$entry['author_user_id']!==(int)$row['owner_user_id']) {
            throw new PacsException('Collegamento del referto non coerente.');
        }
        return $entry;
    }
    public function saveReport(int $patientId,string $id,int $revision,array $input): int
    {
        $row=$this->row($patientId,$id,true);
        if ($row['state']!=='ready' || $row['workflow_stage']!=='performed') throw new PacsException('Registrare prima l’esecuzione dell’esame.');
        $payload=$this->open($row)['payload']; $entryId=0;
        $this->transaction(function () use ($row,$revision,$input,$payload,$patientId,&$entryId) {
            $this->lockContext($row,$payload);
            $current=$this->report($patientId,$row['id']);
            if ($current && $current['state']!=='draft') throw new PacsException('Il referto è definitivo. Usare il percorso di revisione nella cartella.');
            $entryId=(new ClinicalRecordService($this->db,$this->tenantId,$this->userId))->saveEntry($patientId,[
                'id'=>$current['id'] ?? 0,'revision'=>(int)($input['entry_revision'] ?? 0),'kind'=>'report',
                'title'=>$current['content']['title'] ?? $payload['description'].' · '.$row['accession'],'body'=>(string)($input['body'] ?? ''),
                'occurred_at'=>(string)($input['occurred_at'] ?? ''),'appointment_id'=>(int)$row['appointment_id'],
            ]+array_intersect_key($current['content'] ?? [],array_flip(['anamnesis','findings','diagnosis','therapy','follow_up'])));
            // Keep the original anchor so shared readers can still see the last definitive version.
            $this->change($row,$revision,['report_entry_id'=>(int)($row['report_entry_id'] ?: $entryId)],'pacs_report_saved');
        });
        return $entryId;
    }
    public function linkImages(int $patientId,string $id,int $revision): string
    {
        $row=$this->row($patientId,$id,true);
        if ($row['state']!=='ready' || $row['workflow_stage']!=='performed' || (int)$row['revision']!==$revision) {
            throw new PacsException('Collegamento immagini disponibile dopo l’esecuzione, su una richiesta aggiornata.');
        }
        $payload=$this->open($row)['payload']; $this->checkAppointment($row);
        // PACS verifies PatientID, Issuer and StudyUID; accession must also match this specific order.
        return $this->pacs->link($patientId,$row['binding_id'],$row['study_uid'],(int)$row['binding_revision'],0,$row['accession'],
            function (string $linkId) use ($row,$revision,$payload) {
                $this->lockContext($row,$payload);
                $this->change($row,$revision,['study_link_id'=>$linkId],'pacs_order_images_linked');
            });
    }
}
