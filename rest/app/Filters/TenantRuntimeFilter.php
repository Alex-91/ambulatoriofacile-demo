<?php

namespace App\Filters;

use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\TenantDatabaseConnector;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class TenantRuntimeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('portal');
        if ($this->shouldSkipPath($request)) {
            return null;
        }

        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return null;
        }

        $rawContext = session()->get(TenantContextService::SESSION_KEY);
        $tenantId = (int) (($rawContext['tenant_id'] ?? 0));
        if ($tenantId <= 0) {
            return null;
        }

        try {
            $catalog = new TenantCatalogService();
            $tenant = $catalog->getTenantById($tenantId);
            if (!$tenant || (int) ($tenant['is_active'] ?? 0) !== 1) {
                throw new \RuntimeException('Tenant non disponibile.');
            }

            $config = (new TenantDatabaseConnector())->buildConnectionConfig($tenant);
            $dbConfig = config(\Config\Database::class);
            $dbConfig->default = $config;
        } catch (\Throwable $e) {
            log_message('error', 'TenantRuntimeFilter failed: ' . $e->getMessage());
            $this->clearTenantSession();
            return redirect()->to(portal_public_access_url('login'))->with('error', 'Spazio cliente non disponibile. Effettua di nuovo il login.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function shouldSkipPath(RequestInterface $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');

        if ($path === '') {
            return false;
        }

        $prefixes = [
            'login',
            'logout',
            'register',
            'reset',
            'auth',
            'demo',
            'api/doctors',
            'checkUsername',
            'checkMessaggio',
            'checkMessaggio.php',
            'aggiornaNoteApp',
            'aggiornaNoteApp.php',
            'checkAppMultiplo',
            'checkAppMultiplo.php',
            'otp',
            'password/scaduta',
            'tenant',
            'admin/piattaforma/spazi-clienti',
        ];

        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function clearTenantSession(): void
    {
        session()->remove([
            TenantContextService::SESSION_KEY,
            'isLoggedIn',
            'isLoggedInConfirmed',
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
            'schede_access_map',
            'schede_data',
            'nav_refresh_meta',
            'platform_user_id',
            'platform_user_email',
            \App\Services\TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY,
            'loginSource',
        ]);
    }
}
