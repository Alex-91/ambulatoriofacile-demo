<?php

namespace App\Controllers\Admin;

use App\Services\BillingTsModuleStatusService;
use App\Services\BillingDocumentSettingsService;
use App\Services\BillingDocumentService;

class BillingDashboardController extends BillingAdminBaseController
{
    private BillingTsModuleStatusService $moduleStatus;
    private BillingDocumentSettingsService $documentSettings;
    private BillingDocumentService $documents;

    public function __construct()
    {
        parent::__construct();
        $this->moduleStatus = new BillingTsModuleStatusService();
        $this->documentSettings = new BillingDocumentSettingsService();
        $this->documents = new BillingDocumentService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();

        return view('admin/billing/dashboard', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'moduleStatus' => $this->moduleStatus->describe(
                $this->currentTenantContext(),
                (int) ($tenantScope['tenant_id'] ?? 0)
            ),
            'documentSettings' => $this->documentSettings->resolveTenantSettings(
                (int) ($tenantScope['tenant_id'] ?? 0)
            ),
            'documentsDashboard' => $this->documents->buildDashboardForTenant(
                (int) ($tenantScope['tenant_id'] ?? 0)
            ),
        ]);
    }
}
