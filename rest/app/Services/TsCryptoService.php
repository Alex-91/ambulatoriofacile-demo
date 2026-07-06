<?php

namespace App\Services;

use App\Config\TsBilling;

class TsCryptoService
{
    private TsBilling $config;
    private ?string $cachedCertificatePem = null;

    public function __construct(?TsBilling $config = null)
    {
        $this->config = $config ?? config(TsBilling::class);
    }

    public function encryptRequired(string $plainText): string
    {
        $plainText = trim($plainText);
        if ($plainText === '') {
            throw new \RuntimeException('Valore TS obbligatorio mancante per la cifratura.');
        }

        $publicKey = openssl_pkey_get_public($this->resolveCertificatePem());
        if ($publicKey === false) {
            throw new \RuntimeException('Impossibile caricare la chiave pubblica del certificato TS.');
        }

        $encrypted = '';
        $ok = openssl_public_encrypt($plainText, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
        if (!$ok || $encrypted === '') {
            throw new \RuntimeException('Impossibile cifrare il valore richiesto per il Sistema TS.');
        }

        return base64_encode($encrypted);
    }

    public function encryptOptional(?string $plainText): ?string
    {
        $plainText = trim((string) $plainText);
        if ($plainText === '') {
            return null;
        }

        return $this->encryptRequired($plainText);
    }

    private function resolveCertificatePem(): string
    {
        if ($this->cachedCertificatePem !== null) {
            return $this->cachedCertificatePem;
        }

        $path = trim($this->config->publicCertPath);
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Certificato pubblico TS non trovato: ' . $path);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Impossibile leggere il certificato pubblico TS.');
        }

        if (strpos($raw, 'BEGIN CERTIFICATE') !== false) {
            return $this->cachedCertificatePem = $raw;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($raw), 64, "\n")
            . "-----END CERTIFICATE-----\n";

        return $this->cachedCertificatePem = $pem;
    }
}
