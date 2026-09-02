<?php

namespace Tests\Unit;

use App\Services\WhatsAppGatewayRequestSigner;
use App\Services\WhatsAppGatewayWebhookVerifier;
use CodeIgniter\Test\CIUnitTestCase;

final class WhatsAppGatewayWebhookVerifierTest extends CIUnitTestCase
{
    public function testValidSignedWebhookIsAccepted(): void
    {
        $secret = 'a-very-long-webhook-secret-for-tests-only';
        $body = '{"event_type":"message_received"}';
        $target = '/login/api/whatsapp-gateway/incoming';
        $timestamp = 1788300000;
        $requestId = 'hook-12345678';
        $signature = hash_hmac(
            'sha256',
            WhatsAppGatewayRequestSigner::canonical('POST', $target, 42, $timestamp, $requestId, $body),
            $secret
        );

        $result = (new WhatsAppGatewayWebhookVerifier('gateway-app', $secret, 300))->verify(
            'POST',
            $target,
            $body,
            [
                'X-AmbulatorioFacile-Key-ID' => 'gateway-app',
                'X-AmbulatorioFacile-Tenant-ID' => '42',
                'X-AmbulatorioFacile-Timestamp' => (string) $timestamp,
                'X-AmbulatorioFacile-Request-ID' => $requestId,
                'X-AmbulatorioFacile-Signature' => $signature,
            ],
            $timestamp + 10
        );

        $this->assertSame(42, $result['tenant_id']);
        $this->assertSame($requestId, $result['request_id']);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);

        $secret = 'a-very-long-webhook-secret-for-tests-only';
        $target = '/api/whatsapp-gateway/incoming';
        $timestamp = 1788300000;
        $requestId = 'hook-12345678';
        $signature = hash_hmac(
            'sha256',
            WhatsAppGatewayRequestSigner::canonical('POST', $target, 7, $timestamp, $requestId, '{}'),
            $secret
        );

        (new WhatsAppGatewayWebhookVerifier('gateway-app', $secret, 300))->verify(
            'POST',
            $target,
            '{"changed":true}',
            [
                'X-AmbulatorioFacile-Key-ID' => 'gateway-app',
                'X-AmbulatorioFacile-Tenant-ID' => '7',
                'X-AmbulatorioFacile-Timestamp' => (string) $timestamp,
                'X-AmbulatorioFacile-Request-ID' => $requestId,
                'X-AmbulatorioFacile-Signature' => $signature,
            ],
            $timestamp
        );
    }
}
