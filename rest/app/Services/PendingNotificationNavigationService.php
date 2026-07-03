<?php

namespace App\Services;

use CodeIgniter\HTTP\RequestInterface;

class PendingNotificationNavigationService
{
    public const SESSION_KEY = 'pending_notification_navigation';
    private const TTL_SECONDS = 900;

    public function captureFromRequest(RequestInterface $request): void
    {
        if (!$this->shouldCaptureRequest($request)) {
            return;
        }

        $url = $this->buildRelativeUrlFromRequest($request);
        if ($url === '') {
            return;
        }

        session()->set(self::SESSION_KEY, [
            'url' => $url,
            'created_at' => time(),
        ]);
    }

    public function consumeRedirectUrl(string $defaultRedirect): string
    {
        if (!$this->canOverrideDefaultRedirect($defaultRedirect)) {
            return $defaultRedirect;
        }

        $pendingUrl = $this->consumePendingUrl();
        if ($pendingUrl === null) {
            return $defaultRedirect;
        }

        return $pendingUrl;
    }

    public function clear(): void
    {
        session()->remove(self::SESSION_KEY);
    }

    private function shouldCaptureRequest(RequestInterface $request): bool
    {
        $method = strtoupper(trim((string) $request->getMethod()));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->isAJAX()) {
            return false;
        }

        $path = $this->normalizePath((string) $request->getUri()->getPath());
        if (!$this->isAgendaPath($path)) {
            return false;
        }

        return true;
    }

    private function buildRelativeUrlFromRequest(RequestInterface $request): string
    {
        $path = $this->normalizePath((string) $request->getUri()->getPath());
        $query = trim((string) $request->getUri()->getQuery());

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    private function consumePendingUrl(): ?string
    {
        $pending = session()->get(self::SESSION_KEY);
        if (!is_array($pending)) {
            return null;
        }

        $createdAt = (int) ($pending['created_at'] ?? 0);
        $rawUrl = trim((string) ($pending['url'] ?? ''));
        if ($createdAt <= 0 || $rawUrl === '' || (time() - $createdAt) > self::TTL_SECONDS) {
            $this->clear();
            return null;
        }

        $url = $this->normalizeInternalUrl($rawUrl);
        if ($url === '') {
            $this->clear();
            return null;
        }

        $path = $this->normalizePath((string) parse_url($url, PHP_URL_PATH));
        if (!$this->isAgendaPath($path)) {
            $this->clear();
            return null;
        }

        $this->clear();

        return $url;
    }

    private function canOverrideDefaultRedirect(string $defaultRedirect): bool
    {
        $normalized = $this->normalizeInternalUrl($defaultRedirect);
        if ($normalized === '') {
            return true;
        }

        $path = $this->normalizePath((string) parse_url($normalized, PHP_URL_PATH));
        if ($path === '/sostituzioni') {
            return false;
        }

        foreach ([
            '/auth',
            '/login',
            '/password-imposta',
            '/login/password-imposta',
            '/password/scaduta',
            '/reset',
            '/recupero',
            '/login/recupero',
            '/piattaforma',
            '/login/piattaforma',
        ] as $blockedPrefix) {
            if ($path === $blockedPrefix || str_starts_with($path, $blockedPrefix . '/')) {
                return false;
            }
        }

        return true;
    }

    private function normalizeInternalUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            return '';
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $baseParts = parse_url(site_url('/'));
            $valueParts = parse_url($value);
            if ($baseParts === false || $valueParts === false) {
                return '';
            }

            $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
            $valueHost = strtolower((string) ($valueParts['host'] ?? ''));
            $baseScheme = strtolower((string) ($baseParts['scheme'] ?? ''));
            $valueScheme = strtolower((string) ($valueParts['scheme'] ?? ''));

            if ($baseHost === '' || $valueHost === '' || $baseHost !== $valueHost || $baseScheme !== $valueScheme) {
                return '';
            }

            $path = $this->normalizePath((string) ($valueParts['path'] ?? '/'));
            $query = trim((string) ($valueParts['query'] ?? ''));

            return $path . ($query !== '' ? ('?' . $query) : '');
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return '';
        }

        if (
            isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            return '';
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = trim((string) ($parts['query'] ?? ''));

        return $path . ($query !== '' ? ('?' . $query) : '');
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');
        $path = preg_replace('#/+#', '/', $path) ?? '/';

        return $path !== '' ? $path : '/';
    }

    private function isAgendaPath(string $path): bool
    {
        return in_array($path, ['/agenda', '/login/spazio/agenda', '/spazio/agenda'], true);
    }
}
