<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        helper(['portal', 'session_auth']);

        if (session_access_is_confirmed()) {
            $path = strtolower(trim((string) $request->getUri()->getPath(), '/'));
            if (($path === 'admin' || str_starts_with($path, 'admin/'))
                && !session_has_operational_profile_access()) {
                return redirect()
                    ->to(portal_operational_home_url())
                    ->with('error', 'Profilo operativo non disponibile per questo account.');
            }

            return null;
        }

        return redirect()->to(portal_public_access_url('login'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
