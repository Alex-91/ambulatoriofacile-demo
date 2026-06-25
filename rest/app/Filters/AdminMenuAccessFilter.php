<?php

namespace App\Filters;

use App\Services\AdminMenuVisibilityService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminMenuAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper('session_auth');

        if (!session_access_is_confirmed()) {
            return null;
        }

        $userId = (int) (session()->get('id_user') ?? 0);
        if ($userId <= 0) {
            $sessionUser = session()->get('utente_sess');
            if (is_object($sessionUser) && !empty($sessionUser->id_user)) {
                $userId = (int) $sessionUser->id_user;
            }
        }

        if ($userId <= 0) {
            return null;
        }

        $service = new AdminMenuVisibilityService();
        if (!$service->isAvailable()) {
            return null;
        }

        $menuKey = $service->resolveManagedKeyForRequestPath((string) $request->getUri()->getPath());
        if ($menuKey === null || $service->canUserSeeMenuKey($userId, $menuKey)) {
            return null;
        }

        helper('portal');

        $message = 'Questa voce di menu non e disponibile per il tuo profilo.';
        if (method_exists($request, 'isAJAX') && $request->isAJAX()) {
            return service('response')->setStatusCode(403)->setJSON([
                'ok' => false,
                'error' => $message,
            ]);
        }

        return redirect()->to(portal_operational_home_url())->with('error', $message);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
