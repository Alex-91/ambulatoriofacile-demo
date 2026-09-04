<?php

use App\Services\TenantContextService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SessionAuthHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('session_auth');
        $this->resetSessionState();
    }

    protected function tearDown(): void
    {
        $this->resetSessionState();
        parent::tearDown();
    }

    public function testReturnsTrueWhenSessionIsAlreadyConfirmed(): void
    {
        $session = service('session');
        $session->set('isLoggedInConfirmed', true);

        $this->assertTrue(session_access_is_confirmed());
    }

    public function testSelfHealsPlatformTenantSessionWithMissingConfirmationFlag(): void
    {
        $session = service('session');
        $user = new stdClass();
        $user->id_user = 42;

        $session->set([
            'utente_sess' => $user,
            'platform_user_id' => 77,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_staff',
            ],
        ]);

        $this->assertTrue(session_access_is_confirmed());
        $this->assertTrue((bool) $session->get('isLoggedInConfirmed'));
        $this->assertTrue((bool) $session->get('isLoggedIn'));
    }

    public function testDoesNotConfirmLegacyPartialSessionWithoutPlatformContext(): void
    {
        $session = service('session');
        $user = new stdClass();
        $user->id_user = 42;

        $session->set('utente_sess', $user);

        $this->assertFalse(session_access_is_confirmed());
        $this->assertNull($session->get('isLoggedInConfirmed'));
    }

    public function testSecretaryProfileOpensAgendaFirst(): void
    {
        $user = new stdClass();
        $user->id_user = 42;
        $user->tipo_pers = 3;

        service('session')->set([
            'utente_sess' => $user,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_staff',
            ],
        ]);

        $this->assertTrue(session_user_is_secretary_profile());
        $this->assertTrue(session_should_open_agenda_first());
    }

    public function testNurseProfileDoesNotOpenAgendaFirst(): void
    {
        $user = new stdClass();
        $user->id_user = 42;
        $user->tipo_pers = 2;

        service('session')->set([
            'utente_sess' => $user,
            TenantContextService::SESSION_KEY => [
                'tenant_id' => 12,
                'tenant_role' => 'tenant_staff',
            ],
        ]);

        $this->assertFalse(session_user_is_secretary_profile());
        $this->assertFalse(session_should_open_agenda_first());
    }

    private function resetSessionState(): void
    {
        service('session')->remove([
            'isLoggedIn',
            'isLoggedInConfirmed',
            'utente_sess',
            'platform_user_id',
            'platform_is_admin',
            TenantContextService::SESSION_KEY,
        ]);
    }
}
