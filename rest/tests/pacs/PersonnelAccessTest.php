<?php
namespace Tests\Pacs;
use App\Services\{PersonnelAccessService,ClinicalAccessPolicy};
use CodeIgniter\Test\CIUnitTestCase;
require_once __DIR__.'/../_support/ClinicalMasterPlatformFixture.php';

final class PersonnelAccessTest extends CIUnitTestCase
{
    protected $db;
    private $platform;
    protected function setUp(): void
    {
        parent::setUp();
        $this->platform=new \Tests\Support\ClinicalMasterPlatformFixture();
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $this->db->query('CREATE TABLE dap01_users (id_user INTEGER PRIMARY KEY,tipo_user INTEGER,is_active INTEGER)');
        $this->db->query('CREATE TABLE dap03_personale (id_personale INTEGER PRIMARY KEY,id_user INTEGER,tipo INTEGER,is_active INTEGER)');
        foreach ([1=>1,6=>4,7=>4] as $id=>$role) {
            $this->db->table('dap01_users')->insert(['id_user'=>$id,'tipo_user'=>$role===4 ? 1:2,'is_active'=>1]);
            $this->db->table('dap03_personale')->insert(['id_personale'=>$id*10,'id_user'=>$id,'tipo'=>$role,'is_active'=>1]);
        }
        require_once APPPATH.'Database/Migrations/2026-09-14-090001_CreatePersonnelAccessBlocks.php';
        $migration=new \App\Database\Migrations\CreatePersonnelAccessBlocks(\Config\Database::forge($this->db));
        $migration->up(); $migration->up();
    }
    protected function tearDown(): void { $this->db->close(); $this->platform->close(); parent::tearDown(); }
    private function denied(callable $f): void
    { try { $f(); } catch (\RuntimeException) { $this->addToAssertionCount(1); return; } $this->fail('Expected denial'); }
    public function testMasterDisablesPersistentlyWithoutDeletingClinicalRelationships(): void
    {
        $service=new PersonnelAccessService($this->db,42);
        $service->disable(6,10); $service->disable(6,10);
        $this->assertTrue($service->blocked(1));
        $this->assertFalse((new PersonnelAccessService($this->db,43))->blocked(1));
        $this->assertSame(3,$this->db->table('dap03_personale')->countAllResults());
        $this->assertSame(3,$this->db->table('dap01_users')->countAllResults());
        $row=$this->db->table('personnel_access_blocks')->get()->getRowArray();
        $this->assertSame(6,(int)$row['blocked_by']);
        $this->denied(fn()=>PersonnelAccessService::assertLoginAllowed($this->db,1,42));
        $this->denied(fn()=>(new ClinicalAccessPolicy($this->db,1,42))->actor());
        $this->assertSame(1,$this->db->table('personnel_access_blocks')->countAllResults());
    }
    public function testSelfMasterOtherTenantAndDoctorCannotDisable(): void
    {
        $service=new PersonnelAccessService($this->db,42);
        foreach ([[6,60],[6,70],[1,60],[7,10],[6,999]] as [$actor,$target]) $this->denied(fn()=>$service->disable($actor,$target));
        $this->denied(fn()=>(new PersonnelAccessService($this->db,43))->disable(6,10));
        $this->assertSame(0,$this->db->table('personnel_access_blocks')->countAllResults());
    }
    public function testOpenSessionStopsAtNextRequestAndAnonymousIsUnaffected(): void
    {
        $db=$this->db;
        $filter=new class($db) extends \App\Filters\PersonnelAccessFilter {
            public function __construct(private $db) {}
            protected function assertAccount(int $tenantId,int $userId): void { (new PersonnelAccessService($this->db,$tenantId))->assertActive($userId); }
        };
        session()->set(['userId'=>1,'isLoggedInConfirmed'=>true,\App\Services\TenantContextService::SESSION_KEY=>['tenant_id'=>42]]);
        $request=service('request');
        $this->assertNull($filter->before($request));
        (new PersonnelAccessService($this->db,42))->disable(6,10);
        $this->assertNotNull($filter->before($request));
        $this->assertNull(session()->get('isLoggedInConfirmed'));
        $this->assertNull(session()->get('userId'));
        $this->assertNull($filter->before($request));
    }
}
