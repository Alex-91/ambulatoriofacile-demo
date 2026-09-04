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
    public bool $allowAbsoluteCertificatePaths = false;
    public int $connectTimeout = 20;
    public int $requestTimeout = 90;
    public int $jwtTtlSeconds = 300;
    public int $maxPdfBytes = 15728640;

    public function __construct()
    {
        $this->tenantStorageRoot = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'tenants';
        $this->secretsRoot = $this->resolvePath(
            (string) env('FSE2_SECRETS_ROOT', ''),
            rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'fse2' . DIRECTORY_SEPARATOR . 'secrets'
        );
        $this->allowProduction = $this->toBoolean(env('FSE2_ALLOW_PRODUCTION', false));
        $this->allowAbsoluteCertificatePaths = $this->toBoolean(env('FSE2_ALLOW_ABSOLUTE_CERT_PATHS', false));
        $this->connectTimeout = max(1, (int) env('FSE2_CONNECT_TIMEOUT', 20));
        $this->requestTimeout = max(5, (int) env('FSE2_REQUEST_TIMEOUT', 90));
        $this->jwtTtlSeconds = max(60, min(600, (int) env('FSE2_JWT_TTL_SECONDS', 300)));
        $this->maxPdfBytes = max(1048576, (int) env('FSE2_MAX_PDF_BYTES', 15728640));

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
