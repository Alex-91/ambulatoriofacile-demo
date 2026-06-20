<?php

if (!function_exists('portal_public_access_url')) {
    function portal_public_access_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $configuredBaseUrl = trim((string) env('APP_PUBLIC_ACCESS_BASE_URL', ''));

        if ($configuredBaseUrl === '') {
            return site_url($path);
        }

        $baseUrl = rtrim($configuredBaseUrl, '/') . '/';
        if ($path === '') {
            return $baseUrl;
        }

        return $baseUrl . $path;
    }
}

if (!function_exists('portal_login_area_url')) {
    function portal_login_area_url(string $path = ''): string
    {
        $path = trim($path, '/');
        return site_url($path === '' ? 'login' : 'login/' . $path);
    }
}

if (!function_exists('portal_platform_url')) {
    function portal_platform_url(string $path = ''): string
    {
        $path = trim($path, '/');
        return portal_login_area_url($path === '' ? 'piattaforma' : 'piattaforma/' . $path);
    }
}

if (!function_exists('portal_tenant_space_url')) {
    function portal_tenant_space_url(string $path = ''): string
    {
        $path = trim($path, '/');
        return portal_login_area_url($path === '' ? 'spazio' : 'spazio/' . $path);
    }
}

if (!function_exists('portal_tenant_switch_url')) {
    function portal_tenant_switch_url(int $tenantId): string
    {
        return portal_login_area_url('spazi/cambia/' . max(0, $tenantId));
    }
}
