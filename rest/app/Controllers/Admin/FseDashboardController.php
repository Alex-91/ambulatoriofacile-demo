<?php

namespace App\Controllers\Admin;

use App\Services\FseDocumentService;
use App\Services\FseProfileService;

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
            'success' => session()->getFlashdata('success'), 'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }
}
