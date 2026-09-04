<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationSettingsService;
use App\Services\TenantFeatureService;
use App\Services\WhatsAppCampaignReadinessService;
use App\Services\WhatsAppTenantConsoleService;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppCampaignReadinessServiceTest extends CIUnitTestCase
{
    public function testCampaignIsReadyOnlyWithActiveChannelAndLoggedInAccount(): void
    {
        $service = $this->serviceWith(
            ['module' => ['available' => true], 'platform_channel_controls' => ['wa' => ['enabled' => true]]],
            [AppointmentNotificationSettingsService::FEATURE_WHATSAPP => true],
            [
                'gateway_configured' => true,
                'tenant_routed' => true,
                'gateway_available' => true,
                'account' => ['connected' => true, 'logged_in' => true],
            ]
        );

        $result = $service->resolve(42);

        $this->assertTrue($result['channel_active']);
        $this->assertTrue($result['ready']);
        $this->assertSame('', $result['reason']);
    }

    public function testCampaignPageCanExplainMissingTenantRouting(): void
    {
        $service = $this->serviceWith(
            ['module' => ['available' => true], 'platform_channel_controls' => ['wa' => ['enabled' => true]]],
            [AppointmentNotificationSettingsService::FEATURE_WHATSAPP => true],
            [
                'gateway_configured' => true,
                'tenant_routed' => false,
                'gateway_available' => false,
                'setup_message' => 'Routing gateway non predisposto.',
                'account' => ['connected' => false, 'logged_in' => false],
            ]
        );

        $result = $service->resolve(42);

        $this->assertTrue($result['channel_active']);
        $this->assertFalse($result['ready']);
        $this->assertSame('Routing gateway non predisposto.', $result['reason']);
    }

    private function serviceWith(array $settingsPayload, array $featureMap, array $consolePayload): WhatsAppCampaignReadinessService
    {
        $settings = $this->getMockBuilder(AppointmentNotificationSettingsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantSettings'])
            ->getMock();
        $settings->method('resolveTenantSettings')->willReturn($settingsPayload);

        $features = $this->getMockBuilder(TenantFeatureService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveEffectiveFeatureMapForTenant'])
            ->getMock();
        $features->method('resolveEffectiveFeatureMapForTenant')->willReturn($featureMap);

        $console = $this->getMockBuilder(WhatsAppTenantConsoleService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();
        $console->method('build')->willReturn($consolePayload);

        return new WhatsAppCampaignReadinessService($settings, $features, $console);
    }
}
