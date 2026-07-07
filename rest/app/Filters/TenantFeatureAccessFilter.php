<?php

namespace App\Filters;

use App\Services\BillingFeatureService;
use App\Libraries\TenantFeatureRegistry;
use App\Services\TenantContextService;
use App\Services\TsFeatureService;
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

        $context = $tenantContext->getCurrentTenant();
        $billingFeatureService = new BillingFeatureService();
        if ($billingFeatureService->allowsLocalTestingBypass($context, $featureKey)) {
            log_message('warning', '[TenantFeatureAccessFilter] bypass locale Billing attivo | path={path} | feature={featureKey} | tenant_id={tenantId} | tenant_role={tenantRole}', [
                'path' => trim((string) $request->getUri()->getPath(), '/'),
                'featureKey' => $featureKey,
                'tenantId' => (int) ($context->tenantId ?? 0),
                'tenantRole' => trim((string) ($context->tenantRole ?? '')),
            ]);

            return null;
        }

        $tsFeatureService = new TsFeatureService();
        if ($tsFeatureService->allowsLocalTestingBypass($context, $featureKey)) {
            log_message('warning', '[TenantFeatureAccessFilter] bypass locale TS attivo | path={path} | feature={featureKey} | tenant_id={tenantId} | tenant_role={tenantRole}', [
                'path' => trim((string) $request->getUri()->getPath(), '/'),
                'featureKey' => $featureKey,
                'tenantId' => (int) ($context->tenantId ?? 0),
                'tenantRole' => trim((string) ($context->tenantRole ?? '')),
            ]);

            return null;
        }

        log_message('warning', '[TenantFeatureAccessFilter] accesso negato | path={path} | feature={featureKey} | tenant_id={tenantId} | tenant_role={tenantRole}', [
            'path' => trim((string) $request->getUri()->getPath(), '/'),
            'featureKey' => $featureKey,
            'tenantId' => (int) ($context->tenantId ?? 0),
            'tenantRole' => trim((string) ($context->tenantRole ?? '')),
        ]);

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
