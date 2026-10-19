<?php
namespace App\Filters;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\HTTP\{RequestInterface,ResponseInterface};
final class WhatsAppBookingCsrfFilter extends CSRF
{
    public function after(RequestInterface $request,ResponseInterface $response,$arguments=null)
    {
        $name=service('security')->getCookieName();
        foreach(service('response')->getCookies() as $cookie)if($cookie->getPrefixedName()===$name)$response->setCookie($cookie);
    }
}
