<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\TenantContext;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\TsFeatureService;

abstract class TsAdminBaseController extends BaseController
{
    protected TenantCatalogService $tenantCatalog;
    protected TsFeatureService $featureService;

    public function __construct()
    {
        helper(['portal', 'admin_menu', 'session_auth']);
        $this->tenantCatalog = new TenantCatalogService();
        $this->featureService = new TsFeatureService();
    }

    protected function ensureAccess()
    {
        if (!session_access_is_confirmed()) {
            return $this->redirectToLogin();
        }

        $me = session()->get('utente_sess');
        $isPlatformAdminSession = session()->get('is_admin') === true
            || (bool) (session()->get('platform_is_admin') ?? false) === true
            || (is_object($me) && (int) ($me->tipo ?? 0) === 1);

        $tenantScope = $this->resolveTenantScope();
        $tenantRole = strtolower(trim((string) ($tenantScope['tenant_role'] ?? '')));
        $hasTenantOperationalAccess = in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)
            || session_has_tenant_app_admin_access();

        if (!$isPlatformAdminSession && !$hasTenantOperationalAccess) {
            return redirect()->to(site_url('/'))
                ->with('error', 'La console operativa Sistema TS è disponibile solo per admin piattaforma o responsabili dello spazio.');
        }

        if ((int) ($tenantScope['tenant_id'] ?? 0) <= 0) {
            $fallbackUrl = $hasTenantOperationalAccess ? portal_tenant_space_url('sistema-ts') : site_url('admin');

            return redirect()->to($fallbackUrl)->with('error', 'Spazio TS non risolto per questa sessione.');
        }

        if (empty($tenantScope['feature_enabled'])) {
            $fallbackUrl = $hasTenantOperationalAccess ? portal_tenant_space_url('sistema-ts') : site_url('admin');

            return redirect()->to($fallbackUrl)->with('error', 'Sistema TS non attivo per questo spazio.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveTenantScope(): array
    {
        $context = $this->currentTenantContext();
        if ($context !== null) {
            $featureEnabled = $this->featureService->isEnabledForContext($context)
                || $this->featureService->allowsLocalTestingBypass($context);

            return [
                'tenant_id' => $context->tenantId,
                'tenant_name' => $context->tenantName,
                'tenant_role' => $context->tenantRole,
                'feature_enabled' => $featureEnabled,
                'source' => 'session',
            ];
        }

        $runtimeTenant = $this->tenantCatalog->resolveCurrentRuntimeTenant();

        return [
            'tenant_id' => (int) ($runtimeTenant['id_tenant'] ?? 0),
            'tenant_name' => trim((string) ($runtimeTenant['tenant_name'] ?? '')),
            'tenant_role' => '',
            'feature_enabled' => $runtimeTenant !== null
                ? $this->featureService->isEnabledForTenant((int) ($runtimeTenant['id_tenant'] ?? 0))
                : false,
            'source' => 'runtime',
        ];
    }

    protected function currentTenantContext(): ?TenantContext
    {
        return (new TenantContextService())->getCurrentTenant();
    }

    protected function currentAdminUserId(): int
    {
        $me = session()->get('utente_sess');
        if (is_object($me) && !empty($me->id_user)) {
            return (int) $me->id_user;
        }

        return (int) (session()->get('id_user') ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function adminMenuItems(): array
    {
        $menuAdmin = session()->get('menuDataAdmin');
        $adminItems = is_array($menuAdmin['result'] ?? null) ? $menuAdmin['result'] : [];
        if ($adminItems !== []) {
            return $adminItems;
        }

        $headerItems = session()->get('header_menu_items');

        return is_array($headerItems) ? $headerItems : [];
    }
}
