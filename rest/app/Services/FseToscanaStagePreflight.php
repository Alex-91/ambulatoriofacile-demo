<?php
namespace App\Services;

use App\Config\Fse2;

/** Read-only local checks. A passing report is not a regional authorization. */
class FseToscanaStagePreflight
{
    public function __construct(private ?Fse2 $config = null) {}

    public static function assertIdentity(array $certificate, string $role): string
    {
        if (!in_array($role, ['A1', 'S1'], true)) throw new \InvalidArgumentException('Ruolo certificato non valido.');
        $cn = (string) ($certificate['common_name'] ?? '');
        if (!preg_match('/\A' . $role . '#090#([A-Za-z0-9._-]+)\z/', $cn, $match)) {
            throw new \RuntimeException('Certificato Toscana staging richiesto: CN ' . $role . '#090#NOMEFORNITOREXX.');
        }
        return $match[1];
    }

    public function inspect(array $profile): array
    {
        $config = $this->config ??= config(Fse2::class);
        if (($profile['access_mode'] ?? '') !== 'toscana_privati' || ($profile['environment'] ?? '') !== 'test'
            || ($profile['region_code'] ?? '') !== '090') throw new \RuntimeException('Preflight riservato a Toscana 090 in ambiente test.');
        $endpoint = $config->gatewayUrlForProfile($profile);
        $certificates = []; $vendors = [];
        foreach (['auth' => 'A1', 'signature' => 'S1'] as $prefix => $role) {
            $cert = $config->resolveCertificatePath((string) ($profile[$prefix . '_certificate_path'] ?? ''));
            $key = $config->resolveCertificatePath((string) ($profile[$prefix . '_private_key_path'] ?? ''));
            if (!is_file($cert) || !is_readable($cert) || !is_file($key) || !is_readable($key)) {
                throw new \RuntimeException('Coppia certificato ' . $prefix . ' non disponibile.');
            }
            $certificates[$prefix] = FseCertificateInspector::inspect(file_get_contents($cert), file_get_contents($key),
                (string) ($profile[$prefix . '_private_key_passphrase'] ?? ''));
            $vendors[] = self::assertIdentity($certificates[$prefix], $role);
        }
        if (!hash_equals($vendors[0], $vendors[1])) throw new \RuntimeException('I certificati Toscana devono riferirsi allo stesso fornitore.');
        $audiencePresent = false;
        try { $audiencePresent = $config->jwtAudienceForProfile($profile) !== ''; } catch (\RuntimeException $e) { /* Report missing CART configuration. */ }
        return ['mode' => 'LOCAL_TOSCANA_STAGE_PREFLIGHT', 'checked_at' => gmdate('c'),
            'endpoint' => $endpoint, 'region_code' => '090', 'certificates' => $certificates,
            'jwt_audience_configured' => $audiencePresent, 'transport_switch_enabled' => $config->allowToscanaStage,
            'certificate_checks_passed' => true, 'official_authorization' => 'NOT_VERIFIED',
            'distinct_public_keys' => !hash_equals($certificates['auth']['public_key_sha256'], $certificates['signature']['public_key_sha256']),
            'operational_ready' => false, 'network_calls' => 0,
            'next_steps' => ['Conferma utenza e struttura autorizzata sul ticket regionale.',
                'Inviare modulo CART e solo certificato pubblico di autenticazione.',
                'Confermare audience e abilitazione degli endpoint con CART.',
                'Eseguire collaudo ufficiale RSA e RAD e conservare gli esiti.']];
    }
}
