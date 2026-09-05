<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\SmsFactorDeliveryReceiptService;
use App\Services\SmsProviderConfigurationService;

class SmsFactorWebhookController extends BaseController
{
    public function deliveryReport()
    {
        try {
            $payload = json_decode((string) $this->request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Payload DLR SMSFactor non valido.');
            }

            $tenantId = 0;
            $messageId = trim((string) ($payload['message_id'] ?? ''));
            if (preg_match('/^af-(\d+)-[A-Za-z0-9._-]+$/', $messageId, $matches) === 1) {
                $tenantId = max(0, (int) $matches[1]);
            }
            $runtime = (new SmsProviderConfigurationService())->resolveRuntime($tenantId);
            $expectedSignature = trim((string) ($runtime['smsfactor']['webhook_signature'] ?? ''));
            $providedSignature = $this->request->getHeaderLine('X-SMSFactor-Signature');
            if (!SmsFactorDeliveryReceiptService::signatureMatches($providedSignature, $expectedSignature)) {
                log_message('warning', 'Webhook DLR SMSFactor rifiutato: firma non valida o non configurata.');
                return $this->response->setStatusCode(401)->setJSON([
                    'ok' => false,
                    'error' => 'invalid_signature',
                ]);
            }

            $result = (new SmsFactorDeliveryReceiptService())->record($payload);
            return $this->response
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON($result);
        } catch (\InvalidArgumentException | \JsonException $e) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Registrazione DLR SMSFactor fallita: {message}', [
                'message' => $e->getMessage(),
            ]);
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'error' => 'processing_failed',
            ]);
        }
    }
}
