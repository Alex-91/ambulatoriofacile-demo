<?php
namespace App\Libraries;

/** Pure peer-address check; never trust forwarding headers to identify the proxy itself. */
final class TrustedProxyPolicy
{
    public static function matches(string $peer, array $proxies): bool
    {
        $address = @inet_pton($peer);
        if ($address === false) return false;
        foreach ($proxies as $network => $header) {
            if (!is_string($network) || !is_string($header) || trim($header) === '') continue;
            $parts = explode('/', $network);
            $base = @inet_pton($parts[0]);
            if ($base === false || strlen($base) !== strlen($address) || count($parts) > 2) continue;
            if (count($parts) === 1) {
                if ($base === $address) return true;
                continue;
            }
            if (!ctype_digit($parts[1])) continue;
            $bits = (int) $parts[1];
            // A catch-all is not a trusted proxy configuration.
            if ($bits < 1 || $bits > strlen($base) * 8) continue;
            $bytes = intdiv($bits, 8); $remainder = $bits % 8;
            if (substr($address, 0, $bytes) !== substr($base, 0, $bytes)) continue;
            if (!$remainder || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (0xff << (8 - $remainder))) === 0) return true;
        }
        return false;
    }
}
