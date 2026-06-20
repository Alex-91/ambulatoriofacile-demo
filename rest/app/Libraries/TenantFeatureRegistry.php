<?php

namespace App\Libraries;

class TenantFeatureRegistry
{
    /**
     * @return array<string, array<string, array<int, string>>>
     */
    public static function definitions(): array
    {
        return [
            'agenda' => [
                'route_prefixes' => ['agenda', 'prenotazioni', 'visite-domiciliari', 'sostituzioni'],
                'menu_prefixes' => ['agenda', 'prenotazioni', 'visite-domiciliari', 'sostituzioni'],
                'schede_codes' => ['agenda'],
            ],
            'posta' => [
                'route_prefixes' => ['posta', 'compose', 'messaggi', 'draft', 'bozze', 'inviata'],
                'menu_prefixes' => ['posta', 'compose', 'messaggi', 'draft', 'bozze', 'inviata'],
                'schede_codes' => ['posta'],
            ],
            'chat' => [
                'route_prefixes' => ['chat'],
                'menu_prefixes' => ['chat'],
                'schede_codes' => ['chat'],
            ],
        ];
    }

    public static function resolveFeatureKeyFromRoutePath(string $path): ?string
    {
        return self::resolveByPrefixes($path, 'route_prefixes');
    }

    public static function resolveFeatureKeyFromMenuLink(string $link): ?string
    {
        return self::resolveByPrefixes($link, 'menu_prefixes');
    }

    public static function resolveFeatureKeyFromSchedaCode(string $codice): ?string
    {
        $normalized = trim(strtolower($codice));
        if ($normalized === '') {
            return null;
        }

        foreach (self::definitions() as $featureKey => $definition) {
            foreach ((array) ($definition['schede_codes'] ?? []) as $code) {
                if ($normalized === trim(strtolower((string) $code))) {
                    return $featureKey;
                }
            }
        }

        return null;
    }

    private static function resolveByPrefixes(string $value, string $bucket): ?string
    {
        $normalized = trim(strtolower($value), '/');
        if ($normalized === '') {
            return null;
        }

        foreach (self::definitions() as $featureKey => $definition) {
            foreach ((array) ($definition[$bucket] ?? []) as $prefix) {
                $prefix = trim(strtolower((string) $prefix), '/');
                if ($prefix === '') {
                    continue;
                }

                if ($normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
                    return $featureKey;
                }
            }
        }

        return null;
    }
}
