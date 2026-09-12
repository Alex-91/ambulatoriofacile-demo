<?php
namespace Tests\Unit;

use App\Controllers\Admin\FseDashboardController;
use App\Libraries\TenantContext;
use CodeIgniter\Test\{CIUnitTestCase, ControllerTestTrait};
use CodeIgniter\Security\Exceptions\SecurityException;

/** Real controller/guard, session helper, routes and CSRF library; not a browser/login E2E. */
final class FseHttpSecurityTest extends CIUnitTestCase
{
    use ControllerTestTrait;
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('FseSyntheticBoot', false)) $this->markTestSkipped('Use ops/fse-validation/phpunit.xml: no real DB permitted.');
        $_SERVER['HTTP_HOST'] = 'fse-synthetic.invalid';
        $_POST = []; $_COOKIE = [];
    }
    private function login(string $role, bool $feature = true): void
    {
        $context = new TenantContext(tenantId:42, tenantKey:'synthetic-only', tenantRole:$role, featureFlags:['fse2'=>$feature]);
        session()->set(['isLoggedInConfirmed'=>true, 'utente_sess'=>(object)['id_user'=>9, 'tipo'=>2], 'tenant_context'=>$context->toArray()]);
    }
    public function testAnonymousCannotDownloadOfflineReport(): void
    {
        $this->controller(FseDashboardController::class)->execute('offlineReport')->assertRedirect();
    }
    public function testRegularOperatorCannotDownloadOfflineReport(): void
    {
        $this->login('tenant_user');
        $this->controller(FseDashboardController::class)->execute('offlineReport')->assertRedirect();
        $this->assertStringContainsString('responsabili', (string) session()->getFlashdata('error'));
    }
    public function testPendingOtpDoesNotOpenFseDespiteTenantContext(): void
    {
        $this->login('tenant_admin');
        session()->set(['isLoggedInConfirmed'=>false, \App\Services\TenantLoginOtpService::SESSION_KEY_REQUIRED=>true]);
        $this->controller(FseDashboardController::class)->execute('offlineReport')->assertRedirect();
        $this->assertFalse(session()->get('isLoggedInConfirmed'));
    }
    public function testDisabledModuleIsDeniedOutsideLocalBypass(): void
    {
        $this->login('tenant_admin', false);
        $this->controller(FseDashboardController::class)->execute('offlineReport')->assertRedirect();
        $this->assertStringContainsString('non attivo', (string) session()->getFlashdata('error'));
    }
    public function testResponsibleWithModuleCanDownloadSyntheticReport(): void
    {
        $this->login('tenant_admin');
        $this->controller(FseDashboardController::class)->execute('offlineReport')->assertStatus(200);
        $this->assertSame('SQLite3', \Config\Database::connect()->DBDriver);
    }
    public function testFseRoutesHaveCsrfBeforeAndAfterIncludingSettingsAliases(): void
    {
        $config = new \Config\Filters();
        foreach (['admin/fse2', 'admin/fse2/documenti/firma/1', 'admin/fse2/documenti/correggi/1', 'admin/fse2/documenti/laboratorio-toscana/1', 'admin/fse2/laboratorio-toscana/azione', 'spazio/fse2/save', 'login/spazio/fse2/healthcheck'] as $path) {
            $filters = new \CodeIgniter\Filters\Filters($config, $this->request, $this->response);
            $active = $filters->initialize($path)->getFilters();
            $this->assertContains('fsecsrf', $active['before']); $this->assertContains('fsecsrf', $active['after']);
        }
    }
    public function testPostWithoutCsrfIsRejected(): void
    {
        $this->request->setMethod('POST'); $this->request->setGlobal('post', []);
        $security = new \CodeIgniter\Security\Security(new \Config\Security());
        \Config\Services::injectMock('security', $security);
        $this->expectException(SecurityException::class); (new \App\Filters\FseCsrfFilter())->before($this->request);
    }
    public function testValidCsrfAcceptedAndTokenRotates(): void
    {
        $config = new \Config\Security();
        $security = new \CodeIgniter\Security\Security($config); $token = $security->getHash();
        $this->request->setMethod('POST'); $this->request->setGlobal('post', [$config->tokenName=>$token]);
        $security->verify($this->request);
        $this->assertNotSame($token, $security->getHash());
    }
    public function testRotatedCookieSurvivesRedirect(): void
    {
        $config = new \Config\Security();
        $security = new \CodeIgniter\Security\Security($config);
        \Config\Services::injectMock('security', $security);
        $token = $security->getHash();
        $this->request->setMethod('POST'); $this->request->setGlobal('post', [$config->tokenName=>$token]);
        $filter = new \App\Filters\FseCsrfFilter();
        $this->assertNull($filter->before($this->request));
        $redirect = redirect()->to('http://fse-synthetic.invalid/admin/fse2');
        $filter->after($this->request, $redirect);
        $cookies = array_filter($redirect->getCookies(), fn($cookie) => $cookie->getPrefixedName() === $security->getCookieName());
        $this->assertCount(1, $cookies);
        $this->assertSame($security->getHash(), array_values($cookies)[0]->getValue());
        $this->assertNotSame($token, $security->getHash());
    }
    public function testOperatorCannotReachAnyDocumentMutationOrDownload(): void
    {
        $this->login('tenant_user');
        foreach (['save','revise','prepare','uploadSigned','validateDocument','publish','status','deleteDocument','download','supportBundle','importToscanaLab'] as $action) {
            $this->controller(\App\Controllers\Admin\FseDocumentsController::class)->execute($action, 1)->assertRedirect();
            $this->assertStringContainsString('responsabili', (string) session()->getFlashdata('error'));
        }
    }
    public function testIncorrectCsrfIsRejected(): void
    {
        $this->request->setMethod('POST'); $this->request->setGlobal('post', ['csrf_test_name'=>'forged']);
        $security = new \CodeIgniter\Security\Security(new \Config\Security());
        $this->expectException(SecurityException::class); $security->verify($this->request);
    }

    public function testPreparationSettingsIgnoreForgedEnableFlag(): void
    {
        $this->login('tenant_admin');
        $this->request->setMethod('POST'); $this->request->setGlobal('post',['id_fse_profile'=>12,'is_enabled'=>1]);
        $this->controller(\App\Controllers\Tenant\FseSettingsController::class);
        $profiles=$this->createMock(\App\Services\FseProfileService::class);
        $profiles->expects($this->once())->method('saveProfile')->with(42,$this->callback(fn($input)=>$input['is_enabled']===0),12,0,false)->willReturn(['id_fse_profile'=>12]);
        (new \ReflectionProperty($this->controller,'profiles'))->setValue($this->controller,$profiles);
        $this->execute('save')->assertRedirect();
    }

    public static function gatewayFlashMessages(): array
    {
        return [[false,'error','errors'],[false,'warning','warning'],[true,'warning','warning'],[true,'error','errors'],[true,'success','success']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gatewayFlashMessages')]
    public function testGatewayRejectionNeverAppearsAsSuccess(bool $ok, string $severity, string $flash): void
    {
        $this->login('tenant_admin');
        session()->remove(['success','errors','warning']);
        $this->controller(\App\Controllers\Admin\FseDocumentsController::class);
        $dispatch=$this->createMock(\App\Services\FseDispatchService::class);
        $dispatch->expects($this->once())->method('validate')->with(42,1,9)->willReturn([
            'ok'=>$ok,'message'=>'Fixed safe message','feedback'=>['severity'=>$severity]]);
        (new \ReflectionProperty($this->controller,'dispatch'))->setValue($this->controller,$dispatch);
        $this->execute('validateDocument',1)->assertRedirect();
        $this->assertSame($flash==='errors' ? ['generic'=>'Fixed safe message'] : 'Fixed safe message',session()->getFlashdata($flash));
        if ($flash!=='success') $this->assertNull(session()->getFlashdata('success'));
    }

    public function testProfileSaveErrorDoesNotRetainPassphrasesInSession(): void
    {
        $this->login('tenant_admin');
        $this->request->setMethod('POST'); $this->request->setGlobal('post',['profile_name'=>'Synthetic','auth_private_key_passphrase'=>'mtls-test-secret','signature_private_key_passphrase'=>'jwt-test-secret']);
        $this->controller(\App\Controllers\Tenant\FseSettingsController::class);
        $profiles=$this->createMock(\App\Services\FseProfileService::class);
        $profiles->expects($this->once())->method('saveProfile')->willThrowException(new \RuntimeException('Synthetic validation error'));
        (new \ReflectionProperty($this->controller,'profiles'))->setValue($this->controller,$profiles);
        $this->execute('save')->assertRedirect();
        $old=session()->getFlashdata('_ci_old_input')['post'];
        $this->assertSame('Synthetic',$old['profile_name']);
        $this->assertArrayNotHasKey('auth_private_key_passphrase',$old);
        $this->assertArrayNotHasKey('signature_private_key_passphrase',$old);
    }

    public function testSupportDownloadUsesOnlyCurrentTenantAndDoesNotEchoException(): void
    {
        $this->login('tenant_admin');
        $this->controller(\App\Controllers\Admin\FseDocumentsController::class);
        $support=$this->createMock(\App\Services\FseSupportBundleService::class);
        $support->expects($this->once())->method('forDocument')->with(42,998)->willThrowException(new \RuntimeException('PRIVATE_DATABASE_SECRET'));
        (new \ReflectionProperty($this->controller,'support'))->setValue($this->controller,$support);
        $this->execute('supportBundle',998)->assertStatus(404);
        $this->assertStringNotContainsString('PRIVATE_DATABASE_SECRET',$this->response->getBody());
    }

    public function testRegularOperatorCannotUseInteractiveLab(): void
    {
        $this->login('tenant_user');
        foreach (['index','command','report'] as $action) $this->controller(\App\Controllers\Admin\FseToscanaLabController::class)->execute($action)->assertRedirect();
    }

    public function testSupportDownloadIsNotCacheable(): void
    {
        $this->login('tenant_admin');
        $this->controller(\App\Controllers\Admin\FseDocumentsController::class);
        $support=$this->createMock(\App\Services\FseSupportBundleService::class);
        $support->expects($this->once())->method('forDocument')->with(42,1)->willReturn(['kind'=>'FSE_TECHNICAL_SUPPORT_ONLY']);
        (new \ReflectionProperty($this->controller,'support'))->setValue($this->controller,$support);
        $result=$this->execute('supportBundle',1);
        $result->assertStatus(200);
        $this->assertContains('no-store',array_map('trim',explode(',',$result->response()->getHeaderLine('Cache-Control'))));
    }

    public function testDocumentLabBridgeIgnoresForgedTenantAndDoesNotExposePrivateErrors(): void
    {
        $this->login('tenant_admin');
        $this->request->setMethod('POST'); $this->request->setGlobal('post',['tenant_id'=>43,'document_id'=>999,'actor'=>888]);
        $this->controller(\App\Controllers\Admin\FseDocumentsController::class);
        $lab=$this->createMock(\App\Services\FseToscanaDocumentLab::class);
        $lab->expects($this->once())->method('import')->with(42,1,9)->willThrowException(new \RuntimeException('PRIVATE_SECRET'));
        (new \ReflectionProperty($this->controller,'toscanaLab'))->setValue($this->controller,$lab);
        $this->execute('importToscanaLab',1)->assertRedirect();
        $this->assertStringNotContainsString('PRIVATE_SECRET',json_encode(session()->getFlashdata('errors')));
    }

    public function testLabIgnoresForgedTenantAndActorAndUsesPostOnlyAllowlist(): void
    {
        $this->login('tenant_admin');
        $root=rtrim(WRITEPATH,'/\\').'/fse-workflow-tests-'.bin2hex(random_bytes(8));
        $store=new \App\Services\FseToscanaLabStore($root);
        try {
            $this->request->setMethod('POST');
            $this->request->setGlobal('post',['command'=>'new','revision'=>0,'profile'=>'SITE_A_PRIVATE','tenant_id'=>999,'actor'=>888,'report_text'=>'PRIVATE_PATIENT']);
            $this->controller(\App\Controllers\Admin\FseToscanaLabController::class);
            (new \ReflectionProperty($this->controller,'store'))->setValue($this->controller,$store);
            $this->execute('command')->assertRedirect();
            $state=$store->read(42);
            $this->assertCount(1,$state['documents']); $this->assertSame(9,$state['events'][0]['actor']);
            $this->assertStringNotContainsString('PRIVATE_PATIENT',json_encode($state));
            $this->assertCount(0,$store->read(999)['documents']);
        } finally {
            foreach (new \DirectoryIterator($root) as $file) if ($file->isFile() && !$file->isLink()) unlink($file->getPathname());
            rmdir($root);
        }
    }
}
