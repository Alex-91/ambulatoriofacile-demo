<?php
namespace Tests\Unit;

use App\Config\Fse2;
use App\Services\{FseToscanaStagePreflight,FseGatewayClient,FseGatewayTransport,FseJwtService,FseRadiologyData};
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseToscanaStagePreflightTest extends CIUnitTestCase
{
    private array $files = [];

    private function pair(string $cn): array
    {
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
        $csr=openssl_csr_new(['commonName'=>$cn],$key,['digest_alg'=>'sha256']);
        $cert=openssl_csr_sign($csr,null,$key,2,['digest_alg'=>'sha256']);
        openssl_x509_export($cert,$certPem); openssl_pkey_export($key,$keyPem);
        $paths=[];
        foreach ([$certPem,$keyPem] as $pem) { $path=tempnam(sys_get_temp_dir(),'fse-090-test-'); file_put_contents($path,$pem); $this->files[]=$paths[]=$path; }
        return $paths;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) unlink($path);
        parent::tearDown();
    }

    private function config(): Fse2
    {
        $config=new Fse2(); $config->allowAbsoluteCertificatePaths=true; return $config;
    }

    private function profile(string $auth='A1#090#SYNTHETICXX', string $sign='S1#090#SYNTHETICXX'): array
    {
        [$cert,$key]=$this->pair($auth); [$signCert,$signKey]=$this->pair($sign);
        return ['access_mode'=>'toscana_privati','environment'=>'test','region_code'=>'090',
            'auth_certificate_path'=>$cert,'auth_private_key_path'=>$key,
            'signature_certificate_path'=>$signCert,'signature_private_key_path'=>$signKey];
    }

    public function testLocalReportCannotDeclareReadinessOrExposeKeys(): void
    {
        $report=(new FseToscanaStagePreflight($this->config()))->inspect($this->profile());
        $this->assertTrue($report['certificate_checks_passed']);
        $this->assertFalse($report['operational_ready']);
        $this->assertFalse($report['jwt_audience_configured']);
        $this->assertFalse($report['transport_switch_enabled']);
        $this->assertSame(0,$report['network_calls']);
        $this->assertSame('NOT_VERIFIED',$report['official_authorization']);
        foreach ($this->files as $path) $this->assertStringNotContainsString($path,json_encode($report));
        $this->assertStringNotContainsString('PRIVATE KEY',json_encode($report));
    }

    public static function wrongNames(): array
    {
        return [['A1#111#VENDOR','A1'],['S1#090#VENDOR','A1'],['A1#090VENDOR','A1'],
            ['S1#111#VENDOR','S1'],['S1#090#','S1'],['S1#090#VENDOR#OTHER','S1'],["S1#090#VENDOR\n",'S1']];
    }

    #[DataProvider('wrongNames')]
    public function testWrongRoleRegionOrShapeIsRejected(string $cn,string $role): void
    {
        $this->expectExceptionMessage('#090#'); FseToscanaStagePreflight::assertIdentity(['common_name'=>$cn],$role);
    }

    public function testMismatchedVendorsAreRejected(): void
    {
        $this->expectExceptionMessage('stesso fornitore');
        (new FseToscanaStagePreflight($this->config()))->inspect($this->profile('A1#090#ONE','S1#090#TWO'));
    }

    public function testWrongPrivateKeyIsRejected(): void
    {
        $profile=$this->profile(); $profile['auth_private_key_path']=$profile['signature_private_key_path'];
        $this->expectExceptionMessage('non corrispondono');
        (new FseToscanaStagePreflight($this->config()))->inspect($profile);
    }

    public function testRealClientBlocksOldNationalCertificatesBeforeJwtOrTransport(): void
    {
        $config=$this->config(); $config->allowToscanaStage=true;
        $profile=$this->profile('A1#111#SYNTHETIC','S1#111#SYNTHETIC');
        $jwt=$this->createMock(FseJwtService::class); $jwt->expects($this->never())->method('createTokens');
        $transport=$this->createMock(FseGatewayTransport::class); $transport->expects($this->never())->method('send');
        $this->expectExceptionMessage('A1#090#');
        (new FseGatewayClient($jwt,$config,$transport))->delete($profile+['document_oid_root'=>'1.2.3'],['document_unique_id'=>'SYNTHETIC'],'unused');
    }

    public function testProductionCannotPassLocalPreflight(): void
    {
        $this->expectExceptionMessage('ambiente test');
        (new FseToscanaStagePreflight($this->config()))->inspect(['access_mode'=>'toscana_privati','environment'=>'production','region_code'=>'090']);
    }

    public function testRadIsConfinedToToscanaTestUntilOfficialWorkflowIsEnabled(): void
    {
        FseRadiologyData::assertLocalProfile(['access_mode'=>'toscana_privati','environment'=>'test']);
        foreach ([['access_mode'=>'gateway','environment'=>'test'],['access_mode'=>'toscana_privati','environment'=>'production']] as $profile) {
            try { FseRadiologyData::assertLocalProfile($profile); $this->fail('RAD gate bypassed'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('preparazione locale',$e->getMessage()); }
        }
    }

    public function testCliProducesTechnicalReportAndRequiresExplicitTlsPermission(): void
    {
        $profile=$this->profile();
        foreach (['auth_certificate_path','auth_private_key_path','signature_certificate_path','signature_private_key_path'] as $field) $profile[$field]=basename($profile[$field]);
        $path=tempnam(sys_get_temp_dir(),'fse-090-profile-'); $this->files[]=$path;
        file_put_contents($path,json_encode($profile));
        foreach ([[],['--probe-tls']] as $flags) {
            $process=proc_open(array_merge([PHP_BINARY,'-d','xdebug.mode=off',dirname(APPPATH,2).'/ops/fse-validation/toscana-stage-preflight.php',
                '--profile='.$path,'--secrets-root='.sys_get_temp_dir()],$flags),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            fclose($pipes[0]); $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
            $this->assertSame('',$errors); $this->assertSame($flags ? 2 : 0,$exit);
            $report=json_decode($output,true,32,JSON_THROW_ON_ERROR);
            $this->assertSame(0,$report['network_calls']); $this->assertFalse($report['operational_ready']);
            if ($flags) $this->assertSame('PREFLIGHT_CART_CONFIRMATION_REQUIRED',$report['error_code']);
            $this->assertStringNotContainsString('PRIVATE KEY',$output);
        }
    }
}
