<?php
namespace Tests\Pacs;
use CodeIgniter\Test\{CIUnitTestCase,ControllerTestTrait};
use App\Controllers\PacsDemoController;
final class PacsPatientDemoTest extends CIUnitTestCase
{
 use ControllerTestTrait;
 public function testMasterChartShowsDemoWithoutClinicalPermissions():void
 {
  helper(['session_auth','form','url']);
  $data=['patientId'=>100,'patient'=>['patient_name'=>'Demo <script>'],'tenant'=>['id_tenant'=>4],
   'chart'=>['actor'=>['role'=>4],'clinical'=>false,'consents'=>[],'objects'=>[],'templates'=>[],'manage_templates'=>false],
   'editing'=>null,'revisionOf'=>null,'pacsEnabled'=>true,'pacsDemo'=>['orders'=>[],'selected'=>null]];
  $html=view('clinical/patient',$data,['saveData'=>false]);
  $this->assertStringContainsString('Nuovo esame',$html);
  $this->assertStringContainsString(esc(site_url('cartella-clinica/pazienti/100/pacs-demo'),'attr'),$html);
  $this->assertStringContainsString('Demo &lt;script&gt;',$html);
  $this->assertStringNotContainsString('Nuovo episodio o documento',$html);
  $data['pacsDemo']=null;$html=view('clinical/patient',$data,['saveData'=>false]);
  $this->assertStringNotContainsString('Nuovo esame',$html);
 }
 public function testPatientDemoEndpointsDenyUnauthenticatedAndGetMutations():void
 {
  helper(['session_auth','form','url']);session()->remove(['utente_sess','id_user','access_confirmed','tenant_context']);
  $this->request->setMethod('GET');
  $this->controller(PacsDemoController::class)->execute('exportPatientOrder',100)->assertStatus(400);
  $this->controller(PacsDemoController::class)->execute('savePatientOrder',100)->assertRedirect();
  foreach(['pacs-demo','pacs-demo/worklist'] as $suffix){
   $filters=new \CodeIgniter\Filters\Filters(new \Config\Filters(),$this->request,$this->response);
   $this->assertContains('clinicalcsrf',$filters->initialize('cartella-clinica/pazienti/100/'.$suffix)->getFilters()['before']);
  }
 }
}
