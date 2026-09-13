<?php

namespace App\Config;

use CodeIgniter\Config\BaseConfig;

class Fse2 extends BaseConfig
{
    public const FEATURE_KEY = 'fse2';

    public const CF_OID = '2.16.840.1.113883.2.9.4.3.2';
    public const LOINC_OID = '2.16.840.1.113883.6.1';

    /** @var array<string, string> */
    public array $documentTypes = [
        'RSA' => 'Referto di Specialistica Ambulatoriale',
    ];

    /** @var array<string, string> */
    public array $facilityTypes = [
        'Territorio' => 'Territorio',
        'Ospedale' => 'Ospedale',
        'Prevenzione' => 'Prevenzione',
    ];

    /** @var array<string, string> */
    public array $administrativeRequests = [
        'NOSSN' => 'Prestazione privata / non SSN',
        'SSN' => 'Servizio Sanitario Nazionale',
        'SSR' => 'Servizio Sanitario Regionale',
    ];

    /** @var array<string, string> */
    public array $stateLabels = [
        'draft' => 'Bozza',
        'preparing' => 'Controllo CDA e PDF/A',
        'checking_signature' => 'Verifica firma',
        'ready_to_validate' => 'Da validare',
        'signed' => 'Firmato',
        'validating' => 'In validazione',
        'validated' => 'Validato',
        'publishing' => 'In pubblicazione',
        'deleting' => 'In eliminazione',
        'published' => 'Pubblicato',
        'rejected' => 'Scartato',
        'deleted' => 'Eliminato',
    ];

    /** @var array<string, array<string, string>> */
    public array $environments;

    public string $tenantStorageRoot;
    public string $tenantStorageSegment = 'fse2';
    public string $secretsRoot;
    public bool $allowProduction = false;
    public bool $allowToscanaStage = false;
    public bool $allowAbsoluteCertificatePaths = false;
    public int $connectTimeout = 20;
    public int $requestTimeout = 90;
    public int $jwtTtlSeconds = 300;
    /** Optional server TLS CA bundle, distinct from mTLS/JWT and clinical signature certificates. */
    public string $gatewayCaBundle = '';
    public int $maxPdfBytes = 15728640;
    public string $validatorPython;
    public string $validatorSettings;
    public int $validatorTimeout = 55;
    public int $validatorMaxConcurrent = 2;
    public int $validatorMinAvailableMiB = 0;

    public function __construct()
    {
        $this->tenantStorageRoot = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'tenants';
        $this->secretsRoot = $this->resolvePath(
            (string) env('FSE2_SECRETS_ROOT', ''),
            rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'fse2' . DIRECTORY_SEPARATOR . 'secrets'
        );
        $this->allowProduction = $this->toBoolean(env('FSE2_ALLOW_PRODUCTION', false));
        $this->allowToscanaStage = $this->toBoolean(env('FSE2_ALLOW_TOSCANA_STAGE', false));
        $this->allowAbsoluteCertificatePaths = $this->toBoolean(env('FSE2_ALLOW_ABSOLUTE_CERT_PATHS', false));
        $this->connectTimeout = max(1, (int) env('FSE2_CONNECT_TIMEOUT', 20));
        $this->requestTimeout = max(5, (int) env('FSE2_REQUEST_TIMEOUT', 90));
        $this->jwtTtlSeconds = max(60, min(600, (int) env('FSE2_JWT_TTL_SECONDS', 300)));
        $this->gatewayCaBundle = trim((string) env('FSE2_GATEWAY_CA_BUNDLE', ''));
        $this->maxPdfBytes = max(1048576, (int) env('FSE2_MAX_PDF_BYTES', 15728640));
        $this->validatorPython = trim((string) env('FSE2_VALIDATOR_PYTHON', ''));
        $this->validatorSettings = trim((string) env('FSE2_VALIDATOR_SETTINGS', ''));
        $this->validatorTimeout = max(5, min(60, (int) env('FSE2_VALIDATOR_TIMEOUT', 55)));
        $this->validatorMaxConcurrent = max(1, min(8, (int) env('FSE2_VALIDATOR_MAX_CONCURRENT', 2)));
        $this->validatorMinAvailableMiB = max(0, min(8192, (int) env('FSE2_VALIDATOR_MIN_AVAILABLE_MIB', 0)));

        $this->environments = [
            'test' => [
                'gateway_base_url' => rtrim((string) env(
                    'FSE2_TEST_GATEWAY_URL',
                    'https://modipa-val.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1'
                ), '/'),
            ],
            'production' => [
                'gateway_base_url' => rtrim((string) env(
                    'FSE2_PRODUCTION_GATEWAY_URL',
                    'https://modipa.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1'
                ), '/'),
            ],
        ];
    }

