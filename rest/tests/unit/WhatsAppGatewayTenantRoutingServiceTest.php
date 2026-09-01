<?php

namespace Tests\Unit;

use App\Services\WhatsAppGatewayTenantRoutingService;
use CodeIgniter\Test\CIUnitTestCase;

class WhatsAppGatewayTenantRoutingServiceTest extends CIUnitTestCase
{
    public function testMergePreservesOtherFeatureConfiguration(): void
    {
        $config = WhatsAppGatewayTenantRoutingService::mergeIntoFeatureConfig([
            'existing' => ['value' => 12],
        ], true);

        $this->assertSame(['value' => 12], $config['existing']);
        $this->assertTrue(WhatsAppGatewayTenantRoutingService::isEnabledInFeatureConfig($config));
    }

    public function testRoutingCanBeDisabledWithoutRemovingOtherConfiguration(): void
    {
        $config = WhatsAppGatewayTenantRoutingService::mergeIntoFeatureConfig([
            'existing' => ['value' => 12],
            WhatsAppGatewayTenantRoutingService::CONFIG_KEY => ['enabled' => true],
        ], false);

        $this->assertSame(['value' => 12], $config['existing']);
        $this->assertFalse(WhatsAppGatewayTenantRoutingService::isEnabledInFeatureConfig($config));
    }

    public function testMissingOrInvalidRoutingConfigurationIsDisabled(): void
    {
        $this->assertFalse(WhatsAppGatewayTenantRoutingService::isEnabledInFeatureConfig([]));
        $this->assertFalse(WhatsAppGatewayTenantRoutingService::isEnabledInFeatureConfig([
            WhatsAppGatewayTenantRoutingService::CONFIG_KEY => 'invalid',
        ]));
        $this->assertFalse(WhatsAppGatewayTenantRoutingService::isEnabledInFeatureConfig([
            WhatsAppGatewayTenantRoutingService::CONFIG_KEY => ['enabled' => 'false'],
        ]));
    }
}
