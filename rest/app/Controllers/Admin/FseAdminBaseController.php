<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\TenantContext;
use App\Services\FseFeatureService;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;

abstract class FseAdminBaseController extends BaseController
{
    protected TenantCatalogService $tenantCatalog;
    protected FseFeatureService $featureService;

    public function __construct()
    {
        helper(['portal', 'admin_menu', 'session_auth']);
        $this->tenantCatalog = new TenantCatalogService();
        $this->featureService = new FseFeatureService();
    }

    protected function ensureAccess()
    {
        if (!session_access_is_confirmed()) return $this->redirectToLogin();
        $me = session()->get('utente_sess');
        $isPlatformAdmin = session()->get('is_admin') === true || (bool) session()->get('platform_is_admin') || (is_object($me) && (int) ($me->tipo ?? 0) === 1);
        $scope = $this->resolveTenantScope();
        $tenantAdmin = in_array(strtolower((string) ($scope['tenant_role'] ?? '')), ['tenant_master', 'tenant_admin'], true)
            || session_has_tenant_app_admin_access();
        if (!$isPlatformAdmin && !$tenantAdmin) return redirect()->to(site_url('/'))->with('error', 'La console FSE è riservata ai responsabili dello spazio.');
        if ((int) ($scope['tenant_id'] ?? 0) <= 0) return redirect()->to(site_url('admin'))->with('error', 'Spazio FSE non risolto.');
        if (empty($scope['feature_enabled'])) return redirect()->to(site_url('admin'))->with('error', 'Modulo FSE 2.0 non attivo per questo spazio.');
        return null;
    }

    /** @return array<string,mixed> */
    protected function resolveTenantScope(): array
    {
        $context = $this->currentTenantContext();
        if ($context !== null) {
            return ['tenant_id' => $context->tenantId, 'tenant_name' => $context->tenantName, 'tenant_role' => $context->tenantRole,
                'feature_enabled' => $this->featureService->isEnabledForContext($context) || $this->featureService->allowsLocalTestingBypass($context)];
        }
        $tenant = $this->tenantCatalog->resolveCurrentRuntimeTenant();
        $id = (int) ($tenant['id_tenant'] ?? 0);
        return ['tenant_id' => $id, 'tenant_name' => (string) ($tenant['tenant_name'] ?? ''), 'tenant_role' => '', 'feature_enabled' => $this->featureService->isEnabledForTenant($id)];
    }

    protected function currentTenantContext(): ?TenantContext { return (new TenantContextService())->getCurrentTenant(); }
    protected function currentAdminUserId(): int
    {
        $me = session()->get('utente_sess');
        return is_object($me) && !empty($me->id_user) ? (int) $me->id_user : (int) (session()->get('id_user') ?? 0);
    }
    /** @return array<int,array<string,mixed>> */
    protected function adminMenuItems(): array
    {
        $menu = session()->get('menuDataAdmin');
        return is_array($menu['result'] ?? null) ? $menu['result'] : (is_array(session()->get('header_menu_items')) ? session()->get('header_menu_items') : []);
    }
}
