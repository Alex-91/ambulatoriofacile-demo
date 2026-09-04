<?php

use App\Services\PersonnelAdminAccessService;
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
}
