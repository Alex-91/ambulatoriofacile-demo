<?php

use App\Services\LegacyLoginHandoffService;
use App\Services\TenantContextService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class LegacyLoginHandoffServiceTest extends CIUnitTestCase
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

    public function testTenantMasterAdminHandoffOpensAgenda(): void
    {
        service('session')->set(TenantContextService::SESSION_KEY, [
            'tenant_id' => 12,
            'tenant_role' => 'tenant_master',
        ]);

        $this->assertSame('agenda', $this->resolveConfirmedAdminRedirect(''));
    }

    public function testTenantAdminHandoffKeepsOperationalProfile(): void
    {
        service('session')->set(TenantContextService::SESSION_KEY, [
            'tenant_id' => 12,
            'tenant_role' => 'tenant_admin',
        ]);

        $this->assertSame('admin', $this->resolveConfirmedAdminRedirect(''));
    }

    public function testExistingConfirmedRedirectTakesPriority(): void
    {
        service('session')->set(TenantContextService::SESSION_KEY, [
            'tenant_id' => 12,
            'tenant_role' => 'tenant_master',
        ]);

        $this->assertSame('sostituzioni', $this->resolveConfirmedAdminRedirect('sostituzioni'));
    }

    private function resolveConfirmedAdminRedirect(string $confirmedRedirect): string
    {
        $reflection = new ReflectionClass(LegacyLoginHandoffService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveConfirmedAdminRedirect');

        return (string) $method->invoke($service, $confirmedRedirect);
    }

    private function resetSessionState(): void
    {
        service('session')->remove([
            'tenant_app_admin',
            TenantContextService::SESSION_KEY,
        ]);
    }
}
