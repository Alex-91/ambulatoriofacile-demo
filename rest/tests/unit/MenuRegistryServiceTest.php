<?php

use App\Services\MenuRegistryService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class MenuRegistryServiceTest extends CIUnitTestCase
{
    public function testWhatsappChatbotIsOnlyRegisteredInPlatformConsole(): void
    {
        $registry = new MenuRegistryService();

        $this->assertNull($registry->findTenantContextItem('spazio/chatbot-whatsapp'));

        $item = $registry->findPlatformConsoleItem('piattaforma/chatbot-whatsapp');
        $this->assertIsArray($item);
        $this->assertSame('Chatbot WhatsApp', $item['title'] ?? null);
        $this->assertContains('piattaforma/chatbot-whatsapp/save', $item['route_prefixes'] ?? []);
    }

    public function testWhatsappCampaignsAreRegisteredInTenantConsole(): void
    {
        $item = (new MenuRegistryService())->findTenantContextItem('spazio/invii-whatsapp');

        $this->assertIsArray($item);
        $this->assertSame('Invii WhatsApp', $item['title'] ?? null);
        $this->assertContains('spazio/invii-whatsapp/create', $item['route_prefixes'] ?? []);
    }

    public function testFse2MenusAreRegisteredSeparatelyFromTs(): void
    {
        $registry = new MenuRegistryService();
        $admin = $registry->findTenantAdminItem('fse2');
        $settings = $registry->findTenantContextItem('spazio/fse2');
        $this->assertSame('FSE 2.0', $admin['title'] ?? null);
        $this->assertSame('Configura FSE 2.0', $settings['title'] ?? null);
        $this->assertContains('admin/fse2', $admin['route_prefixes'] ?? []);
    }
}
