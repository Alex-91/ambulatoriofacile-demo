<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();

        if ((bool) $session->get('isLoggedInConfirmed') === true) {
            return null;
        }

        return redirect()->to($this->canonicalHomeUrl());
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function canonicalHomeUrl(): string
    {
        if ($this->isLocalRequest()) {
            return site_url('/');
        }

        $configuredBaseUrl = trim((string) (env('APP_CANONICAL_URL', '') ?: env('app.baseURL', '')));
        if ($configuredBaseUrl !== '') {
            return rtrim($configuredBaseUrl, '/') . '/';
        }

        return site_url('/');
    }

    private function isLocalRequest(): bool
    {
        $host = $this->resolveRequestHost();
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function resolveRequestHost(): string
    {
        $forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($forwardedHost !== '') {
            $parts = explode(',', $forwardedHost);
            return strtolower(trim((string) ($parts[0] ?? '')));
        }

        return strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    }
}
