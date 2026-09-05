<?php

namespace App\Services;

use App\Config\Crypto as CryptoConfig;
use Config\Encryption;

class TenantNotificationSecretsService
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'notifsec:v1:';

    private ?string $binaryKey = null;

    public function __construct(?string $keySeed = null)
    {
        if ($keySeed !== null && $keySeed !== '') {
            $this->binaryKey = hash('sha256', $keySeed, true);
        }
    }

    public function encrypt(?string $plainText): ?string
    {
        if ($plainText === null || $plainText === '') {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->resolveBinaryKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if (!is_string($cipherText) || $cipherText === '') {
            throw new \RuntimeException('Impossibile cifrare la password SMTP.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public function decrypt(?string $payload): ?string
    {
        $payload = trim((string) $payload);
        if ($payload === '') {
            return null;
        }
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new \RuntimeException('Formato password SMTP non riconosciuto.');
        }

        $binary = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if (!is_string($binary) || strlen($binary) < 29) {
            throw new \RuntimeException('Password SMTP cifrata non valida.');
        }

        $plainText = openssl_decrypt(
            substr($binary, 28),
            self::CIPHER,
            $this->resolveBinaryKey(),
            OPENSSL_RAW_DATA,
            substr($binary, 0, 12),
            substr($binary, 12, 16)
        );

        if (!is_string($plainText)) {
            throw new \RuntimeException('Impossibile decifrare la password SMTP.');
        }

        return $plainText;
    }

    private function resolveBinaryKey(): string
    {
        if ($this->binaryKey !== null) {
            return $this->binaryKey;
        }

        $cryptoConfig = config(CryptoConfig::class);
        $cryptoKeyHex = trim((string) ($cryptoConfig->keyHex ?? ''));
        if ($cryptoKeyHex !== '' && ctype_xdigit($cryptoKeyHex) && strlen($cryptoKeyHex) % 2 === 0) {
            $decoded = hex2bin($cryptoKeyHex);
            if (is_string($decoded) && $decoded !== '') {
                return $this->binaryKey = hash('sha256', $decoded, true);
            }
        }

        foreach ([
            trim((string) env('NOTIFICATION_SECRET_KEY', '')),
            trim((string) getenv('database.platform.DB_ENCRYPTION_KEY')),
            trim((string) getenv('database.default.DB_ENCRYPTION_KEY')),
            trim((string) (config(Encryption::class)->key ?? '')),
        ] as $seed) {
            if ($seed !== '') {
                return $this->binaryKey = hash('sha256', $seed, true);
            }
        }

        throw new \RuntimeException('Chiave di cifratura per le credenziali SMTP non configurata.');
    }
}
