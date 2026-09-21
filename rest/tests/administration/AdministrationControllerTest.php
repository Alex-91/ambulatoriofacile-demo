<?php
namespace Tests\Administration;
use App\Controllers\AdministrationController;
use CodeIgniter\Test\{CIUnitTestCase,ControllerTestTrait};
class FeatureMap extends \App\Services\TenantFeatureService
{
    public array $map=[];
    public function __construct() {}
    public function resolveEffectiveFeatureMapForTenant(int $tenantId): array { return $this->map; }
}
final class AdministrationControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    protected function setUp(): void
    { parent::setUp(); helper(['session_auth','form','url']); session()->remove(['utente_sess','id_user','userId','isLoggedInConfirmed','access_confirmed','tenant_context']); }
    public function testAnonymousCannotReadOrChangeAdministration(): void
    {
        $this->request->setMethod('POST');
        foreach (['index','change'] as $action) {
            $r=$this->controller(AdministrationController::class)->execute($action); $r->assertStatus(400);
            $this->assertStringContainsString('no-store',$r->response()->getHeaderLine('Cache-Control'));
            $this->assertStringNotContainsString('request_token',$r->response()->getBody());
        }
    }
    public function testEveryAdministrationRouteHasCsrfRotation(): void
    {
        foreach (['','/operazione','/preventivo/abc/fattura','/dettaglio/quotes/abc','/export/cases'] as $suffix) {
            $filters=new \CodeIgniter\Filters\Filters(new \Config\Filters(),$this->request,$this->response);
            $active=$filters->initialize('admin/amministrazione'.$suffix)->getFilters();
            $this->assertContains('administrationcsrf',$active['before']); $this->assertContains('administrationcsrf',$active['after']);
        }
    }
    public function testModuleDependenciesAreEnforcedAgainstEffectivePlatformMap(): void
    {
        $source=new FeatureMap(); $gate=new \App\Services\AdministrationFeatureService($source);
        $source->map=['admin_payers'=>true,'admin_ssn'=>true,'admin_compensation'=>true,'admin_quotes'=>true];
        $this->assertSame([],$gate->available(42));
        $source->map['billing']=true; $source->map['admin_quotes']=false;
        $this->assertSame(['admin_compensation'=>'Spettanze professionisti'],$gate->available(42));
        $source->map['admin_quotes']=true; $this->assertCount(4,$gate->available(42));
        $this->assertFalse($gate->enabled(0,'admin_quotes')); $this->assertFalse($gate->enabled(42,'arbitrary'));
    }
}
