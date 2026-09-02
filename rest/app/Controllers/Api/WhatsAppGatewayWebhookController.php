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
        $requestTarget = (string) ($_SERVER['AF_ORIGINAL_REQUEST_URI']
            ?? $this->request->getServer('REQUEST_URI')
            ?? '');
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

        $verifier = new WhatsAppGatewayWebhookVerifier(
            (string) env('WHATSAPP_GATEWAY_API_KEY_ID', 'ambulatoriofacile-app'),
            (string) env('WHATSAPP_GATEWAY_API_SECRET', ''),
            (int) env('WHATSAPP_GATEWAY_ALLOWED_CLOCK_SKEW_SECONDS', 300)
        );
        $verified = null;
        $verificationError = null;
        foreach ($this->signatureRequestTargets($requestTarget) as $signatureTarget) {
            try {
                $verified = $verifier->verify('POST', $signatureTarget, $body, $headers);
                break;
            } catch (\Throwable $e) {
                $verificationError = $e;
            }
        }
        if (!is_array($verified)) {
            log_message('warning', 'WhatsApp gateway webhook rejected: {message}', [
                'message' => $verificationError?->getMessage() ?? 'Firma webhook non valida.',
            ]);
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

    /**
     * The public proxy may remove the visible /demo or /login prefix before PHP.
     * Accept the exact request target and the canonical public variant only.
     *
     * @return list<string>
     */
    private function signatureRequestTargets(string $requestTarget): array
    {
        $targets = [$requestTarget];
        $canonicalUrl = trim((string) (env('APP_CANONICAL_URL', '') ?: env('APP_BASE_URL', '')));
        $visiblePrefix = trim((string) parse_url($canonicalUrl, PHP_URL_PATH), '/');
        $parts = parse_url($requestTarget);
        if ($parts === false) {
            return $targets;
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
        if ($visiblePrefix !== '') {
            $prefix = '/' . $visiblePrefix;
            if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                $publicTarget = $prefix . ($path === '/' ? '' : $path);
                if (!empty($parts['query'])) {
                    $publicTarget .= '?' . $parts['query'];
                }
                $targets[] = $publicTarget;
            }
        }

        // Coolify can strip the external mount prefix before the request reaches
        // PHP. These are the only public aliases explicitly registered in Routes.
        $webhookSuffix = '/api/whatsapp-gateway/incoming';
        $querySuffix = !empty($parts['query']) ? ('?' . $parts['query']) : '';
        if ($path === $webhookSuffix) {
            $targets[] = '/demo' . $webhookSuffix . $querySuffix;
            $targets[] = '/login' . $webhookSuffix . $querySuffix;
        } elseif (in_array($path, ['/demo' . $webhookSuffix, '/login' . $webhookSuffix], true)) {
            $targets[] = $webhookSuffix . $querySuffix;
        }

        return array_values(array_unique($targets));
    }
}
