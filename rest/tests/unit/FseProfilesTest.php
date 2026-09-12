<?php
namespace Tests\Unit;

use App\Models\PlatformTenantFseProfilesModel;
use App\Services\{FseOnboardingService, FseProfileService, FseSecretsService};
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class FseProfilesTest extends CIUnitTestCase
{
    private FseProfileService $profiles;
    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $this->db->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY)');
        $this->db->query('INSERT INTO platform_tenants VALUES (42), (43)');
        require_once APPPATH.'Database/Migrations/2026-09-04-010002_CreatePlatformTenantFseProfiles.php';
        (new class(Database::forge($this->db)) extends \App\Database\Migrations\CreatePlatformTenantFseProfiles {
            protected $DBGroup = null; // Isolated SQLite, never the configured platform DB.
        })->up();
        $secrets = $this->createMock(FseSecretsService::class);
        $secrets->method('encrypt')->willReturnCallback(fn($v) => $v ? 'test:'.base64_encode($v) : null);
        $secrets->method('decrypt')->willReturnCallback(fn($v) => $v ? base64_decode(substr($v,5)) : null);
        $this->profiles = new FseProfileService(new PlatformTenantFseProfilesModel($this->db), $secrets);
    }
    protected function tearDown(): void { $this->db->close(); parent::tearDown(); }
    private function create(string $name, int $tenant=42): array
    {
        return $this->profiles->saveProfile($tenant, ['profile_name'=>$name, 'access_mode'=>'toscana_privati', 'environment'=>'test',
            'region_code'=>'090','site_code'=>'SITE-'.$name,'care_regime'=>'NOSSN','author_cf'=>'VRDLGI70A01H501X',
            'auth_private_key_passphrase'=>'synthetic-secret','facility_name'=>$name]);
    }
    private function edit(array $row, array $changes=[], bool $default=false): array
    {
        $id=(int)$row['id_fse_profile'];
        $view=$this->profiles->resolveTenantSettings((int)$row['id_tenant'],$id)['profile'];
        return $this->profiles->saveProfile((int)$row['id_tenant'],array_replace($view,$changes),$id,0,$default);
    }
    public function testProfilesAreTenantScopedAndSecretsAreNotExposed(): void
    {
        $a=$this->create('A'); $this->create('A',43);
        $this->assertCount(1,$this->profiles->listForTenant(42));
        $this->assertNull($this->profiles->getProfileForTenant(43,(int)$a['id_fse_profile']));
        $view=$this->profiles->listForTenant(42)[0];
        $this->assertTrue($view['has_auth_passphrase']);
        $this->assertArrayNotHasKey('auth_private_key_passphrase_enc',$view);
        $this->assertStringNotContainsString('synthetic-secret',json_encode($view));
        $this->expectExceptionMessage('non disponibile');
        $this->profiles->saveProfile(43,[],(int)$a['id_fse_profile']);
    }
    public function testDefaultSwitchKeepsOneDefaultAndInvalidatesStaleForm(): void
    {
        $a=$this->create('A'); $b=$this->create('B'); $other=$this->create('Other',43);
        $old=$this->profiles->resolveTenantSettings(42,(int)$a['id_fse_profile'])['profile'];
        $this->edit($b,[],true);
        $this->assertSame((int)$b['id_fse_profile'],(int)$this->profiles->getDefaultProfileForTenant(42)['id_fse_profile']);
        $this->assertSame(1,$this->db->table('platform_tenant_fse_profiles')->where('id_tenant',42)->where('is_default',1)->countAllResults());
        $this->assertSame($other,$this->profiles->getDefaultProfileForTenant(43));
        $this->expectExceptionMessage('ricaricare');
        $this->profiles->saveProfile(42,$old,(int)$a['id_fse_profile'],0,true);
    }
    public function testStaleEditIsRejectedWithoutOverwritingNewData(): void
    {
        $a=$this->create('A'); $id=(int)$a['id_fse_profile'];
        $old=$this->profiles->resolveTenantSettings(42,$id)['profile'];
        $this->edit($a,['facility_name'=>'Updated']);
        try { $this->profiles->saveProfile(42,$old,$id); $this->fail('Stale form accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('ricaricare',$e->getMessage()); }
        $this->assertSame('Updated',$this->profiles->getProfileForTenant(42,$id)['facility_name']);
    }
    public function testDocumentRoutingIsFrozenButDisableAndCredentialsRemainCurrent(): void
    {
        $a=$this->create('A'); $id=(int)$a['id_fse_profile'];
        $snapshot=FseProfileService::snapshot($this->profiles->runtimeProfileForTenant(42,$id));
        $document=['id_fse_profile'=>$id,'profile_snapshot_json'=>json_encode($snapshot)];
        $this->assertStringNotContainsString('synthetic-secret',json_encode($snapshot));
        $this->assertArrayNotHasKey('author_cf',$snapshot);
        $this->edit($a,['facility_name'=>'New organisation','auth_private_key_passphrase'=>'rotated']);
        $this->edit($this->create('B'),[],true);
        $profile=$this->profiles->runtimeProfileForDocument(42,$document);
        $this->assertSame('A',$profile['facility_name']);
        $this->assertSame('rotated',$profile['auth_private_key_passphrase']);
        $this->assertSame(0,(int)$profile['is_enabled']);
        $this->assertSame($id,(int)$profile['id_fse_profile']);
        $this->expectExceptionMessage('Configura prima');
        $this->profiles->runtimeProfileForDocument(43,$document);
    }
    public function testMalformedSnapshotAndMissingProfileDoNotFallBackToDefault(): void
    {
        $a=$this->create('A');
        foreach ([[],['id_fse_profile'=>$a['id_fse_profile'],'profile_snapshot_json'=>'broken'],
            ['id_fse_profile'=>$a['id_fse_profile'],'profile_snapshot_json'=>'{"id_fse_profile":999}']] as $document) {
            try { $this->profiles->runtimeProfileForDocument(42,$document); $this->fail('Invalid association accepted'); }
            catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }
    public function testChecklistNeverConfusesEnteredFieldsWithOperationalApproval(): void
    {
        $a=$this->create('A');
        $settings=$this->profiles->resolveTenantSettings(42,(int)$a['id_fse_profile']);
        $this->assertFalse($settings['onboarding']['operational_ready']);
        $this->assertFalse((new FseOnboardingService())->check([])['operational_ready']);
        $this->expectExceptionMessage('Regime FSE');
        $this->edit($a,['care_regime'=>'UNKNOWN']);
    }
}
