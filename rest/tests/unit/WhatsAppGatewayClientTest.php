<?php

namespace Tests\Unit;

use App\Services\WhatsAppGatewayClient;
use CodeIgniter\Test\CIUnitTestCase;

class WhatsAppGatewayClientTest extends CIUnitTestCase
{
    public function testGatewayProviderRoutesEveryValidTenant(): void
    {
        $this->assertTrue(WhatsAppGatewayClient::isRoutedToGateway(42, 'gateway', ''));
        $this->assertFalse(WhatsAppGatewayClient::isRoutedToGateway(0, 'gateway', ''));
    }

    public function testHybridProviderRoutesOnlyConfiguredTenants(): void
    {
        $this->assertTrue(WhatsAppGatewayClient::isRoutedToGateway(42, 'hybrid', '7, 42, 88'));
        $this->assertFalse(WhatsAppGatewayClient::isRoutedToGateway(41, 'hybrid', '7, 42, 88'));
        $this->assertFalse(WhatsAppGatewayClient::isRoutedToGateway(42, 'ultramsg', '42'));
    }
}
