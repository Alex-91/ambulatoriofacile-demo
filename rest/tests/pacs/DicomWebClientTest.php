<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Services\Pacs\{DicomWebClient,PacsException,PacsProfiles,CurlPacsTransport,PacsFeatureService};
use App\Services\TenantFeatureService;
use CodeIgniter\Test\CIUnitTestCase;

final class DicomWebClientTest extends CIUnitTestCase
{
    public function testExactPatientSearchUsesStandardTagsAndOffset(): void
    {
        $t=new MemoryPacsTransport(); $c=new DicomWebClient(MemoryPacsTransport::profile(),$t);
        $result=$c->studies('P-100','TEST-HOSPITAL',2);
        $this->assertSame('1.2.826.0.1.100',$result['studies'][0]['uid']);
        parse_str(parse_url($t->calls[0]['url'],PHP_URL_QUERY),$query);
        $this->assertSame('P-100',$query['PatientID']); $this->assertSame('TEST-HOSPITAL',$query['IssuerOfPatientID']);
        $this->assertSame('25',$query['offset']); $this->assertSame('application/dicom+json',$t->calls[0]['accept']);
    }
    public function testWrongPatientMissingIssuerAndWrongStudyAreRejected(): void
    {
        foreach ([MemoryPacsTransport::study('WRONG'),MemoryPacsTransport::study('P-100',''),MemoryPacsTransport::study('P-100','TEST-HOSPITAL','1.2.3')] as $row) {
            $t=new MemoryPacsTransport(); $t->replies=[MemoryPacsTransport::json([$row])];
            try { (new DicomWebClient(MemoryPacsTransport::profile(),$t))->verifiedStudy('1.2.826.0.1.100','P-100','TEST-HOSPITAL'); $this->fail('Foreign study accepted'); }
            catch (PacsException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }
    public function testEmptyPartialMalformedAndRedirectResponses(): void
    {
        foreach ([
            ['status'=>302,'type'=>'text/html','body'=>'secret token upstream'],
            ['status'=>401,'type'=>'application/dicom+json','body'=>'secret upstream'],
            ['status'=>200,'type'=>'text/html','body'=>'<script>bad</script>'],
            ['status'=>200,'type'=>'application/dicom+json','body'=>'{'],
            ['status'=>200,'type'=>'application/dicom+json','body'=>'{}'],
        ] as $reply) {
            $t=new MemoryPacsTransport(); $t->replies=[$reply+['warning'=>false]];
            try { (new DicomWebClient(MemoryPacsTransport::profile(),$t))->studies('P-100','TEST-HOSPITAL'); $this->fail('Bad response accepted'); }
            catch (PacsException $e) { $this->assertStringNotContainsString('secret',$e->getMessage()); }
        }
        $t=new MemoryPacsTransport(); $t->replies=[['status'=>204,'type'=>'','body'=>'','warning'=>false]];
        $this->assertSame([], (new DicomWebClient(MemoryPacsTransport::profile(),$t))->studies('P-100','TEST-HOSPITAL')['studies']);
        $t->replies=[array_replace(MemoryPacsTransport::json([MemoryPacsTransport::study()]),['warning'=>true])];
        $this->assertTrue((new DicomWebClient(MemoryPacsTransport::profile(),$t))->studies('P-100','TEST-HOSPITAL')['more']);
    }
    public function testWildcardTraversalAndMalformedIdentifiersNeverReachTransport(): void
    {
        $t=new MemoryPacsTransport(); $c=new DicomWebClient(MemoryPacsTransport::profile(),$t);
        foreach (['*','P?','P\\100',"P\n100",''] as $value) {
            try { $c->studies($value,'TEST'); $this->fail('Wildcard identity accepted'); }
            catch (PacsException) { $this->assertCount(0,$t->calls); }
        }
        foreach (['../secrets','1.02.3','1.2/3',str_repeat('1.',40).'1'] as $uid) {
            try { $c->series($uid); $this->fail('Bad UID accepted'); }
            catch (PacsException) { $this->assertCount(0,$t->calls); }
        }
    }
    public function testProfileIsolationDefaultOffAndSecretsAreReferences(): void
    {
        $p=MemoryPacsTransport::profile();
        $profiles=new PacsProfiles(['tenants'=>['42'=>[$p]]]);
        $this->assertSame([],$profiles->forTenant(43));
        $this->assertSame('cloud',$profiles->get(42,'cloud')['id']);
        foreach ([['enabled'=>false],['auth'=>'bearer','token_env'=>'SECRET'],['qido_url'=>'http://localhost'],['wado_url'=>'https://user:pass@pacs.test/'],['viewer_url'=>'https://{study}.example.test/']] as $bad) {
            try { (new PacsProfiles(['tenants'=>['42'=>[array_replace($p,$bad)]]]))->get(42,'cloud'); $this->fail('Invalid profile accepted'); }
            catch (PacsException) { $this->assertTrue(true); }
        }
    }
    public function testPublicCloudTransportRejectsPrivateLoopbackAndReservedDestinations(): void
    {
        foreach (['127.0.0.1','10.0.0.1','172.16.0.1','192.168.1.1','169.254.169.254','100.100.100.200','198.18.0.1','224.0.0.1','0.0.0.0','::1','::ffff:127.0.0.1'] as $ip) $this->assertFalse(CurlPacsTransport::publicAddress($ip),$ip);
        $this->assertTrue(CurlPacsTransport::publicAddress('8.8.8.8'));
        $this->expectException(PacsException::class);
        (new CurlPacsTransport())->get(MemoryPacsTransport::profile(),'https://127.0.0.1/internal','application/dicom+json',100);
    }
    public function testFeatureRequiresBothEntitlementsAndImmediatelyObservesRevocation(): void
    {
        $features=$this->getMockBuilder(TenantFeatureService::class)->disableOriginalConstructor()->onlyMethods(['resolveEffectiveFeatureMapForTenant'])->getMock();
        $features->method('resolveEffectiveFeatureMapForTenant')->willReturnOnConsecutiveCalls(['pacs_dicom'=>true],['pacs_dicom'=>true,'clinical_records'=>true],['clinical_records'=>true]);
        $gate=new PacsFeatureService($features);
        $this->assertFalse($gate->isEnabledForTenant(42)); $this->assertTrue($gate->isEnabledForTenant(42)); $this->assertFalse($gate->isEnabledForTenant(42));
        $service=(new \ReflectionClass(TenantFeatureService::class))->newInstanceWithoutConstructor();
        $defs=(new \ReflectionMethod($service,'platformCoreFeatureDefinitions'))->invoke($service);
        $this->assertSame(0,$defs['pacs_dicom']['default_enabled']); $this->assertSame(0,$defs['pacs_dicom']['is_tenant_managed']);
    }
    public function testWadoSingleInstanceAndHierarchyValidation(): void
    {
        $t=new MemoryPacsTransport(); $c=new DicomWebClient(MemoryPacsTransport::profile(),$t);
        $metadata=MemoryPacsTransport::study();
        $metadata['0020000E']=['vr'=>'UI','Value'=>['1.2.3.4']];
        $metadata['00080018']=['vr'=>'UI','Value'=>['1.2.3.4.5']];
        $bytes=str_repeat("\0",128).'DICMsynthetic';
        $reply=['status'=>200,'type'=>'multipart/related; type="application/dicom"; boundary="test-boundary"','body'=>"--test-boundary\r\nContent-Type: application/dicom\r\n\r\n".$bytes."\r\n--test-boundary--\r\n",'warning'=>false];
        $t->replies=[MemoryPacsTransport::json([$metadata]),$reply];
        $this->assertSame($bytes,$c->download('1.2.826.0.1.100','1.2.3.4','1.2.3.4.5','P-100','TEST-HOSPITAL')['bytes']);
        $t->replies=[MemoryPacsTransport::json([$metadata])];
        $this->expectException(PacsException::class); $c->download('1.2.9','1.2.3.4','1.2.3.4.5','P-100','TEST-HOSPITAL');
    }
}
