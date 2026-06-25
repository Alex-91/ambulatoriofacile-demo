<?php

use App\Services\TenantContextService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PortalHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('portal');
        $this->resetSessionState();
    }

    protected function tearDown(): void
    {
        $this->resetSessionState();
        parent::tearDown();
    }

    public function testTenantAgendaUrlUsesBridgeEvenWithoutPlatformUserId(): void
    {
        service('session')->set(TenantContextService::SESSION_KEY, [
            'tenant_id' => 12,
            'tenant_role' => 'tenant_master',
        ]);

        $this->assertStringEndsWith('/login/spazio/agenda', portal_tenant_agenda_url());
    }

    public function testTenantOperationalProfileUrlUsesBridgeEvenWithoutPlatformUserId(): void
    {
        service('session')->set(TenantContextService::SESSION_KEY, [
            'tenant_id' => 12,
            'tenant_role' => 'tenant_master',
        ]);

        $this->assertStringEndsWith('/login/spazio/profilo-operativo', portal_tenant_operational_profile_url());
    }

    public function testTenantOperationalProfileUrlFallsBackToAdminWithoutTenantContext(): void
    {
        $this->assertStringEndsWith('/admin', portal_tenant_operational_profile_url());
    }

    private function resetSessionState(): void
    {
        service('session')->remove([
            'platform_user_id',
            TenantContextService::SESSION_KEY,
        ]);
    }
}
