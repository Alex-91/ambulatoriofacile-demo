<?php

if (!function_exists('portal_public_access_url')) {
    function portal_public_access_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $demoSessionActive = false;

        try {
            $demoSessionActive = (bool) (session()->get(\App\Services\DemoAccessService::SESSION_KEY_ACTIVE) ?? false);
        } catch (\Throwable) {
            $demoSessionActive = false;
        }

        if ($demoSessionActive) {
            return site_url($path);
        }

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
        $demoSessionActive = false;

        try {
            $demoSessionActive = (bool) (session()->get(\App\Services\DemoAccessService::SESSION_KEY_ACTIVE) ?? false);
        } catch (\Throwable) {
            $demoSessionActive = false;
        }

        if ($demoSessionActive) {
            return site_url($path);
        }

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

if (!function_exists('portal_tenant_agenda_url')) {
    function portal_tenant_agenda_url(): string
    {
        helper('session_auth');

        $tenantContextRaw = session()->get(\App\Services\TenantContextService::SESSION_KEY);
        $tenantId = is_array($tenantContextRaw) ? (int) ($tenantContextRaw['tenant_id'] ?? 0) : 0;

        if ($tenantId > 0) {
            if (session_access_is_confirmed()) {
                return site_url('agenda');
            }

            $bridgePath = 'login/spazio/agenda';
            $demoSessionActive = false;

            try {
                $demoSessionActive = (bool) (session()->get(\App\Services\DemoAccessService::SESSION_KEY_ACTIVE) ?? false);
            } catch (\Throwable) {
                $demoSessionActive = false;
            }

            return $demoSessionActive ? site_url($bridgePath) : portal_public_access_url($bridgePath);
        }

        return site_url('agenda');
    }
}

if (!function_exists('portal_tenant_operational_profile_url')) {
    function portal_tenant_operational_profile_url(): string
    {
        helper('session_auth');

        $tenantContextRaw = session()->get(\App\Services\TenantContextService::SESSION_KEY);
        $tenantId = is_array($tenantContextRaw) ? (int) ($tenantContextRaw['tenant_id'] ?? 0) : 0;

        if ($tenantId > 0) {
            if (session_access_is_confirmed()) {
                return site_url('admin');
            }

            $bridgePath = 'login/spazio/profilo-operativo';
            $demoSessionActive = false;

            try {
                $demoSessionActive = (bool) (session()->get(\App\Services\DemoAccessService::SESSION_KEY_ACTIVE) ?? false);
            } catch (\Throwable) {
                $demoSessionActive = false;
            }

            return $demoSessionActive ? site_url($bridgePath) : portal_public_access_url($bridgePath);
        }

        return site_url('admin');
    }
}

if (!function_exists('portal_space_label')) {
    function portal_space_label(bool $capitalized = false): string
    {
        return $capitalized ? 'Studio' : 'studio';
    }
}

if (!function_exists('portal_space_manager_label')) {
    function portal_space_manager_label(bool $capitalized = false): string
    {
        return $capitalized ? 'Responsabile dello studio' : 'responsabile dello studio';
    }
}

if (!function_exists('portal_space_role_label')) {
    function portal_space_role_label(string $role): string
    {
        return match (strtolower(trim($role))) {
            'tenant_master' => 'Responsabile dello studio',
            'tenant_admin' => 'Amministratore dello studio',
            'tenant_staff' => 'Collaboratore dello studio',
            default => trim($role) !== '' ? $role : 'Collaboratore dello studio',
        };
    }
}

if (!function_exists('portal_current_path_matches')) {
    function portal_current_path_matches(string $path): bool
    {
        $normalizePath = static function ($value): string {
            $pathValue = trim((string) $value);
            if ($pathValue === '') {
                return '';
            }

            $parsedPath = parse_url($pathValue, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $pathValue = $parsedPath;
            }

            return trim(str_replace('\\', '/', $pathValue), '/');
        };

        $appendVariants = static function (array &$variants, string $candidate): void {
            $candidate = trim($candidate, '/');
            if ($candidate === '') {
                return;
            }

            $variants[$candidate] = true;

            if (str_starts_with($candidate, 'login/')) {
                $relativeCandidate = substr($candidate, strlen('login/'));
                if (is_string($relativeCandidate) && $relativeCandidate !== '') {
                    $variants[$relativeCandidate] = true;
                }
            }
        };

        $targetVariants = [];
        $appendVariants($targetVariants, $normalizePath($path));

        $requestVariants = [];
        $appendVariants($requestVariants, $normalizePath(service('uri')->getPath()));
        $appendVariants($requestVariants, $normalizePath($_SERVER['AF_ORIGINAL_REQUEST_URI'] ?? ''));
        $appendVariants($requestVariants, $normalizePath($_SERVER['REQUEST_URI'] ?? ''));

        foreach (array_keys($requestVariants) as $currentPath) {
            foreach (array_keys($targetVariants) as $targetPath) {
                if ($currentPath === $targetPath) {
                    return true;
                }

                if ($currentPath !== '' && str_ends_with($currentPath, '/' . $targetPath)) {
                    return true;
                }
            }
        }

        return false;
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

if (!function_exists('portal_operational_home_url')) {
    function portal_operational_home_url(): string
    {
        helper('session_auth');

        $isConfirmed = session_access_is_confirmed();

        if ($isConfirmed && session_should_open_agenda_first()) {
            return site_url('agenda');
        }

        if ($isConfirmed && session_has_operational_profile_access()) {
            return site_url('admin');
        }

        if ($isConfirmed) {
            return site_url('app');
        }

        return site_url('/');
    }
}

if (!function_exists('portal_session_console_url')) {
    function portal_session_console_url(): ?string
    {
        helper('session_auth');

        $tenantContextRaw = session()->get(\App\Services\TenantContextService::SESSION_KEY);
        if (is_array($tenantContextRaw) && $tenantContextRaw !== []) {
            $tenantContext = \App\Libraries\TenantContext::fromArray($tenantContextRaw);
            if ($tenantContext->isValid()) {
                return portal_operational_home_url();
            }
        }

        if ((bool) (session()->get('platform_is_admin') ?? false) === true) {
            return portal_platform_url('spazi-clienti');
        }

        return null;
    }
}
