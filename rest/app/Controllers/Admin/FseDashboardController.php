<?php

namespace App\Controllers\Admin;

use App\Services\FseDocumentService;
use App\Services\FseProfileService;
use App\Services\FseHealthcheckService;
use App\Services\FseOfflineLab;

class FseDashboardController extends FseAdminBaseController
{
    public function index()
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $scope = $this->resolveTenantScope();
        $tenantId = (int) $scope['tenant_id'];
        return view('admin/fse/dashboard', [
            'menu_items' => $this->adminMenuItems(), 'tenantScope' => $scope,
            'dashboard' => (new FseDocumentService())->buildDashboardForTenant($tenantId),
            'profile' => (new FseProfileService())->getDefaultProfileForTenant($tenantId),
            'readiness' => session()->getFlashdata('fse_readiness') ?? (new FseHealthcheckService())->runForTenant($tenantId, false, false),
            'success' => session()->getFlashdata('success'), 'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function healthcheck()
    {
        if ($guard = $this->ensureAccess()) return $guard;
        $result = (new FseHealthcheckService())->runForTenant((int) $this->resolveTenantScope()['tenant_id'], true, false);
        return redirect()->to(site_url('admin/fse2'))->with('fse_readiness', $result);
    }

    public function offline()
    {
        if ($guard = $this->ensureAccess()) return $guard;
        return view('admin/fse/offline', ['menu_items' => $this->adminMenuItems(), 'report' => (new FseOfflineLab())->run()]);
    }

    public function offlineReport()
    {
        if ($guard = $this->ensureAccess()) return $guard;
        return $this->response->download('fse-collaudo-SOLO-SIMULAZIONE.json', json_encode((new FseOfflineLab())->run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
