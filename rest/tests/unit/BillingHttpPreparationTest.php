<?php
namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/** Pure config/runtime checks. Run only with the isolated application compatibility bootstrap. */
final class BillingHttpPreparationTest extends CIUnitTestCase
{
    use \CodeIgniter\Test\ControllerTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $dependencyLab = realpath((string) getenv('FSE_DEPENDENCY_LAB'));
        $write = realpath(WRITEPATH);
        $linuxLab = realpath((string) getenv('FSE_LAB_ROOT'));
        $linuxMarker = $linuxLab ? json_decode((string) @file_get_contents($linuxLab.'/lab.json'), true) : null;
        $linuxIsolated = PHP_OS_FAMILY === 'Linux' && is_file('/opt/fse/linux-lab-image')
            && class_exists('FseSyntheticBoot', false) && $write && $linuxLab
            && dirname($linuxLab) === realpath(ROOTPATH.'writable/fse-app-labs')
            && preg_match('/^[a-f0-9]{32}$/D', basename($linuxLab))
            && dirname($write) === $linuxLab && preg_match('/^unit-[a-f0-9]{16}$/D', basename($write))
            && ($linuxMarker['mode'] ?? '') === 'FSE_SYNTHETIC_APP_LAB'
            && ($linuxMarker['runtime'] ?? '') === 'linux-container';
        if ($linuxIsolated) return;
        if (!defined('FSE_FRAMEWORK_BOOTSTRAPPING') || !$dependencyLab || !$write
            || dirname(dirname($write)) !== $dependencyLab
            || !preg_match('/^application-(baseline|candidate)-[a-f0-9]{16}$/D', basename(dirname($write)))) {
            $this->markTestSkipped('Isolated application compatibility runtime required.');
        }
    }

    public function testPdfRuntimeUsesSeparateWritableTenantCaches(): void
    {
        $factory = new \App\Services\BillingPdfOptionsFactory();
        $a = $factory->create(42); $b = $factory->create(43);
        $this->assertNotSame($a->getFontCache(), $b->getFontCache());
        $this->assertStringStartsWith(rtrim(WRITEPATH, '/\\') . '/', $a->getFontCache());
        $this->assertDirectoryExists($a->getFontCache());
        $this->assertDirectoryExists($a->getTempDir());
        $this->assertNotSame($a->getFontDir(), $a->getFontCache());
        $this->assertFalse($a->getIsPhpEnabled());
        $this->assertFalse($a->getIsJavascriptEnabled());
        $this->assertTrue($a->getIsRemoteEnabled());
    }

    public function testPdfRuntimeRefusesMissingTenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \App\Services\BillingPdfOptionsFactory())->create(0);
    }

    public function testBillingDocumentFormsHaveCsrfIncludingPaymentAndEmail(): void
    {
        foreach (['admin/fatturazione-documenti', 'admin/fatturazione-documenti/nuovo',
            'admin/fatturazione-documenti/modifica/1', 'admin/fatturazione-documenti/save',
            'admin/fatturazione-documenti/pagamento/1', 'admin/fatturazione-documenti/elimina/1',
            'admin/fatturazione-documenti/email/1', 'admin/fatturazione-documenti/email/1/send',
            'admin/fatturazione-documenti/pdf/1', 'admin/fatturazione-scadenzario',
            'admin/fatturazione-documento/save', 'admin/sistema-ts/documenti/send',
            'admin/fatturazione-ts/documenti/send', 'login/spazio/sistema-ts/save',
            'spazio/fatturazione-ts/repair-schema', 'login/spazio/fatturazione/save'] as $path) {
            $filters = new \CodeIgniter\Filters\Filters(new \Config\Filters(), $this->request, $this->response);
            $active = $filters->initialize($path)->getFilters();
            $this->assertContains('billingcsrf', $active['before']);
            $this->assertContains('billingcsrf', $active['after']);
        }
    }

    public function testBillingFilterDoesNotExpandIntoUnrelatedLegacyRoutes(): void
    {
        foreach (['agenda', 'login', 'admin/fse2', 'api/smsfactor/dlr'] as $path) {
            $filters = new \CodeIgniter\Filters\Filters(new \Config\Filters(), $this->request, $this->response);
            $active = $filters->initialize($path)->getFilters();
            $this->assertNotContains('billingcsrf', $active['before']);
            $this->assertNotContains('billingcsrf', $active['after']);
        }
    }

    public function testBillingPostWithoutTokenIsRejected(): void
    {
        $_COOKIE = []; $_POST = [];
        $this->request->setMethod('POST'); $this->request->setGlobal('post', []);
        \Config\Services::injectMock('security', new \CodeIgniter\Security\Security(new \Config\Security()));
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        (new \App\Filters\BillingCsrfFilter())->before($this->request);
    }

    public function testBillingValidTokenRotatesAndSurvivesRedirect(): void
    {
        $_COOKIE = []; $_POST = [];
        $config = new \Config\Security();
        $security = new \CodeIgniter\Security\Security($config);
        \Config\Services::injectMock('security', $security);
        $token = $security->getHash();
        $this->request->setMethod('POST'); $this->request->setGlobal('post', [$config->tokenName => $token]);
        $filter = new \App\Filters\BillingCsrfFilter();
        $this->assertNull($filter->before($this->request));
        $redirect = redirect()->to('http://fse-synthetic.invalid/admin/fatturazione-documenti');
        $filter->after($this->request, $redirect);
        $cookies = array_values(array_filter($redirect->getCookies(), fn($cookie) => $cookie->getPrefixedName() === $security->getCookieName()));
        $this->assertCount(1, $cookies);
        $this->assertSame($security->getHash(), $cookies[0]->getValue());
        $this->assertNotSame($token, $security->getHash());
    }
}
