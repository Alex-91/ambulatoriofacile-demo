<?php

if (!function_exists('session_access_is_confirmed')) {
    function session_access_is_confirmed(): bool
    {
        $session = session();

        if ((bool) ($session->get('isLoggedInConfirmed') ?? false) === true) {
            return true;
        }

        $sessionUser = $session->get('utente_sess');
        if (!is_object($sessionUser) || (int) ($sessionUser->id_user ?? 0) <= 0) {
            return false;
        }

        $platformUserId = (int) ($session->get('platform_user_id') ?? 0);
        $isPlatformAdmin = (bool) ($session->get('platform_is_admin') ?? false) === true;
        $tenantContext = $session->get(\App\Services\TenantContextService::SESSION_KEY);
        $hasTenantContext = is_array($tenantContext) && $tenantContext !== [];

        if (!$isPlatformAdmin && $platformUserId <= 0 && !$hasTenantContext) {
            return false;
        }

        // Self-heal platform/tenant sessions that already have a valid user context
        // but lost the confirmation flag before reaching a filtered route.
        $session->set([
            'isLoggedIn' => true,
            'isLoggedInConfirmed' => true,
        ]);

        return true;
    }
}

if (!function_exists('session_current_tenant_role')) {
    function session_current_tenant_role(): string
    {
        $tenantContext = session()->get(\App\Services\TenantContextService::SESSION_KEY);
        if (!is_array($tenantContext) || $tenantContext === []) {
            return '';
        }

        return strtolower(trim((string) ($tenantContext['tenant_role'] ?? '')));
    }
}

if (!function_exists('session_user_is_doctor_profile')) {
    function session_user_is_doctor_profile(): bool
    {
        $sessionUser = session()->get('utente_sess');
        if (!is_object($sessionUser)) {
            return false;
        }

        return (int) ($sessionUser->tipo_pers ?? 0) === 1;
    }
}

if (!function_exists('session_should_open_agenda_first')) {
    function session_should_open_agenda_first(): bool
    {
        $tenantRole = session_current_tenant_role();
        if ($tenantRole === 'tenant_master') {
            return true;
        }

        return session_user_is_doctor_profile();
    }
}

if (!function_exists('session_has_operational_profile_access')) {
    function session_has_operational_profile_access(): bool
    {
        $tenantRole = session_current_tenant_role();
        if (in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return true;
        }

        if ($tenantRole !== '') {
            return false;
        }

        if (session_user_is_doctor_profile()) {
            return false;
        }

        $session = session();
        $sessionUser = $session->get('utente_sess');

        return $session->get('is_admin') === true
            || (int) ($session->get('admin') ?? 0) === 1
            || (int) ($session->get('tipoUser') ?? 0) === 1
            || (is_object($sessionUser) && (int) ($sessionUser->tipo ?? 0) === 1);
    }
}
