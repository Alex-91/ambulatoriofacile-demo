<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
require_once __DIR__.'/../_support/ClinicalMasterPlatformFixture.php';
use App\Services\{ClinicalSetupService,ClinicalFeatureService};
use App\Services\Pacs\{PacsManagedProfiles,PacsProfiles,PacsException};
use CodeIgniter\Test\CIUnitTestCase;

class SetupClinicalGate extends ClinicalFeatureService
{
    public bool $enabled=true;
    public function isEnabledForTenant(int $tenant): bool { return $this->enabled; }
}
final class ClinicalSetupTest extends CIUnitTestCase
{
    protected $db;
    private $platform;
    private string $key;
    private array $env=[];
    private SetupClinicalGate $clinical;
    private MutablePacsGate $pacs;
    private ClinicalSetupService $setup;
    protected function setUp(): void
    {
        parent::setUp();
        $this->key=config(\App\Config\Crypto::class)->keyHex;
        config(\App\Config\Crypto::class)->keyHex=bin2hex(random_bytes(32));
        foreach (['PACS_CONFIG_FILE','PACS_PROFILES_JSON'] as $k) { $this->env[$k]=getenv($k); putenv($k); }
        $this->platform=new \Tests\Support\ClinicalMasterPlatformFixture();
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $this->db->query('CREATE TABLE dap01_users (id_user INTEGER PRIMARY KEY,is_active INTEGER)');
        $this->db->query('CREATE TABLE dap03_personale (id_personale INTEGER PRIMARY KEY,id_user INTEGER,tipo INTEGER,is_active INTEGER)');
        $this->db->query('CREATE TABLE dap02_clients (id_client INTEGER PRIMARY KEY)');
        foreach ([1=>1,6=>4] as $id=>$role) {
            $this->db->table('dap01_users')->insert(['id_user'=>$id,'is_active'=>1]);
            $this->db->table('dap03_personale')->insert(['id_personale'=>$id*10,'id_user'=>$id,'tipo'=>$role,'is_active'=>1]);
        }
        $this->db->table('dap02_clients')->insert(['id_client'=>100]);
        $this->clinical=new SetupClinicalGate(); $this->pacs=new MutablePacsGate();
        $this->setup=new ClinicalSetupService($this->db,42,$this->clinical,$this->pacs);
    }
    protected function tearDown(): void
    {
        $this->db->close(); $this->platform->close(); config(\App\Config\Crypto::class)->keyHex=$this->key;
        foreach ($this->env as $k=>$v) putenv($v===false ? $k : "$k=$v");
        parent::tearDown();
    }
    private function store(int $tenant=42): PacsManagedProfiles { return new PacsManagedProfiles($this->db,$tenant,$this->pacs); }
    private function form(): array
    { return ['id'=>'managed','label'=>'PACS sintetico','qido_url'=>'https://pacs.example.test/dicom-web','wado_url'=>'https://pacs.example.test/dicom-web','auth'=>'basic','username'=>'synthetic-user','password'=>'synthetic-secret','enabled'=>'1','download_enabled'=>'1','revision'=>0]; }
    private function denied(callable $call): void
    { try { $call(); } catch (\RuntimeException) { $this->addToAssertionCount(1); return; } $this->fail('Operation unexpectedly accepted'); }
    public function testPreparationAndAcceptanceAreIdempotentAndCreateNoClinicalData(): void
    {
        $this->assertFalse($this->setup->inspect()['ready']);
        $this->setup->initialize(6); $this->setup->initialize(6);
        $report=$this->setup->acceptance();
        $this->assertTrue($report['ready']); $this->assertTrue($report['storage_tested']);
        $this->assertSame('not_assessed',$report['external_validation']);
        $this->assertSame(1,$this->db->table('dap02_clients')->countAllResults());
        $this->assertSame(2,$this->db->table('dap01_users')->countAllResults());
        foreach (['clinical_entries','clinical_consents','pacs_orders'] as $table) $this->assertSame(0,$this->db->table($table)->countAllResults());
        $this->assertSame([],glob(WRITEPATH.'clinical-private/42/.setup-*'));
    }
    public function testOnlyActualMasterOfEntitledSpaceCanPrepare(): void
    {
        $this->denied(fn()=>$this->setup->initialize(1));
        $this->denied(fn()=>(new ClinicalSetupService($this->db,43,$this->clinical,$this->pacs))->initialize(6));
        $this->clinical->enabled=false;
        $this->denied(fn()=>$this->setup->initialize(6));
        $this->assertFalse($this->db->tableExists('clinical_entries'));
    }
    public function testPartialExistingClinicalSchemaIsNotReportedReady(): void
    {
        $this->setup->initialize(6);
        $this->db->query('ALTER TABLE clinical_entries DROP COLUMN signature_evidence_json');
        unset($this->db->dataCache['field_names']['clinical_entries']);
        $report=$this->setup->inspect();
        $this->assertFalse(array_column($report['checks'],'ok','id')['clinical_schema']);
        $this->assertFalse($report['ready']);
    }
    public function testClinicalOnlySpaceDoesNotInitializeDiagnosticWorkflow(): void
    {
        $this->pacs->enabled=false; $this->setup->initialize(6);
        $this->assertTrue($this->setup->acceptance()['ready']);
        $this->assertFalse($this->db->tableExists('pacs_orders'));
        $this->denied(fn()=>$this->store()->save(6,$this->form()));
    }
    public function testEncryptedProfilesReloadAndSecretsNeverReturnToForms(): void
    {
        $this->setup->initialize(6); $this->store()->save(6,$this->form());
        $cipher=$this->db->table('pacs_managed_profiles')->get()->getRowArray()['config_enc'];
        $this->assertStringNotContainsString('synthetic-secret',$cipher);
        $editable=$this->store()->editable()['managed'];
        $this->assertArrayNotHasKey('credentials',$editable); $this->assertTrue($editable['credentials_present']);
        $profile=(new PacsProfiles(null,$this->db))->get(42,'managed');
        $this->assertSame('synthetic-secret',PacsProfiles::credential($profile,'password'));
        $this->assertTrue(PacsProfiles::credentialsReady($profile));
        $this->assertSame([],(new PacsProfiles(null,$this->db))->forTenant(43));
        $this->assertStringNotContainsString('synthetic-secret',json_encode($this->db->table('clinical_setup_audit')->get()->getResultArray()));
    }
    public function testUpdateUsesRevisionAndRequiresNewCredentialsOnDestinationChange(): void
    {
        $this->setup->initialize(6); $form=$this->form(); $this->store()->save(6,$form);
        $next=array_replace($form,['revision'=>1,'username'=>'','password'=>'','label'=>'Updated']);
        $this->store()->save(6,$next);
        $this->denied(fn()=>$this->store()->save(6,$next));
        $this->denied(fn()=>$this->store()->save(6,array_replace($next,['revision'=>2,'qido_url'=>'https://other.example.test/dicom-web'])));
        $this->assertSame('synthetic-secret',PacsProfiles::credential($this->store()->all()['managed'],'password'));
        $this->store()->save(6,array_replace($next,['revision'=>2,'enabled'=>'0']));
        $this->denied(fn()=>(new PacsProfiles(null,$this->db))->get(42,'managed'));
    }
    public function testDoctorDisabledModuleAndServerCollisionCannotSave(): void
    {
        $this->setup->initialize(6);
        $this->denied(fn()=>$this->store()->save(1,$this->form()));
        $this->denied(fn()=>$this->store()->save(6,$this->form(),['managed']));
        $this->pacs->enabled=false; $this->denied(fn()=>$this->store()->save(6,$this->form()));
        $this->assertSame(0,$this->db->table('pacs_managed_profiles')->countAllResults());
    }
    public function testValidationAndEncryptedTenantBindingFailClosed(): void
    {
        $this->setup->initialize(6);
        foreach ([['id'=>'../path'],['qido_url'=>'http://pacs.example.test'],['qido_url'=>'https://user:pass@pacs.example.test'],['auth'=>'other'],['token'=>"bad\r\nheader",'auth'=>'bearer'],['username'=>'a:b']] as $bad) $this->denied(fn()=>$this->store()->save(6,array_replace($this->form(),$bad)));
        $this->store()->save(6,$this->form());
        $this->db->table('pacs_managed_profiles')->where('tenant_id',42)->update(['tenant_id'=>43]);
        $this->denied(fn()=>$this->store(43)->all());
    }
    public function testManagedAndServerProfilesCoexistWithoutOverwritingIdentity(): void
    {
        $this->setup->initialize(6); $this->store()->save(6,$this->form());
        putenv('PACS_PROFILES_JSON='.json_encode(['tenants'=>['42'=>[MemoryPacsTransport::profile()]]]));
        $profiles=new PacsProfiles(null,$this->db);
        $this->assertCount(2,$profiles->forTenant(42));
        $original=PacsProfiles::fingerprint($profiles->get(42,'managed'));
        $this->store()->save(6,array_replace($this->form(),['revision'=>1,'identity_namespace'=>'another-source']));
        $this->assertNotSame($original,PacsProfiles::fingerprint($profiles->get(42,'managed')));
        putenv('PACS_PROFILES_JSON='.json_encode(['tenants'=>['42'=>[array_replace(MemoryPacsTransport::profile(),['id'=>'managed'])]]]));
        $this->denied(fn()=>$profiles->forTenant(42));
    }
}
