<?php
// Standalone local preflight. No .env, sessions, application or database bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/gateway-bootstrap.php';
$options = getopt('', ['profile:', 'secrets-root:', 'probe-tls', 'cart-authorized']);
$report = ['mode'=>'LOCAL_TOSCANA_STAGE_PREFLIGHT','network_calls'=>0,'operational_ready'=>false];
try {
    if (empty($options['profile']) || empty($options['secrets-root'])) throw new RuntimeException('PREFLIGHT_ARGUMENTS');
    if (!is_file($options['profile']) || !is_readable($options['profile'])) throw new RuntimeException('PREFLIGHT_PROFILE');
    $profile = json_decode(file_get_contents($options['profile']), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($profile)) throw new RuntimeException('PREFLIGHT_PROFILE');
    $config = new \App\Config\Fse2();
    $config->secretsRoot = realpath($options['secrets-root']) ?: throw new RuntimeException('PREFLIGHT_SECRETS_ROOT');
    $config->allowAbsoluteCertificatePaths = false;
    $report = (new \App\Services\FseToscanaStagePreflight($config))->inspect($profile);
    if (array_key_exists('probe-tls', $options)) {
        if (!array_key_exists('cart-authorized', $options) || !$report['jwt_audience_configured']) throw new RuntimeException('PREFLIGHT_CART_CONFIRMATION_REQUIRED');
        if (!extension_loaded('curl')) throw new RuntimeException('PREFLIGHT_CURL');
        // HEAD of the fixed stage base URL only. No JWT, PDF, CDA or patient data.
        $ch = curl_init($report['endpoint']);
        if ($ch === false) throw new RuntimeException('PREFLIGHT_CURL');
        try {
            $configured = curl_setopt_array($ch, [CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>20,
                CURLOPT_SSLCERT=>$config->resolveCertificatePath($profile['auth_certificate_path']),
                CURLOPT_SSLKEY=>$config->resolveCertificatePath($profile['auth_private_key_path']),
                CURLOPT_KEYPASSWD=>(string)($profile['auth_private_key_passphrase'] ?? '')]);
            if (!$configured) throw new RuntimeException('PREFLIGHT_CURL');
            $report['network_calls'] = 1;
            curl_exec($ch);
            $report['tls_probe'] = ['curl_error_code'=>curl_errno($ch), 'tls_verify_result'=>(int)curl_getinfo($ch,CURLINFO_SSL_VERIFYRESULT),
                'http_status'=>(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE), 'authorization_verified'=>false];
            if ($report['tls_probe']['curl_error_code'] !== 0 || $report['tls_probe']['tls_verify_result'] !== 0 || $report['tls_probe']['http_status'] === 0) {
                throw new RuntimeException('PREFLIGHT_TLS_FAILED');
            }
            $report['tls_probe']['status'] = 'TLS_REACHED_HTTP_ONLY';
        } finally { curl_close($ch); }
    }
    $report['status'] = 'local_checks_passed';
} catch (Throwable $e) {
    $report['status'] = 'blocked';
    // Never include paths, key material, passphrases or raw exception messages.
    $report['error_code'] = preg_match('/\APREFLIGHT_[A-Z_]+\z/', $e->getMessage()) ? $e->getMessage() : 'PREFLIGHT_CHECK_FAILED';
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($report['status'] === 'local_checks_passed' ? 0 : 2);
