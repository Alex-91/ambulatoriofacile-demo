<?php
namespace App\Services\Pacs;

/** Public HTTPS only, DNS pinned per request, no redirects/proxy, bounded memory/time. */
class CurlPacsTransport implements PacsTransport
{
    public static function publicAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !str_starts_with($ip, '169.254.') && !str_starts_with($ip, '100.64.')
            && !self::inRange($ip, '100.64.0.0', 10)
            && !self::inRange($ip, '198.18.0.0', 15)
            && !self::inRange($ip, '224.0.0.0', 4);
    }
    private static function inRange(string $ip, string $base, int $bits): bool
    {
        return (ip2long($ip) & (-1 << (32-$bits))) === (ip2long($base) & (-1 << (32-$bits)));
    }
    public function get(array $profile, string $url, string $accept, int $maxBytes): array
    {
        $p = PacsProfiles::url($url, true);
        $host = $p['host'];
        $addresses = $this->resolve($host);
        $headers = ['Accept: '.$accept, 'Cache-Control: no-store'];
        $auth = $profile['auth'] ?? 'none';
        if ($auth === 'bearer') $headers[] = 'Authorization: Bearer '.PacsProfiles::credential($profile,'token');
        if ($auth === 'basic') $headers[] = 'Authorization: Basic '.base64_encode(PacsProfiles::credential($profile,'username').':'.PacsProfiles::credential($profile,'password'));
        $body = ''; $type = ''; $warning = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER=>$headers, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30, CURLOPT_NOSIGNAL=>true,
            CURLOPT_RESOLVE=>[$host.':'.($p['port'] ?? 443).':'.$addresses[0]],
            CURLOPT_HEADERFUNCTION=>static function ($ch, string $line) use (&$type, &$warning): int {
                if (stripos($line,'Content-Type:') === 0) $type = trim(substr($line,13));
                if (stripos($line,'Warning:') === 0) $warning = true;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($ch, string $chunk) use (&$body,$maxBytes): int {
                if (strlen($body)+strlen($chunk)>$maxBytes) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (!empty($profile['ca_file'])) curl_setopt($curl, CURLOPT_CAINFO, $profile['ca_file']);
        try {
            if (curl_exec($curl) === false) throw new PacsException('Risposta PACS non disponibile, troppo grande o fuori tempo massimo.');
            return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'type'=>$type,'body'=>$body,'warning'=>$warning];
        } finally { curl_close($curl); }
    }
    protected function resolve(string $host): array
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!$addresses) throw new PacsException('PACS non raggiungibile.');
        foreach ($addresses as $ip) if (!self::publicAddress($ip)) throw new PacsException('Destinazione PACS non ammessa dal collegamento cloud.');
        return $addresses;
    }
}
