<?php

namespace App\Controllers\Admin;

use App\Services\TsDocumentService;

class TsDashboardController extends TsAdminBaseController
{
    private TsDocumentService $documents;

    public function __construct()
    {
        parent::__construct();
        $this->documents = new TsDocumentService();
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
        ]);
    }
}
