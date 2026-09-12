<?php
namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\{RequestInterface, ResponseInterface};

/** Billing document forms: retain the framework's rotated cookie on redirect responses. */
final class BillingCsrfFilter extends CSRF
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = parent::before($request, $arguments);
        if ($response instanceof ResponseInterface) $this->copyCsrfCookie($response);
        return $response;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $this->copyCsrfCookie($response);
        return null;
    }

    private function copyCsrfCookie(ResponseInterface $response): void
    {
        $name = service('security')->getCookieName();
        foreach (service('response')->getCookies() as $cookie) {
            if ($cookie->getPrefixedName() === $name) $response->setCookie($cookie);
        }
    }
}
