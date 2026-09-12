<?php
namespace Tests\Unit;
use App\Services\{ClinicalFeatureService,TenantFeatureService};
use CodeIgniter\Test\CIUnitTestCase;

final class ClinicalFeatureTest extends CIUnitTestCase
{
    public function testFeatureIsOffByDefaultAndOnlyPlatformManaged(): void
    {
        $service=(new \ReflectionClass(TenantFeatureService::class))->newInstanceWithoutConstructor();
        $definitions=(new \ReflectionMethod($service,'platformCoreFeatureDefinitions'))->invoke($service);
        foreach (['clinical_records','fse2','billing',\App\Config\TsBilling::FEATURE_KEY] as $key) {
            $this->assertSame(0,$definitions[$key]['default_enabled']);
            $this->assertSame(0,$definitions[$key]['is_tenant_managed']);
        }
    }
    public function testGrantAndRevocationUseFreshTenantEntitlements(): void
    {
        $features=$this->getMockBuilder(TenantFeatureService::class)->disableOriginalConstructor()->onlyMethods(['resolveEffectiveFeatureMapForTenant'])->getMock();
        $features->expects($this->exactly(3))->method('resolveEffectiveFeatureMapForTenant')->with(42)
            ->willReturnOnConsecutiveCalls([],['clinical_records'=>true],['clinical_records'=>false]);
        session()->set(['platform_is_admin'=>true,'is_admin'=>true]);
        $service=new ClinicalFeatureService($features);
        $this->assertFalse($service->isEnabledForTenant(42));
        $this->assertTrue($service->isEnabledForTenant(42));
        $this->assertFalse($service->isEnabledForTenant(42));
    }
    public function testMissingTenantDoesNotReadDatabaseAndDeniedGateFailsClosed(): void
    {
        $features=$this->getMockBuilder(TenantFeatureService::class)->disableOriginalConstructor()->onlyMethods(['resolveEffectiveFeatureMapForTenant'])->getMock();
        $features->expects($this->never())->method('resolveEffectiveFeatureMapForTenant');
        $this->expectExceptionMessage('Modulo Cartella clinica non attivo');
        (new ClinicalFeatureService($features))->assertEnabledForTenant(0);
    }
    public function testUnavailablePlatformDoesNotEnableModule(): void
    {
        $features=$this->getMockBuilder(TenantFeatureService::class)->disableOriginalConstructor()->onlyMethods(['resolveEffectiveFeatureMapForTenant'])->getMock();
        $features->method('resolveEffectiveFeatureMapForTenant')->willThrowException(new \RuntimeException('DB unavailable'));
        $this->assertFalse((new ClinicalFeatureService($features))->isEnabledForTenant(42));
    }
}
