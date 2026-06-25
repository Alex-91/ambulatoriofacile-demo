<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Models\PlatformUsersModel;
use App\Services\TenantAppSessionBootstrapService;
use App\Services\TenantContextService;

class ConsoleBridge extends BaseController
{
    public function agenda()
    {
        return $this->redirectToConsole('agenda');
    }

    public function operationalProfile()
    {
        return $this->redirectToConsole('admin');
    }

    private function redirectToConsole(string $target)
    {
        helper(['portal', 'session_auth']);

        $session = session();
        [$tenantId, $tenantRole] = $this->readTenantContext($session);
        $sessionConfirmed = session_access_is_confirmed();
        $platformUserId = $this->resolvePlatformUserId();

        if ($platformUserId > 0 && $tenantId > 0) {
            try {
                (new TenantAppSessionBootstrapService())->bootstrap($platformUserId, $tenantId);
                $session = session();
                [$tenantId, $tenantRole] = $this->readTenantContext($session);
                $sessionConfirmed = session_access_is_confirmed();
            } catch (\Throwable $e) {
                log_message('warning', '[ConsoleBridge] bootstrap fallito: {message}', [
                    'message' => $e->getMessage(),
                ]);

                $sessionConfirmed = session_access_is_confirmed();
                if (!$sessionConfirmed) {
                    return redirect()->to(portal_public_access_url('login'))
                        ->with('error', 'Sessione spazio non disponibile. Effettua di nuovo il login.');
                }
            }
        } elseif (!$sessionConfirmed) {
            return redirect()->to(portal_public_access_url('login'))
                ->with('error', 'Sessione spazio non disponibile. Effettua di nuovo il login.');
        }

        if ($target === 'admin' && !$this->canAccessOperationalProfile($tenantRole)) {
            if ($sessionConfirmed) {
                return redirect()->to(site_url('agenda'))
                    ->with('error', 'Profilo operativo non disponibile per questo account.');
            }

            return redirect()->to(portal_public_access_url('login'))
                ->with('error', 'Sessione spazio non disponibile. Effettua di nuovo il login.');
        }

        if ($target === 'admin') {
            if (!$this->hasAdminAccess()) {
                return redirect()->to(site_url('agenda'))
                    ->with('error', 'Profilo operativo non disponibile per questo account.');
            }

            return redirect()->to(site_url('admin'));
        }

        return redirect()->to(site_url('agenda'));
    }

    /**
     * @return array{0:int,1:string}
     */
    private function readTenantContext($session): array
    {
        $rawTenantContext = $session->get(TenantContextService::SESSION_KEY);
        $tenantId = is_array($rawTenantContext) ? (int) ($rawTenantContext['tenant_id'] ?? 0) : 0;
        $tenantRole = is_array($rawTenantContext)
            ? strtolower(trim((string) ($rawTenantContext['tenant_role'] ?? '')))
            : '';

        return [$tenantId, $tenantRole];
    }

    private function resolvePlatformUserId(): int
    {
        $session = session();
        $platformUserId = (int) ($session->get('platform_user_id') ?? 0);
        if ($platformUserId > 0) {
            return $platformUserId;
        }

        $platformUserEmail = trim((string) ($session->get('platform_user_email') ?? ''));
        if ($platformUserEmail === '') {
            return 0;
        }

        $platformUser = (new PlatformUsersModel())->findByEmailInsensitive($platformUserEmail);
        return (int) ($platformUser['id_platform_user'] ?? 0);
    }

    private function canAccessOperationalProfile(string $tenantRole): bool
    {
        if (in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return true;
        }

        return $this->hasAdminAccess();
    }

    private function hasAdminAccess(): bool
    {
        $session = session();
        $currentUser = $session->get('utente_sess');

        return $session->get('is_admin') === true
            || (int) ($session->get('admin') ?? 0) === 1
            || (bool) ($session->get('tenant_app_admin') ?? false) === true
            || (is_object($currentUser) && (int) ($currentUser->tipo ?? 0) === 1);
    }
}
