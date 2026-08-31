<?php

namespace Tests\Unit;

use App\Services\WhatsAppGatewayRequestSigner;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppGatewayRequestSignerTest extends CIUnitTestCase
{
    public function testSignatureMatchesGatewayContract(): void
    {
        $signer = new WhatsAppGatewayRequestSigner(
            'app',
            'a-very-long-test-secret-that-is-safe'
        );
        $body = '{"to":"+393331234567","text":"Promemoria"}';
        $headers = $signer->headers(
            'POST',
            '/v1/accounts/primary/messages/text',
            42,
            $body,
            1725100000,
            'req-test-0001'
        );

        $this->assertSame('app', $headers['X-AmbulatorioFacile-Key-ID']);
        $this->assertSame('42', $headers['X-AmbulatorioFacile-Tenant-ID']);
        $this->assertSame(
            'a2b84c49e9c7664018f3466e4c545664db615368a067e80d8e184b997302df32',
            $headers['X-AmbulatorioFacile-Signature']
        );
    }
}
