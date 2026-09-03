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
}
