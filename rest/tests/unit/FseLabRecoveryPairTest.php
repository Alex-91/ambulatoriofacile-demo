<?php
namespace Tests\Unit;
use CodeIgniter\Test\CIUnitTestCase;

final class FseLabRecoveryPairTest extends CIUnitTestCase
{
    protected function setUp(): void { parent::setUp(); require_once dirname(APPPATH,2).'/ops/fse-validation/lab-recovery-pair.php'; }
    private function pair(int $a=7,int $b=11): array {
        $base=['set_id'=>'SYNTHETIC','profile_snapshot_json'=>'{"synthetic":true}','local_state'=>'signed'];
        return [$base+['id_fse_document'=>$a,'version_number'=>1],$base+['id_fse_document'=>$b,'version_number'=>2,'previous_document_id'=>$a]];
    }
    public function testNonSequentialIdsAndUnrelatedDraftAreAccepted(): void {
        $rows=$this->pair(); $rows[]=['id_fse_document'=>1,'local_state'=>'draft'];
        $this->assertSame([7,11],\fse_lab_revision_pair(array_reverse($rows)));
    }
    public function testAmbiguousChainsAreRefused(): void {
        $this->expectExceptionMessage('unambiguous'); \fse_lab_revision_pair([...$this->pair(),...$this->pair(21,23)]);
    }
    public function testBrokenProfileBindingIsRefused(): void {
        $rows=$this->pair(); $rows[1]['profile_snapshot_json']='OTHER';
        $this->expectExceptionMessage('Broken'); \fse_lab_revision_pair($rows);
    }
    public function testMissingParentIsRefused(): void {
        $this->expectExceptionMessage('unambiguous'); \fse_lab_revision_pair([$this->pair()[1]]);
    }
    public function testDuplicateIdsAreRefused(): void {
        $rows=$this->pair(); $this->expectExceptionMessage('identity'); \fse_lab_revision_pair([...$rows,$rows[0]]);
    }
}
