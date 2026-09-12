<?php
namespace Tests\Unit;
use App\Config\Fse2;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseNegativeHarnessTest extends CIUnitTestCase
{
    public static function jwtFaults(): array { return [['jwt-missing-purpose'],['jwt-action-invalid']]; }

    #[DataProvider('jwtFaults')]
    public function testFaultsAreSignedWithSyntheticKeysAndConfinedToTestProfile(string $fault): void
    {
        require_once dirname(APPPATH,2) . '/ops/fse-validation/gateway-negative-jwt.php';
        $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        $csr=openssl_csr_new(['commonName'=>'S1#111#SYNTHETIC'],$key,['digest_alg'=>'sha256']);
        $cert=openssl_csr_sign($csr,null,$key,2,['digest_alg'=>'sha256']);
        openssl_pkey_export($key,$keyPem); openssl_x509_export($cert,$certPem);
        $certPath=tempnam(sys_get_temp_dir(),'fse-fake-cert-'); $keyPath=tempnam(sys_get_temp_dir(),'fse-fake-key-');
        $pdfPath=tempnam(sys_get_temp_dir(),'fse-fake-pdf-');
        try {
            file_put_contents($certPath,$certPem); file_put_contents($keyPath,$keyPem); file_put_contents($pdfPath,'%PDF-SYNTHETIC');
            $config=new Fse2(); $config->allowAbsoluteCertificatePaths=true;
            $endpoint='https://modipa-val.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1';
            $profile=['environment'=>'test','access_mode'=>'gateway','gateway_base_url'=>$endpoint,'jwt_audience'=>$endpoint,
                'signature_certificate_path'=>$certPath,'signature_private_key_path'=>$keyPath,'locality'=>'TEST',
                'organization_name'=>'SYNTHETIC','organization_id'=>'TEST'];
            $jwt=new \FseNegativeTestJwt($config,$fault);
            $result=$jwt->createTokens($profile,['author_cf'=>'RSSMRA80A01H501U','patient_cf'=>'RSSMRA80A01H501U'],$pdfPath);
            $claims=$result['claims']['signature'];
            if ($fault==='jwt-missing-purpose') { $this->assertArrayNotHasKey('purpose_of_use',$claims); $this->assertSame('CREATE',$claims['action_id']); }
            else { $this->assertSame('TEST',$claims['action_id']); $this->assertSame('TREATMENT',$claims['purpose_of_use']); }
            [$h,$c,$s]=explode('.',$result['signature']);
            $this->assertSame(1,openssl_verify($h.'.'.$c,base64_decode(strtr($s,'-_','+/')),$certPem,OPENSSL_ALGO_SHA256));
            $this->assertSame(hash_file('sha256',$pdfPath),$claims['attachment_hash']);
            $profile['environment']='production';
            $this->expectExceptionMessage('restricted'); $jwt->createTokens($profile,[],$pdfPath);
        } finally { unlink($certPath); unlink($keyPath); unlink($pdfPath); }
    }
}
