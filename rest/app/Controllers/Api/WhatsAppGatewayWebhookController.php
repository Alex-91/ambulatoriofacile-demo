<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\WhatsAppChatbotService;
use App\Services\WhatsAppGatewayWebhookVerifier;

class WhatsAppGatewayWebhookController extends BaseController
{
    public function incoming()
    {
        $body = (string) $this->request->getBody();
        $requestTarget = (string) ($this->request->getServer('REQUEST_URI') ?? '');
        if ($requestTarget === '') {
            $requestTarget = '/' . ltrim($this->request->getUri()->getPath(), '/');
        }

        $headers = [];
        foreach ([
            'X-AmbulatorioFacile-Key-ID',
            'X-AmbulatorioFacile-Tenant-ID',
            'X-AmbulatorioFacile-Timestamp',
            'X-AmbulatorioFacile-Request-ID',
            'X-AmbulatorioFacile-Signature',
        ] as $name) {
            $headers[$name] = $this->request->getHeaderLine($name);
        }

        try {
            $verified = (new WhatsAppGatewayWebhookVerifier(
                (string) env('WHATSAPP_GATEWAY_API_KEY_ID', 'ambulatoriofacile-app'),
                (string) env('WHATSAPP_GATEWAY_API_SECRET', ''),
                (int) env('WHATSAPP_GATEWAY_ALLOWED_CLOCK_SKEW_SECONDS', 300)
            ))->verify('POST', $requestTarget, $body, $headers);
        } catch (\Throwable $e) {
            log_message('warning', 'WhatsApp gateway webhook rejected: {message}', ['message' => $e->getMessage()]);
            return $this->response->setStatusCode(401)->setJSON(['ok' => false, 'error' => 'invalid_signature']);
        }

        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || (string) ($payload['event_type'] ?? '') !== 'message_received') {
                throw new \InvalidArgumentException('Evento WhatsApp non supportato.');
            }
            $payloadTenantId = (int) ($payload['tenant_id'] ?? 0);
            if ($payloadTenantId > 0 && $payloadTenantId !== (int) $verified['tenant_id']) {
                throw new \InvalidArgumentException('Tenant del payload non coerente.');
            }

            $result = (new WhatsAppChatbotService())->processIncoming((int) $verified['tenant_id'], $payload);
            return $this->response
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON($result);
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'WhatsApp chatbot webhook processing failed: {message}', [
                'message' => $e->getMessage(),
                'tenant_id' => (int) $verified['tenant_id'],
            ]);
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'processing_failed',
            ]);
        }
    }
}
