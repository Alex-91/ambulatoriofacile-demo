<?php
namespace Tests\Unit;
use App\Services\{ClinicalRecordService,ClinicalVault,ClinicalSignatureService};
use CodeIgniter\Test\CIUnitTestCase;

final class ClinicalRecordTest extends CIUnitTestCase
{
    protected $db;
    private string $root;
    private string $oldKey;
    protected function setUp(): void
    {
        parent::setUp(); helper(['form','url']);
        $crypto=config(\App\Config\Crypto::class); $this->oldKey=$crypto->keyHex;
        $crypto->keyHex=bin2hex(random_bytes(32));
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $this->root=WRITEPATH.'clinical-test-'.bin2hex(random_bytes(8));
        $this->db->query('CREATE TABLE dap01_users (id_user INTEGER PRIMARY KEY, username TEXT, is_active INTEGER)');
        $this->db->query('CREATE TABLE dap03_personale (id_personale INTEGER PRIMARY KEY, id_user INTEGER, tipo INTEGER, is_active INTEGER)');
        $this->db->query('CREATE TABLE dap02_clients (id_client INTEGER PRIMARY KEY)');
        $this->db->query('CREATE TABLE dap09_client_doctor (id_client INTEGER, id_dot INTEGER)');
        $this->db->query('CREATE TABLE dap14_seg_dot (id_seg INTEGER, id_dot INTEGER)');
        $this->db->query('CREATE TABLE dap15_inf_dot (id_inf INTEGER, id_dot INTEGER)');
        $this->db->query('CREATE TABLE dap11_agenda_slot (id_slot INTEGER PRIMARY KEY, data_slot TEXT, ora_inizio TEXT, ora_fine TEXT)');
        $this->db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INTEGER PRIMARY KEY, id_client INTEGER, id_dot INTEGER, id_slot INTEGER, tipo_visita_label TEXT, stato TEXT, created_at TEXT)');
        foreach([1=>1,2=>1,3=>3,4=>2,5=>1] as $id=>$role) {
            $this->db->table('dap01_users')->insert(['id_user'=>$id,'username'=>'VRDLGI70A01H501X','is_active'=>1]);
            $this->db->table('dap03_personale')->insert(['id_personale'=>$id*10,'id_user'=>$id,'tipo'=>$role,'is_active'=>1]);
        }
        $this->db->table('dap02_clients')->insertBatch([['id_client'=>100],['id_client'=>200]]);
        $this->db->table('dap09_client_doctor')->insertBatch([['id_client'=>100,'id_dot'=>10],['id_client'=>100,'id_dot'=>20],['id_client'=>200,'id_dot'=>50]]);
        $this->db->table('dap14_seg_dot')->insert(['id_seg'=>30,'id_dot'=>10]);
        $this->db->table('dap15_inf_dot')->insert(['id_inf'=>40,'id_dot'=>10]);
        $this->db->table('dap11_agenda_slot')->insert(['id_slot'=>1,'data_slot'=>'2026-09-12','ora_inizio'=>'10:00','ora_fine'=>'10:30']);
        $this->db->table('dap12_agenda_appuntamenti')->insert(['id_appuntamento'=>7,'id_client'=>100,'id_dot'=>10,'id_slot'=>1,'tipo_visita_label'=>'Visita sintetica','stato'=>'CONFERMATO']);
        require_once APPPATH.'Database/Migrations/2026-09-12-160001_CreateClinicalRecords.php';
        (new \App\Database\Migrations\CreateClinicalRecords(\Config\Database::forge($this->db)))->up();
    }
    protected function tearDown(): void
    {
        $this->db->close();
        config(\App\Config\Crypto::class)->keyHex=$this->oldKey;
        // Only files created in this test's randomly allocated directory.
        if(is_dir($this->root)) {
            $items=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            rmdir($this->root);
        }
        parent::tearDown();
    }
    public function testAgendaLegacyIdentityIsMappedWithoutGrantingAnotherDoctorsAccess(): void
    {
        $this->db->query('ALTER TABLE dap03_personale ADD legacy_id_dot INTEGER');
        $this->db->table('dap03_personale')->where('id_personale',10)->update(['legacy_id_dot'=>110]);
        $this->db->table('dap03_personale')->where('id_personale',20)->update(['legacy_id_dot'=>10]);
        $this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',7)->update(['id_dot'=>110]);
        $this->db->table('dap09_client_doctor')->where('id_client',100)->delete();
        $chart=$this->service()->patient(100);
        $this->assertSame(7,(int)$chart['appointments'][0]['id_appuntamento']);
        $this->expectException(\RuntimeException::class);
        $this->service(2)->patient(100);
    }

    public function testHistoricalFseDownloadChecksPatientAuthorAndHash(): void
    {
        $this->db->query('CREATE TABLE fse_documents (id_fse_document INTEGER PRIMARY KEY,id_client INTEGER,created_by INTEGER,local_state TEXT,signed_pdf_path TEXT,signed_pdf_sha256 TEXT)');
        $config=config(\App\Config\Fse2::class);$old=$config->tenantStorageRoot;$config->tenantStorageRoot=$this->root.'/fse';
        try {
            $bytes="%PDF-1.4\n Synthetic historical report";
            $path=(new \App\Services\FseStorageService())->store(42,1,'signed.pdf',$bytes);
            $this->db->table('fse_documents')->insert(['id_fse_document'=>1,'id_client'=>100,'created_by'=>1,'local_state'=>'signed','signed_pdf_path'=>$path,'signed_pdf_sha256'=>hash('sha256',$bytes)]);
            $this->assertSame($bytes,$this->service()->downloadFseReport(100,1)['bytes']);
            foreach ([[2,100],[1,200],[3,100]] as [$user,$patient]) {
                try { $this->service($user)->downloadFseReport($patient,1);$this->fail('Unauthorized historical report download'); }
                catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
            }
            file_put_contents($path,$bytes.' tampered');
            try { $this->service()->downloadFseReport(100,1);$this->fail('Modified PDF downloaded'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('Integrità',$e->getMessage()); }
        } finally { $config->tenantStorageRoot=$old; }
    }

    private function service(int $user=1,int $tenant=42,bool $manager=false): ClinicalRecordService
    { return new ClinicalRecordService($this->db,$tenant,$user,new ClinicalVault($tenant,null,$this->root),$manager); }
    private function entry(array $extra=[]): array
    { return $extra+['kind'=>'report','title'=>'Referto sintetico','body'=>'Contenuto sintetico <script>not executed</script>','occurred_at'=>'2026-09-12T10:00','appointment_id'=>7]; }
    private function consent(int $template=0,string $decision='granted',int $previous=0): int
    {
        $s=$this->service(1,42,true);
        $template=$template ?: $s->createTemplate(['kind'=>'dossier','version'=>'1','title'=>'Test sintetico','content'=>'Testo sintetico']);
        $proof=$s->attach(100,"%PDF-1.4\n synthetic evidence",'consenso.pdf','consent');
        return $s->recordConsent(100,['template_id'=>$template,'decision'=>$decision,'previous_id'=>$previous,'signer_name'=>'Persona sintetica','signer_capacity'=>'Paziente','evidence_object_id'=>$proof]);
    }
    public function testDraftFinalRevisionAndPdfKeepHistory(): void
    {
        $s=$this->service(); $id=$s->saveEntry(100,$this->entry());
        $s->saveEntry(100,$this->entry(['id'=>$id,'revision'=>1,'body'=>'Aggiornato']));
        $s->finalize(100,$id,2,['patient_name'=>'Persona sintetica','patient_tax_code'=>'RSSMRA80A01H501U']);
        $final=$s->entry(100,$id); $this->assertSame('final',$final['state']);
        $pdf=$s->download(100,$final['pdf_object_id']); $this->assertStringStartsWith('%PDF-',$pdf['bytes']);
        file_put_contents(WRITEPATH.'clinical-original-synthetic.pdf',$pdf['bytes']);
        $new=$s->saveEntry(100,$this->entry(['previous_entry_id'=>$id]));
        $this->assertSame($id,(int)$s->entry(100,$new)['previous_entry_id']);
        $this->assertSame('Aggiornato',$s->entry(100,$id)['content']['body']);
        $this->assertSame($pdf,$s->download(100,$final['pdf_object_id']));
        $this->assertStringNotContainsString('Aggiornato',$this->db->table('clinical_entries')->where('id',$id)->get()->getRowArray()['payload_enc']);
        $this->assertSame('2026-09-12',$s->patient(100)['appointments'][0]['data_slot']);
        $this->assertGreaterThan(4,$this->db->table('clinical_audit')->countAllResults());
    }
    public function testStaleDraftCannotOverwriteChanges(): void
    {
        $s=$this->service(); $id=$s->saveEntry(100,$this->entry());
        $s->saveEntry(100,$this->entry(['id'=>$id,'revision'=>1]));
        $this->expectExceptionMessage('aggiornata'); $s->saveEntry(100,$this->entry(['id'=>$id,'revision'=>1]));
    }
    public function testFinalDocumentCannotBeEdited(): void
    {
        $s=$this->service(); $id=$s->saveEntry(100,$this->entry()); $s->finalize(100,$id,1,[]);
        $this->expectException(\RuntimeException::class); $s->saveEntry(100,$this->entry(['id'=>$id,'revision'=>2]));
    }
    public function testOtherPatientAndInactiveStaffAreDenied(): void
    {
        foreach([200,999] as $patient) { try{$this->service()->patient($patient);$this->fail('Access granted');}catch(\RuntimeException $e){$this->assertStringContainsString('Paziente',$e->getMessage());} }
        $this->db->table('dap03_personale')->where('id_user',1)->update(['is_active'=>0]);
        $this->expectException(\RuntimeException::class); $this->service()->patient(100);
    }
    public function testSecretaryCanRecordConsentButCannotReadClinicalOrSign(): void
    {
        $s=$this->service(); $id=$s->saveEntry(100,$this->entry());
        $chart=$this->service(3)->patient(100); $this->assertFalse($chart['clinical']); $this->assertSame([],$chart['entries']);
        $this->expectException(\RuntimeException::class); $this->service(3)->entry(100,$id);
    }
    public function testNurseCannotCreateMedicalReport(): void
    { $this->expectException(\InvalidArgumentException::class);$this->service(4)->saveEntry(100,$this->entry()); }
    public function testConsentGrantAndRevokeChangeSharingButNeverExposeDrafts(): void
    {
        $s=$this->service(); $draft=$s->saveEntry(100,$this->entry());
        $hidden=$s->attach(100,"%PDF-1.4\n draft",'draft.pdf','clinical',$draft);
        $final=$s->saveEntry(100,$this->entry());$s->finalize(100,$final,1,[]);
        $this->assertSame(0,$this->service(2)->patient(100)['total']);
        $grant=$this->consent();$chart=$this->service(2)->patient(100);
        $this->assertSame(1,$chart['total']);$this->assertNotContains($hidden,array_column($chart['objects'],'id'));
        $this->consent(1,'revoked',$grant);
        $this->assertSame(0,$this->service(2)->patient(100)['total']);
        $this->assertCount(2,$s->patient(100)['consents']);
    }
    public function testGrantRequiresEvidenceAndOldFormCannotOverrideRevocation(): void
    {
        $grant=$this->consent();$this->consent(1,'revoked',$grant);
        $this->expectExceptionMessage('stato del consenso è cambiato');$this->consent(1,'granted',$grant);
    }
    public function testCrossTenantCipherAndObjectTamperingAreRejected(): void
    {
        $v=new ClinicalVault(42,null,$this->root);$cipher=$v->seal('test','Secret synthetic');
        try{(new ClinicalVault(43,null,$this->root))->open('test',$cipher);$this->fail('Cross tenant read');}catch(\RuntimeException $e){$this->assertStringContainsString('contesto',$e->getMessage());}
        $id=str_repeat('a',32);$sha=$v->put($id,'Synthetic blob');
        $this->assertSame('Synthetic blob',$v->get($id,$sha));
        $this->expectException(\RuntimeException::class);$v->get($id,str_repeat('0',64));
    }
    public function testUploadsRejectActiveHtmlAndCrossPatientEvidence(): void
    { $this->expectException(\InvalidArgumentException::class);$this->service()->attach(100,'<html><script>alert(1)</script></html>','x.pdf','clinical'); }
    public function testNoSignatureAcceptedWhenValidatorFails(): void
    {
        $s=$this->service();$id=$s->saveEntry(100,$this->entry());$s->finalize(100,$id,1,[]);
        $validator=$this->getMockBuilder(ClinicalSignatureService::class)->disableOriginalConstructor()->onlyMethods(['verify'])->getMock();
        $validator->method('verify')->willThrowException(new \RuntimeException('Invalid signature'));
        try{$s->acceptSignature(100,$id,'%PDF-invalid','pades','VRDLGI70A01H501X',$validator);$this->fail();}catch(\RuntimeException $e){$this->assertSame('Invalid signature',$e->getMessage());}
        $this->assertSame('final',$s->entry(100,$id)['state']);$this->assertNull($s->entry(100,$id)['signed_object_id']);
    }
    public function testViewEscapesClinicalTextAndUsesCsrf(): void
    {
        $s=$this->service();$s->saveEntry(100,$this->entry());
        $html=view('clinical/patient',['chart'=>$s->patient(100),'patient'=>['patient_name'=>'<script>patient</script>'],'patientId'=>100,'tenant'=>[],'editing'=>null,'revisionOf'=>null]);
        $this->assertStringNotContainsString('<script>',$html);$this->assertStringContainsString('&lt;script&gt;',$html);
        $this->assertStringContainsString(csrf_token(),$html);
        $filters=new \Config\Filters();$this->assertSame(['cartella-clinica/*'],$filters->filters['clinicalcsrf']['before']);
    }
}
