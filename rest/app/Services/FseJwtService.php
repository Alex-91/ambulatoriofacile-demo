<?php

namespace App\Services;

use App\Config\Fse2;

class FseJwtService
{
    private Fse2 $config;

    public function __construct(?Fse2 $config = null)
    {
        $this->config = $config ?? config(Fse2::class);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $document
     * @return array{authorization:string,signature:string,claims:array<string,array<string,mixed>>}
     */
    public function createTokens(array $profile, array $document, string $filePath, string $action = 'CREATE'): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Documento FSE non disponibile per il calcolo dell’hash JWT.');
        }

        $certificatePath = $this->config->resolveCertificatePath((string) ($profile['signature_certificate_path'] ?? ''));
        $privateKeyPath = $this->config->resolveCertificatePath((string) ($profile['signature_private_key_path'] ?? ''));
        $certificatePem = $this->readFile($certificatePath, 'certificato signature');
        $privateKeyPem = $this->readFile($privateKeyPath, 'chiave privata signature');
        $privateKeyPassphrase = (string) ($profile['signature_private_key_passphrase'] ?? '');
        $commonName = $this->certificateCommonName($certificatePem);
        $x5c = $this->certificateDerBase64($certificatePem);
        $baseUrl = $this->config->gatewayUrl(
            (string) ($profile['environment'] ?? 'test'),
            (string) ($profile['gateway_base_url'] ?? '')
        );
        $now = time();
        $authorSubject = $this->iheSubject((string) ($document['author_cf'] ?? $profile['author_cf'] ?? ''));
        $patientSubject = $this->iheSubject((string) ($document['patient_cf'] ?? ''));

        $reserved = [
            'aud' => $baseUrl,
            'sub' => $authorSubject,
            'iat' => $now,
            'exp' => $now + $this->config->jwtTtlSeconds,
            'jti' => bin2hex(random_bytes(16)),
        ];
        $authorizationClaims = ['iss' => 'auth:' . $commonName] + $reserved;
        $signatureClaims = [
            'iss' => 'integrity:' . $commonName,
            'subject_role' => trim((string) ($profile['subject_role'] ?? 'DRS')),
            'purpose_of_use' => 'TREATMENT',
            'locality' => trim((string) ($profile['locality'] ?? '')),
            'subject_organization' => trim((string) ($profile['organization_name'] ?? '')),
            'subject_organization_id' => trim((string) ($profile['organization_id'] ?? '')),
            'person_id' => $patientSubject,
            'patient_consent' => (bool) ($document['patient_consent'] ?? true),
            'action_id' => strtoupper($action),
            'resource_hl7_type' => "('" . trim((string) ($document['loinc_code'] ?? '11488-4')) . '^^' . Fse2::LOINC_OID . "')",
            'attachment_hash' => hash_file('sha256', $filePath),
            'subject_application_id' => trim((string) ($profile['app_id'] ?? 'AMBULATORIOFACILE')),
            'subject_application_vendor' => trim((string) ($profile['app_vendor'] ?? 'AMBULATORIOFACILE')),
            'subject_application_version' => trim((string) ($profile['app_version'] ?? '1.0.0')),
        ] + $reserved;

        foreach ($signatureClaims as $key => $value) {
            if ($value === '' || $value === null) {
                throw new \RuntimeException('Claim JWT FSE obbligatorio mancante: ' . $key . '.');
            }
        }

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'x5c' => [$x5c]];

        return [
            'authorization' => $this->encode($header, $authorizationClaims, $privateKeyPem, $privateKeyPassphrase),
            'signature' => $this->encode($header, $signatureClaims, $privateKeyPem, $privateKeyPassphrase),
            'claims' => [
                'authorization' => $authorizationClaims,
                'signature' => $signatureClaims,
            ],
        ];
    }

    /** @param array<string,mixed> $profile @param array<string,mixed> $document */
    public function createAuthorizationToken(array $profile, array $document): string
    {
        $certificatePath = $this->config->resolveCertificatePath((string) ($profile['signature_certificate_path'] ?? ''));
        $privateKeyPath = $this->config->resolveCertificatePath((string) ($profile['signature_private_key_path'] ?? ''));
        $certificatePem = $this->readFile($certificatePath, 'certificato signature');
        $privateKeyPem = $this->readFile($privateKeyPath, 'chiave privata signature');
        $now = time();
        $claims = [
            'iss' => 'auth:' . $this->certificateCommonName($certificatePem),
            'aud' => $this->config->gatewayUrl((string) ($profile['environment'] ?? 'test'), (string) ($profile['gateway_base_url'] ?? '')),
            'sub' => $this->iheSubject((string) ($document['author_cf'] ?? $profile['author_cf'] ?? '')),
            'iat' => $now, 'exp' => $now + $this->config->jwtTtlSeconds, 'jti' => bin2hex(random_bytes(16)),
        ];
        return $this->encode(
            ['alg' => 'RS256', 'typ' => 'JWT', 'x5c' => [$this->certificateDerBase64($certificatePem)]],
            $claims,
            $privateKeyPem,
            (string) ($profile['signature_private_key_passphrase'] ?? '')
        );
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public function encode(array $header, array $claims, string $privateKeyPem, string $passphrase = ''): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($privateKey === false) {
            throw new \RuntimeException('Chiave privata signature FSE non leggibile.');
        }

        $segments = [
            $this->base64UrlEncode($this->json($header)),
            $this->base64UrlEncode($this->json($claims)),
        ];
        $signingInput = implode('.', $segments);
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firma JWT FSE non riuscita.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function iheSubject(string $fiscalCode): string
    {
        $fiscalCode = strtoupper(trim($fiscalCode));
        if (!preg_match('/^[A-Z0-9]{11,16}$/', $fiscalCode)) {
            throw new \RuntimeException('Codice fiscale non valido per il token FSE.');
        }

        return $fiscalCode . '^^^&' . Fse2::CF_OID . '&ISO';
    }

    private function certificateCommonName(string $certificatePem): string
    {
        $parsed = openssl_x509_parse($certificatePem);
        $commonName = trim((string) ($parsed['subject']['CN'] ?? ''));
        if ($commonName === '') {
            throw new \RuntimeException('Common Name non presente nel certificato signature FSE.');
        }

        return $commonName;
    }

    private function certificateDerBase64(string $certificatePem): string
    {
        $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificatePem) ?? '';
        if ($body === '' || base64_decode($body, true) === false) {
            throw new \RuntimeException('Certificato signature FSE non in formato PEM valido.');
        }

        return $body;
    }

    private function readFile(string $path, string $label): string
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('File ' . $label . ' FSE non disponibile.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            throw new \RuntimeException('File ' . $label . ' FSE vuoto.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Serializzazione JWT FSE non riuscita.');
        }

        return $json;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
