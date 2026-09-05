<?php

use App\Services\PersonnelAdminAccessService;
use App\Services\LegacyTenantSessionService;
use CodeIgniter\Test\CIUnitTestCase;

final class PersonnelAdminAccessServiceTest extends CIUnitTestCase
{
    public function testDoctorAndSecretaryCanReceiveAdministrativeAccess(): void
    {
        self::assertTrue(PersonnelAdminAccessService::isEligiblePersonnelType(1));
        self::assertTrue(PersonnelAdminAccessService::isEligiblePersonnelType(3));
        self::assertFalse(PersonnelAdminAccessService::isEligiblePersonnelType(2));
        self::assertFalse(PersonnelAdminAccessService::isEligiblePersonnelType(4));
    }

    public function testLocalSecretaryCanBeAdministrativeOrNormal(): void
    {
        self::assertSame(
            PersonnelAdminAccessService::USER_TYPE_ADMIN,
            PersonnelAdminAccessService::resolveLocalUserType(3, true)
        );
        self::assertSame(
            PersonnelAdminAccessService::USER_TYPE_STAFF,
            PersonnelAdminAccessService::resolveLocalUserType(3, false)
        );
    }

    public function testAdministrativeSecretaryReceivesAgendaAdminRoleOverlay(): void
    {
        self::assertTrue(PersonnelAdminAccessService::isLegacyAdminProfile(3, 1));
        self::assertFalse(PersonnelAdminAccessService::isLegacyAdminProfile(3, 2));
        self::assertFalse(PersonnelAdminAccessService::isLegacyAdminProfile(2, 1));
    }

    public function testPersonnelViewsExposeTheAdministrativeAccessFlag(): void
    {
        $createSource = file_get_contents(APPPATH . 'Views/admin/personale_create.php');
        $editSource = file_get_contents(APPPATH . 'Views/admin/personale_modifica.php');

        self::assertIsString($createSource);
        self::assertIsString($editSource);
        self::assertStringContainsString('name="is_personale_admin"', $createSource);
        self::assertStringContainsString('name="is_personale_admin"', $editSource);
        self::assertStringContainsString('data-admin-type-ids', $createSource);
        self::assertStringContainsString('data-admin-type-ids="1,3"', $editSource);
    }

    public function testSpaceUserViewsUseRoleNeutralAdministrativeLabel(): void
    {
        $tenantSource = file_get_contents(APPPATH . 'Views/tenant/space_users.php');
        $platformSource = file_get_contents(APPPATH . 'Views/admin/tenant_spaces.php');

        self::assertIsString($tenantSource);
        self::assertIsString($platformSource);
        self::assertStringContainsString('Accesso amministratore applicativo', $tenantSource);
        self::assertStringContainsString('Accesso amministratore applicativo', $platformSource);
        self::assertStringNotContainsString('Medico amministratore', $tenantSource);
        self::assertStringNotContainsString('Medico amministratore', $platformSource);
    }

    public function testLegacyUsernameLoginQueuesTenantAccessBeforeSessionBootstrap(): void
    {
        $source = file_get_contents(APPPATH . 'Services/LegacyTenantLoginService.php');

        self::assertIsString($source);
        $queuePosition = strpos($source, '$this->tenantSession->queuePendingRuntime($tenant, $appUserId, $userType);');
        $bootstrapPosition = strpos($source, '$handoff->bootstrapUserById($appUserId, $expectedUsername);');

        self::assertNotFalse($queuePosition);
        self::assertNotFalse($bootstrapPosition);
        self::assertLessThan($bootstrapPosition, $queuePosition);
    }

    public function testLegacyTenantRuntimePropagatesMembershipAdminAccessToSession(): void
    {
        $source = file_get_contents(APPPATH . 'Services/LegacyTenantSessionService.php');

        self::assertIsString($source);
        self::assertStringContainsString("'is_app_admin' => (bool) \$membershipAccess['is_app_admin']", $source);
        self::assertStringContainsString('$this->applyMembershipAdministrativeAccess($tenant, $payload);', $source);
        self::assertStringContainsString("'tenant_app_admin' => true", $source);
        self::assertStringContainsString("'menuDataAdmin' => ['result' => \$menuAdmin]", $source);
    }

    public function testLegacyAppAdminOverlayAcceptsPersonnelProfilesOnly(): void
    {
        $reflection = new ReflectionClass(LegacyTenantSessionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('canCurrentUserReceiveAppAdminAccess');

        foreach ([1, 2, 3] as $personnelType) {
            service('session')->set('utente_sess', (object) [
                'id_personale' => 42,
                'tipo_pers' => $personnelType,
            ]);
            self::assertTrue($method->invoke($service));
        }

        service('session')->set('utente_sess', (object) [
            'id_client' => 42,
            'tipo_pers' => 0,
        ]);
        self::assertFalse($method->invoke($service));
        service('session')->remove('utente_sess');
    }
}
