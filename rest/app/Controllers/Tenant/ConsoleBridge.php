<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Models\PlatformUsersModel;
use App\Services\PendingNotificationNavigationService;
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
                $bootstrap = (new TenantAppSessionBootstrapService())->bootstrap($platformUserId, $tenantId);
                $session = session();
                [$tenantId, $tenantRole] = $this->readTenantContext($session);
                $sessionConfirmed = session_access_is_confirmed();

                if (!$sessionConfirmed && (bool) ($session->get('isLoggedIn') ?? false) === true) {
                    $this->capturePendingConsoleNavigation();
                    return redirect()->to((string) ($bootstrap['redirectUrl'] ?? site_url('auth')));
                }
            } catch (\Throwable $e) {
                log_message('warning', '[ConsoleBridge] bootstrap fallito: {message}', [
                    'message' => $e->getMessage(),
                ]);

                $sessionConfirmed = session_access_is_confirmed();
                if (!$sessionConfirmed) {
                    $this->capturePendingConsoleNavigation();
                    return redirect()->to(portal_public_access_url('login'))
                        ->with('error', 'Sessione spazio non disponibile. Effettua di nuovo il login.');
                }
            }
        } elseif (!$sessionConfirmed) {
            $this->capturePendingConsoleNavigation();
            return redirect()->to(portal_public_access_url('login'))
                ->with('error', 'Sessione spazio non disponibile. Effettua di nuovo il login.');
        }

        if ($target === 'admin' && !$this->canAccessOperationalProfile($tenantRole)) {
            if ($sessionConfirmed) {
                return redirect()->to($this->buildConsoleRedirectUrl('agenda'))
                    ->with('error', 'Profilo operativo non disponibile per questo account.');
            }

            return redirect()->to(portal_public_access_url('login'))
                ->with('error', 'Sessione spazio non disponibile. Effettua di nuovo il login.');
        }

        if ($target === 'admin') {
            if (!$this->hasAdminAccess()) {
                return redirect()->to($this->buildConsoleRedirectUrl('agenda'))
                    ->with('error', 'Profilo operativo non disponibile per questo account.');
            }

            return redirect()->to($this->buildConsoleRedirectUrl('admin'));
        }

        return redirect()->to($this->buildConsoleRedirectUrl('agenda'));
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
        return session_has_operational_profile_access();
    }

    private function hasAdminAccess(): bool
    {
        return session_has_operational_profile_access();
    }

    private function capturePendingConsoleNavigation(): void
    {
        (new PendingNotificationNavigationService())->captureFromRequest($this->request);
    }

    private function buildConsoleRedirectUrl(string $path): string
    {
        $url = site_url($path);

        if ($path !== 'agenda') {
            return $url;
        }

        return $this->appendCurrentQueryToUrl($url);
    }

    private function appendCurrentQueryToUrl(string $url): string
    {
        $currentQuery = trim((string) $this->request->getUri()->getQuery());
        if ($currentQuery === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $existingQuery = [];
        parse_str((string) ($parts['query'] ?? ''), $existingQuery);

        $incomingQuery = [];
        parse_str($currentQuery, $incomingQuery);

        $mergedQuery = array_replace($existingQuery, $incomingQuery);
        $rebuiltQuery = http_build_query($mergedQuery);

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? (':' . (int) $parts['port']) : '';
        $path = (string) ($parts['path'] ?? '/');
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? ('#' . $parts['fragment']) : '';

        if ($host === '') {
            return $url;
        }

        return $scheme . '://' . $host . $port . $path . ($rebuiltQuery !== '' ? ('?' . $rebuiltQuery) : '') . $fragment;
    }
}
