<?php
namespace App\Controllers\Admin;

use App\Services\AccountantDelegationService;

class AccountantDelegationsController extends BillingAdminBaseController
{
    protected function ensureAccess()
    {
        if ($guard = parent::ensureAccess()) { return $guard; }
        $context = $this->currentTenantContext();
        if (!$context || $context->tenantRole !== 'tenant_master' || !(new \App\Services\AccountantTenantAccess())->enabled($context->tenantId)) {
            return $this->response->setStatusCode(403)->setBody('Account del medico non attivo.');
        }
        return null;
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) { return $guard; }
        $service = new AccountantDelegationService();
        $doctor = $this->currentTenantContext();
        return view('accountant/manage', ['ready' => $service->ready(), 'doctor' => $doctor,
            'menu_items' => $this->adminMenuItems(),
            'delegations' => $service->ready() ? $service->forDoctor($doctor->tenantId) : [],
            'history' => $service->ready() ? $service->history($doctor->tenantId) : []]);
    }

    public function invite()
    {
        if ($guard = $this->ensureAccess()) { return $guard; }
        $service = new AccountantDelegationService();
        if (!$service->ready()) { return $this->response->setStatusCode(503)->setBody('Area commercialista non ancora attivata.'); }
        $doctor = $this->currentTenantContext();
        try {
            $invite = $service->invite($doctor->tenantId, $doctor->tenantName,
                (string) $this->request->getPost('email'), (int) $this->request->getPost('year'));
            $sent = $service->sendLink($invite['email'], site_url('commercialista/conferma') . '?invito=' . $invite['token'], true);
            if (!$sent) { throw new \RuntimeException('Invio non riuscito. Controlla la configurazione email e ripeti l’invito.'); }
            return redirect()->to(site_url('admin/fatturazione-commercialista'))->with('success', 'Invito inviato. La delega si attiva quando viene accettato.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/fatturazione-commercialista'))->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/fatturazione-commercialista'))->with('error', 'Invito non consegnato. Verifica la configurazione email e riprova tra un minuto.');
        }
    }

    public function revoke(int $id)
    {
        if ($guard = $this->ensureAccess()) { return $guard; }
        $service = new AccountantDelegationService();
        if (!$service->ready()) { return $this->response->setStatusCode(503); }
        $service->revoke($this->currentTenantContext()->tenantId, $id);
        return redirect()->to(site_url('admin/fatturazione-commercialista'))->with('success', 'Delega revocata. L’accesso ai documenti è stato bloccato.');
    }
}
