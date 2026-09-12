<?php
namespace Tests\Unit;
use App\Services\FseSupportBundleService;
use CodeIgniter\Test\CIUnitTestCase;

final class FseSupportBundleTest extends CIUnitTestCase
{
    public function testExportDropsAllClinicalContentSecretsPathsAndArbitraryCorrelationText(): void
    {
        $bundle=FseSupportBundleService::build(['id_fse_document'=>12,'id_fse_profile'=>7,'local_state'=>'validating',
            'gateway_http_status'=>408,'trace_id'=>'0123456789abcdef0123456789abcdef','span_id'=>'PRIVATE_PATIENT',
            'patient_cf'=>'RSSMRA80A01H501U','author_cf'=>'PRIVATE_AUTHOR','report_text'=>'PRIVATE_CLINICAL',
            'cda_path'=>'/PRIVATE/path','profile_snapshot_json'=>'PRIVATE_CONFIG','last_gateway_message'=>'PRIVATE_REMOTE',
            'last_response_json'=>'{"transport":{"outcome_uncertain":true,"x_cart_id":"PRIVATE_SECRET"},"token":"PRIVATE_TOKEN"}',
            'signed_pdf_sha256'=>str_repeat('a',64)],
            [['event_type'=>'gateway_validation','event_level'=>'error','message'=>'PRIVATE_EVENT','context_json'=>'PRIVATE_KEY',
              'created_by'=>998,'created_at'=>'2026-09-09 12:00:00']]);
        $json=json_encode($bundle);
        $this->assertStringNotContainsString('PRIVATE',$json); $this->assertStringNotContainsString('RSSMRA80A01H501U',$json);
        $this->assertSame('0123456789abcdef0123456789abcdef',$bundle['correlation']['trace_id']);
        $this->assertNull($bundle['correlation']['span_id']); $this->assertNull($bundle['correlation']['x_cart_id']);
        $this->assertSame('REMOTE_OUTCOME_UNCERTAIN',$bundle['diagnosis']['code']);
        $this->assertFalse($bundle['automatic_retry']); $this->assertSame(str_repeat('a',64),$bundle['artifacts']['signed_pdf_sha256']);
        $this->assertArrayNotHasKey('message',$bundle['events'][0]);
    }
    public function testRealNationalWorkflowShapeIsPreservedAndUnknownPlaceholderOmitted(): void
    {
        $workflow='2.16.840.1.113883.2.9.2.120.4.4.'.str_repeat('a',64).'.1234567890^^^^urn:ihe:iti:xdw:2013:workflowInstanceId';
        $result=FseSupportBundleService::build(['workflow_instance_id'=>$workflow]);
        $this->assertSame($workflow,$result['correlation']['workflow_id']);
        $result=FseSupportBundleService::build(['workflow_instance_id'=>'UNKNOWN_WORKFLOW_ID','trace_id'=>'RSSMRA80A01H501U']);
        $this->assertNull($result['correlation']['workflow_id']); $this->assertNull($result['correlation']['trace_id']);
    }

    public function testSameLocalIdInTwoTenantDatabasesNeverMixesEvidence(): void
    {
        $databases=[]; $resolved=[];
        try {
            foreach ([42,43] as $tenant) {
                $db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
                $databases[]=$db;
                $db->query('CREATE TABLE fse_documents (id_fse_document INTEGER PRIMARY KEY, local_state TEXT, trace_id TEXT)');
                $db->query('CREATE TABLE fse_document_events (id_fse_event INTEGER PRIMARY KEY, id_fse_document INTEGER, event_type TEXT, event_level TEXT, created_at TEXT)');
                $db->table('fse_documents')->insert(['id_fse_document'=>1,'local_state'=>'draft','trace_id'=>str_repeat($tenant===42 ? 'a' : 'b',32)]);
                $db->table('fse_document_events')->insert(['id_fse_event'=>1,'id_fse_document'=>1,'event_type'=>'prepare','event_level'=>$tenant===42 ? 'info' : 'error']);
                $db->table('fse_document_events')->insert(['id_fse_event'=>2,'id_fse_document'=>2,'event_type'=>'gateway_delete','event_level'=>'warning']);
                $resolved[$tenant]=['documents'=>new \App\Models\FseDocumentModel($db),'events'=>new \App\Models\FseDocumentEventModel($db)];
            }
            $contexts=$this->createMock(\App\Services\FseTenantDatabaseContextService::class);
            $contexts->expects($this->exactly(3))->method('resolveTenantContext')->willReturnCallback(static fn($tenant)=>$resolved[$tenant]);
            $service=new FseSupportBundleService($contexts);
            foreach ([42,43] as $tenant) {
                $bundle=$service->forDocument($tenant,1);
                $this->assertSame(str_repeat($tenant===42 ? 'a' : 'b',32),$bundle['correlation']['trace_id']);
                $this->assertCount(1,$bundle['events']);
                $this->assertSame($tenant===42 ? 'info' : 'error',$bundle['events'][0]['level']);
            }
            $this->expectExceptionMessage('non disponibile');
            $service->forDocument(42,999);
        } finally { foreach ($databases as $db) $db->close(); }
    }
}
