<?php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AccountantIsolationFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (!$request instanceof \CodeIgniter\HTTP\IncomingRequest) { return null; }
        $path = trim($request->getUri()->getRoutePath(), '/');
        $portal = $path === 'commercialista' || str_starts_with($path, 'commercialista/');
        if ($portal || str_starts_with($path, 'admin/fatturazione-commercialista') || session()->has('accountant_email')) {
            service('response')->setHeader('Cache-Control', 'no-store, private')
                ->setHeader('Referrer-Policy', 'no-referrer')->setHeader('X-Robots-Tag', 'noindex, nofollow')
                ->setHeader('X-Frame-Options', 'DENY');
        }
        if (session()->has('accountant_email') && !$portal) {
            helper('url');
            return redirect()->to(site_url('commercialista'));
        }
    }
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $path = trim($request->getUri()->getRoutePath(), '/');
        if ($path === 'commercialista' || str_starts_with($path, 'commercialista/')
            || str_starts_with($path, 'admin/fatturazione-commercialista') || session()->has('accountant_email')) {
            $response->setHeader('Cache-Control', 'no-store, private')->setHeader('Referrer-Policy', 'no-referrer')
                ->setHeader('X-Robots-Tag', 'noindex, nofollow')->setHeader('X-Frame-Options', 'DENY');
        }
    }
}
