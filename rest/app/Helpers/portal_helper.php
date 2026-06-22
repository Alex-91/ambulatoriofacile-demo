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
        return portal_public_access_url($path === '' ? 'login' : 'login/' . $path);
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

if (!function_exists('portal_current_path_matches')) {
    function portal_current_path_matches(string $path): bool
    {
        $targetPath = trim($path, '/');
        $currentPath = trim(service('uri')->getPath(), '/');

        if ($currentPath === $targetPath) {
            return true;
        }

        return $currentPath !== '' && str_ends_with($currentPath, '/' . $targetPath);
    }
}

if (!function_exists('portal_resolve_redirect_url')) {
    function portal_resolve_redirect_url(string $path = ''): string
    {
        $path = trim($path);
        if ($path === '') {
            return site_url('/');
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return site_url(ltrim($path, '/'));
    }
}

if (!function_exists('portal_session_console_url')) {
    function portal_session_console_url(): ?string
    {
        $tenantContextRaw = session()->get(\App\Services\TenantContextService::SESSION_KEY);
        if (is_array($tenantContextRaw) && $tenantContextRaw !== []) {
            $tenantContext = \App\Libraries\TenantContext::fromArray($tenantContextRaw);
            if ($tenantContext->isValid()) {
                $tenantRole = strtolower(trim($tenantContext->tenantRole));
                $onboardingStatus = strtolower(trim($tenantContext->onboardingStatus));

                if ($tenantRole === 'tenant_master') {
                    if (in_array($onboardingStatus, ['draft', 'setup'], true)) {
                        return portal_tenant_space_url('onboarding');
                    }

                    if ($tenantContext->allows('staff_management')) {
                        return portal_tenant_space_url('utenti');
                    }

                    return portal_tenant_space_url('funzioni');
                }

                if ($tenantRole === 'tenant_admin' && $tenantContext->allows('staff_management')) {
                    return portal_tenant_space_url('utenti');
                }

                return site_url('/');
            }
        }

        if ((bool) (session()->get('platform_is_admin') ?? false) === true) {
            return portal_platform_url('spazi-clienti');
        }

        return null;
    }
}
