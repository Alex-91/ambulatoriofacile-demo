<?php

if (!function_exists('admin_menu_context_key')) {
    function admin_menu_context_key(?string $title = '', ?string $link = ''): string
    {
        $raw = strtolower(trim((string)$link . ' ' . (string)$title));
        $raw = str_replace(['\\', '_'], ['/', '-'], $raw);
        return preg_replace('/\s+/', ' ', $raw) ?: $raw;
    }
}

if (!function_exists('admin_menu_pretty_title')) {
    function admin_menu_pretty_title(?string $title = '', ?string $link = ''): string
    {
        $title = trim((string)$title);
        $link = trim((string)$link);
        $key = admin_menu_context_key($title, $link);

        return match (true) {
            str_contains($key, 'personale/dap14') || str_contains($key, 'dap14') => 'Segretarie e medici',
            str_contains($key, 'personale/dap15') || str_contains($key, 'dap15') => 'Infermieri e medici',
            str_contains($key, 'whatsapp-reminders') => 'Stato reminder WhatsApp',
            str_contains($key, 'otp-statistiche') => 'Statistiche OTP',
            str_contains($key, 'visibilita-moduli') => 'Visibilita moduli',
            str_contains($key, 'agenda/sedi') => 'Sedi agenda',
            str_contains($key, 'schede-utenti') => 'Schede utente',
            str_contains($key, 'sostituti') => 'Gestione sostituti',
            str_contains($key, 'personale/nuovo') || str_contains($key, 'inserisci personale') => 'Nuovo personale',
            str_contains($key, 'personale/modifica') || str_contains($key, 'modifica personale') => 'Modifica personale',
            str_contains($key, 'clienti') => 'Clienti',
            str_contains($key, 'logs') => 'Log di sistema',
            $title !== '' => $title,
            $link !== '' => ucwords(str_replace(['-', '_', '/'], ' ', basename($link))),
            default => 'Voce menu',
        };
    }
}

if (!function_exists('admin_menu_fallback_icon')) {
    function admin_menu_fallback_icon(?string $title = '', ?string $link = ''): string
    {
        $key = admin_menu_context_key($title, $link);

        return match (true) {
            str_contains($key, 'whatsapp') => 'fa-whatsapp',
            str_contains($key, 'otp') => 'fa-line-chart',
            str_contains($key, 'visibilita') || str_contains($key, 'moduli') => 'fa-toggle-on',
            str_contains($key, 'dap14') || str_contains($key, 'segret') => 'fa-users',
            str_contains($key, 'dap15') || str_contains($key, 'inferm') => 'fa-heartbeat',
            str_contains($key, 'personale/nuovo') || str_contains($key, 'inserisci personale') || str_contains($key, 'nuovo personale') => 'fa-user-plus',
            str_contains($key, 'personale/modifica') || str_contains($key, 'modifica personale') => 'fa-pencil',
            str_contains($key, 'clienti') => 'fa-building-o',
            str_contains($key, 'logs') => 'fa-file-text-o',
            str_contains($key, 'agenda/sedi') => 'fa-map-marker',
            str_contains($key, 'sostituti') => 'fa-exchange',
            str_contains($key, 'schede-utenti') => 'fa-th-large',
            str_contains($key, 'personale') => 'fa-users',
            default => 'fa-circle-o',
        };
    }
}

if (!function_exists('admin_menu_resolve_icon')) {
    function admin_menu_resolve_icon(?string $icon = '', ?string $title = '', ?string $link = ''): string
    {
        $icon = strtolower(trim((string)$icon));
        $fallback = admin_menu_fallback_icon($title, $link);
        $unsupportedAliases = [
            'fa-sign-out-alt' => $fallback,
            'fa-right-from-bracket' => $fallback,
        ];

        if ($icon === '' || $icon === 'fa-circle-o' || $icon === 'circle-o') {
            return $fallback;
        }

        if (preg_match('/fa-[a-z0-9-]+/', $icon, $matches) === 1) {
            $resolved = $matches[0];
            if ($resolved === 'fa-circle-o') {
                return $fallback;
            }

            return $unsupportedAliases[$resolved] ?? $resolved;
        }

        if (preg_match('/^[a-z0-9-]+$/', $icon) === 1) {
            $candidate = 'fa-' . ltrim($icon, '-');
            if ($candidate === 'fa-circle-o') {
                return $fallback;
            }

            return $unsupportedAliases[$candidate] ?? $candidate;
        }

        return $fallback;
    }
}
