<?php

namespace App\Controllers\Admin;

use App\Services\BillingDocumentService;

class BillingScheduleController extends BillingAdminBaseController
{
    private BillingDocumentService $documents;

    public function __construct()
    {
        parent::__construct();
        $this->documents = new BillingDocumentService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);

        return view('admin/billing/schedule', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'schedule' => $this->documents->buildPaymentScheduleForTenant($tenantId),
            'success' => session()->getFlashdata('success'),
            'warning' => session()->getFlashdata('warning'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }
}
