<?php

namespace App\Filters;

use App\Libraries\TenantFeatureRegistry;
use App\Services\TenantContextService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class TenantFeatureAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('session_auth');

        if (!session_access_is_confirmed()) {
            return null;
        }

        $featureKey = $this->resolveFeatureKey($request);
        if ($featureKey === null) {
            return null;
        }

        $tenantContext = new TenantContextService();
        if (!$tenantContext->hasCurrentTenant()) {
            return null;
        }

        if ($tenantContext->currentTenantAllows($featureKey)) {
            return null;
        }

        $isAjax = strtolower((string) ($request->getHeaderLine('X-Requested-With'))) === 'xmlhttprequest';
        if ($isAjax) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'success' => false,
                    'message' => 'Modulo non disponibile per questo spazio cliente.',
                ]);
        }

        return redirect()->to(site_url('/'))->with('error', 'Modulo non disponibile per questo spazio cliente.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function resolveFeatureKey(RequestInterface $request): ?string
    {
        $path = trim($request->getUri()->getPath(), '/');
        return TenantFeatureRegistry::resolveFeatureKeyFromRoutePath($path);
    }
}
