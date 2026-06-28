<?php

namespace App\Controllers;

use App\Services\DemoAccessService;

class DemoAccessController extends BaseController
{
    public function enter()
    {
        helper('url');
        $demoAccess = new DemoAccessService();

        if (! $demoAccess->isDemoSiteEnabled()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $accessUrl = site_url('access');
        $requestedUsername = trim((string) $this->request->getGet('u'));

        try {
            $result = $demoAccess->loginAccount($requestedUsername);

            return redirect()->to((string) ($result['redirectUrl'] ?? site_url('/')));
        } catch (\Throwable $e) {
            log_message('error', '[DemoAccessController::enter] accesso demo diretto fallito: {message}', [
                'message' => $e->getMessage(),
                'username' => $requestedUsername,
            ]);

            session()->setFlashdata('demo_access_feedback', [
                'ok' => false,
                'message' => 'Accesso demo non disponibile per il ruolo richiesto. Controlla seed e configurazione demo.',
            ]);

            return redirect()->to($accessUrl);
        }
    }
}
