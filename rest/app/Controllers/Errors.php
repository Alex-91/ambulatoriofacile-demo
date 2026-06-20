<?php

namespace App\Controllers;

class Errors extends BaseController
{
    public function redirectHome()
    {
        helper('portal');

        $path = trim((string) $this->request->getUri()->getPath(), '/');
        if ($path === 'app' || $path === 'app/index.php') {
            if ((bool) session()->get('isLoggedInConfirmed') === true) {
                return redirect()->to(site_url('/'));
            }

            return redirect()->to(portal_public_access_url('login'));
        }

        return redirect()->to($this->canonicalHomeUrl());
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
