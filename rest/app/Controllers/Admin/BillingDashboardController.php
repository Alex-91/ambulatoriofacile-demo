<?php
namespace App\Controllers\Admin;

class BillingDashboardController extends BillingAdminBaseController
{
    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }
        return redirect()->to(site_url('admin/fatturazione-documenti'));
    }
}
