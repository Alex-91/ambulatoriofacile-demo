<?php

use App\Services\TenantContextService;
use App\Services\DemoAccessService;
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

    public function testTenantAgendaUrlUsesDirectAppPathWhenSessionIsConfirmed(): void
    {
        $user = new stdClass();
        $user->id_user = 42;

        service('session')->set([
            'utente_sess' => $user,
            'isLoggedInConfirmed' => true,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_master',
            ],
        ]);

        $this->assertStringEndsWith('/agenda', portal_tenant_agenda_url());
        $this->assertStringNotContainsString('/login/spazio/agenda', portal_tenant_agenda_url());
    }

    public function testTenantOperationalProfileUrlUsesDirectAppPathWhenSessionIsConfirmed(): void
    {
        $user = new stdClass();
        $user->id_user = 42;

        service('session')->set([
            'utente_sess' => $user,
            'isLoggedInConfirmed' => true,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_master',
            ],
        ]);

        $this->assertStringEndsWith('/admin', portal_tenant_operational_profile_url());
        $this->assertStringNotContainsString('/login/spazio/profilo-operativo', portal_tenant_operational_profile_url());
    }

    public function testTenantAgendaUrlKeepsLoginPrefixInDemoMode(): void
    {
        service('session')->set([
            DemoAccessService::SESSION_KEY_ACTIVE => true,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_master',
            ],
        ]);

        $this->assertStringEndsWith('/spazio/agenda', portal_tenant_agenda_url());
        $this->assertStringContainsString('/login/spazio/agenda', portal_tenant_agenda_url());
    }

    public function testTenantOperationalProfileUrlKeepsLoginPrefixInDemoMode(): void
    {
        service('session')->set([
            DemoAccessService::SESSION_KEY_ACTIVE => true,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_master',
            ],
        ]);

        $this->assertStringEndsWith('/spazio/profilo-operativo', portal_tenant_operational_profile_url());
        $this->assertStringContainsString('/login/spazio/profilo-operativo', portal_tenant_operational_profile_url());
    }

    public function testTenantOperationalProfileUrlFallsBackToAdminWithoutTenantContext(): void
    {
        $this->assertStringEndsWith('/admin', portal_tenant_operational_profile_url());
    }

    private function resetSessionState(): void
    {
        service('session')->remove([
            'utente_sess',
            'isLoggedInConfirmed',
            'platform_user_id',
            DemoAccessService::SESSION_KEY_ACTIVE,
            TenantContextService::SESSION_KEY,
        ]);
    }
}
