<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Services\Pacs\{CurlPacsTransport,DicomWebClient,PacsException};
use CodeIgniter\Test\CIUnitTestCase;

/** Real Orthanc TLS test. The only loopback resolver override lives in excluded test code. */
final class OrthancInteropTest extends CIUnitTestCase
{
    private function lab(): array
    {
        $root=getenv('PACS_SYNTHETIC_LAB');
        if (!$root) $this->markTestSkipped('Explicit synthetic Orthanc laboratory not requested.');
        $m=json_decode(file_get_contents($root.'/manifest.json'),true,32,JSON_THROW_ON_ERROR);
        $this->assertSame('ambulatoriofacile-pacs-synthetic-v1',$m['marker']);
        $this->assertSame(18443,$m['port']);
        return [$root,$m];
    }
    private function client(string $root,array $m,bool $wrongPassword=false,bool $trust=true): DicomWebClient
    {
        putenv('PACS_SYNTHETIC_USER='.$m['username']); putenv('PACS_SYNTHETIC_PASSWORD='.($wrongPassword ? 'wrong' : $m['password']));
        $p=MemoryPacsTransport::profile();
        $p['qido_url']=$p['wado_url']='https://pacs.example.test:18443/dicom-web';
        $p['auth']='basic'; $p['username_env']='PACS_SYNTHETIC_USER'; $p['password_env']='PACS_SYNTHETIC_PASSWORD';
        if ($trust) $p['ca_file']=$root.'/ca.pem';
        $transport=new class extends CurlPacsTransport {
            protected function resolve(string $host): array {
                if ($host!=='pacs.example.test') throw new PacsException('Unexpected lab host.');
                return ['127.0.0.1'];
            }
        };
        return new DicomWebClient($p,$transport);
    }
    protected function tearDown(): void
    { putenv('PACS_SYNTHETIC_USER'); putenv('PACS_SYNTHETIC_PASSWORD'); parent::tearDown(); }
    public function testRealQidoWadoAndBytePerfectDicomDownload(): void
    {
        [$root,$m]=$this->lab(); $c=$this->client($root,$m); $s=$m['studies'][0];
        $search=$c->studies($s['patient'],$s['issuer']);
        $this->assertCount(1,$search['studies']); $this->assertSame($s['study'],$search['studies'][0]['uid']);
        $this->assertSame($s['study'],$c->verifiedStudy($s['study'],$s['patient'],$s['issuer'])['uid']);
        $this->assertSame($s['series'],$c->series($s['study'])['rows'][0]['uid']);
        $this->assertSame($s['instance'],$c->instances($s['study'],$s['series'])['rows'][0]['uid']);
        $file=$c->download($s['study'],$s['series'],$s['instance'],$s['patient'],$s['issuer']);
        $this->assertSame(hash_file('sha256',$root.'/synthetic-1.dcm'),hash('sha256',$file['bytes']));
        $this->assertSame('application/dicom',$file['mime']);
    }
    public function testRealOtherPatientAndInvalidCredentialsAreDenied(): void
    {
        [$root,$m]=$this->lab();
        $c=$this->client($root,$m);
        $this->assertSame([],$c->studies('P-100','OTHER-HOSPITAL')['studies']);
        $c=$this->client($root,$m,true);
        $this->expectException(PacsException::class); $c->studies('P-100','TEST-HOSPITAL');
    }
    public function testTlsTrustCannotBeBypassed(): void
    {
        [$root,$m]=$this->lab();
        $c=$this->client($root,$m,false,false);
        $this->expectException(PacsException::class); $c->studies('P-100','TEST-HOSPITAL');
    }
}
