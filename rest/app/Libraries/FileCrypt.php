<?php namespace App\Libraries;

class FileCrypt
{
    public static function algo(): string
    {
        return getenv('FILE_CRYPT_ALGO') ?: 'AES-256-CBC';
    }

    public static function key(): string
    {
        return getenv('FILE_CRYPT_KEY') ?: '123456';
    }

    public static function iv(): string
    {
        // AES-256-CBC vuole 16 byte
        $iv = getenv('FILE_CRYPT_IV') ?: '12dasdq3g5b2434b';
        return substr(str_pad($iv, 16, "\0"), 0, 16);
    }

    public static function encryptBytes(string $plain): string|false
    {
        return openssl_encrypt($plain, self::algo(), self::key(), OPENSSL_RAW_DATA, self::iv());
    }

    public static function decryptBytes(string $cipher): string|false
    {
        return openssl_decrypt($cipher, self::algo(), self::key(), OPENSSL_RAW_DATA, self::iv());
    }

    public static function decryptStoredPayload(string $payload): string|false
    {
        // Legacy/imported attachments are stored as raw encrypted bytes.
        $plain = self::decryptBytes($payload);
        if ($plain !== false) {
            return $plain;
        }

        // Backward compatibility for files saved by the newer module as base64(cipher).
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return false;
        }

        return self::decryptBytes($decoded);
    }
}
