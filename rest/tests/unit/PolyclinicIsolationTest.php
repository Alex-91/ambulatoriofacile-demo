<?php
namespace Tests\Unit;

use App\Services\{PolyclinicFeatureService, TenantFeatureService, MenuResolverService, AdminMenuVisibilityService, MenuRegistryService};
use App\Libraries\{TenantContext, TenantFeatureRegistry};
use CodeIgniter\Test\CIUnitTestCase;

final class PolyclinicIsolationTest extends CIUnitTestCase
{
    public function testEntitlementsAreIndependentAndRevocationOverridesStaleSession(): void
    {
        $maps=[1=>['billing'=>true],2=>['polyclinic_billing'=>true],3=>['billing'=>true,'polyclinic_billing'=>true],4=>[]];
        $source=$this->createMock(TenantFeatureService::class);
        $source->method('resolveEffectiveFeatureMapForTenant')->willReturnCallback(static fn($id)=>$maps[$id]);
        $feature=new PolyclinicFeatureService($source);
        foreach ([1=>false,2=>true,3=>true,4=>false] as $id=>$enabled) {
            $context=new TenantContext(tenantId:$id,tenantKey:'test-'.$id,tenantRole:'tenant_master',featureFlags:['billing'=>true,'polyclinic_billing'=>true]);
            $this->assertSame($enabled,$feature->isEnabledForContext($context));
            $this->assertFalse($feature->allowsLocalTestingBypass($context));
        }
        $this->assertFalse($feature->isEnabledForTenant(0));
        $this->assertFalse($feature->isEnabledForContext(null));
        $this->assertSame('billing',TenantFeatureRegistry::resolveFeatureKeyFromRoutePath('admin/fatturazione'));
        $this->assertSame('polyclinic_billing',TenantFeatureRegistry::resolveFeatureKeyFromMenuLink('fatturazione-poliambulatori'));
    }

    public function testMenuHidesStaleEntryWhenDisabledAndKeepsBillingSeparate(): void
    {
        $features=$this->createMock(PolyclinicFeatureService::class);
        $features->method('isEnabledForTenant')->willReturnCallback(static fn($id)=>$id===2);
        $menus=new MenuResolverService($this->createMock(AdminMenuVisibilityService::class),new MenuRegistryService(),$features);
        $method=new \ReflectionMethod($menus,'injectPolyclinicMenu');
        $rows=[['link'=>'fatturazione','titolo_menu'=>'Fatturazione'],['link'=>'fatturazione-poliambulatori','titolo_menu'=>'Vecchia cache']];
        $this->assertSame(['fatturazione'],array_column($method->invoke($menus,$rows,1),'link'));
        $enabled=$method->invoke($menus,$rows,2);
        $this->assertSame(['fatturazione','fatturazione-poliambulatori'],array_column($enabled,'link'));
        $this->assertSame('Fatturazione poliambulatori',$enabled[1]['titolo_menu']);
    }

    public function testModuleIsOffByDefaultAndSchemaIsNotAnAutomaticMigration(): void
    {
        $service=(new \ReflectionClass(TenantFeatureService::class))->newInstanceWithoutConstructor();
        $definitions=(new \ReflectionMethod($service,'platformCoreFeatureDefinitions'))->invoke($service);
        $this->assertSame(0,$definitions['polyclinic_billing']['default_enabled']);
        $this->assertSame(0,$definitions['polyclinic_billing']['tenant_default_enabled']);
        $this->assertSame(0,$definitions['polyclinic_billing']['is_tenant_managed']);
        $this->assertSame([],glob(APPPATH.'Database/Migrations/*Polyclinic*'));
        $this->assertFileExists(APPPATH.'Database/Polyclinic/CreatePolyclinicAdministration.php');
    }
}
