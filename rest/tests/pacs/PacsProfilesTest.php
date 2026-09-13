<?php
namespace Tests\Pacs;
require_once __DIR__.'/PacsTestSupport.php';
use App\Services\Pacs\{PacsProfiles,PacsException};
use CodeIgniter\Test\CIUnitTestCase;

final class PacsProfilesTest extends CIUnitTestCase
{
    private array $original = [];
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['PACS_CONFIG_FILE','PACS_PROFILES_JSON'] as $key) {
            $this->original[$key] = getenv($key);
            putenv($key);
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->original as $key=>$value) putenv($value === false ? $key : "$key=$value");
        parent::tearDown();
    }
    public function testRuntimeConfigurationIsTenantScopedAndRequiresExplicitEnablement(): void
    {
        $p = MemoryPacsTransport::profile();
        putenv('PACS_PROFILES_JSON='.json_encode(['tenants'=>['4'=>[$p],'5'=>[array_replace($p,['enabled'=>'true'])]]]));
        $profiles = new PacsProfiles();
        $this->assertSame('cloud',$profiles->get(4,'cloud')['id']);
        $this->assertSame([],$profiles->forTenant(6));
        $this->expectException(PacsException::class);
        $profiles->get(5,'cloud');
    }
    public function testMalformedOversizedAndInvalidShapeFailClosedWithoutLeakingInput(): void
    {
        foreach (['{secret',str_repeat(' ',262145),'null','[]','{"tenants":false}'] as $json) {
            putenv('PACS_PROFILES_JSON='.$json);
            try { (new PacsProfiles())->forTenant(4); $this->fail('Invalid configuration accepted'); }
            catch (PacsException $e) { $this->assertSame('Configurazione PACS non valida.',$e->getMessage()); }
        }
    }
    public function testConfiguredFileCannotSilentlyFallBackToEnvironment(): void
    {
        putenv('PACS_PROFILES_JSON={"tenants":{}}');
        putenv('PACS_CONFIG_FILE='.__DIR__.'/missing-private-config.json');
        $this->expectException(PacsException::class);
        (new PacsProfiles())->forTenant(4);
    }
    public function testInjectedConfigurationHasPrecedenceAndEmptyEnvironmentDefaultsOff(): void
    {
        $this->assertSame([],(new PacsProfiles())->forTenant(4));
        putenv('PACS_PROFILES_JSON=invalid');
        $this->assertSame([],(new PacsProfiles(['tenants'=>[]]))->forTenant(4));
    }
}
