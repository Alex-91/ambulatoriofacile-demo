<?php

namespace Tests\Unit;

use App\Config\Fse2;
use App\Services\FseJwtService;
use CodeIgniter\Test\CIUnitTestCase;

final class FseJwtServiceTest extends CIUnitTestCase
{
    public function testCreatesTwoRs256TokensWithAttachmentHash(): void
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($private);
        $csr = openssl_csr_new(['commonName' => 'A1#FSETEST'], $private, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $private, 1, ['digest_alg' => 'sha256']);
        openssl_pkey_export($private, $privatePem);
        openssl_x509_export($cert, $certificatePem);
        $base = tempnam(sys_get_temp_dir(), 'fsejwt');
        $keyPath = $base . '.key.pem'; $certPath = $base . '.cert.pem'; $pdfPath = $base . '.pdf';
        file_put_contents($keyPath, $privatePem); file_put_contents($certPath, $certificatePem); file_put_contents($pdfPath, '%PDF-test');
        try {
            $config = new Fse2(); $config->allowAbsoluteCertificatePaths = true;
            $service = new FseJwtService($config);
            $profile = [
                'environment' => 'test', 'signature_certificate_path' => $certPath, 'signature_private_key_path' => $keyPath,
                'subject_role' => 'DRS', 'locality' => 'Test', 'organization_name' => 'Studio Test', 'organization_id' => '001',
                'app_id' => 'AFTEST', 'app_vendor' => 'AmbulatorioFacile', 'app_version' => 'test',
            ];
            $document = ['author_cf' => 'VRDLGI70A01H501X', 'patient_cf' => 'RSSMRA80A01H501U', 'patient_consent' => true, 'loinc_code' => '11488-4'];
            $tokens = $service->createTokens($profile, $document, $pdfPath);
            $this->assertCount(3, explode('.', $tokens['authorization']));
            $this->assertCount(3, explode('.', $tokens['signature']));
            $this->assertSame(hash_file('sha256', $pdfPath), $tokens['claims']['signature']['attachment_hash']);
            $this->assertSame('CREATE', $tokens['claims']['signature']['action_id']);
            $this->assertCount(3, explode('.', $service->createAuthorizationToken($profile, $document)));
        } finally {
            foreach ([$keyPath, $certPath, $pdfPath, $base] as $path) if (is_file($path)) unlink($path);
        }
    }
}
