<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Patient chart: every operation is bound to one tenant connection and one staff identity. */
class ClinicalRecordService
{
    public const KINDS = ['encounter'=>'Visita / episodio','anamnesis'=>'Anamnesi','allergies'=>'Allergie',
        'therapy'=>'Terapia','diagnosis'=>'Diagnosi','note'=>'Nota clinica','report'=>'Referto'];
    public const CONSENTS = ['privacy_notice'=>'Presa visione informativa','treatment'=>'Consenso alla prestazione',
        'dossier'=>'Condivisione dossier sanitario','delivery'=>'Consegna documenti','marketing'=>'Comunicazioni promozionali'];
    private ClinicalAccessPolicy $access;
    public function __construct(private BaseConnection $db, private int $tenantId, private int $userId,
        private ?ClinicalVault $vault = null, private bool $manageTemplates = false)
    {
        if ($tenantId <= 0 || $userId <= 0) throw new \InvalidArgumentException('Contesto clinico non valido.');
        $this->vault ??= new ClinicalVault($tenantId);
        $this->access = new ClinicalAccessPolicy($db, $userId);
    }
    public function ready(): bool
    {
        foreach (['clinical_entries','clinical_objects','clinical_consent_templates','clinical_consents','clinical_patient_state','clinical_audit'] as $table) {
            if (!$this->db->tableExists($table)) return false;
        }
        return true;
    }
    public function actor(): array { return $this->access->actor(); }
    public function patient(int $patientId, int $page = 1, bool $includeFse = true): array
    {
        $this->assertReady();
        $actor = $this->access->assertPatient($patientId, false);
        $clinical = $actor['role'] !== 3;
        $shared = $this->shared($patientId);
        $this->audit($patientId, $clinical ? 'chart_viewed' : 'consents_viewed', (string) $patientId);
        $page = max(1, $page);
        $entries = [];
        $total = 0;
        if ($clinical) {
            $builder = $this->entryQuery($patientId, $shared);
            $total = $builder->countAllResults();
            $entries = $this->entryQuery($patientId, $shared)->orderBy('occurred_at','DESC')->orderBy('id','DESC')->get(25, ($page-1)*25)->getResultArray();
            foreach ($entries as &$entry) $entry = $this->decodeEntry($entry);
            unset($entry);
        }
        $objects = $this->db->table('clinical_objects')->where('id_client',$patientId);
        if (!$clinical) $objects->where('category','consent');
        elseif (!$shared) $objects->groupStart()->where('created_by',$this->userId)->orWhere('category','consent')->groupEnd();
        if ($clinical && $shared) {
            $visible = $this->entryQuery($patientId, true)->select('id');
            $objects->groupStart()->where('entry_id',null)->orWhereIn('entry_id',$visible)->groupEnd();
        }
        $files = $objects->orderBy('created_at','DESC')->get()->getResultArray();
        foreach ($files as &$file) $file['name'] = $this->vault->open('name:'.$file['id'], $file['name_enc']);
        unset($file);
        $consents = $this->db->table('clinical_consents')->where('id_client',$patientId)->orderBy('id','DESC')->get()->getResultArray();
        foreach ($consents as &$consent) $consent['signer'] = json_decode($this->vault->open('consent:'.$patientId, $consent['signer_enc']), true, 512, JSON_THROW_ON_ERROR);
        unset($consent);
        return ['actor'=>$actor,'clinical'=>$clinical,'shared'=>$shared,'entries'=>$entries,'total'=>$total,
            'page'=>$page,'objects'=>$files,'consents'=>$consents,'templates'=>$this->templates(),
            'appointments'=>$clinical ? $this->appointments($patientId, $shared, $actor) : [],
            'fse_enabled'=>$includeFse,
            'fse_reports'=>$clinical && $includeFse ? $this->fseReports($patientId, $shared) : [],
            'manage_templates'=>$this->manageTemplates];
    }
    public function saveEntry(int $patientId, array $input): int
    {
        $this->assertReady();
        $actor = $this->access->assertPatient($patientId);
        $kind = (string) ($input['kind'] ?? 'encounter');
        if (!isset(self::KINDS[$kind]) || ($actor['role'] !== 1 && $kind !== 'note')) throw new \InvalidArgumentException('Tipo di documento non consentito per questo profilo.');
        $title = $this->text($input['title'] ?? '', 160, true);
        $body = $this->text($input['body'] ?? '', 40000, true);
        $occurred = $this->dateTime((string) ($input['occurred_at'] ?? ''));
        $payload = ['title'=>$title,'body'=>$body];
        foreach (['anamnesis','findings','diagnosis','therapy','follow_up'] as $key) $payload[$key] = $this->text($input[$key] ?? '', 20000);
        $id = (int) ($input['id'] ?? 0);
        $previous = (int) ($input['previous_entry_id'] ?? 0);
        return $this->transaction($patientId, function () use ($patientId,$payload,$kind,$occurred,$input,$id,$previous): int {
            $current = $id > 0 ? $this->entry($patientId, $id) : null;
            if ($current && ($current['state'] !== 'draft' || (int) $current['author_user_id'] !== $this->userId)) throw new \RuntimeException('Il documento non è una tua bozza modificabile.');
            if ($current && (int) ($input['revision'] ?? 0) !== (int) $current['revision']) throw new \RuntimeException('La bozza è stata aggiornata. Riaprila prima di salvare.');
            if ($previous > 0 && !$current) {
                $original = $this->entry($patientId, $previous);
                if ($original['state'] === 'draft' || (int) $original['author_user_id'] !== $this->userId) throw new \RuntimeException('La correzione deve riferirsi a un tuo documento definitivo.');
                if ($this->db->table('clinical_entries')->where('previous_entry_id',$previous)->countAllResults()) throw new \RuntimeException('Esiste già una revisione di questo documento.');
            }
            $appointment = (int) ($input['appointment_id'] ?? 0);
            if ($appointment > 0) $this->assertAppointment($patientId, $appointment);
            $now = date('Y-m-d H:i:s');
            $record = ['id_client'=>$patientId,'author_user_id'=>$this->userId,'kind'=>$kind,'occurred_at'=>$occurred,
                'payload_enc'=>$this->vault->seal('entry:'.$patientId, json_encode($payload, JSON_THROW_ON_ERROR)),
                'appointment_id'=>$appointment ?: null,'revision'=>$current ? (int) $current['revision']+1 : 1,'updated_at'=>$now];
            if ($current) {
                $this->db->table('clinical_entries')->where('id',$id)->where('id_client',$patientId)->where('state','draft')->where('revision',$current['revision'])->update($record);
                if ($this->db->affectedRows() !== 1) throw new \RuntimeException('Aggiornamento concorrente della bozza.');
                $saved = $id;
            } else {
                $record += ['state'=>'draft','previous_entry_id'=>$previous ?: null,'created_at'=>$now];
                if (!$this->db->table('clinical_entries')->insert($record)) throw new \RuntimeException('Salvataggio clinico non riuscito.');
                $saved = (int) $this->db->insertID();
            }
            $this->audit($patientId, $current ? 'entry_updated' : ($previous ? 'entry_revision_created' : 'entry_created'), (string)$saved);
            return $saved;
        });
    }
    public function entry(int $patientId, int $id): array
    {
        $this->assertReady(); $this->access->assertPatient($patientId);
        $row = $this->entryQuery($patientId,$this->shared($patientId))->where('id',$id)->get()->getRowArray();
        if (!$row) throw new \RuntimeException('Documento clinico non disponibile.');
        $this->audit($patientId,'entry_viewed',(string)$id);
        return $this->decodeEntry($row);
    }
    public function finalize(int $patientId, int $id, int $revision, array $patientIdentity): void
    {
        $this->access->assertDoctor($patientId);
        $entry = $this->entry($patientId,$id);
        if ($entry['state'] !== 'draft' || (int)$entry['author_user_id'] !== $this->userId || (int)$entry['revision'] !== $revision) throw new \RuntimeException('Bozza non finalizzabile o aggiornata da un altro accesso.');
        $author = $this->db->table('dap01_users')->select('username')->where('id_user',$this->userId)->get()->getRowArray();
        $html = view('clinical/record_pdf', ['entry'=>$entry,'patient'=>$patientIdentity,'tenantId'=>$this->tenantId,'authorIdentity'=>$author['username'] ?? '']);
        $options = (new BillingPdfOptionsFactory())->create($this->tenantId); $options->setIsRemoteEnabled(false);
        $pdf = new \Dompdf\Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
        $canvas=$pdf->getCanvas();
        if (!$canvas instanceof \Dompdf\Adapter\CPDF) throw new \RuntimeException('Generatore PDF clinico non compatibile.');
        $cpdf=$canvas->get_cpdf(); $cpdf->addForm(0,false);
        $cpdf->objects[$cpdf->acroFormId]['info']['Fields']=[];
        $bytes = $pdf->output();
        $this->transaction($patientId,function () use ($patientId,$id,$revision,$bytes): void {
            $current = $this->entry($patientId,$id);
            if ($current['state'] !== 'draft' || (int)$current['revision'] !== $revision) throw new \RuntimeException('La bozza è cambiata durante la generazione del PDF.');
            $object = $this->storeObject($patientId,$bytes,'documento-clinico.pdf','application/pdf','original',$id);
            $this->db->table('clinical_entries')->where('id',$id)->where('id_client',$patientId)->where('revision',$revision)->where('state','draft')->update([
                'state'=>'final','pdf_object_id'=>$object,'finalized_at'=>date('Y-m-d H:i:s'),'revision'=>$revision+1,'updated_at'=>date('Y-m-d H:i:s')]);
            if ($this->db->affectedRows() !== 1) throw new \RuntimeException('Finalizzazione concorrente.');
            $this->audit($patientId,'entry_finalized',(string)$id);
        });
    }
    public function attach(int $patientId, string $bytes, string $name, string $category, int $entryId = 0): string
    {
        $this->assertReady(); $this->access->assertPatient($patientId,$category !== 'consent');
        if (!in_array($category,['clinical','consent'],true)) throw new \InvalidArgumentException('Categoria allegato non valida.');
        if ($category === 'consent' && $entryId) throw new \InvalidArgumentException('Il consenso va associato al paziente.');
        if ($entryId) {
            $entry = $this->entry($patientId,$entryId);
            if ((int)$entry['author_user_id'] !== $this->userId || $entry['state'] !== 'draft') throw new \RuntimeException('Gli allegati del documento definitivo non possono essere modificati.');
        }
        $mime = $this->fileMime($bytes);
        return $this->transaction($patientId,function () use ($patientId,$bytes,$name,$mime,$category,$entryId): string {
            if ($entryId && $this->entry($patientId,$entryId)['state'] !== 'draft') throw new \RuntimeException('Il documento è stato finalizzato nel frattempo.');
            $id = $this->storeObject($patientId,$bytes,$name,$mime,$category,$entryId);
            $this->audit($patientId,'attachment_created',$id); return $id;
        });
    }
    public function download(int $patientId, string $objectId): array
    {
        $this->assertReady();
        $object = $this->db->table('clinical_objects')->where('id',$objectId)->where('id_client',$patientId)->get()->getRowArray();
        if (!$object) throw new \RuntimeException('Documento non disponibile.');
        $this->access->assertPatient($patientId,$object['category'] !== 'consent');
        if ($object['category'] !== 'consent' && (int)$object['created_by'] !== $this->userId && !$this->shared($patientId)) throw new \RuntimeException('Documento non disponibile per il profilo corrente.');
        if ($object['entry_id']) $this->entry($patientId,(int)$object['entry_id']);
        $bytes = $this->vault->get($objectId,$object['sha256']);
        $this->audit($patientId,'document_downloaded',$objectId);
        return ['bytes'=>$bytes,'mime'=>$object['mime'],'name'=>$this->vault->open('name:'.$objectId,$object['name_enc'])];
    }
    public function templates(): array
    {
        $this->assertReady(); $this->access->actor();
        $rows = $this->db->table('clinical_consent_templates')->orderBy('id','DESC')->get()->getResultArray();
        foreach ($rows as &$row) $row['content'] = $this->vault->open('template:'.$row['kind'].':'.$row['version'],$row['content_enc']);
        return $rows;
    }
    public function createTemplate(array $input): int
    {
        $this->assertReady(); $this->access->actor();
        if (!$this->manageTemplates) throw new \RuntimeException('Solo il responsabile può pubblicare modelli di consenso.');
        $kind = (string)($input['kind'] ?? '');
        if (!isset(self::CONSENTS[$kind])) throw new \InvalidArgumentException('Finalità non valida.');
        $title = $this->text($input['title'] ?? '',160,true); $version = $this->text($input['version'] ?? '',32,true);
        $content = $this->text($input['content'] ?? '',60000,true);
        if ($this->db->table('clinical_consent_templates')->where('kind',$kind)->where('version',$version)->countAllResults()) throw new \RuntimeException('Questa versione esiste già. Pubblicare una nuova versione.');
        $this->db->transBegin();
        try {
            if (!$this->db->table('clinical_consent_templates')->insert(['kind'=>$kind,'title'=>$title,'version'=>$version,
                'content_enc'=>$this->vault->seal('template:'.$kind.':'.$version,$content),'sha256'=>hash('sha256',$content),'created_by'=>$this->userId,'created_at'=>date('Y-m-d H:i:s')])) throw new \RuntimeException('Pubblicazione modello non riuscita.');
            $id = (int)$this->db->insertID(); $this->audit(null,'consent_template_published',(string)$id);
            if (!$this->db->transStatus() || !$this->db->transCommit()) throw new \RuntimeException('Pubblicazione modello non riuscita.');
            return $id;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
    public function recordConsent(int $patientId, array $input): int
    {
        $this->assertReady(); $this->access->assertPatient($patientId,false);
        $templateId = (int)($input['template_id'] ?? 0);
        $template = $this->db->table('clinical_consent_templates')->where('id',$templateId)->get()->getRowArray();
        if (!$template) throw new \InvalidArgumentException('Modello di consenso non disponibile.');
        $decision = (string)($input['decision'] ?? '');
        $allowed = $template['kind'] === 'privacy_notice' ? ['acknowledged'] : ['granted','denied','revoked'];
        if (!in_array($decision,$allowed,true)) throw new \InvalidArgumentException('Esito non valido per questa finalità.');
        $signer = ['name'=>$this->text($input['signer_name'] ?? '',160,true),
            'capacity'=>$this->text($input['signer_capacity'] ?? '',160,true),
            'note'=>$this->text($input['note'] ?? '',2000)];
        $evidence = (string)($input['evidence_object_id'] ?? '');
        if ($decision === 'granted' && $evidence === '') throw new \InvalidArgumentException('Allegare il documento sottoscritto prima di registrare il consenso.');
        if ($evidence !== '' && !$this->db->table('clinical_objects')->where('id',$evidence)->where('id_client',$patientId)->where('category','consent')->countAllResults()) throw new \InvalidArgumentException('Documento di consenso non appartenente al paziente.');
        return $this->transaction($patientId,function () use ($patientId,$input,$templateId,$template,$decision,$signer,$evidence): int {
            $latest = $this->db->table('clinical_consents')->where('id_client',$patientId)->where('kind',$template['kind'])->orderBy('id','DESC')->get(1)->getRowArray();
            if ((int)($input['previous_id'] ?? 0) !== (int)($latest['id'] ?? 0)) throw new \RuntimeException('Lo stato del consenso è cambiato: riaprire la cartella.');
            if ($decision === 'revoked' && (!$latest || $latest['decision'] !== 'granted' || (int)$latest['template_id'] !== $templateId)) throw new \RuntimeException('Non esiste un consenso attivo per questa versione da revocare.');
            if (!$this->db->table('clinical_consents')->insert(['id_client'=>$patientId,'template_id'=>$templateId,'kind'=>$template['kind'],'decision'=>$decision,
                'signer_enc'=>$this->vault->seal('consent:'.$patientId,json_encode($signer,JSON_THROW_ON_ERROR)),
                'evidence_object_id'=>$evidence ?: null,'previous_id'=>$latest['id'] ?? null,'recorded_by'=>$this->userId,'recorded_at'=>date('Y-m-d H:i:s')])) throw new \RuntimeException('Registrazione consenso non riuscita.');
            $id=(int)$this->db->insertID(); $this->audit($patientId,'consent_'.$decision,(string)$id); return $id;
        });
    }
    public function acceptSignature(int $patientId, int $entryId, string $bytes, string $format, string $authorCf, ?ClinicalSignatureService $validator = null): void
    {
        $this->access->assertDoctor($patientId);
        $entry = $this->entry($patientId,$entryId);
        if ($entry['state'] !== 'final' || (int)$entry['author_user_id'] !== $this->userId) throw new \RuntimeException('Selezionare un proprio documento definitivo da firmare.');
        $original = $this->download($patientId,$entry['pdf_object_id']);
        $evidence = ($validator ?? new ClinicalSignatureService())->verify($original['bytes'],$bytes,$format,$authorCf);
        $this->transaction($patientId,function () use ($patientId,$entryId,$entry,$bytes,$format,$evidence): void {
            $current=$this->entry($patientId,$entryId);
            if ($current['state'] !== 'final' || $current['revision'] !== $entry['revision']) throw new \RuntimeException('Il documento è cambiato durante la verifica della firma.');
            $id=$this->storeObject($patientId,$bytes,$format === 'cades' ? 'documento-firmato.pdf.p7m' : 'documento-firmato.pdf',
                $format === 'cades' ? 'application/pkcs7-mime' : 'application/pdf','signed',$entryId);
            $this->db->table('clinical_entries')->where('id',$entryId)->where('id_client',$patientId)->where('state','final')->update([
                'state'=>'signed','signed_object_id'=>$id,'signature_evidence_json'=>json_encode($evidence,JSON_THROW_ON_ERROR),
                'revision'=>(int)$entry['revision']+1,'updated_at'=>date('Y-m-d H:i:s')]);
            if ($this->db->affectedRows() !== 1) throw new \RuntimeException('Firma concorrente del documento.');
            $this->audit($patientId,'signature_verified',(string)$entryId);
        });
    }
    private function entryQuery(int $patientId, bool $shared)
    {
        $query=$this->db->table('clinical_entries')->where('id_client',$patientId);
        if (!$shared) $query->where('author_user_id',$this->userId);
        else $query->groupStart()->where('author_user_id',$this->userId)->orWhere('state !=','draft')->groupEnd();
        return $query;
    }
    private function decodeEntry(array $row): array
    {
        $row['content']=json_decode($this->vault->open('entry:'.$row['id_client'],$row['payload_enc']),true,512,JSON_THROW_ON_ERROR);
        unset($row['payload_enc']); return $row;
    }
    private function shared(int $patientId): bool
    {
        $last=$this->db->table('clinical_consents')->where('id_client',$patientId)->where('kind','dossier')->orderBy('id','DESC')->get(1)->getRowArray();
        return $last && $last['decision'] === 'granted' && !empty($last['evidence_object_id']);
    }
    private function appointments(int $patientId, bool $shared, array $actor): array
    {
        $table='dap12_agenda_appuntamenti';
        if (!$this->db->tableExists($table) || !$this->db->fieldExists('id_client',$table)) return [];
        $fields=array_intersect(['id_appuntamento','id_tipo_visita','tipo_visita_label','motivo_visita','stato','id_dot','created_at'], $this->db->getFieldNames($table));
        $query=$this->db->table($table.' a')->select(implode(',',array_map(static fn($f)=>'a.'.$f,$fields)))->where('a.id_client',$patientId);
        if ($this->db->tableExists('dap11_agenda_slot') && $this->db->fieldExists('id_slot',$table)) {
            $query->join('dap11_agenda_slot s','s.id_slot = a.id_slot','left')->select('s.data_slot,s.ora_inizio,s.ora_fine');
        }
        if (!$shared) {
            $agendaIds=$this->access->agendaDoctorIds([$actor['staff_id']]);
            if (!$agendaIds) return [];
            $query->whereIn('a.id_dot',$agendaIds);
        }
        return $query->orderBy('a.id_appuntamento','DESC')->get()->getResultArray();
    }
    private function fseReports(int $patientId, bool $shared): array
    {
        if (!$this->db->tableExists('fse_documents')) return [];
        $query=$this->db->table('fse_documents')->select('id_fse_document, document_type, local_state, service_start, created_by')->where('id_client',$patientId);
        if (!$shared) $query->where('created_by',$this->userId);
        else $query->groupStart()->where('created_by',$this->userId)->orWhereIn('local_state',['signed','published'])->groupEnd();
        return $query->orderBy('id_fse_document','DESC')->get()->getResultArray();
    }
    public function downloadFseReport(int $patientId, int $documentId): array
    {
        $this->assertReady();
        (new ClinicalAccessPolicy($this->db,$this->userId))->assertPatient($patientId,true);
        if (!$this->db->tableExists('fse_documents')) throw new \RuntimeException('Referto non disponibile.');
        $record=$this->db->table('fse_documents')->where('id_fse_document',$documentId)->where('id_client',$patientId)->get()->getRowArray();
        if (!$record || ((int)$record['created_by']!==$this->userId && (!$this->shared($patientId) || !in_array($record['local_state'],['signed','published'],true)))) throw new \RuntimeException('Referto non accessibile.');
        $kind=!empty($record['signed_pdf_path']) ? 'signed_pdf' : 'unsigned_pdf';
        $bytes=(new FseArtifactValidationService())->storedArtifact($this->tenantId,$documentId,$record,$kind);
        $this->audit($patientId,'fse_report_downloaded',(string)$documentId);
        return ['mime'=>'application/pdf','name'=>'referto-fse-'.$documentId.($kind==='signed_pdf' ? '-firmato' : '-non-firmato').'.pdf','bytes'=>$bytes];
    }
    private function assertAppointment(int $patientId, int $appointment): void
    {
        if (!$this->db->tableExists('dap12_agenda_appuntamenti') || !$this->db->fieldExists('id_client','dap12_agenda_appuntamenti')
            || !$this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',$appointment)->where('id_client',$patientId)->countAllResults()) throw new \InvalidArgumentException('Prestazione non appartenente al paziente.');
    }
    private function storeObject(int $patientId,string $bytes,string $name,string $mime,string $category,int $entryId=0): string
    {
        $name=basename(str_replace('\\','/',$name)); $name=preg_replace('/[\x00-\x1f\x7f]/','',$name);
        $name=$this->text($name,180,true); $id=bin2hex(random_bytes(16));
        $sha=$this->vault->put($id,$bytes);
        if (!$this->db->table('clinical_objects')->insert(['id'=>$id,'id_client'=>$patientId,'entry_id'=>$entryId ?: null,
            'category'=>$category,'mime'=>$mime,'name_enc'=>$this->vault->seal('name:'.$id,$name),'sha256'=>$sha,
            'size_bytes'=>strlen($bytes),'created_by'=>$this->userId,'created_at'=>date('Y-m-d H:i:s')])) throw new \RuntimeException('Registrazione documento non riuscita.');
        return $id;
    }
    private function fileMime(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes)>ClinicalVault::MAX_BYTES) throw new \InvalidArgumentException('Documento vuoto o oltre 15 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime,['application/pdf','image/jpeg','image/png'],true)) throw new \InvalidArgumentException('Sono ammessi PDF, JPEG e PNG.');
        if ($mime==='application/pdf' && !str_starts_with($bytes,'%PDF-')) throw new \InvalidArgumentException('PDF non valido.');
        if ($mime!=='application/pdf' && @getimagesizefromstring($bytes)===false) throw new \InvalidArgumentException('Immagine non valida.');
        return $mime;
    }
    private function transaction(int $patientId, callable $action): mixed
    {
        $this->db->transBegin();
        try {
            if (!$this->db->table('clinical_patient_state')->where('id_client',$patientId)->countAllResults()) {
                if (!$this->db->table('clinical_patient_state')->insert(['id_client'=>$patientId,'revision'=>0])) throw new \RuntimeException('Operazione concorrente sulla cartella, riprovare.');
            }
            // Update obtains the patient row lock on MySQL and serializes chart/consent changes.
            if (!$this->db->table('clinical_patient_state')->where('id_client',$patientId)->set('revision','revision + 1',false)->update()) throw new \RuntimeException('Cartella temporaneamente occupata.');
            $result=$action();
            if (!$this->db->transStatus() || !$this->db->transCommit()) throw new \RuntimeException('Salvataggio clinico non completato.');
            return $result;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
    private function audit(?int $patientId,string $event,string $entity): void
    {
        if (!$this->db->table('clinical_audit')->insert(['id_client'=>$patientId,'actor_user_id'=>$this->userId,'event'=>$event,'entity_id'=>$entity,'recorded_at'=>date('Y-m-d H:i:s')])) throw new \RuntimeException('Registrazione accesso clinico non disponibile.');
    }
    private function assertReady(): void { if (!$this->ready()) throw new \RuntimeException('Archivio clinico da inizializzare tramite la migrazione dedicata.'); }
    private function text(mixed $value,int $max,bool $required=false): string
    {
        if (!is_scalar($value) && $value!==null) throw new \InvalidArgumentException('Campo testuale non valido.');
        $value=trim((string)$value);
        if (($required && $value==='') || mb_strlen($value)>$max || str_contains($value,"\0")) throw new \InvalidArgumentException('Campo obbligatorio mancante o testo troppo lungo.');
        return $value;
    }
    private function dateTime(string $value): string
    {
        $value=str_replace('T',' ',trim($value));
        if (strlen($value)===16) $value.=':00';
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if (!$date || $date->format('Y-m-d H:i:s')!==$value) throw new \InvalidArgumentException('Data della prestazione non valida.');
        return $value;
    }
}
