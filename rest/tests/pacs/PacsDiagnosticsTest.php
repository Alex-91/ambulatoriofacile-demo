<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Services\Pacs\{PacsDiagnostics,PacsProfiles,PacsTransport};
use CodeIgniter\Test\CIUnitTestCase;

final class PacsDiagnosticsTest extends CIUnitTestCase
{
    public function testProbeUsesRandomIdentityAndNeverReturnsRemoteContent(): void
    {
        $transport=new class implements PacsTransport {
            public array $urls=[];
            public array $reply=['status'=>200,'type'=>'application/dicom+json','body'=>'[]','warning'=>false];
            public function get(array $profile,string $url,string $accept,int $maxBytes): array {
                $this->urls[]=$url;
                if ($maxBytes>8192) throw new \RuntimeException('Unbounded probe');
                return $this->reply;
            }
        };
        $service=new PacsDiagnostics(new PacsProfiles(['tenants'=>['42'=>[MemoryPacsTransport::profile()]]]),$transport);
        $this->assertTrue($service->probe(42,'cloud')['ok']);
        $this->assertTrue($service->probe(42,'cloud')['ok']);
        $this->assertNotSame($transport->urls[0],$transport->urls[1]);
        parse_str(parse_url($transport->urls[0],PHP_URL_QUERY),$query);
        $this->assertMatchesRegularExpression('/^AF-CONNECTION-CHECK-[a-f0-9]{32}$/',$query['PatientID']);
        $this->assertSame('1',$query['limit']);
        foreach ([401,403,500,302,200] as $code) {
            $transport->reply=['status'=>$code,'type'=>'application/dicom+json','body'=>'[{"secret":"PATIENT-SECRET"}]','warning'=>false];
            $result=$service->probe(42,'cloud');
            $this->assertFalse($result['ok']); $this->assertStringNotContainsString('PATIENT-SECRET',json_encode($result));
        }
        $before=count($transport->urls);
        try { $service->probe(43,'cloud'); $this->fail('Wrong tenant accepted'); } catch (\App\Services\Pacs\PacsException) { $this->assertCount($before,$transport->urls); }
    }
}
