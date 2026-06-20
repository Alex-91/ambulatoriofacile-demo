<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Services\PlatformAccessService;
use App\Services\TenantAppSessionBootstrapService;

class PlatformAccessController extends BaseController
{
    private PlatformAccessService $accessService;

    public function __construct()
    {
        $this->accessService = new PlatformAccessService();
    }

    public function recovery()
    {
        return view('login/platform_recovery', [
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function sendRecovery()
    {
        $email = strtolower(trim((string) $this->request->getPost('email')));

        try {
            if ($email !== '') {
                $this->accessService->requestPasswordResetByEmail($email);
            }

            return redirect()
                ->to(site_url('login/recupero'))
                ->with('success', 'Se l account esiste, abbiamo inviato una email con le istruzioni per l accesso.');
        } catch (\Throwable $e) {
            log_message('error', 'PlatformAccessController::sendRecovery failed: ' . $e->getMessage());

            return redirect()
                ->to(site_url('login/recupero'))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function passwordSetup()
    {
        $token = trim((string) ($this->request->getGet('token') ?? ''));
        $resolved = $token !== '' ? $this->accessService->resolveToken($token) : null;
        $pending = $token === '' ? $this->accessService->getPendingPasswordSetup() : null;

        if ($token !== '' && $resolved === null) {
            return redirect()
                ->to(site_url('login'))
                ->with('login_error', 'Il link di accesso non e valido oppure e scaduto.');
        }

        if ($token === '' && $pending === null) {
            return redirect()
                ->to(site_url('login'))
                ->with('login_error', 'La sessione per impostare la password e scaduta. Effettua di nuovo il login.');
        }

        $platformUser = (array) (($resolved['platform_user'] ?? null) ?: ($pending['platform_user'] ?? null) ?: []);
        $tenant = is_array($resolved['tenant'] ?? null) ? $resolved['tenant'] : null;

        return view('login/platform_password_setup', [
            'token' => $token,
            'platformUser' => $platformUser,
            'tenant' => $tenant,
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function savePassword()
    {
        $token = trim((string) $this->request->getPost('token'));
        $password = (string) $this->request->getPost('password');
        $password2 = (string) $this->request->getPost('password2');

        if ($password === '' || $password2 === '' || $password !== $password2) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', ['generic' => 'Le due password devono essere compilate e coincidere.']);
        }

        try {
            if ($token !== '') {
                $this->accessService->completePasswordSetupByToken($token, $password);

                return redirect()
                    ->to(site_url('login'))
                    ->with('login_success', 'Password salvata correttamente. Ora puoi entrare dal login unico con la tua email.');
            }

            $result = $this->accessService->completePendingPasswordSetup($password);
            $selectableTenants = (array) ($result['selectable_tenants'] ?? []);

            if (count($selectableTenants) === 1) {
                $tenantId = (int) ($selectableTenants[0]['id_tenant'] ?? 0);
                $platformUserId = (int) (($result['platform_user']['id_platform_user'] ?? 0));
                if ($platformUserId > 0 && $tenantId > 0) {
                    $bootstrap = (new TenantAppSessionBootstrapService())->bootstrap($platformUserId, $tenantId);
                    return redirect()->to(site_url((string) ($bootstrap['redirectUrl'] ?? '/')));
                }
            }

            return redirect()
                ->to(site_url('login'))
                ->with('login_success', 'Password aggiornata. Ora accedi con la tua email per entrare nel tuo spazio.');
        } catch (\Throwable $e) {
            log_message('error', 'PlatformAccessController::savePassword failed: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }
}
