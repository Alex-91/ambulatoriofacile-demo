<?php

namespace App\Services;

/** Local key/certificate checks only: does NOT attest trust, revocation or accreditation. */
final class FseCertificateInspector
{
    public static function inspect(string $certificatePem, string $privateKeyPem, string $passphrase = '', ?string $csrPem = null, ?int $now = null): array
    {
        if (!preg_match('/\A\s*-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----\s*\z/D', $certificatePem)) {
            throw new \RuntimeException('Atteso un singolo certificato PEM, senza chiavi o contenuti aggiuntivi.');
        }
        $certificate = @openssl_x509_read($certificatePem);
        $key = @openssl_pkey_get_private($privateKeyPem, $passphrase);
        if ($certificate === false || $key === false) throw new \RuntimeException('Certificato o chiave privata non leggibili.');
        if (!openssl_x509_check_private_key($certificate, $key)) throw new \RuntimeException('Certificato e chiave privata non corrispondono.');
        $parsed = openssl_x509_parse($certificate);
        $details = openssl_pkey_get_details($key);
        if (!is_array($parsed) || !is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new \RuntimeException('Il Gateway richiede una coppia RSA di almeno 2048 bit.');
        }
        $now ??= time();
        if (($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now) throw new \RuntimeException('Certificato non ancora valido.');
        if (($parsed['validTo_time_t'] ?? 0) <= $now) throw new \RuntimeException('Certificato scaduto.');
        $cn = $parsed['subject']['CN'] ?? null;
        if (!is_string($cn) || trim($cn) === '') throw new \RuntimeException('Common Name certificato non disponibile.');
        if ($csrPem !== null) {
            $csrKey = @openssl_csr_get_public_key($csrPem);
            $csrDetails = $csrKey === false ? false : openssl_pkey_get_details($csrKey);
            if (!is_array($csrDetails) || !hash_equals($details['key'], $csrDetails['key'])) {
                throw new \RuntimeException('Il certificato non corrisponde alla CSR originale.');
            }
        }
        return ['common_name' => $cn, 'issuer_common_name' => $parsed['issuer']['CN'] ?? '',
            'valid_from' => gmdate('c', $parsed['validFrom_time_t']), 'valid_to' => gmdate('c', $parsed['validTo_time_t']),
            'certificate_sha256' => openssl_x509_fingerprint($certificate, 'sha256'),
            'public_key_sha256' => hash('sha256', $details['key']), 'rsa_bits' => $details['bits'],
            'key_pair_matches' => true, 'csr_matches' => $csrPem !== null,
            'key_usage' => $parsed['extensions']['keyUsage'] ?? '',
            'extended_key_usage' => $parsed['extensions']['extendedKeyUsage'] ?? ''];
    }
}
