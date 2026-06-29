<?php

use App\Models\PlatformUsersModel;
use App\Services\PlatformAuthService;
use App\Services\TenantCatalogService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PlatformAuthServiceTest extends CIUnitTestCase
{
    public function testAuthenticateAcceptsPasswordWithTrailingWhitespaceWhenStoredHashWasTrimmed(): void
    {
        $plainPassword = 'Af!Reset123aA';

        $usersModel = new class($plainPassword) extends PlatformUsersModel {
            private array $platformUser;

            public function __construct(string $plainPassword)
            {
                $this->platformUser = [
                    'id_platform_user' => 99,
                    'email' => 'frosinsimo@gmail.com',
                    'status' => 'active',
                    'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
                ];
            }

            public function findByEmailInsensitive(string $email): ?array
            {
                return strtolower(trim($email)) === 'frosinsimo@gmail.com'
                    ? $this->platformUser
                    : null;
            }
        };

        $catalog = new class extends TenantCatalogService {
            public function __construct()
            {
            }

            public function listTenantsForPlatformUser(int $platformUserId): array
            {
                return [];
            }
        };

        $service = new PlatformAuthService($usersModel, $catalog);

        $result = $service->authenticate('FrosinSimo@gmail.com', $plainPassword . ' ');

        $this->assertIsArray($result);
        $this->assertSame(99, (int) ($result['platform_user']['id_platform_user'] ?? 0));
    }

    public function testAuthenticateStillRejectsWrongPassword(): void
    {
        $plainPassword = 'Af!Reset123aA';

        $usersModel = new class($plainPassword) extends PlatformUsersModel {
            private array $platformUser;

            public function __construct(string $plainPassword)
            {
                $this->platformUser = [
                    'id_platform_user' => 100,
                    'email' => 'frosinsimo@gmail.com',
                    'status' => 'active',
                    'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
                ];
            }

            public function findByEmailInsensitive(string $email): ?array
            {
                return strtolower(trim($email)) === 'frosinsimo@gmail.com'
                    ? $this->platformUser
                    : null;
            }
        };

        $catalog = new class extends TenantCatalogService {
            public function __construct()
            {
            }

            public function listTenantsForPlatformUser(int $platformUserId): array
            {
                return [];
            }
        };

        $service = new PlatformAuthService($usersModel, $catalog);

        $this->assertNull($service->authenticate('frosinsimo@gmail.com', $plainPassword . 'x'));
    }
}
