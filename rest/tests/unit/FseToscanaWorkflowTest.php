<?php
namespace Tests\Unit;
use App\Services\FseToscanaWorkflow;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseToscanaWorkflowTest extends CIUnitTestCase
{
    private array $state;
    protected function setUp(): void { parent::setUp(); $this->state=FseToscanaWorkflow::initial(42); }
    private function step(string $command,string $id='SIM.1',string $profile='SITE_A_PRIVATE'): void
    {
        $doc=$this->state['documents'][$id] ?? [];
        $op=$this->state['operations'][$doc['pending'] ?? ''] ?? [];
        $this->state=(new FseToscanaWorkflow())->apply($this->state,42,9,$command,$id,
            $command==='new' ? $this->state['revision'] : $doc['revision'],$profile,$op['id'] ?? '',$op['workflow'] ?? '');
    }
    private function published(): void
    {
        foreach (['new','prepare','sign','begin_create','accepted','confirm_ok'] as $command) $this->step($command);
    }
    public function testCreateReceiptIsNotPublicationAndDuplicateBeginFails(): void
    {
        foreach (['new','prepare','sign','begin_create','accepted'] as $command) $this->step($command);
        $this->assertSame('pending',$this->state['documents']['SIM.1']['state']);
        $this->assertSame(1,count($this->state['operations']));
        $this->expectExceptionMessage('LAB_STATE'); $this->step('begin_create');
    }
    public function testReplacementCommitsBothDocumentsAtomicallyAndRetainsOriginalHash(): void
    {
        $this->published(); $hash=$this->state['documents']['SIM.1']['sealed_hash'];
        $this->step('revise');
        foreach (['prepare','sign','begin_replace','accepted'] as $command) $this->step($command,'SIM.2');
        $this->assertSame('published',$this->state['documents']['SIM.1']['state']);
        $this->step('confirm_ok','SIM.2');
        $this->assertSame('superseded',$this->state['documents']['SIM.1']['state']);
        $this->assertSame('published',$this->state['documents']['SIM.2']['state']);
        $this->assertSame($hash,$this->state['documents']['SIM.1']['sealed_hash']);
        $this->assertSame('SIM.2',$this->state['documents']['SIM.1']['superseded_by']);
        $this->assertSame(0,$this->state['network_calls']);
    }
    public function testReplacementCannotBeSentAsNewDocument(): void
    {
        $this->published(); $this->step('revise'); $this->step('prepare','SIM.2'); $this->step('sign','SIM.2');
        $this->expectExceptionMessage('LAB_REPLACE_REQUIRED'); $this->step('begin_create','SIM.2');
    }
    public function testOriginalWithOpenRevisionCannotStartAnotherOperation(): void
    {
        $this->published(); $this->step('revise');
        $this->expectExceptionMessage('LAB_PENDING'); $this->step('begin_delete');
    }
    public function testRejectedReplacementPreservesPublishedOriginal(): void
    {
        $this->published(); $this->step('revise');
        foreach (['prepare','sign','begin_replace','rejected'] as $command) $this->step($command,'SIM.2');
        $this->assertSame('published',$this->state['documents']['SIM.1']['state']);
        $this->assertNull($this->state['documents']['SIM.1']['open_revision']);
        $this->assertSame('rejected',$this->state['documents']['SIM.2']['state']);
    }
    public static function updateOutcomes(): array { return [['metadata',true],['metadata',false],['delete',true],['delete',false]]; }
    #[DataProvider('updateOutcomes')]
    public function testMetadataAndDeleteRespectFinalOutcome(string $kind,bool $success): void
    {
        $this->published(); $hash=$this->state['documents']['SIM.1']['sealed_hash'];
        $this->step('begin_'.$kind); $this->step('accepted');
        $this->assertSame(1,$this->state['documents']['SIM.1']['metadata_version']);
        $this->step($success ? 'confirm_ok' : 'confirm_ko'); $doc=$this->state['documents']['SIM.1'];
        $this->assertSame($kind==='delete' && $success ? 'deleted' : 'published',$doc['state']);
        $this->assertSame($kind==='metadata' && $success ? 2 : 1,$doc['metadata_version']);
        $this->assertSame($hash,$doc['sealed_hash']);
    }
    public function testTimeoutSurvivesSerializationAndRejectsLateReply(): void
    {
        foreach (['new','prepare','sign','begin_create','timeout'] as $command) $this->step($command);
        $this->state=json_decode(json_encode($this->state),true);
        $this->assertSame('pending',$this->state['documents']['SIM.1']['state']);
        $this->expectExceptionMessage('LAB_REPLY_ORDER'); $this->step('accepted');
    }
    public function testWrongWorkflowCannotConfirmPublication(): void
    {
        foreach (['new','prepare','sign','begin_create','accepted'] as $command) $this->step($command);
        $doc=$this->state['documents']['SIM.1'];
        $this->expectExceptionMessage('LAB_CORRELATION');
        (new FseToscanaWorkflow())->apply($this->state,42,9,'confirm_ok','SIM.1',$doc['revision'],$doc['profile'],$doc['pending'],'SIM.OTHER');
    }
    public function testStaleOperatorAndWrongProfileAreBlocked(): void
    {
        $this->step('new'); $this->step('prepare');
        try { (new FseToscanaWorkflow())->apply($this->state,42,10,'sign','SIM.1',0); $this->fail('Stale write'); }
        catch (\RuntimeException $e) { $this->assertSame('LAB_STALE',$e->getMessage()); }
        $this->expectExceptionMessage('LAB_PROFILE'); $this->step('sign','SIM.1','SITE_B_SSR');
    }
    public function testStateFromAnotherTenantIsRejected(): void
    {
        $this->expectExceptionMessage('LAB_SCOPE');
        (new FseToscanaWorkflow())->apply($this->state,43,9,'new','',0);
    }
}
