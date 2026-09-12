<?php
namespace App\Services;

/** Minimal child environment, not a sandbox or a substitute for OS isolation. */
final class FseValidatorEnvironment
{
    /** Application credentials and runtime injection variables are never inherited. */
    public static function build(?array $parent = null): array
    {
        $parent ??= getenv();
        $allowed = ['PATH'=>'PATH', 'SYSTEMROOT'=>'SystemRoot', 'WINDIR'=>'WINDIR',
            'TEMP'=>'TEMP', 'TMP'=>'TMP', 'TMPDIR'=>'TMPDIR'];
        $result = ['LANG'=>'C.UTF-8', 'LC_ALL'=>'C.UTF-8', 'TZ'=>'UTC'];
        foreach ($parent as $key => $value) {
            $canonical = $allowed[strtoupper((string) $key)] ?? null;
            if ($canonical !== null && is_string($value) && $value !== ''
                && strlen($value) <= 32767 && !str_contains($value, "\0")) {
                $result[$canonical] = $value;
            }
        }
        return $result;
    }
}
