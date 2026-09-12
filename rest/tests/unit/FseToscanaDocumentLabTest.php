<?php
namespace Tests\Unit;

use App\Services\{FseArtifactValidationService, FseSecretsService, FseSyntheticAppBoundary, FseTenantDatabaseContextService, FseToscanaDocumentLab, FseToscanaLabStore, FseToscanaWorkflow};
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseToscanaDocumentLabTest extends CIUnitTestCase
{
    private function proof(int $id=1, int $previous=0, int $version=1): array
    {
        return ['document_id'=>$id, 'previous_id'=>$previous, 'version'=>$version, 'profile_sha256'=>str_repeat('a',64),
            'set_sha256'=>str_repeat('b',64), 'cda_sha256'=>hash('sha256','CDA'.$id), 'signed_pdf_sha256'=>hash('sha256','PDF'.$id)];
    }
    private function step(array $state, string $action, string $id='SIM.1'): array
    {
        $doc=$state['documents'][$id]; $op=$state['operations'][$doc['pending'] ?? ''] ?? [];
        return (new FseToscanaWorkflow())->apply($state,42,9,$action,$id,$doc['revision'],$doc['profile'],$op['id'] ?? '',$op['workflow'] ?? '');
    }
    public function testImportUsesVerifiedHashAndNeverInventsASecondSourceVersion(): void
    {
        $engine=new FseToscanaWorkflow(); $proof=$this->proof();
        $state=$engine->importSignedSnapshot($engine->initial(42),42,9,$proof);
        $this->assertSame($proof['signed_pdf_sha256'],$state['documents']['SIM.1']['sealed_hash']);
        foreach (['begin_create','accepted','confirm_ok'] as $step) $state=$this->step($state,$step);
        $this->assertSame($state,$engine->importSignedSnapshot($state,42,9,$proof));
        $this->expectExceptionMessage('LAB_SOURCE_REVISION'); $this->step($state,'revise');
    }
    public function testActualSourceRevisionCanBeRehearsedWithoutChangingItsOriginalSnapshot(): void
    {
        $engine=new FseToscanaWorkflow();
        $state=$engine->importSignedSnapshot($engine->initial(42),42,9,$this->proof());
        foreach (['begin_create','accepted','confirm_ok'] as $step) $state=$this->step($state,$step);
        $state=$engine->importSignedSnapshot($state,42,9,$this->proof(2,1,2));
        $original=$state['documents']['SIM.1']['source_snapshot'];
        foreach (['begin_replace','accepted','confirm_ok'] as $step) $state=$this->step($state,$step,'SIM.2');
        $this->assertSame('superseded',$state['documents']['SIM.1']['state']);
        $this->assertSame('published',$state['documents']['SIM.2']['state']);
        $this->assertSame($original,$state['documents']['SIM.1']['source_snapshot']);
        $this->assertSame(0,$state['network_calls']); $this->assertFalse($state['official_accreditation_evidence']);
    }
    public function testReimportNeverResetsAnUncertainOperation(): void
    {
        $engine=new FseToscanaWorkflow(); $proof=$this->proof();
        $state=$engine->importSignedSnapshot($engine->initial(42),42,9,$proof);
        foreach (['begin_create','timeout'] as $step) $state=$this->step($state,$step);
        $this->assertSame($state,$engine->importSignedSnapshot($state,42,9,$proof));
        $this->expectExceptionMessage('LAB_SOURCE_CHANGED');
        $engine->importSignedSnapshot($state,42,9,array_replace($proof,['signed_pdf_sha256'=>str_repeat('c',64)]));
    }
    public function testFirstSnapshotDoesNotAttachToAnUnrelatedInventedLabDocument(): void
    {
        $engine=new FseToscanaWorkflow();
        $state=$engine->apply($engine->initial(42),42,9,'new','',0);
        $original=$state['documents']['SIM.1'];
        $state=$engine->importSignedSnapshot($state,42,9,$this->proof());
        $this->assertNull($state['documents']['SIM.2']['previous']);
        $this->assertSame($original,$state['documents']['SIM.1']);
        $state=$this->step($state,'begin_create','SIM.2');
        $this->assertSame('pending',$state['documents']['SIM.2']['state']);
    }
    public static function invalidChildren(): array
    {
        return [['profile_sha256',str_repeat('c',64)],['set_sha256',str_repeat('c',64)],['version',3],['previous_id',99]];
    }
    #[DataProvider('invalidChildren')]
    public function testRevisionCannotChangeParentProfileSetOrVersion(string $key, mixed $value): void
    {
        $engine=new FseToscanaWorkflow(); $state=$engine->importSignedSnapshot($engine->initial(42),42,9,$this->proof());
        foreach (['begin_create','accepted','confirm_ok'] as $step) $state=$this->step($state,$step);
        $this->expectExceptionMessage('LAB_PARENT');
        $engine->importSignedSnapshot($state,42,9,array_replace($this->proof(2,1,2),[$key=>$value]));
    }
    public function testProofRejectsClinicalOrArbitraryExtraFields(): void
    {
        $engine=new FseToscanaWorkflow(); $this->expectExceptionMessage('LAB_SOURCE');
        $engine->importSignedSnapshot($engine->initial(42),42,9,$this->proof()+['patient_cf'=>'PRIVATE']);
    }
    public function testGenericCommandCannotForgeAnImport(): void
    {
        $engine=new FseToscanaWorkflow(); $state=$engine->apply($engine->initial(42),42,9,'new','',0);
        $this->expectExceptionMessage('LAB_COMMAND');
        $engine->apply($state,42,9,'import_signed_snapshot','SIM.1',0);
    }
    public function testOrdinaryRuntimeCannotEvenResolveTenantDatabase(): void
    {
        $boundary=new FseSyntheticAppBoundary(); $this->assertFalse($boundary->isActive());
        $contexts=$this->createMock(FseTenantDatabaseContextService::class);
        $contexts->expects($this->never())->method('resolveTenantContext');
        $service=new FseToscanaDocumentLab($boundary,$contexts);
        $this->expectExceptionMessage('LAB_BOUNDARY'); $service->import(42,1,9);
    }
    public static function sourceOutcomes(): array
    {
        return [['ok'],['draft'],['production'],['wrong_profile'],['tampered'],['changed_during_validation']];
    }
    #[DataProvider('sourceOutcomes')]
    public function testBridgeValidatesArtifactsAndNeverWritesSourceDatabase(string $outcome): void
    {
        $db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $root=rtrim(WRITEPATH,'/\\').'/fse-bridge-tests-'.bin2hex(random_bytes(8));
        $store=new FseToscanaLabStore($root);
        try {
            $db->query('CREATE TABLE fse_documents (id_fse_document INTEGER PRIMARY KEY, id_fse_profile INTEGER, local_state TEXT, profile_snapshot_json TEXT, document_oid_root TEXT, set_id TEXT, version_number INTEGER, previous_document_id INTEGER, cda_sha256 TEXT, signed_pdf_sha256 TEXT, author_cf_enc TEXT, report_text_enc TEXT)');
            $doc=['id_fse_document'=>1,'id_fse_profile'=>7,'local_state'=>$outcome==='draft' ? 'draft' : 'signed',
                'profile_snapshot_json'=>json_encode(['id_fse_profile'=>$outcome==='wrong_profile' ? 8 : 7,'access_mode'=>'toscana_privati','environment'=>$outcome==='production' ? 'production' : 'test']),
                'document_oid_root'=>'1.2.3','set_id'=>'SYNTHETIC_SET','version_number'=>1,'previous_document_id'=>null,
                'cda_sha256'=>str_repeat('a',64),'signed_pdf_sha256'=>str_repeat('b',64),
                'author_cf_enc'=>'PRIVATE_AUTHOR','report_text_enc'=>'PRIVATE_CLINICAL'];
            $db->table('fse_documents')->insert($doc);
            $before=$db->table('fse_documents')->get()->getResultArray();
            $boundary=$this->createMock(FseSyntheticAppBoundary::class);
            $boundary->method('isActive')->willReturn(true);
            $boundary->expects($this->once())->method('assertDatabase')->with(42,$db);
            $contexts=$this->createMock(FseTenantDatabaseContextService::class);
            $contexts->expects($this->once())->method('resolveTenantContext')->with(42)
                ->willReturn(['db'=>$db,'documents'=>new \App\Models\FseDocumentModel($db)]);
            $secrets=$this->createMock(FseSecretsService::class); $secrets->method('decrypt')->willReturn('TEST_ONLY');
            $validation=$this->createMock(FseArtifactValidationService::class);
            $willValidate=in_array($outcome,['ok','tampered','changed_during_validation'],true);
            $validation->expects($willValidate ? $this->once() : $this->never())->method('assertForDispatch')
                ->with(42,1,$this->callback(fn($row)=>$row['author_cf']==='TEST_ONLY'),true)
                ->willReturnCallback(static function() use ($outcome,$db) {
                    if ($outcome==='tampered') throw new \RuntimeException('PRIVATE_VALIDATOR_DETAIL');
                    if ($outcome==='changed_during_validation') $db->table('fse_documents')->where('id_fse_document',1)->update(['signed_pdf_sha256'=>str_repeat('c',64)]);
                    return ['ok'=>true];
                });
            $service=new FseToscanaDocumentLab($boundary,$contexts,$validation,$store,$secrets);
            $error=null;
            try { $state=$service->import(42,1,9); } catch (\RuntimeException $e) { $error=$e; }
            if ($outcome==='ok') {
                $this->assertNull($error); $this->assertCount(1,$state['documents']);
                $this->assertSame(str_repeat('b',64),$state['documents']['SIM.1']['sealed_hash']);
                $this->assertStringNotContainsString('PRIVATE',json_encode($state));
            } else { $this->assertNotNull($error); $this->assertCount(0,$store->read(42)['documents']); }
            if ($outcome!=='changed_during_validation') $this->assertSame($before,$db->table('fse_documents')->get()->getResultArray());
            $this->assertCount(0,$store->read(43)['documents']);
        } finally {
            $db->close();
            foreach (new \DirectoryIterator($root) as $file) if ($file->isFile() && !$file->isLink()) unlink($file->getPathname());
            rmdir($root);
        }
    }
}
