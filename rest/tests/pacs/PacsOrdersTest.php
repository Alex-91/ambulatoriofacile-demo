<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Services\Pacs\{PacsService,PacsProfiles,PacsOrderService,PacsException,ModalityWorklist};
use App\Services\TenantPatientLookupService;
use CodeIgniter\Test\CIUnitTestCase;

final class PacsOrdersTest extends CIUnitTestCase
{
    protected $db;
    private string $key;
    private MutablePacsGate $gate;
    private MemoryPacsTransport $transport;
    private int $lookupCalls=0;
    private int $changeOnLookup=0;
    private array $patient=['patient_last_name'=>'SINTÈTICO','patient_first_name'=>'PAZIENTE','patient_birth_date'=>'1980-01-01'];
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
        require_once APPPATH.'Database/Migrations/2026-09-13-110001_CreatePacsOrders.php';
        (new \App\Database\Migrations\CreatePacsIntegration(\Config\Database::forge($this->db)))->up();
        (new \App\Database\Migrations\CreatePacsOrders(\Config\Database::forge($this->db)))->up();
        $this->gate=new MutablePacsGate(); $this->transport=new MemoryPacsTransport();
    }
    protected function tearDown(): void
    { $this->db->close(); config(\App\Config\Crypto::class)->keyHex=$this->key; parent::tearDown(); }
    private function pacs(int $user=1,int $tenant=42): PacsService
    { return new PacsService($this->db,$tenant,$user,new PacsProfiles(['tenants'=>['42'=>[MemoryPacsTransport::profile()],'43'=>[MemoryPacsTransport::profile()]]]),$this->gate,$this->transport); }
    private function service(int $user=1,int $tenant=42): PacsOrderService
    {
        $patients=$this->getMockBuilder(TenantPatientLookupService::class)->disableOriginalConstructor()->onlyMethods(['getPatientByIdForTenant'])->getMock();
        $patients->method('getPatientByIdForTenant')->willReturnCallback(function ($t,$p) use ($tenant) {
            if (++$this->lookupCalls===$this->changeOnLookup) $this->patient['patient_first_name']='CAMBIATO DURANTE EXPORT';
            $this->assertSame($tenant,$t); $this->assertSame(100,$p); return $this->patient;
        });
        return new PacsOrderService($this->db,$tenant,$user,$this->pacs($user,$tenant),$this->gate,$patients);
    }
    private function input(): array
    { return ['description'=>'TC sintetica','procedure_code'=>'LAB-CT','coding_scheme'=>'99AFLAB','modality'=>'CT','station_ae'=>'FINDSCU','scheduled_at'=>'2026-09-20T10:30','reason'=>'Nota interna riservata']; }
    private function create(): array
    {
        $binding=$this->pacs()->bind(100,'cloud','P-100','TEST-HOSPITAL',0,true);
        return [$this->service()->create(100,$binding,$this->input(),bin2hex(random_bytes(16))),$binding];
    }
    private function denied(callable $action): void
    { $caught=false; try { $action(); } catch (\RuntimeException) { $caught=true; } $this->assertTrue($caught,'Expected denial'); }
    public function testIdempotencyEncryptionStableIdentifiersAndApproval(): void
    {
        $binding=$this->pacs()->bind(100,'cloud','P-100','TEST-HOSPITAL',0,true);
        $s=$this->service(); $key=bin2hex(random_bytes(16)); $input=$this->input();
        $id=$s->create(100,$binding,$input,$key);
        $this->assertSame($id,$s->create(100,$binding,$input,$key));
        $this->denied(fn()=>$s->create(100,$binding,['description'=>'Diversa']+$input,$key));
        $this->assertSame(1,$this->db->table('pacs_orders')->countAllResults());
        $raw=$this->db->table('pacs_orders')->get()->getRowArray();
        $this->assertStringNotContainsString('TC sintetica',$raw['payload_enc']);
        $this->assertStringNotContainsString('SINTÈTICO',$raw['payload_enc']);
        $this->assertMatchesRegularExpression('/^AF[A-F0-9]{14}$/',$raw['accession']);
        $this->assertLessThanOrEqual(64,strlen($raw['study_uid']));
        $this->denied(fn()=>$s->export(100,$id,1,'dicom'));
        $this->denied(fn()=>$s->approve(100,$id,1,false));
        $s->update(100,$id,['description'=>'TC aggiornata']+$input,1);
        $this->denied(fn()=>$s->approve(100,$id,1,true));
        $s->approve(100,$id,2,true);
        $this->denied(fn()=>$s->update(100,$id,$input,3));
        $row=$s->read(100,$id);
        $this->assertSame('ready',$row['state']); $this->assertSame(3,(int)$row['revision']);
        $this->assertSame($raw['accession'],$row['accession']); $this->assertSame($raw['study_uid'],$row['study_uid']);
        $this->assertCount(0,$this->transport->calls);
    }
    public function testExportStandardDatasetAndCancelStaleForms(): void
    {
        [$id]=$this->create(); $s=$this->service(); $s->approve(100,$id,1,true);
        $json=$s->export(100,$id,2,'json'); $data=json_decode($json['bytes'],true,32,JSON_THROW_ON_ERROR);
        $this->assertSame('P-100',$data['00100020']['Value'][0]);
        $this->assertSame('TEST-HOSPITAL',$data['00100021']['Value'][0]);
        $this->assertSame('SINTÈTICO^PAZIENTE',$data['00100010']['Value'][0]['Alphabetic']);
        $this->assertSame('19800101',$data['00100030']['Value'][0]);
        $step=$data['00400100']['Value'][0];
        $this->assertSame('CT',$step['00080060']['Value'][0]);
        $this->assertSame('FINDSCU',$step['00400001']['Value'][0]);
        $this->assertSame('20260920',$step['00400002']['Value'][0]);
        $this->assertSame('103000',$step['00400003']['Value'][0]);
        $this->assertSame('+0200',$data['00080201']['Value'][0]);
        $this->assertStringNotContainsString('Nota interna',$json['bytes']);
        $this->denied(fn()=>$s->cancel(100,$id,2));
        $file=$s->export(100,$id,3,'dicom');
        $this->assertSame('DICM',substr($file['bytes'],128,4));
        $this->assertStringContainsString('SINTÈTICO^PAZIENTE',$file['bytes']);
        $this->assertSame('ready',$s->read(100,$id)['state']);
        $this->assertSame(hash('sha256',$file['bytes']),$s->read(100,$id)['last_export_sha256']);
        $s->cancel(100,$id,4);
        $this->denied(fn()=>$s->export(100,$id,5,'dicom'));
        $this->denied(fn()=>$s->update(100,$id,$this->input(),5));
        $this->assertSame('cancelled',$s->read(100,$id)['state']);
        $this->assertSame(1,$this->db->table('pacs_orders')->countAllResults());
    }
    public function testTenantPatientRolesConsentAndRevocation(): void
    {
        [$id,$binding]=$this->create();
        foreach ([[1,43,100],[1,42,200],[3,42,100],[5,42,100],[2,42,100],[4,42,100]] as [$user,$tenant,$patient]) $this->denied(fn()=>$this->service($user,$tenant)->read($patient,$id));
        $this->db->table('clinical_consents')->insert(['id'=>1,'id_client'=>100,'kind'=>'dossier','decision'=>'granted','evidence_object_id'=>'proof']);
        foreach ([2,4] as $user) {
            $this->assertSame($id,$this->service($user)->read(100,$id)['id']);
            $this->denied(fn()=>$this->service($user)->approve(100,$id,1,true));
            $this->denied(fn()=>$this->service($user)->cancel(100,$id,1));
            $this->denied(fn()=>$this->service($user)->export(100,$id,1,'dicom'));
        }
        $this->db->table('clinical_consents')->insert(['id'=>2,'id_client'=>100,'kind'=>'dossier','decision'=>'revoked','evidence_object_id'=>'proof']);
        $this->assertSame([],$this->service(2)->listing(100)['rows']);
        $this->service()->approve(100,$id,1,true);
        $this->gate->enabled=false;
        $this->denied(fn()=>$this->service()->listing(100));
        $this->denied(fn()=>$this->service()->export(100,$id,2,'dicom'));
        $this->gate->enabled=true;
        $this->pacs()->unbind(100,$binding,1);
        $this->denied(fn()=>$this->service()->export(100,$id,2,'dicom'));
        $this->service()->cancel(100,$id,2);
        $this->assertSame('cancelled',$this->service()->read(100,$id)['state']);
    }
    public function testCanonicalDemographicsCannotBeSpoofedAndChangesBlockApprovalAndExport(): void
    {
        [$id]=$this->create(); $s=$this->service();
        $this->patient['patient_first_name']='CORRETTO';
        $this->denied(fn()=>$s->approve(100,$id,1,true));
        $s->update(100,$id,['patient_first_name'=>'SPOOFED','patient_name'=>'SPOOFED']+$this->input(),1);
        $this->assertSame('SINTÈTICO^CORRETTO',$s->read(100,$id)['payload']['patient_name']);
        $s->approve(100,$id,2,true);
        $this->patient['patient_birth_date']='1981-01-01';
        $this->denied(fn()=>$s->export(100,$id,3,'dicom'));
        $this->assertNull($s->read(100,$id)['last_exported_at']);
    }
    public function testRebindingAndAuditFailureFailClosed(): void
    {
        [$id,$binding]=$this->create(); $s=$this->service(); $s->approve(100,$id,1,true);
        $this->pacs()->bind(100,'cloud','NEW','TEST-HOSPITAL',1,true);
        $this->denied(fn()=>$s->export(100,$id,2,'dicom'));
        $s->cancel(100,$id,2);
        $id2=$s->create(100,$binding,$this->input(),bin2hex(random_bytes(16)));
        $s->approve(100,$id2,1,true);
        $this->db->query("CREATE TRIGGER reject_order_audit BEFORE INSERT ON pacs_audit BEGIN SELECT RAISE(ABORT, 'synthetic'); END");
        $this->denied(fn()=>$s->export(100,$id2,2,'dicom'));
        $raw=$this->db->table('pacs_orders')->where('id',$id2)->get()->getRowArray();
        $this->assertSame(2,(int)$raw['revision']); $this->assertNull($raw['last_exported_at']);
        $this->denied(fn()=>$s->cancel(100,$id2,2));
        $this->assertSame('ready',$this->db->table('pacs_orders')->where('id',$id2)->get()->getRowArray()['state']);
    }
    public function testDemographicsChangingBetweenValidationAndCommitBlockExport(): void
    {
        [$id]=$this->create(); $s=$this->service(); $s->approve(100,$id,1,true);
        $this->lookupCalls=0; $this->changeOnLookup=2;
        $this->denied(fn()=>$s->export(100,$id,2,'dicom'));
        $row=$this->db->table('pacs_orders')->where('id',$id)->get()->getRowArray();
        $this->assertSame(2,(int)$row['revision']); $this->assertNull($row['last_exported_at']);
    }
    public function testValidationRejectsDstAmbiguityOverflowInvalidAeAndMissingDemographics(): void
    {
        foreach (['0000-09-20T10:00','2026-03-29T02:30','2026-10-25T02:30','2026-02-30T10:00','2026-09-20T10:30:00'] as $value) $this->denied(fn()=>ModalityWorklist::schedule($value));
        $this->assertSame('+01:00',ModalityWorklist::schedule('2026-10-25T03:30')->format('P'));
        foreach ([
            ['description'=>['nested']],['station_ae'=>'INVALID\\AE'],['modality'=>'UNKNOWN'],['procedure_code'=>str_repeat('A',17)],
            ['description'=>"bad\nvalue"],['coding_scheme'=>''],['reason'=>str_repeat('x',1001)]
        ] as $bad) $this->denied(fn()=>ModalityWorklist::payload($bad+$this->input(),$this->patient));
        $this->denied(fn()=>ModalityWorklist::payload($this->input(),['patient_last_name'=>'X']));
        $this->assertSame('2.25.340282366920938463463374607431768211455',ModalityWorklist::uid(str_repeat('f',32)));
        $this->assertSame('2.25.0',ModalityWorklist::uid(str_repeat('0',32)));
        $this->assertSame('2026-09-20 08:30:00',ModalityWorklist::payload($this->input(),$this->patient)['scheduled_utc']);
    }
    public function testMigrationIsIdempotentAndReadIsPaginated(): void
    {
        [$id,$binding]=$this->create(); $s=$this->service();
        (new \App\Database\Migrations\CreatePacsOrders(\Config\Database::forge($this->db)))->up();
        for ($i=0;$i<26;$i++) $s->create(100,$binding,$this->input(),bin2hex(random_bytes(16)));
        $a=$s->listing(100); $b=$s->listing(100,2);
        $this->assertCount(25,$a['rows']); $this->assertTrue($a['more']);
        $this->assertCount(2,$b['rows']); $this->assertFalse($b['more']);
        $this->assertSame([],array_intersect(array_column($a['rows'],'id'),array_column($b['rows'],'id')));
    }
    #[\PHPUnit\Framework\Attributes\Group('pacs_lab')]
    public function testGenerateExplicitSyntheticWorklistForIndependentScu(): void
    {
        $root=getenv('PACS_SYNTHETIC_LAB');
        if (!$root) $this->markTestSkipped('Explicit synthetic worklist laboratory not requested.');
        $m=json_decode(file_get_contents($root.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
        $this->assertSame('ambulatoriofacile-pacs-synthetic-v1',$m['marker']);
        [$id]=$this->create(); $s=$this->service(); $s->approve(100,$id,1,true);
        $file=$s->export(100,$id,2,'dicom');
        $this->assertNotFalse(file_put_contents($root.'/worklists/af-synthetic.wl',$file['bytes']));
        $row=$s->read(100,$id);
        file_put_contents($root.'/worklist-expected.json',json_encode(['accession'=>$row['accession'],'study_uid'=>$row['study_uid'],'patient_id'=>'P-100','issuer'=>'TEST-HOSPITAL','patient_name'=>'SINTÈTICO^PAZIENTE'],JSON_THROW_ON_ERROR));
    }
}
