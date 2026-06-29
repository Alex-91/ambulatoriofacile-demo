<?php

namespace App\Filters;

use App\Services\PlatformAdminAccessService;
use App\Services\PlatformImpersonationService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class PlatformImpersonationFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $service = new PlatformImpersonationService();
        if (!$service->isImpersonating()) {
            return null;
        }

        $path = trim($request->getUri()->getPath(), '/');

        $platformAdminAccess = new PlatformAdminAccessService();
        if (!$platformAdminAccess->canAccessPlatformConsole()) {
            $service->stopImpersonation('platform_access_revoked', false);

            return redirect()
                ->to(site_url('login'))
                ->with('login_error', 'Autorizzazione master non piu valida. Effettua di nuovo il login.');
        }

        if ($path === 'logout' || $path === 'admin/personale/logout') {
            $service->stopImpersonation('logout', false);
            return null;
        }

        if (!$service->isExpired()) {
            return null;
        }

        $result = $service->stopImpersonation('timeout');

        return redirect()
            ->to((string) ($result['redirectUrl'] ?? site_url('login')))
            ->with('success', 'Accesso delegato scaduto automaticamente. Sei tornato alla console piattaforma.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
