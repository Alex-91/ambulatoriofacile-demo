<?php

namespace Tests\Unit;

use App\Services\FseCertificateInspector;
use CodeIgniter\Test\CIUnitTestCase;

final class FseCertificateInspectorTest extends CIUnitTestCase
{
    private function fixture(int $bits = 2048): array
    {
        $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'S1#111#SYNTHETIC'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $keyPem);
        openssl_csr_export($csr, $csrPem);
        openssl_x509_export($cert, $certPem);
        return [$certPem, $keyPem, $csrPem];
    }

    public function testValidPairAndCsrReturnPublicMetadataOnly(): void
    {
        [$cert, $key, $csr] = $this->fixture();
        $result = FseCertificateInspector::inspect($cert, $key, '', $csr);
        $this->assertTrue($result['key_pair_matches']);
        $this->assertTrue($result['csr_matches']);
        $this->assertSame(2048, $result['rsa_bits']);
        $this->assertSame('S1#111#SYNTHETIC', $result['common_name']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function testMismatchedPrivateKeyIsRejected(): void
    {
        [$cert] = $this->fixture(); [, $key] = $this->fixture();
        $this->expectExceptionMessage('chiave privata non corrispondono');
        FseCertificateInspector::inspect($cert, $key);
    }

    public function testMismatchedCsrIsRejected(): void
    {
        [$cert, $key] = $this->fixture(); [, , $csr] = $this->fixture();
        $this->expectExceptionMessage('CSR originale');
        FseCertificateInspector::inspect($cert, $key, '', $csr);
    }

    public function testExpiredCertificateIsRejected(): void
    {
        [$cert, $key] = $this->fixture();
        $this->expectExceptionMessage('scaduto');
        FseCertificateInspector::inspect($cert, $key, '', null, time() + 10 * 86400);
    }

    public function testNotYetValidCertificateIsRejected(): void
    {
        [$cert, $key] = $this->fixture();
        $this->expectExceptionMessage('non ancora valido');
        FseCertificateInspector::inspect($cert, $key, '', null, time() - 10 * 86400);
    }

    public function testWeakRsaIsRejected(): void
    {
        [$cert, $key] = $this->fixture(1024);
        $this->expectExceptionMessage('2048');
        FseCertificateInspector::inspect($cert, $key);
    }

    public function testPemWithAppendedKeyIsRejected(): void
    {
        [$cert, $key] = $this->fixture();
        $this->expectExceptionMessage('singolo certificato');
        FseCertificateInspector::inspect($cert . $key, $key);
    }

    public function testEncryptedKeySupportsCorrectPassphrase(): void
    {
        [$cert, $key] = $this->fixture();
        openssl_pkey_export(openssl_pkey_get_private($key), $encrypted, 'synthetic-passphrase');
        $this->assertTrue(FseCertificateInspector::inspect($cert, $encrypted, 'synthetic-passphrase')['key_pair_matches']);
        $this->expectExceptionMessage('non leggibili');
        FseCertificateInspector::inspect($cert, $encrypted, 'wrong-passphrase');
    }
}
