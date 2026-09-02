<?php

namespace Tests\Unit;

use App\Controllers\Api\WhatsAppGatewayWebhookController;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppGatewayWebhookControllerTest extends CIUnitTestCase
{
    public function testNormalizedWebhookPathIncludesOnlyRegisteredPublicAliases(): void
    {
        $targets = $this->signatureTargets('/api/whatsapp-gateway/incoming');

        $this->assertContains('/api/whatsapp-gateway/incoming', $targets);
        $this->assertContains('/demo/api/whatsapp-gateway/incoming', $targets);
        $this->assertContains('/login/api/whatsapp-gateway/incoming', $targets);
    }

    public function testPublicLoginWebhookPathIncludesNormalizedAlias(): void
    {
        $targets = $this->signatureTargets('/login/api/whatsapp-gateway/incoming');

        $this->assertContains('/login/api/whatsapp-gateway/incoming', $targets);
        $this->assertContains('/api/whatsapp-gateway/incoming', $targets);
    }

    /**
     * @return list<string>
     */
    private function signatureTargets(string $requestTarget): array
    {
        $reflection = new \ReflectionClass(WhatsAppGatewayWebhookController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('signatureRequestTargets');
        $method->setAccessible(true);

        return $method->invoke($controller, $requestTarget);
    }
}
