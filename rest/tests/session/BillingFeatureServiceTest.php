<?php

use App\Libraries\TenantContext;
use App\Services\BillingFeatureService;
use App\Services\TenantContextService;
use App\Services\TenantFeatureService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BillingFeatureServiceTest extends CIUnitTestCase
{
    private string $originalHttpHost = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHttpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $_SERVER['HTTP_HOST'] = 'localhost';
        $this->resetSessionState();
    }

    protected function tearDown(): void
    {
        $_SERVER['HTTP_HOST'] = $this->originalHttpHost;
        $this->resetSessionState();
        parent::tearDown();
    }

    public function testLocalTenantMasterBypassMarksBillingAsEnabled(): void
    {
        $context = new TenantContext(
            12,
            'studio-fisioterapico',
            'Studio Fisioterapico',
            'active',
            'completed',
            'pro',
            'Pro',
            'tenant_master',
            34,
            91,
            'tenant-12',
            'billing',
            []
        );

        service('session')->set(TenantContextService::SESSION_KEY, $context->toArray());

        $tenantFeatures = $this->getMockBuilder(TenantFeatureService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveEffectiveFeatureMapForTenant'])
            ->getMock();

        $tenantFeatures->expects($this->once())
            ->method('resolveEffectiveFeatureMapForTenant')
            ->with(12)
            ->willReturn([
                'billing' => false,
            ]);

        $service = new BillingFeatureService($tenantFeatures);

        $this->assertTrue($service->allowsLocalTestingBypass($context));
        $this->assertTrue($service->isEnabledForContext($context));
        $this->assertTrue($service->isEnabledForTenant(12));
    }

    private function resetSessionState(): void
    {
        service('session')->remove([
            TenantContextService::SESSION_KEY,
        ]);
    }
}
