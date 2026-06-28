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

        return $normalized;
    }
}

if (! function_exists('push_vapid_public_key')) {
    function push_vapid_public_key(): string
    {
        return normalize_push_vapid_public_key((string) env('VAPID_PUBLIC_KEY', ''));
    }
}
