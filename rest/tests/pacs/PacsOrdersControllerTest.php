<?php
namespace Tests\Pacs;
use App\Controllers\PacsOrdersController;
use App\Services\Pacs\{PacsService,PacsOrderService};
use CodeIgniter\Test\{CIUnitTestCase,ControllerTestTrait};

class InjectedOrdersController extends PacsOrdersController
{
    public static PacsOrderService $orders;
    public static PacsService $pacs;
    protected function ordersContext(): array { return [self::$orders,self::$pacs,['id_tenant'=>42,'tenant_name'=>'Test']]; }
    protected function patientIdentity(int $tenantId,int $patientId): array { return ['patient_name'=>'Paziente <script>']; }
}
final class PacsOrdersControllerTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    protected function setUp(): void
    {
        parent::setUp(); helper(['session_auth','form','url']); session()->remove(['utente_sess','id_user','access_confirmed','tenant_context']);
        InjectedOrdersController::$pacs=$this->getMockBuilder(PacsService::class)->disableOriginalConstructor()->getMock();
    }
    public function testRealControllerDeniesUnauthenticatedRequestsAndAllRoutesAreCsrfProtected(): void
    {
        $this->request->setMethod('POST');
        $this->controller(PacsOrdersController::class)->execute('queue')->assertStatus(400);
        foreach (['index','create'] as $action) $this->controller(PacsOrdersController::class)->execute($action,100)->assertStatus(400);
        foreach (['detail','update','approve','cancel','export','advance','images','report'] as $action) $this->controller(PacsOrdersController::class)->execute($action,100,str_repeat('a',32))->assertStatus(400);
        foreach (['','/abc','/abc/modifica','/abc/conferma','/abc/annulla','/abc/esporta','/abc/avanzamento','/abc/immagini','/abc/referto'] as $suffix) {
            $filters=new \CodeIgniter\Filters\Filters(new \Config\Filters(),$this->request,$this->response);
            $active=$filters->initialize('cartella-clinica/pazienti/100/pacs/richieste'.$suffix)->getFilters();
            $this->assertContains('clinicalcsrf',$active['before']); $this->assertContains('clinicalcsrf',$active['after']);
        }
    }
    public function testMutationsArePostOnlyEvenWhenCalledDirectly(): void
    {
        InjectedOrdersController::$orders=$this->getMockBuilder(PacsOrderService::class)->disableOriginalConstructor()->getMock();
        InjectedOrdersController::$orders->expects($this->never())->method('create');
        InjectedOrdersController::$orders->expects($this->never())->method('export');
        $this->request->setMethod('GET');
        $this->controller(InjectedOrdersController::class)->execute('create',100)->assertStatus(400);
        foreach (['update','approve','cancel','export','advance','images','report'] as $action) $this->controller(InjectedOrdersController::class)->execute($action,100,str_repeat('a',32))->assertStatus(400);
    }
    public function testIndexEscapesPatientAndHidesCreateFormWithoutBinding(): void
    {
        $s=$this->getMockBuilder(PacsOrderService::class)->disableOriginalConstructor()->onlyMethods(['listing'])->getMock();
        $s->method('listing')->willReturn(['rows'=>[],'page'=>1,'more'=>false,'doctor'=>true,'user_id'=>1]);
        InjectedOrdersController::$orders=$s;
        InjectedOrdersController::$pacs->method('overview')->willReturn(['bindings'=>[]]);
        $r=$this->controller(InjectedOrdersController::class)->execute('index',100);
        $r->assertStatus(200); $body=$r->response()->getBody();
        $this->assertStringContainsString('Paziente &lt;script&gt;',$body);
        $this->assertStringNotContainsString('name="request_key"',$body);
        $this->assertStringContainsString('no-store',$r->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('no-referrer',$r->response()->getHeaderLine('Referrer-Policy'));
    }
    public function testExportResponseIsAttachmentPrivateAndByteExact(): void
    {
        $s=$this->getMockBuilder(PacsOrderService::class)->disableOriginalConstructor()->onlyMethods(['export'])->getMock();
        $s->expects($this->once())->method('export')->with(100,'abc',2,'dicom')->willReturn(['bytes'=>"SYNTHETIC\0DICM",'mime'=>'application/dicom','name'=>'worklist-AF123.wl']);
        InjectedOrdersController::$orders=$s;
        $this->request->setMethod('POST'); $this->request->setGlobal('post',['revision'=>'2','format'=>'dicom']);
        $r=$this->controller(InjectedOrdersController::class)->execute('export',100,'abc');
        $r->assertStatus(200);
        $this->assertSame("SYNTHETIC\0DICM",$r->response()->getBody());
        $this->assertSame('attachment; filename="worklist-AF123.wl"',$r->response()->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store',$r->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff',$r->response()->getHeaderLine('X-Content-Type-Options'));
    }
    public function testReceptionQueueHasNoClinicalActionsAndEscapesAllLabels(): void
    {
        $s=$this->getMockBuilder(PacsOrderService::class)->disableOriginalConstructor()->onlyMethods(['queue'])->getMock();
        $s->method('queue')->willReturn(['date'=>'2026-09-20','page'=>1,'more'=>false,'completed'=>false,'role'=>3,'rows'=>[
            ['id'=>str_repeat('a',32),'patient_id'=>100,'patient_name'=>'<script>','description'=>'<b>','scheduled_at'=>'2026-09-20T10:30',
            'accession'=>'AF123','appointment_id'=>10,'stage'=>'awaiting','revision'=>2,'problem'=>'','clinical_owner'=>false],
        ]]);
        InjectedOrdersController::$orders=$s;
        $this->request->setMethod('GET');
        $r=$this->controller(InjectedOrdersController::class)->execute('queue'); $r->assertStatus(200);
        $body=$r->response()->getBody();
        $this->assertStringContainsString('&lt;script&gt;',$body); $this->assertStringContainsString('Registra accettazione',$body);
        $this->assertStringNotContainsString('Immagini e referto',$body); $this->assertStringNotContainsString('Avvia esame',$body);
        $this->assertStringContainsString('no-store',$r->response()->getHeaderLine('Cache-Control'));
        $filters=new \CodeIgniter\Filters\Filters(new \Config\Filters(),$this->request,$this->response);
        $this->assertContains('clinicalcsrf',$filters->initialize('cartella-clinica/diagnostica')->getFilters()['before']);
    }
    public function testAcceptanceRedirectsToOperationalQueueAndPreservesSelectedDate(): void
    {
        $s=$this->getMockBuilder(PacsOrderService::class)->disableOriginalConstructor()->onlyMethods(['advance'])->getMock();
        $s->expects($this->once())->method('advance')->with(100,'abc',2,'accepted');
        InjectedOrdersController::$orders=$s;
        $this->request->setMethod('POST'); $this->request->setGlobal('post',['revision'=>'2','stage'=>'accepted','queue_date'=>'2026-09-20']);
        $r=$this->controller(InjectedOrdersController::class)->execute('advance',100,'abc');
        $r->assertStatus(303);
        $this->assertStringContainsString('cartella-clinica/diagnostica?date=2026-09-20',$r->response()->getHeaderLine('Location'));
        $this->assertStringNotContainsString('/pacs/',$r->response()->getHeaderLine('Location'));
    }

}
