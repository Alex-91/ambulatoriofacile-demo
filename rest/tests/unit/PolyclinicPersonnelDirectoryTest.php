<?php
namespace Tests\Unit;
use App\Services\{PolyclinicPersonnelDirectory,PolyclinicAdministrationService};
use CodeIgniter\Test\CIUnitTestCase;
final class PolyclinicPersonnelDirectoryTest extends CIUnitTestCase
{
    protected $db; private $directory; private $clinic;
    protected function setUp(): void {
        parent::setUp();$this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        require_once APPPATH.'Database/Polyclinic/CreatePolyclinicAdministration.php';
        (new \App\Database\Polyclinic\CreatePolyclinicAdministration(\Config\Database::forge($this->db)))->up();
        $this->db->query('CREATE TABLE dap03_personale (id_personale INTEGER PRIMARY KEY,nome TEXT,cognome TEXT,tipo INTEGER,is_active INTEGER,legacy_id_dot INTEGER,show_in_agenda INTEGER)');
        $this->db->table('dap03_personale')->insertBatch([
            ['id_personale'=>1,'nome'=>'Anna','cognome'=>'Test','tipo'=>1,'is_active'=>1,'legacy_id_dot'=>101,'show_in_agenda'=>1],
            ['id_personale'=>2,'nome'=>'Anna','cognome'=>'Test','tipo'=>2,'is_active'=>1,'legacy_id_dot'=>102,'show_in_agenda'=>0],
            ['id_personale'=>3,'nome'=>'Sara','cognome'=>'Staff','tipo'=>3,'is_active'=>1,'legacy_id_dot'=>0,'show_in_agenda'=>1],
        ]);
        $this->db->dataCache=[];
        $this->directory=new PolyclinicPersonnelDirectory($this->db);
        $this->clinic=new PolyclinicAdministrationService($this->db,7,static fn($id)=>['id_client'=>$id,'patient_first_name'=>'Paziente','patient_last_name'=>'Sintetico','patient_tax_code'=>'TEST']);
    }
    protected function tearDown(): void {$this->db->close();parent::tearDown();}
    private function oldDoctor(array $data=[]): int {return $this->clinic->saveCatalog(['kind'=>'doctor','code'=>'OLD-'.random_int(1,99999),'name'=>'Anna Test','active'=>1]+$data);}
    private function rejects(callable $fn): void {try {$fn();$this->fail('Expected rejection');}catch (\DomainException $e){$this->assertNotEmpty($e->getMessage());}}
    public function testProjectionIsReadOnlyAndNeverMatchesNames(): void {
        $this->oldDoctor();$before=$this->db->table('pc_catalog')->get()->getResultArray();$rows=$this->clinic->catalog()['doctor'];
        $this->assertCount(3,$rows);$this->assertContains('staff:1',array_column($rows,'id'));$this->assertContains('staff:2',array_column($rows,'id'));$this->assertNotContains('staff:3',array_column($rows,'id'));
        $this->assertSame($before,$this->db->table('pc_catalog')->get()->getResultArray());$this->assertSame(0,$this->directory->profile(1)['catalog_id']);
    }
    public function testUniqueAgendaLinkReusesIdAndRejectsAmbiguity(): void {
        $id=$this->oldDoctor(['agenda_id'=>101]);$before=$this->db->table('pc_catalog')->get()->getResultArray();
        $this->assertSame($id,$this->directory->profile(1)['catalog_id']);$this->assertCount(2,$this->clinic->catalog()['doctor']);$this->assertSame($before,$this->db->table('pc_catalog')->get()->getResultArray());
        $this->db->table('dap03_personale')->where('id_personale',2)->update(['legacy_id_dot'=>101]);$this->assertSame(0,$this->directory->profile(1)['catalog_id']);
    }
    public function testManualLinkPreservesHistoryAndRejectsSecondOwner(): void {
        $id=$this->oldDoctor();$this->db->table('pc_orders')->insert(['doctor_id'=>$id,'snapshot_json'=>'{"doctor":"Nome storico"}','request_key'=>'test-history-order','request_hash'=>'hash']);
        $this->db->table('pc_settlements')->insert(['doctor_id'=>$id,'amount_cents'=>700,'payment_date'=>'2026-09-27','request_key'=>'test-history-settle','request_hash'=>'hash']);
        $before=[$this->db->table('pc_orders')->get()->getResultArray(),$this->db->table('pc_settlements')->get()->getResultArray()];
        $this->directory->saveProfile(1,['professional_enabled'=>1,'professional_specialties'=>'Cardiologia, Medicina sportiva','professional_legacy_id'=>$id]);
        $this->assertSame($id,$this->directory->profile(1)['catalog_id']);$this->assertSame('Cardiologia, Medicina sportiva',$this->directory->profile(1)['specialties']);
        $this->assertSame($before,[$this->db->table('pc_orders')->get()->getResultArray(),$this->db->table('pc_settlements')->get()->getResultArray()]);
        $this->rejects(fn()=>$this->directory->saveProfile(2,['professional_enabled'=>1,'professional_legacy_id'=>$id]));
    }
    public function testProfessionalWithoutAgendaCanBeEnabledAndDisabled(): void {
        $this->directory->saveProfile(3,['professional_enabled'=>1,'professional_specialties'=>'']);$p=$this->directory->profile(3);$this->assertTrue($p['enabled']);$this->assertSame(0,$p['agenda_id']);
        $this->directory->saveProfile(3,['professional_enabled'=>0,'professional_version'=>$p['version']]);$this->assertFalse($this->directory->profile(3)['enabled']);$this->rejects(fn()=>$this->directory->materialize($p['catalog_id']));
    }
    public function testArrivalMaterializesOnceAndOptionalBranchWorks(): void {
        $input=['patient_id'=>10,'doctor_id'=>'staff:1','visit_date'=>'2026-09-27'];$id=$this->clinic->arrive($input+['request_key'=>'test-personnel-arrival-1']);$this->clinic->arrive($input+['request_key'=>'test-personnel-arrival-2']);
        $rows=$this->db->table('pc_encounters')->get()->getResultArray();$this->assertSame($rows[0]['doctor_id'],$rows[1]['doctor_id']);$this->assertSame(1,$this->db->table('pc_catalog')->where('kind','doctor')->countAllResults());
        $service=$this->clinic->saveCatalog(['kind'=>'service','code'=>'TEST','name'=>'Prestazione test','price'=>'50.00','active'=>1,'branch_id'=>0]);
        $this->clinic->saveCatalog(['kind'=>'rule','code'=>'RULE','name'=>'Compenso','doctor_id'=>'staff:1','service_id'=>$service,'mode'=>'percent','value'=>'40','basis'=>'collected','active'=>1]);
        $this->clinic->addOrder(['encounter_id'=>$id,'doctor_id'=>'staff:1','service_id'=>$service,'quantity'=>1,'request_key'=>'test-personnel-order']);
        $order=$this->db->table('pc_orders')->get()->getRowArray();$snap=json_decode($order['snapshot_json'],true);$this->assertSame('Non specificata',$snap['branch']);$this->assertSame('Test Anna',$snap['doctor']);
        $this->db->table('dap03_personale')->where('id_personale',1)->update(['is_active'=>0]);$this->rejects(fn()=>$this->clinic->arrive($input+['request_key'=>'test-personnel-arrival-3']));
    }
    public function testStaleVersionAndMissingPersonnelAreRejected(): void {
        $this->directory->saveProfile(1,['professional_enabled'=>1]);$this->rejects(fn()=>$this->directory->saveProfile(1,['professional_enabled'=>0,'professional_version'=>0]));$this->rejects(fn()=>$this->directory->saveProfile(999,['professional_enabled'=>1]));$this->assertTrue($this->directory->profile(1)['enabled']);
    }
    public function testCatalogSelectionRetainsIdsAndDoesNotCreateSpecialties(): void {
        $a=$this->clinic->saveCatalog(['kind'=>'branch','code'=>'CARD','name'=>'Cardiologia','active'=>1]);
        $b=$this->clinic->saveCatalog(['kind'=>'branch','code'=>'SPORT','name'=>'Medicina dello sport','active'=>1]);
        $input=['professional_specialties_catalog'=>'1','professional_specialty_ids'=>[(string)$a,(string)$b,(string)$a],'professional_enabled'=>1];
        $this->directory->saveProfile(1,$input);
        $p=$this->directory->profile(1);$this->assertSame([$a,$b],$p['specialty_ids']);
        $this->db->table('pc_catalog')->where('id',$a)->update(['name'=>'Cardiologia clinica','active'=>0]);
        $this->directory->saveProfile(1,array_merge($input,['professional_version'=>$p['version']]));
        $p=$this->directory->profile(1);$this->assertSame([$a,$b],$p['specialty_ids']);$this->assertStringContainsString('Cardiologia clinica',$p['specialties']);
        $this->assertCount(2,$this->directory->specialtyOptions());
        $this->rejects(fn()=>$this->directory->saveProfile(2,$input));
        $this->directory->saveProfile(1,['professional_specialties_catalog'=>'1','professional_version'=>$p['version'],'professional_enabled'=>1]);
        $this->assertSame([],$this->directory->profile(1)['specialty_ids']);
    }
    public function testCatalogSelectionRejectsForeignKindsUnknownIdsAndMalformedPayloads(): void {
        $doctor=$this->oldDoctor();
        foreach ([[$doctor],[999999],['abc'],[['id'=>1]],'1',array_fill(0,16,'1')] as $ids) {
            $this->rejects(fn()=>$this->directory->saveProfile(1,['professional_specialties_catalog'=>'1','professional_specialty_ids'=>$ids,'professional_enabled'=>1]));
        }
        $this->assertSame(0,$this->directory->profile(1)['catalog_id']);
    }
}
