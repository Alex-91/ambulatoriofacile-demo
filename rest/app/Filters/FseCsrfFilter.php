<?php
namespace App\Filters;

use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\{RequestInterface, ResponseInterface};

/** Framework CSRF validation, scoped to FSE; preserve rotated cookie on redirects/downloads. */
final class FseCsrfFilter extends CSRF
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
