<?php

namespace App\Libraries;

use App\Config\Push as PushConfig;
use App\Models\PushSubscriptionModel;

class PushService
{
    /**
     * Utenti admin che devono ricevere via push solo i codici OTP.
     */
    private const OTP_ONLY_USER_IDS = [14028, 14030];

    protected PushSubscriptionModel $model;
    protected PushConfig $cfg;

    public function __construct(PushConfig $config)
    {
        $this->cfg   = $config;
        $this->model = new PushSubscriptionModel();
    }

    public function sendToUser(int $userId, array $payload, array $options = []): array
    {
        if ($this->shouldBlockNonOtpDelivery($userId, $payload)) {
            return [
                'ok'        => true,
                'attempted' => 0,
                'success'   => 0,
                'results'   => [],
                'skipped'   => true,
                'reason'    => 'otp_only_user_non_otp_blocked',
            ];
        }

        $subs = $this->model->getActiveByUser($userId, 'phone');

        if (empty($subs)) {
            return [
                'ok'        => false,
                'attempted' => 0,
                'success'   => 0,
                'results'   => [],
                'error'     => 'no active phone subscriptions',
            ];
        }

        $results = [];
        $success = 0;
        $attempted = 0;
        $seenEndpointHashes = [];

        foreach ($subs as $s) {
            $endpoint = trim((string)($s['endpoint'] ?? ''));
            if ($endpoint === '') {
                continue;
            }

            $endpointHash = (string)($s['endpoint_hash'] ?? $this->model->endpointHash($endpoint));
            if ($endpointHash !== '' && isset($seenEndpointHashes[$endpointHash])) {
                continue;
            }
            if ($endpointHash !== '') {
                $seenEndpointHashes[$endpointHash] = true;
            }

            $attempted++;
            $deliveryPayload = $this->applyClientModeHint($payload, $s);

            $res = $this->sendToSubscription([
                'endpoint' => $endpoint,
                'keys'     => [
                    'p256dh' => (string)$s['p256dh'],
                    'auth'   => (string)$s['auth'],
                ],
            ], $deliveryPayload, $options);

            $this->cleanupIfExpired((int)$s['id'], $endpointHash, $res);

            $results[] = [
                'id'            => (int)$s['id'],
                'endpoint_hash' => $endpointHash,
                'result'        => $res,
            ];

            if (!empty($res['ok'])) {
                $success++;
                $this->model->update((int)$s['id'], ['last_seen' => date('Y-m-d H:i:s')]);
                $this->model->keepOnlyMobileEndpointActive($userId, $endpoint);
                break;
            }
        }

        return [
            'ok'        => $success > 0,
            'attempted' => $attempted,
            'success'   => $success,
            'results'   => $results,
            'error'     => ($success > 0 || $attempted > 0) ? null : 'no usable active phone subscriptions',
        ];
    }

    public function sendToEndpoint(string $endpoint, array $payload, array $options = []): array
    {
        $row = $this->model->findByEndpoint($endpoint);
        if (!$row) {
            return ['ok' => false, 'error' => 'endpoint not found'];
        }

        $payload = $this->applyClientModeHint($payload, $row);

        $res = $this->sendToSubscription([
            'endpoint' => (string)$row['endpoint'],
            'keys'     => [
                'p256dh' => (string)$row['p256dh'],
                'auth'   => (string)$row['auth'],
            ],
        ], $payload, $options);

        $endpointHash = (string)($row['endpoint_hash'] ?? $this->model->endpointHash((string)$row['endpoint']));
        $this->cleanupIfExpired((int)$row['id'], $endpointHash, $res);

        if (!empty($res['ok'])) {
            $this->model->update((int)$row['id'], ['last_seen' => date('Y-m-d H:i:s')]);
        }

        return $res;
    }

    public function sendToSubscription(array $subscription, array $payload, array $options = []): array
    {
        if (empty($this->cfg->remoteUrl)) {
            return ['ok' => false, 'error' => 'remote URL missing'];
        }

        $body = json_encode([
            'subscription' => $subscription,
            'payload'      => $payload,
            'options'      => array_merge([
                'TTL'     => 300,
                'urgency' => 'high',
            ], $options),
        ], JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];

        if (!empty($this->cfg->remoteApiKey)) {
            $headers[] = 'X-Push-Key: ' . $this->cfg->remoteApiKey;
        }

        if (!empty($this->cfg->vercelBypass)) {
            $headers[] = 'x-vercel-protection-bypass: ' . $this->cfg->vercelBypass;
        }

        $ch = curl_init($this->cfg->remoteUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'error' => $err];
        }

        $json = json_decode((string)$resp, true);
        if ($code >= 200 && $code < 300) {
            return [
                'ok'     => true,
                'status' => $code,
                'res'    => $json,
            ];
        }

        return [
            'ok'     => false,
            'status' => $code,
            'res'    => $json,
        ];
    }

    protected function cleanupIfExpired(int $id, string $endpointHash, array $res): void
    {
        $status  = (int)($res['status'] ?? 0);
        $expired = !empty($res['res']['expired']);
        $error   = strtolower(trim((string)($res['res']['error'] ?? ($res['error'] ?? ''))));

        $looksExpired = $error !== '' && (
            str_contains($error, 'unsubscribed')
            || str_contains($error, 'expired')
            || str_contains($error, 'invalid subscription')
            || str_contains($error, 'not registered')
        );

        if ($expired || in_array($status, [404, 410], true) || $looksExpired) {
            if ($id > 0) {
                $this->model->update($id, ['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
                return;
            }

            if ($endpointHash !== '') {
                $this->model->deactivateByEndpointHash($endpointHash);
            }
        }
    }

    private function applyClientModeHint(array $payload, array $subscription): array
    {
        $clientMode = $this->detectClientMode($subscription);
        if ($clientMode === null) {
            return $payload;
        }

        $payload['data'] = is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        if (empty($payload['data']['clientMode'])) {
            $payload['data']['clientMode'] = $clientMode;
        }

        return $payload;
    }

    private function shouldBlockNonOtpDelivery(int $userId, array $payload): bool
    {
        return in_array($userId, self::OTP_ONLY_USER_IDS, true) && !$this->isOtpPayload($payload);
    }

    private function isOtpPayload(array $payload): bool
    {
        $type = strtolower(trim((string)($payload['type'] ?? '')));
        if ($type === 'otp') {
            return true;
        }

        $title = strtolower(trim((string)($payload['title'] ?? '')));
        if ($title !== '' && str_contains($title, 'otp')) {
            return true;
        }

        $body = strtolower(trim((string)($payload['body'] ?? '')));
        if ($body !== '' && str_contains($body, 'codice di accesso')) {
            return true;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $otp  = trim((string)($data['otp'] ?? ''));
        if ($otp !== '') {
            return true;
        }

        $url = strtolower(trim((string)($data['url'] ?? '')));
        return $url !== '' && str_contains($url, 'frompush=1');
    }

    private function detectClientMode(array $subscription): ?string
    {
        $label = strtolower(trim((string)($subscription['device_name'] ?? $subscription['device_label'] ?? '')));
        if ($label === '') {
            return null;
        }

        if (str_starts_with($label, 'app -')) {
            return 'standalone';
        }

        if (str_starts_with($label, 'browser -')) {
            return 'browser';
        }

        return null;
    }
}
