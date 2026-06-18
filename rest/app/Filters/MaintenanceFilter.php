<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class MaintenanceFilter implements FilterInterface
{
    private const BYPASS_QUERY_PARAM = 'maintenance_bypass';
    private const BYPASS_COOKIE_NAME = 'maintenance_bypass';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (is_cli() || ! $this->isMaintenanceEnabled()) {
            return null;
        }

        if ($this->isBypassAllowedForRequest($request)) {
            return null;
        }

        if ($this->hasValidBypassToken($request)) {
            return $this->issueBypassCookieAndRedirect($request);
        }

        return service('response')
            ->setStatusCode(503, 'Service Unavailable')
            ->setHeader('Retry-After', '3600')
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setContentType('text/html', 'UTF-8')
            ->setBody(view('maintenance', [
                'title'   => trim((string) env('APP_MAINTENANCE_TITLE', 'Sito in manutenzione')),
                'message' => trim((string) env('APP_MAINTENANCE_MESSAGE', 'Stiamo facendo un intervento tecnico. Riprova tra poco.')),
            ]));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }

    private function isBypassAllowedForRequest(RequestInterface $request): bool
    {
        if ($this->hasValidBypassCookie($request)) {
            return true;
        }

        $allowedIps = $this->getAllowedIps();
        if ($allowedIps === []) {
            return false;
        }

        foreach ($this->extractClientIps($request) as $ip) {
            if ($ip !== '' && in_array($ip, $allowedIps, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasValidBypassToken(RequestInterface $request): bool
    {
        $expected = trim((string) env('APP_MAINTENANCE_BYPASS_TOKEN', ''));
        if ($expected === '') {
            return false;
        }

        $provided = trim((string) ($request->getGet(self::BYPASS_QUERY_PARAM) ?? ''));
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    private function hasValidBypassCookie(RequestInterface $request): bool
    {
        $expected = trim((string) env('APP_MAINTENANCE_BYPASS_TOKEN', ''));
        if ($expected === '') {
            return false;
        }

        $cookie = trim((string) ($request->getCookie(self::BYPASS_COOKIE_NAME) ?? ''));
        if ($cookie === '') {
            return false;
        }

        return hash_equals($expected, $cookie);
    }

    private function issueBypassCookieAndRedirect(RequestInterface $request): ResponseInterface
    {
        $token = trim((string) env('APP_MAINTENANCE_BYPASS_TOKEN', ''));
        $minutes = max(1, (int) env('APP_MAINTENANCE_BYPASS_COOKIE_MINUTES', '480'));
        $secure = $request->isSecure();
        $currentUrl = current_url(true);
        $query = $_GET;
        unset($query[self::BYPASS_QUERY_PARAM]);
        $targetUrl = (string) $currentUrl;
        if ($query !== []) {
            $targetUrl .= '?' . http_build_query($query);
        }

        return service('response')
            ->setCookie([
                'name'     => self::BYPASS_COOKIE_NAME,
                'value'    => $token,
                'expire'   => $minutes * 60,
                'domain'   => '',
                'path'     => '/',
                'prefix'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ])
            ->redirect($targetUrl);
    }

    /**
     * @return list<string>
     */
    private function getAllowedIps(): array
    {
        $raw = trim((string) env('APP_MAINTENANCE_ALLOWED_IPS', ''));
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $allowed = [];
        foreach ($parts as $part) {
            $ip = trim((string) $part);
            if ($ip !== '') {
                $allowed[] = $ip;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @return list<string>
     */
    private function extractClientIps(RequestInterface $request): array
    {
        $candidates = [];

        $directIp = trim((string) $request->getIPAddress());
        if ($directIp !== '') {
            $candidates[] = $directIp;
        }

        $serverCandidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($serverCandidates as $raw) {
            foreach (explode(',', (string) $raw) as $part) {
                $ip = trim($part);
                if ($ip !== '') {
                    $candidates[] = $ip;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private function isMaintenanceEnabled(): bool
    {
        $value = env('APP_MAINTENANCE_MODE', '0');

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
