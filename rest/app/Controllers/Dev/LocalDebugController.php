<?php

namespace App\Controllers\Dev;

use App\Controllers\BaseController;
use App\Services\TenantAppSessionBootstrapService;

class LocalDebugController extends BaseController
{
    public function agendaSpazioTest()
    {
        if (ENVIRONMENT !== 'development') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        helper('portal');

        try {
            $bootstrap = (new TenantAppSessionBootstrapService())->bootstrap(4, 3);
            $redirectUrl = (string) ($bootstrap['redirectUrl'] ?? site_url('agenda'));

            if ($this->request->getGet('inspect') === '1') {
                return $this->response->setJSON([
                    'ok' => true,
                    'redirectUrl' => $redirectUrl,
                    'resolvedRedirectUrl' => portal_resolve_redirect_url($redirectUrl),
                    'session' => [
                        'isLoggedIn' => session()->get('isLoggedIn'),
                        'isLoggedInConfirmed' => session()->get('isLoggedInConfirmed'),
                        'platform_user_id' => session()->get('platform_user_id'),
                        'username' => session()->get('username'),
                        'id_user' => session()->get('id_user'),
                        'tipoUser' => session()->get('tipoUser'),
                        'tenant_context' => session()->get(\App\Services\TenantContextService::SESSION_KEY),
                    ],
                    'bootstrap' => $bootstrap,
                ]);
            }

            return redirect()->to(portal_resolve_redirect_url($redirectUrl));
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
