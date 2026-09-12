<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Controllers\PacsController;
use App\Services\Pacs\{PacsService,PacsException};
use CodeIgniter\Test\{CIUnitTestCase,ControllerTestTrait};
use CodeIgniter\Security\Exceptions\SecurityException;

class InjectedPacsController extends PacsController
{
    public static PacsService $service;
    protected function context(): array { return [self::$service,['id_tenant'=>42,'tenant_name'=>'Synthetic clinic']]; }
    protected function patientIdentity(int $tenantId,int $patientId): array { return ['patient_name'=>'Synthetic patient']; }
}
final class PacsControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    protected function setUp(): void
    { parent::setUp(); helper(['session_auth','form','url']); session()->remove(['utente_sess','id_user','access_confirmed','tenant_context']); }
    public function testUnauthenticatedRealControllerDeniesAllActions(): void
    {
        $this->request->setMethod('POST');
        foreach (['patient','search','bind','unbind','link','unlink'] as $action) {
            $result=$this->controller(PacsController::class)->execute($action,100);
            $result->assertStatus(400);
            $this->assertStringNotContainsString('PACS sintetico',$result->response()->getBody());
        }
        foreach (['study','viewer','download'] as $action) $this->controller(PacsController::class)->execute($action,100,str_repeat('a',32))->assertStatus(400);
    }
    public function testEveryPacsRouteUsesClinicalCsrfFilter(): void
    {
        foreach (['','/cerca','/identita','/disabilita','/collega','/scollega','/studi/abc','/studi/abc/visualizzatore','/studi/abc/scarica'] as $suffix) {
            $path='cartella-clinica/pazienti/100/pacs'.$suffix;
            $filters=new \CodeIgniter\Filters\Filters(new \Config\Filters(),$this->request,$this->response);
            $active=$filters->initialize($path)->getFilters();
            $this->assertContains('clinicalcsrf',$active['before']); $this->assertContains('clinicalcsrf',$active['after']);
        }
    }
    public function testMissingCsrfIsRejected(): void
    {
        $this->request->setMethod('POST'); $this->request->setGlobal('post',[]);
        \Config\Services::injectMock('security',new \CodeIgniter\Security\Security(new \Config\Security()));
        $this->expectException(SecurityException::class);
        (new \App\Filters\BillingCsrfFilter())->before($this->request);
    }
    public function testValidCsrfRotatesAndSurvivesPacsRedirect(): void
    {
        $config=new \Config\Security(); $security=new \CodeIgniter\Security\Security($config);
        \Config\Services::injectMock('security',$security); $token=$security->getHash();
        $this->request->setMethod('POST'); $this->request->setGlobal('post',[$config->tokenName=>$token]);
        $filter=new \App\Filters\BillingCsrfFilter();
        $filter->before($this->request);
        $redirect=redirect()->to(site_url('cartella-clinica/pazienti/100/pacs'));
        $filter->after($this->request,$redirect);
        $this->assertNotSame($token,$security->getHash());
        $cookies=array_values(array_filter($redirect->getCookies(),fn($c)=>$c->getPrefixedName()===$security->getCookieName()));
        $this->assertCount(1,$cookies); $this->assertSame($security->getHash(),$cookies[0]->getValue());
    }
    public function testOverviewResponseIsPrivateAndConfigurationSecretsAreAbsent(): void
    {
        $s=$this->getMockBuilder(PacsService::class)->disableOriginalConstructor()->onlyMethods(['overview'])->getMock();
        $s->expects($this->once())->method('overview')->with(100)->willReturn(['bindings'=>[],'links'=>[],'profiles'=>[],'doctor'=>true,'user_id'=>1]);
        InjectedPacsController::$service=$s;
        $r=$this->controller(InjectedPacsController::class)->execute('patient',100);
        $r->assertStatus(200);
        $this->assertStringContainsString('no-store',$r->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('no-referrer',$r->response()->getHeaderLine('Referrer-Policy'));
        $this->assertStringContainsString('Collegamento da configurare',$r->response()->getBody());
    }
    public function testViewerLaunchIsPostOnlyAndRedirectDoesNotLeakCredentials(): void
    {
        $s=$this->getMockBuilder(PacsService::class)->disableOriginalConstructor()->onlyMethods(['viewer'])->getMock();
        $s->expects($this->once())->method('viewer')->with(100,'link')->willReturn('https://viewer.example.test/view?study=1.2.3');
        InjectedPacsController::$service=$s;
        $this->request->setMethod('GET');
        $this->controller(InjectedPacsController::class)->execute('viewer',100,'link')->assertStatus(400);
        $this->request->setMethod('POST');
        $r=$this->controller(InjectedPacsController::class)->execute('viewer',100,'link');
        $r->assertStatus(303);
        $this->assertSame('https://viewer.example.test/view?study=1.2.3',$r->response()->getHeaderLine('Location'));
        $this->assertSame('no-referrer',$r->response()->getHeaderLine('Referrer-Policy'));
    }
    public function testProviderErrorBodiesNeverReachOperator(): void
    {
        $s=$this->getMockBuilder(PacsService::class)->disableOriginalConstructor()->onlyMethods(['overview'])->getMock();
        $s->method('overview')->willThrowException(new \RuntimeException('password=synthetic-secret'));
        InjectedPacsController::$service=$s;
        $r=$this->controller(InjectedPacsController::class)->execute('patient',100);
        $r->assertStatus(400); $this->assertStringNotContainsString('synthetic-secret',$r->response()->getBody());
    }
}
