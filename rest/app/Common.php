<?php

/**
 * The goal of this file is to allow developers a location
 * where they can overwrite core procedural functions and
 * replace them with their own. This file is loaded during
 * the bootstrap process and is called during the framework's
 * execution.
 *
 * This can be looked at as a `master helper` file that is
 * loaded early on, and may also contain additional functions
 * that you'd like to use throughout your entire application
 *
 * @see: https://codeigniter.com/user_guide/extending/common.html
 */

if (! function_exists('normalize_push_vapid_public_key')) {
    function normalize_push_vapid_public_key(?string $value = null): string
    {
        $normalized = trim((string) ($value ?? env('VAPID_PUBLIC_KEY', '')));
        if ($normalized === '') {
            return '';
        }

        if (
            strlen($normalized) >= 2
            && (
                (str_starts_with($normalized, '"') && str_ends_with($normalized, '"'))
                || (str_starts_with($normalized, "'") && str_ends_with($normalized, "'"))
                || (str_starts_with($normalized, '`') && str_ends_with($normalized, '`'))
            )
        ) {
            $normalized = trim(substr($normalized, 1, -1));
        }

        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        if (in_array(strtolower($normalized), ['change_me', 'changeme', 'null', 'undefined'], true)) {
            return '';
        }

        return $normalized;
    }
}

if (! function_exists('demo_push_vapid_public_key_fallback')) {
    function demo_push_vapid_public_key_fallback(): string
    {
        return 'BNIAqzDqmU9FvLZ_uqQuaRno0lRWkTBgobTIgpIVUuMCs5uxIY9SvO74E9UhCdS9ullYJz5fzNXMOkE-xZtVwrc';
    }
}

if (! function_exists('push_demo_site_enabled')) {
    function push_demo_site_enabled(): bool
    {
        $rawDemoFlag = env('DEMO_SITE_ENABLED');
        if ($rawDemoFlag !== null && $rawDemoFlag !== false && trim((string) $rawDemoFlag) !== '') {
            return in_array(strtolower(trim((string) $rawDemoFlag)), ['1', 'true', 'yes', 'on'], true);
        }

        $baseUrl = trim((string) (env('APP_CANONICAL_URL', '') ?: env('app.baseURL', '')));
        if ($baseUrl === '') {
            return false;
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        if ($host !== '' && str_starts_with($host, 'demo.')) {
            return true;
        }

        $path = strtolower(trim((string) parse_url($baseUrl, PHP_URL_PATH)));
        if ($path === '') {
            return false;
        }

        return $path === '/demo' || str_starts_with($path, '/demo/');
    }
}

if (! function_exists('push_vapid_public_key')) {
    function push_vapid_public_key(): string
    {
        $candidateKeys = [
            'VAPID_PUBLIC_KEY',
            'PUSH_VAPID_PUBLIC_KEY',
            'PUSH_PUBLIC_KEY',
            'NEXT_PUBLIC_VAPID_PUBLIC_KEY',
        ];

        foreach ($candidateKeys as $candidateKey) {
            $value = normalize_push_vapid_public_key((string) env($candidateKey, ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (push_demo_site_enabled()) {
            return demo_push_vapid_public_key_fallback();
        }

        return '';
    }
}
