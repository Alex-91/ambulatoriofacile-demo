<?php

namespace App\Controllers\Admin;

use App\Services\BillingTsModuleStatusService;
use App\Services\TsDocumentService;
use App\Services\TsProfileService;

class TsDashboardController extends TsAdminBaseController
{
    private TsDocumentService $documents;
    private BillingTsModuleStatusService $moduleStatus;
    private TsProfileService $profiles;

    public function __construct()
    {
        parent::__construct();
        $this->documents = new TsDocumentService();
        $this->moduleStatus = new BillingTsModuleStatusService();
        $this->profiles = new TsProfileService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();

        return view('admin/ts/dashboard', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'dashboard' => $this->documents->buildDashboardForTenant((int) ($tenantScope['tenant_id'] ?? 0)),
            'profile' => $this->profiles->getDefaultProfileForTenant((int) ($tenantScope['tenant_id'] ?? 0)),
            'moduleStatus' => $this->moduleStatus->describe(
                $this->currentTenantContext(),
                (int) ($tenantScope['tenant_id'] ?? 0)
            ),
        ]);
    }
}
