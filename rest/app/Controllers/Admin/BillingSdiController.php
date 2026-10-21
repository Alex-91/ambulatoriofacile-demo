<?php

namespace App\Controllers\Admin;

/** Presentation workspace: never reads invoices or invokes a transmission service. */
final class BillingSdiController extends BillingAdminBaseController
{
    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        return view('admin/billing/sdi_channels', [
            'menu_items' => $this->adminMenuItems(),
        ]);
    }
}
