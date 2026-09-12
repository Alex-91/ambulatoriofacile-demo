<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Services\Pacs\{PacsService,PacsProfiles,PacsException};
use CodeIgniter\Test\CIUnitTestCase;

final class PacsServiceTest extends CIUnitTestCase
{
    protected $db;
    private string $key;
    private MutablePacsGate $gate;
    private MemoryPacsTransport $transport;
    protected function setUp(): void
    {
        parent::setUp();
        $this->key=config(\App\Config\Crypto::class)->keyHex;
        config(\App\Config\Crypto::class)->keyHex=bin2hex(random_bytes(32));
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        foreach ([
            'dap01_users'=>'id_user INTEGER PRIMARY KEY, is_active INTEGER',
            'dap03_personale'=>'id_personale INTEGER PRIMARY KEY,id_user INTEGER,tipo INTEGER,is_active INTEGER',
            'dap02_clients'=>'id_client INTEGER PRIMARY KEY',
            'dap09_client_doctor'=>'id_client INTEGER,id_dot INTEGER',
            'dap14_seg_dot'=>'id_seg INTEGER,id_dot INTEGER',
            'dap15_inf_dot'=>'id_inf INTEGER,id_dot INTEGER',
            'clinical_consents'=>'id INTEGER PRIMARY KEY,id_client INTEGER,kind TEXT,decision TEXT,evidence_object_id TEXT',
        ] as $table=>$fields) $this->db->query('CREATE TABLE '.$table.' ('.$fields.')');
        foreach ([1=>1,2=>1,3=>3,4=>2,5=>1] as $id=>$role) {
            $this->db->table('dap01_users')->insert(['id_user'=>$id,'is_active'=>1]);
            $this->db->table('dap03_personale')->insert(['id_personale'=>$id*10,'id_user'=>$id,'tipo'=>$role,'is_active'=>1]);
        }
        $this->db->table('dap02_clients')->insertBatch([['id_client'=>100],['id_client'=>200]]);
        $this->db->table('dap09_client_doctor')->insertBatch([['id_client'=>100,'id_dot'=>10],['id_client'=>100,'id_dot'=>20],['id_client'=>200,'id_dot'=>50]]);
        $this->db->table('dap14_seg_dot')->insert(['id_seg'=>30,'id_dot'=>10]);
        $this->db->table('dap15_inf_dot')->insert(['id_inf'=>40,'id_dot'=>10]);
        require_once APPPATH.'Database/Migrations/2026-09-13-100001_CreatePacsIntegration.php';
        (new \App\Database\Migrations\CreatePacsIntegration(\Config\Database::forge($this->db)))->up();
        $this->gate=new MutablePacsGate(); $this->transport=new MemoryPacsTransport();
    }
    protected function tearDown(): void
    { $this->db->close(); config(\App\Config\Crypto::class)->keyHex=$this->key; parent::tearDown(); }
    private function service(int $user=1,int $tenant=42): PacsService
    {
        return new PacsService($this->db,$tenant,$user,new PacsProfiles(['tenants'=>['42'=>[MemoryPacsTransport::profile()],'43'=>[MemoryPacsTransport::profile()]]]),$this->gate,$this->transport);
    }
    private function bind(): string { return $this->service()->bind(100,'cloud','P-100','TEST-HOSPITAL',0,true); }
    private function denied(callable $action): void
    {
        $caught=false;
        try { $action(); } catch (\RuntimeException) { $caught=true; }
        $this->assertTrue($caught,'Expected access to be denied');
    }
    public function testBindingAndLinkAreEncryptedAndNoNetworkOccursOnOverview(): void
    {
        $id=$this->bind();
        $this->assertCount(0,$this->transport->calls);
        $this->assertCount(1,$this->service()->overview(100)['bindings']);
        $raw=$this->db->table('pacs_patient_bindings')->get()->getRowArray();
        $this->assertStringNotContainsString('P-100',$raw['identity_enc']);
        $this->assertStringNotContainsString('TEST-HOSPITAL',$raw['identity_enc']);
        $link=$this->service()->link(100,$id,'1.2.826.0.1.100',1);
        $this->assertCount(1,$this->service()->overview(100)['links']);
        $this->assertStringNotContainsString('SINTETICO',$this->db->table('pacs_study_links')->where('id',$link)->get()->getRowArray()['metadata_enc']);
        $this->assertGreaterThan(0,$this->db->table('pacs_audit')->countAllResults());
    }
    public function testTenantPatientRoleAndStaffBoundariesBeforeNetwork(): void
    {
        $id=$this->bind();
        foreach ([fn()=>$this->service(1,43)->search(100,$id),fn()=>$this->service()->search(200,$id),fn()=>$this->service(3)->search(100,$id),fn()=>$this->service(5)->search(100,$id),fn()=>$this->service(0)->search(100,$id),fn()=>$this->service(2)->search(100,$id)] as $action) $this->denied($action);
        $this->assertCount(0,$this->transport->calls);
    }
    public function testMissingConfirmationAndDuplicateIdentityDoNotCreateBindings(): void
    {
        $this->denied(fn()=>$this->service()->bind(100,'cloud','P-100','TEST-HOSPITAL',0,false));
        $this->assertSame(0,$this->db->table('pacs_patient_bindings')->countAllResults());
        $this->bind();
        $this->denied(fn()=>$this->service(5)->bind(200,'cloud','P-100','TEST-HOSPITAL',0,true));
        $this->assertSame(1,$this->db->table('pacs_patient_bindings')->countAllResults());
    }
    public function testRevocationBlocksExistingLinksAndPendingResponses(): void
    {
        $id=$this->bind(); $link=$this->service()->link(100,$id,'1.2.826.0.1.100',1);
        $this->gate->enabled=false; $count=count($this->transport->calls);
        $this->denied(fn()=>$this->service()->details(100,$link));
        $this->assertCount($count,$this->transport->calls);
        $this->gate->enabled=true;
        $this->transport->onGet=function () { $this->gate->enabled=false; };
        $this->denied(fn()=>$this->service()->search(100,$id));
    }
    public function testRebindingInvalidatesOldLinksAndStaleForms(): void
    {
        $id=$this->bind(); $link=$this->service()->link(100,$id,'1.2.826.0.1.100',1);
        $this->service()->bind(100,'cloud','P-NEW','TEST-HOSPITAL',1,true);
        $this->assertSame([],$this->service()->overview(100)['links']);
        $this->denied(fn()=>$this->service()->viewer(100,$link));
        $this->denied(fn()=>$this->service()->bind(100,'cloud','P-OLD','TEST-HOSPITAL',1,true));
        $this->denied(fn()=>$this->service()->link(100,$id,'1.2.826.0.1.100',1));
    }
    public function testConcurrentIdentityChangeCannotLinkReturnedStudy(): void
    {
        $id=$this->bind();
        $this->transport->onGet=function () { $this->transport->onGet=null; $this->service()->bind(100,'cloud','P-NEW','TEST-HOSPITAL',1,true); };
        $this->denied(fn()=>$this->service()->link(100,$id,'1.2.826.0.1.100',1));
        $this->assertSame(0,$this->db->table('pacs_study_links')->countAllResults());
    }
    public function testSharingConsentAndRevocationApplyToOtherCareTeamMembers(): void
    {
        $id=$this->bind(); $link=$this->service()->link(100,$id,'1.2.826.0.1.100',1);
        $this->assertSame([],$this->service(2)->overview(100)['links']);
        $this->db->table('clinical_consents')->insert(['id'=>1,'id_client'=>100,'kind'=>'dossier','decision'=>'granted','evidence_object_id'=>'synthetic-proof']);
        $this->assertCount(1,$this->service(2)->overview(100)['links']);
        $this->assertCount(1,$this->service(4)->overview(100)['links']);
        $this->denied(fn()=>$this->service(4)->bind(100,'cloud','P-NEW','TEST-HOSPITAL',1,true));
        $this->db->table('clinical_consents')->insert(['id'=>2,'id_client'=>100,'kind'=>'dossier','decision'=>'revoked','evidence_object_id'=>'synthetic-proof']);
        $this->assertSame([],$this->service(2)->overview(100)['links']);
        $this->denied(fn()=>$this->service(2)->viewer(100,$link));
    }
    public function testUnbindAndUnlinkDoNotDeletePacsObjectsOrAudit(): void
    {
        $id=$this->bind(); $link=$this->service()->link(100,$id,'1.2.826.0.1.100',1);
        $count=count($this->transport->calls);
        $this->service()->unlink(100,$link); $this->service()->unbind(100,$id,1);
        $this->assertCount($count,$this->transport->calls);
        $this->assertSame(1,$this->db->table('pacs_study_links')->countAllResults());
        $this->assertSame([],$this->service()->overview(100)['links']);
        $this->denied(fn()=>$this->service()->search(100,$id));
        $this->assertGreaterThan(2,$this->db->table('pacs_audit')->countAllResults());
    }
    public function testAuditFailureStopsRemoteAccess(): void
    {
        $id=$this->bind();
        $this->db->query("CREATE TRIGGER reject_audit BEFORE INSERT ON pacs_audit BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->denied(fn()=>$this->service()->search(100,$id));
        $this->assertCount(0,$this->transport->calls);
    }
    public function testChangedArchiveRequiresNewIdentityConfirmation(): void
    {
        $id=$this->bind();
        $profile=MemoryPacsTransport::profile(); $profile['qido_url']='https://other.example.test/dicom-web';
        $s=new PacsService($this->db,42,1,new PacsProfiles(['tenants'=>['42'=>[$profile]]]),$this->gate,$this->transport);
        $this->denied(fn()=>$s->search(100,$id));
        $this->assertCount(0,$this->transport->calls);
        $s->bind(100,'cloud','P-100','TEST-HOSPITAL',1,true);
        $this->assertCount(1,$s->search(100,$id)['studies']);
    }
    public function testDuplicateAndReconfirmedLinksAreIdempotent(): void
    {
        $id=$this->bind(); $link=$this->service()->link(100,$id,'1.2.826.0.1.100',1);
        $this->assertSame($link,$this->service()->link(100,$id,'1.2.826.0.1.100',1));
        $this->service()->unlink(100,$link);
        $this->assertSame($link,$this->service()->link(100,$id,'1.2.826.0.1.100',1));
        $this->assertSame(1,$this->db->table('pacs_study_links')->countAllResults());
        $this->assertCount(1,$this->service()->overview(100)['links']);
    }
    public function testPacsPageEscapesRemoteNamesAndSecretsAreAbsent(): void
    {
        helper(['form','url']);
        $id=$this->bind();
        $overview=$this->service()->overview(100);
        $search=['studies'=>[['description'=>'<script>attack</script>','patient_name'=>'Synthetic','birth_date'=>'19800101','date'=>'20260913','modalities'=>'OT','accession'=>'S1','uid'=>'1.2.3']],'binding'=>$overview['bindings'][0],'page'=>1,'more'=>false];
        $html=view('clinical/pacs',['patientId'=>100,'patient'=>['patient_name'=>'Synthetic'],'tenant'=>[],'overview'=>$overview,'search'=>$search]);
        $this->assertStringContainsString('&lt;script&gt;attack&lt;/script&gt;',$html);
        $this->assertStringNotContainsString('<script>attack</script>',$html);
        $this->assertStringNotContainsString('https://pacs.example.test',$html);
        $this->assertStringContainsString('name="csrf_test_name"',$html);
    }
}
