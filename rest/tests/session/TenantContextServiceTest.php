<?php

use App\Libraries\TenantContext;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TenantContextServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSessionState();
    }

    protected function tearDown(): void
    {
        $this->resetSessionState();
        parent::tearDown();
    }

    public function testRestoresTenantContextFromPlatformMembershipWhenSessionPayloadIsIncomplete(): void
    {
        service('session')->set([
            'platform_user_id' => 34,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_name' => 'Studio Fisioterapico',
                'feature_flags' => [
                    'billing' => true,
                ],
            ],
        ]);

        $membership = [
            'id_tenant' => 12,
            'id_platform_user' => 34,
            'app_user_id' => 91,
            'tenant_key' => 'studio-fisioterapico',
            'tenant_name' => 'Studio Fisioterapico',
            'tenant_status' => 'active',
            'onboarding_status' => 'completed',
            'package_code' => 'pro',
            'package_name' => 'Pro',
            'tenant_role' => 'tenant_master',
            'storage_key' => 'tenant-12',
            'feature_profile' => 'billing',
        ];

        $expectedContext = new TenantContext(
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
            ['billing' => true]
        );

        $catalog = $this->getMockBuilder(TenantCatalogService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTenantMembership', 'buildTenantContext'])
            ->getMock();

        $catalog->expects($this->once())
            ->method('getTenantMembership')
            ->with(34, 12)
            ->willReturn($membership);

        $catalog->expects($this->once())
            ->method('buildTenantContext')
            ->with($membership)
            ->willReturn($expectedContext);

        $service = new TenantContextService($catalog);
        $context = $service->getCurrentTenant();

        $this->assertInstanceOf(TenantContext::class, $context);
        $this->assertSame('tenant_master', $context->tenantRole);
        $this->assertSame('studio-fisioterapico', $context->tenantKey);
        $this->assertTrue($context->allows('billing'));

        $stored = service('session')->get(TenantContextService::SESSION_KEY);
        $this->assertIsArray($stored);
        $this->assertSame('studio-fisioterapico', $stored['tenant_key'] ?? null);
        $this->assertSame('tenant_master', $stored['tenant_role'] ?? null);
    }

    private function resetSessionState(): void
    {
        service('session')->remove([
            'platform_user_id',
            TenantContextService::SESSION_KEY,
        ]);
    }
}
