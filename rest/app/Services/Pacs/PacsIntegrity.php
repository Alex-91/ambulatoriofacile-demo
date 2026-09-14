<?php
namespace App\Services\Pacs;

use App\Services\FseSecretsService;

final class PacsIntegrity
{
    public static function hash(string $value): string
    {
        $legacy = (string) config(\App\Config\Crypto::class)->keyHex;
        // Preserve hashes already stored by deployments using the legacy key.
        if (preg_match('/^[a-f0-9]{64}$/iD', $legacy)) {
            return hash_hmac('sha256', $value, hex2bin($legacy));
        }
        return (new FseSecretsService())->keyedHash('pacs-integrity:v1', $value);
    }
}
