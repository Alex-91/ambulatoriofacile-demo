<?php
namespace Tests\Pacs;
use App\Controllers\ClinicalSetupController;
use CodeIgniter\Test\{CIUnitTestCase,ControllerTestTrait};

final class ClinicalSetupControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    protected function setUp(): void
    { parent::setUp(); helper(['session_auth','form','url']); session()->remove(['utente_sess','id_user','userId','isLoggedInConfirmed','access_confirmed','tenant_context']); }
    public function testAnonymousCannotReadPrepareSaveOrProbe(): void
    {
        $this->request->setMethod('POST');
        foreach (['index','prepare','saveProfile','check'] as $action) {
            $r=$this->controller(ClinicalSetupController::class)->execute($action);
            $r->assertStatus(400);
            $this->assertStringContainsString('no-store',$r->response()->getHeaderLine('Cache-Control'));
            $this->assertStringNotContainsString('name="password"',$r->response()->getBody());
        }
    }
    public function testAllSetupRoutesAreProtectedByRotatingCsrf(): void
    {
        foreach (['','/prepara','/pacs','/collaudo'] as $suffix) {
            $filters=new \CodeIgniter\Filters\Filters(new \Config\Filters(),$this->request,$this->response);
            $active=$filters->initialize('cartella-clinica/configurazione'.$suffix)->getFilters();
            $this->assertContains('clinicalcsrf',$active['before']); $this->assertContains('clinicalcsrf',$active['after']);
        }
    }
    public function testSetupViewEscapesConfigurationAndDoesNotRepopulateSecrets(): void
    {
        $p=['id'=>'safe','label'=>'<script>unsafe</script>','qido_url'=>'https://pacs.example.test','wado_url'=>'https://pacs.example.test','auth'=>'basic','enabled'=>true,'download_enabled'=>false,'revision'=>1,'credentials_present'=>true];
        $body=view('clinical/setup',['tenant'=>['tenant_name'=>'Synthetic'],'status'=>['pacs_enabled'=>true,'checks'=>[['id'=>'settings_schema','label'=>'Settings','ok'=>true,'detail'=>'Ready']]],'profiles'=>['safe'=>$p],'serverProfiles'=>[],'report'=>null,'error'=>null],['saveData'=>false]);
        $this->assertStringNotContainsString('<script>unsafe</script>',$body);
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;',$body);
        $this->assertStringContainsString('name="password" value=""',$body);
    }
}
