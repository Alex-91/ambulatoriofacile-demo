<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\SmsFactorDeliveryReceiptService;

class SmsFactorWebhookController extends BaseController
{
    public function deliveryReport()
    {
        $expectedSignature = trim((string) env('SMSFACTOR_WEBHOOK_SIGNATURE', ''));
        $providedSignature = $this->request->getHeaderLine('X-SMSFactor-Signature');
        if (!SmsFactorDeliveryReceiptService::signatureMatches($providedSignature, $expectedSignature)) {
            log_message('warning', 'Webhook DLR SMSFactor rifiutato: firma non valida o non configurata.');
            return $this->response->setStatusCode(401)->setJSON([
                'ok' => false,
                'error' => 'invalid_signature',
            ]);
        }

        try {
            $payload = json_decode((string) $this->request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Payload DLR SMSFactor non valido.');
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
