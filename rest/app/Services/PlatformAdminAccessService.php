<?php

namespace App\Services;

use App\Models\PlatformUsersModel;

class PlatformAdminAccessService
{
    private PlatformUsersModel $usersModel;
    private PlatformAuthService $platformAuth;

    public function __construct(?PlatformUsersModel $usersModel = null, ?PlatformAuthService $platformAuth = null)
    {
        $this->usersModel = $usersModel ?? new PlatformUsersModel();
        $this->platformAuth = $platformAuth ?? new PlatformAuthService();
    }

    /**
     * @param array<string, mixed> $platformUser
     */
    public function isPlatformAdmin(array $platformUser): bool
    {
        $email = strtolower(trim((string) ($platformUser['email'] ?? '')));
        if ($email === '') {
            return false;
        }

        if ((int) ($platformUser['is_platform_admin'] ?? 0) === 1) {
            return true;
        }

        return in_array($email, $this->configuredMasterEmails(), true);
    }

    /**
     * @param array<string, mixed> $platformUser
     * @param array<int, array<string, mixed>> $memberships
     */
    public function bootstrapSession(array $platformUser, array $memberships = []): void
    {
        helper('portal');

        $platformUserId = (int) ($platformUser['id_platform_user'] ?? 0);
        if ($platformUserId <= 0 || !$this->isPlatformAdmin($platformUser)) {
            throw new \RuntimeException('Account non autorizzato alla console piattaforma.');
        }

        $this->usersModel->update($platformUserId, [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        $session = session();
        $session->regenerate(true);
        $session->remove([
            'is_admin_arrow_login',
            'admin_arrow_username',
            'isLoggedIn',
            'isLoggedInConfirmed',
            'is_admin',
            'admin',
            'userId',
            'id_user',
            'username',
            'tipoUser',
            'utente_sess',
            'nome_visualizzato',
            'cellulare',
            'menuData',
            'menuAgenda',
            'menuDataAdmin',
            'header_nav_items',
            'header_menu_items',
            'badge_posta_unread',
            'badge_chat_unread',
            'nav_refresh_meta',
            'schede_access_map',
            'schede_data',
            'otp_identity',
            TenantContextService::SESSION_KEY,
            'platform_user_id',
            'platform_user_email',
            'platform_is_admin',
            TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY,
            PlatformAccessService::SESSION_KEY_PENDING_PASSWORD_SETUP,
        ]);

        $displayName = trim((string) ($platformUser['first_name'] ?? '') . ' ' . (string) ($platformUser['last_name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($platformUser['email'] ?? 'Account master');
        }

        $session->set([
            'isLoggedIn' => true,
            'isLoggedInConfirmed' => true,
            'platform_user_id' => $platformUserId,
            'platform_user_email' => (string) ($platformUser['email'] ?? ''),
            'platform_is_admin' => true,
            'nome_visualizzato' => $displayName,
            'loginSource' => 'platform_console',
            TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY => $this->platformAuth->buildSelectableTenants($memberships),
        ]);
    }

    public function canAccessPlatformConsole(): bool
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return false;
        }

        $platformUser = $this->currentPlatformUser();
        if (!is_array($platformUser)) {
            return false;
        }

        return $this->isPlatformAdmin($platformUser);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentPlatformUser(): ?array
    {
        $platformUserId = (int) (session()->get('platform_user_id') ?? 0);
        if ($platformUserId <= 0) {
            return null;
        }

        $platformUser = $this->usersModel->find($platformUserId);
        if (!is_array($platformUser)) {
            return null;
        }

        return $platformUser;
    }

    /**
     * @return array<int, string>
     */
    public function configuredMasterEmails(): array
    {
        $raw = trim((string) env('PLATFORM_MASTER_EMAILS', ''));
        if ($raw === '') {
            return [];
        }

        $emails = preg_split('/[\s,;]+/', $raw) ?: [];
        $normalized = [];

        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (!in_array($email, $normalized, true)) {
                $normalized[] = $email;
            }
        }

        return $normalized;
    }
}
