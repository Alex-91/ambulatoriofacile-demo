<?php
namespace Tests\Pacs;
use App\Controllers\PacsDemoController;
use CodeIgniter\Test\{CIUnitTestCase,ControllerTestTrait};
final class PacsDemoCaseTest extends CIUnitTestCase
{
 use ControllerTestTrait;
 public function testCaseAndImagesDenyUnauthenticatedAccess():void
 {
  helper(['session_auth','form','url']);session()->remove(['utente_sess','id_user','access_confirmed','tenant_context']);
  $this->controller(PacsDemoController::class)->execute('chart')->assertStatus(400);
  $this->controller(PacsDemoController::class)->execute('chartImage',1)->assertStatus(400);
 }
 public function testVerifiedCaseHasOnePatientOrderStudyAndReport():void
 {
  $c=json_decode(file_get_contents(APPPATH.'Resources/pacs-demo/verified-case.json'),true);
  $this->assertSame('AF_VERIFIED_SYNTHETIC_CHART_V1',$c['marker']);
  $this->assertSame($c['images']['study'],$c['order']['study_uid']);
  $this->assertSame($c['images']['accession'],$c['order']['accession']);
  $this->assertSame($c['images']['patient'],$c['order']['payload']['pacs_patient_id']);
  $this->assertSame($c['images']['issuer'],$c['order']['payload']['pacs_issuer']);
  $this->assertSame($c['report']['id'],$c['order']['report_entry_id']);
  $this->assertSame('draft',$c['report']['state']);$this->assertSame('performed',$c['order']['workflow_stage']);
  $this->assertCount(16,$c['images']['hashes']);$this->assertCount(16,$c['images']['instances']);
 }
 public function testViewClearlyLabelsHistoricalCaseAndOfflinePacs():void
 {
  helper(['session_auth','form','url']);$case=json_decode(file_get_contents(APPPATH.'Resources/pacs-demo/verified-case.json'),true);
  $case['report']['content']['body']='<script>escape</script>';
  $html=view('clinical/pacs_demo_chart',['case'=>$case,'connected'=>false,'tenant'=>['id_tenant'=>4]],['saveData'=>false]);
  $this->assertStringContainsString('sola consultazione',$html);
  $this->assertStringContainsString('PACS non verificabile',$html);
  $this->assertStringContainsString('Bozza di prova',$html);
  $this->assertStringContainsString('&lt;script&gt;escape&lt;/script&gt;',$html);
  $this->assertStringNotContainsString('<script>escape</script>',$html);
 }
}
