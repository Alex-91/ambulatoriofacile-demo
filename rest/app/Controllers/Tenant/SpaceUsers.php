<?php

namespace App\Controllers\Tenant;

use App\Controllers\BaseController;
use App\Services\PlatformAccessService;
use App\Services\TenantCatalogService;
use App\Services\TenantContextService;
use App\Services\TenantProvisioningService;

class SpaceUsers extends BaseController
{
    private TenantContextService $tenantContext;

    public function __construct()
    {
        helper('portal');
        $this->tenantContext = new TenantContextService();
    }

    public function index()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        $tenantId = $context->tenantId;
        $service = new TenantProvisioningService();
        $tenant = (new TenantCatalogService())->getTenantById($tenantId);

        return view('tenant/space_users', [
            'tenantContext' => $context,
            'tenant' => $tenant,
            'tenantMembers' => $service->listTenantMembers($tenantId),
            'tenantCapacity' => $service->getTenantUserCapacity($tenantId),
            'memberSuccess' => session()->getFlashdata('member_success'),
            'memberErrors' => session()->getFlashdata('member_errors') ?? [],
            'memberTempPassword' => session()->getFlashdata('tenant_member_temp_password'),
            'memberWarnings' => session()->getFlashdata('member_warnings') ?? [],
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        $tenantId = $context->tenantId;
        $payload = [
            'id_platform_user_tenant' => (int)($this->request->getPost('member_id_platform_user_tenant') ?? 0),
            'email' => (string)$this->request->getPost('member_email'),
            'first_name' => (string)$this->request->getPost('member_first_name'),
            'last_name' => (string)$this->request->getPost('member_last_name'),
            'tenant_role' => (string)$this->request->getPost('member_tenant_role'),
            'app_user_id' => (int)($this->request->getPost('member_app_user_id') ?? 0),
            'password' => (string)$this->request->getPost('member_password'),
            'is_default' => (int)($this->request->getPost('member_is_default') ?? 0) === 1 ? 1 : 0,
        ];

        $service = new TenantProvisioningService();

        try {
            $result = $service->saveTenantMember($tenantId, $payload);
            $messages = [];
            $warnings = [];
            $tempPassword = (string)(($result['platform_user']['temporary_password'] ?? '') ?: '');
            if ($tempPassword !== '') {
                session()->setFlashdata('tenant_member_temp_password', $tempPassword);
            }

            $messages[] = (($result['mode'] ?? 'created') === 'updated')
                ? 'Utente dello spazio aggiornato con successo.'
                : 'Utente dello spazio aggiunto con successo.';

            if ((int) ($this->request->getPost('member_send_access_email') ?? 0) === 1) {
                try {
                    (new PlatformAccessService())->sendMembershipAccessEmail((int) ($result['membership']['id_platform_user_tenant'] ?? 0), 'tenant_member_save');
                    $messages[] = 'Email di accesso inviata all utente.';
                } catch (\Throwable $e) {
                    $warnings[] = 'Utente salvato, ma l invio accesso non e riuscito: ' . $e->getMessage();
                }
            }

            $redirect = redirect()
                ->to(site_url('spazio/utenti'))
                ->with('member_success', implode(' ', $messages));

            if ($warnings !== []) {
                $redirect = $redirect->with('member_warnings', $warnings);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\SpaceUsers::save failed: ' . $e->getMessage());

            return redirect()
                ->to(site_url('spazio/utenti'))
                ->withInput()
                ->with('member_errors', ['generic' => $e->getMessage()]);
        }
    }

    public function sendAccess()
    {
        if ($guard = $this->ensureAllowed()) {
            return $guard;
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        $membershipId = (int) ($this->request->getPost('member_id_platform_user_tenant') ?? 0);
        $membership = null;
        foreach ((new TenantProvisioningService())->listTenantMembers($context->tenantId) as $row) {
            if ((int) ($row['id_platform_user_tenant'] ?? 0) === $membershipId) {
                $membership = $row;
                break;
            }
        }

        if (!$membership) {
            return redirect()->to(site_url('spazio/utenti'))->with('member_errors', ['generic' => 'Utente dello spazio non trovato.']);
        }

        try {
            (new PlatformAccessService())->sendMembershipAccessEmail($membershipId, 'tenant_member_action');

            return redirect()
                ->to(site_url('spazio/utenti'))
                ->with('member_success', 'Email di accesso inviata con successo.');
        } catch (\Throwable $e) {
            log_message('error', 'Tenant\\SpaceUsers::sendAccess failed: ' . $e->getMessage());

            return redirect()
                ->to(site_url('spazio/utenti'))
                ->with('member_errors', ['generic' => $e->getMessage()]);
        }
    }

    private function ensureAllowed()
    {
        if ((bool)(session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        $context = $this->tenantContext->getCurrentTenant();
        if ($context === null) {
            return redirect()->to(site_url('/'));
        }

        if (!$context->allows('staff_management')) {
            return redirect()->to(site_url('/'))->with('error', 'Gestione utenti non disponibile per questo spazio.');
        }

        if (!in_array($context->tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return redirect()->to(site_url('/'))->with('error', 'Non sei autorizzato a gestire gli utenti di questo spazio.');
        }

        if ((int)(session()->get('platform_user_id') ?? 0) <= 0) {
            return redirect()->to(site_url('/'))->with('error', 'Funzione disponibile solo per accessi piattaforma.');
        }

        return null;
    }
}
