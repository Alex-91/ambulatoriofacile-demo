<?php
namespace App\Services;

/** Standard external signatures: no vendor credentials or private signing keys. */
class ClinicalSignatureService extends FseArtifactValidationService
{
    public function verify(string $original, string $signed, string $format, string $authorCf): array
    {
        $authorCf = strtoupper(trim($authorCf));
        if (!in_array($format, ['pades','cades'], true) || !preg_match('/^[A-Z0-9]{16}$/D', $authorCf)
            || !str_starts_with($original, '%PDF-') || $signed === ''
            || strlen($original) > ClinicalVault::MAX_BYTES || strlen($signed) > ClinicalVault::MAX_BYTES) {
            throw new \InvalidArgumentException('Formato, documento o identità del firmatario non validi.');
        }
        try {
            $result = $this->run(['operation'=>'clinical_signature','format'=>$format,
                'unsigned'=>base64_encode($original),'signed'=>base64_encode($signed),'author_cf'=>$authorCf]);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Verifica della firma non completata. Controllare formato, identità del firmatario e configurazione del validatore: '.$e->getMessage(),0,$e);
        }
        if (($result['signature'] ?? '') !== 'valid' || ($result['format'] ?? '') !== $format
            || !hash_equals(hash('sha256',$original), (string)($result['original_sha256'] ?? ''))
            || !hash_equals(hash('sha256',$signed), (string)($result['signed_sha256'] ?? ''))
            || ($result['trust_policy'] ?? '') !== 'explicit-roots-offline-revocation-required') {
            throw new \RuntimeException('Esito della verifica della firma non verificabile.');
        }
        $evidence = array_intersect_key($result,array_flip(['signature','format','original_sha256','signed_sha256',
            'signer_certificate_sha256','trust_policy']));
        $evidence['qualified_signature'] = 'not_assessed';
        $evidence['verified_at'] = gmdate('c');
        return $evidence;
    }
}
