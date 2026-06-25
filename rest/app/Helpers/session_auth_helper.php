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