    public function gatewayUrl(string $environment, ?string $override = null): string
    {
        $override = rtrim(trim((string) $override), '/');
        if ($override !== '') {
            return $override;
        }

        $environment = strtolower(trim($environment));

        return (string) ($this->environments[$environment]['gateway_base_url']
            ?? $this->environments['test']['gateway_base_url']);
    }

    /** Resolve transport independently of JWT audience; no implicit national fallback for Tuscany. */
    public function gatewayUrlForProfile(array $profile): string
    {
        $environment = (string) ($profile['environment'] ?? 'test');
        if (!in_array($environment, ['test', 'production'], true)) throw new \RuntimeException('Ambiente FSE non riconosciuto.');
        $mode = (string) ($profile['access_mode'] ?? 'gateway');
        if (!in_array($mode, ['gateway', 'regional', 'toscana_privati'], true)) throw new \RuntimeException('Modalità FSE non riconosciuta.');
        $override = trim((string) ($profile['gateway_base_url'] ?? ''));
        if ($mode === 'toscana_privati') {
            $expected = $environment === 'production'
                ? 'https://fse20gw.regione.toscana.it/gateway/v2'
                : 'https://fse20gwstage.regione.toscana.it/gateway/v2';
            if ($override !== '' && rtrim($override, '/') !== $expected) throw new \RuntimeException('URL non coerente con ambiente Toscana Privati v2.');
            return $expected;
        }
        if ($mode === 'regional' && $override === '') throw new \RuntimeException('Il middleware regionale richiede un URL esplicito.');
        $url = $this->gatewayUrl($environment, $override);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('URL Gateway FSE HTTPS non valido.');
        }
        if (in_array(strtolower($parts['host']), ['fse20gw.regione.toscana.it', 'fse20gwstage.regione.toscana.it'], true)) {
            throw new \RuntimeException('Per questo endpoint selezionare Toscana Privati v2.');
        }
        if ($environment === 'test' && strtolower($parts['host']) === 'modipa.fse.salute.gov.it') {
            throw new \RuntimeException('Un profilo di test non può usare il Gateway nazionale di produzione.');
        }
        return $url;
    }

    public function jwtAudienceForProfile(array $profile): string
    {
        $metadata = json_decode((string) ($profile['metadata_json'] ?? ''), true);
        $audience = trim((string) ($profile['jwt_audience'] ?? $metadata['jwt_audience'] ?? ''));
        if ($audience !== '') return $audience;
        if (($profile['access_mode'] ?? '') === 'toscana_privati') {
            throw new \RuntimeException('Audience JWT Toscana da confermare con CART e configurare esplicitamente.');
        }
        return $this->gatewayUrlForProfile($profile);
    }

    public function resolveCertificatePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($this->isAbsolutePath($path)) {
            if (!$this->allowAbsoluteCertificatePaths) {
                throw new \RuntimeException('I percorsi certificato assoluti richiedono FSE2_ALLOW_ABSOLUTE_CERT_PATHS=true.');
            }

            return $path;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $normalized), static fn(string $item): bool => $item !== ''));
        if (in_array('..', $segments, true)) {
            throw new \RuntimeException('Percorso certificato FSE non valido.');
        }

        return rtrim($this->secretsRoot, '\\/') . DIRECTORY_SEPARATOR . ltrim($normalized, '\\/');
    }

    private function resolvePath(string $configured, string $fallback): string
    {
        $configured = trim($configured);

        return $configured !== '' ? $configured : $fallback;
    }

    private function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path);
    }

    /** @param mixed $value */
    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
