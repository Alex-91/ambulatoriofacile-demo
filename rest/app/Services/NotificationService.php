<?php

namespace App\Services;

use App\Models\DeviceLinkModel;
use App\Models\PushDeliveryLogModel;
use App\Models\PushSubscriptionModel;

class NotificationService
{
    protected PushSubscriptionModel $subs;
    protected DeviceLinkModel $links;
    protected PushDeliveryLogModel $logs;

    public function __construct()
    {
        $this->subs  = new PushSubscriptionModel();
        $this->links = new DeviceLinkModel();
        $this->logs  = new PushDeliveryLogModel();
    }

    public function registerSubscription(
        int $userId,
        string $endpoint,
        string $p256dh,
        string $auth,
        array $meta = [],
        bool $singleMobile = true
    ): int {
        $payload = [
            'user_id'      => $userId,
            'endpoint'     => $endpoint,
            'p256dh'       => $p256dh,
            'auth'         => $auth,
            'device_id'    => $this->cleanText((string)($meta['device_id'] ?? ''), 64) ?: null,
            'device_name'  => $this->cleanText((string)($meta['device_name'] ?? ''), 100),
            'device_label' => $this->cleanText((string)($meta['device_label'] ?? ''), 120),
            'device_brand' => $this->cleanText((string)($meta['device_brand'] ?? ''), 64) ?: null,
            'device_model' => $this->cleanText((string)($meta['device_model'] ?? ''), 64) ?: null,
            'device_type'  => $this->cleanText((string)($meta['device_type'] ?? 'phone'), 32) ?: 'phone',
            'device_os'    => $this->cleanText((string)($meta['device_os'] ?? ''), 64) ?: null,
            'browser'      => $this->cleanText((string)($meta['browser'] ?? ''), 64) ?: null,
            'ua'           => $this->cleanUa((string)($meta['ua'] ?? '')),
            'is_mobile'    => (int)($meta['is_mobile'] ?? 1),
            'is_active'    => 1,
            'last_seen'    => date('Y-m-d H:i:s'),
        ];

        if ($payload['device_name'] === '') {
            $payload['device_name'] = $payload['device_label'] !== ''
                ? $payload['device_label']
                : 'Dispositivo mobile';
        }
        if ($payload['device_label'] === '') {
            $payload['device_label'] = $payload['device_name'];
        }

        $id = $this->subs->upsertByEndpoint($payload);

        if ($singleMobile && (int)$payload['is_mobile'] === 1) {
            $this->subs->keepOnlyMobileEndpointActive($userId, $endpoint);
        }

        return $id;
    }

    public function hasActiveMobile(int $userId): bool
    {
        return $this->subs->userHasActiveMobile($userId);
    }

    public function activeMobiles(int $userId): array
    {
        return $this->subs->getActiveMobiles($userId);
    }

    public function createLinkToken(int $userId, int $ttlMinutes = 10): string
    {
        return $this->links->createToken($userId, $ttlMinutes);
    }

    public function completeLinkToken(
        string $token,
        string $endpoint,
        string $p256dh,
        string $auth,
        array $meta = []
    ): array {
        $row = $this->links->findValidToken($token);
        if (!$row) {
            throw new \RuntimeException('Token non valido o scaduto');
        }

        $userId = (int)$row['user_id'];
        if ($userId <= 0) {
            throw new \RuntimeException('Utente collegamento non valido');
        }

        $id = $this->registerSubscription(
            $userId,
            $endpoint,
            $p256dh,
            $auth,
            $meta,
            true
        );

        $this->links->markConsumed((int)$row['id']);

        return [
            'subscription_id' => $id,
            'user_id'         => $userId,
        ];
    }

    public function sendToUser(int $userId, array $payload, string $eventType, array $options = []): array
    {
        if ($this->wasRecentlyDelivered($userId, $eventType, $payload, 15)) {
            return [
                'ok'        => true,
                'attempted' => 0,
                'success'   => 1,
                'results'   => [],
                'skipped'   => true,
                'reason'    => 'recent_duplicate_payload',
            ];
        }

        $result = service('push')->sendToUser($userId, $payload, $options);
        $this->logDelivery($eventType, $userId, $payload, $result);
        return $result;
    }

    public function sendToEndpoint(string $endpoint, array $payload, string $eventType, array $options = []): array
    {
        $result = service('push')->sendToEndpoint($endpoint, $payload, $options);

        $single = [
            'ok'      => !empty($result['ok']),
            'status'  => $result['status'] ?? null,
            'results' => [
                [
                    'endpoint_hash' => $this->subs->endpointHash($endpoint),
                    'result'        => $result,
                ],
            ],
        ];
        $this->logDelivery($eventType, null, $payload, $single);

        return $result;
    }

    public function sendOtpPush(int $userId, string $otp): array
    {
        $payload = [
            'type'  => 'otp',
            'title' => "OTP {$otp}",
            'body'  => "Codice di accesso: {$otp}",
            'tag'   => 'otp-login-' . $otp,
            'icon'  => self::notificationIconUrl(),
            'badge' => self::notificationBadgeUrl(),
            'silent' => true,
            'renotify' => false,
            'requireInteraction' => false,
            'data'  => [
                'url' => base_url('auth?fromPush=1'),
                'otp' => $otp,
            ],
        ];

        return $this->sendToUser($userId, $payload, 'otp', [
            'TTL'     => 300,
            'urgency' => 'high',
        ]);
    }

    public function sendDoctorMailPush(int $patientUserId, ?int $threadId = null): array
    {
        $url = base_url('messaggi/inbox');
        if ($threadId !== null && $threadId > 0) {
            $url = base_url('messaggi/thread/' . (int)$threadId);
        }

        $payload = [
            'type'  => 'mail',
            'title' => 'AmbulatoriCLOUD',
            'body'  => 'Hai ricevuto un nuovo messaggio.',
            'tag'   => 'mail-' . ((int)$threadId > 0 ? (int)$threadId : time()),
            'icon'  => self::notificationIconUrl(),
            'badge' => self::notificationBadgeUrl(),
            'data'  => [
                'url' => $url,
            ],
        ];

        return $this->sendToUser($patientUserId, $payload, 'mail');
    }

    public function sendChatPush(
        int $recipientUserId,
        string $body,
        ?int $threadId = null,
        ?int $messageId = null
    ): array {
        $url = base_url('chat');
        if ($threadId !== null && $threadId > 0) {
            $url = base_url('chat?thread=' . (int)$threadId);
        }

        $payload = [
            'type'      => 'chat',
            'title'     => 'AmbulatoriCLOUD',
            'body'      => $body,
            'messageId' => (int)($messageId ?? 0),
            'tag'       => 'chat-' . ((int)$messageId > 0 ? (int)$messageId : time()),
            'icon'      => self::notificationIconUrl(),
            'badge'     => self::notificationBadgeUrl(),
            'data'      => [
                'url'      => $url,
                'threadId' => (int)($threadId ?? 0),
            ],
        ];

        return $this->sendToUser($recipientUserId, $payload, 'chat');
    }

    public function disconnectUserMobiles(int $userId): void
    {
        $this->subs->deactivateAllByUser($userId);
    }

    public function deactivateEndpoint(string $endpoint, ?int $userId = null): void
    {
        $this->subs->deactivateByEndpoint($endpoint, $userId);
    }

    private function logDelivery(string $eventType, ?int $userId, array $payload, array $result): void
    {
        $rows = $result['results'] ?? [];
        if (empty($rows)) {
            $this->safeInsertLog([
                'event_type'        => $eventType,
                'user_id'           => $userId,
                'endpoint_hash'     => null,
                'success'           => !empty($result['ok']) ? 1 : 0,
                'provider_status'   => isset($result['status']) ? (int)$result['status'] : null,
                'error_message'     => $this->extractError($result),
                'payload_json'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'provider_response' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        foreach ($rows as $r) {
            $endpointHash = (string)($r['endpoint_hash'] ?? ($r['endpoint'] ?? ''));
            if (strlen($endpointHash) !== 64 && $endpointHash !== '') {
                $endpointHash = hash('sha256', $endpointHash);
            }

            $res = $r['result'] ?? [];
            $this->safeInsertLog([
                'event_type'        => $eventType,
                'user_id'           => $userId,
                'endpoint_hash'     => $endpointHash !== '' ? $endpointHash : null,
                'success'           => !empty($res['ok']) ? 1 : 0,
                'provider_status'   => isset($res['status']) ? (int)$res['status'] : null,
                'error_message'     => $this->extractError($res),
                'payload_json'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'provider_response' => json_encode($res, JSON_UNESCAPED_UNICODE),
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function safeInsertLog(array $row): void
    {
        try {
            $this->logs->insert($row);
        } catch (\Throwable $e) {
            log_message('error', 'push log insert skipped: {err}', ['err' => $e->getMessage()]);
        }
    }

    private function extractError(array $res): ?string
    {
        $error = $res['error'] ?? ($res['res']['error'] ?? null);
        if ($error === null) {
            return null;
        }

        $s = trim((string)$error);
        return $s !== '' ? mb_substr($s, 0, 255) : null;
    }

    private function cleanText(string $v, int $max): string
    {
        return mb_substr(trim($v), 0, $max);
    }

    private function cleanUa(string $v): string
    {
        return mb_substr(trim($v), 0, 255);
    }

    private function wasRecentlyDelivered(int $userId, string $eventType, array $payload, int $windowSeconds): bool
    {
        if ($userId <= 0 || $windowSeconds <= 0) {
            return false;
        }

        $tag = trim((string)($payload['tag'] ?? ''));
        $type = trim((string)($payload['type'] ?? ''));
        if ($tag === '' || $type === '') {
            return false;
        }

        $threshold = date('Y-m-d H:i:s', time() - $windowSeconds);
        $rows = $this->logs
            ->select('payload_json')
            ->where('user_id', $userId)
            ->where('event_type', $eventType)
            ->where('success', 1)
            ->where('created_at >=', $threshold)
            ->orderBy('id', 'DESC')
            ->findAll(10);

        foreach ($rows as $row) {
            $loggedPayload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($loggedPayload)) {
                continue;
            }

            if (
                trim((string)($loggedPayload['tag'] ?? '')) === $tag
                && trim((string)($loggedPayload['type'] ?? '')) === $type
            ) {
                return true;
            }
        }

        return false;
    }

    public static function notificationIconUrl(): string
    {
        return base_url('notifications/icon.svg');
    }

    public static function notificationBadgeUrl(): string
    {
        return base_url('notifications/badge.svg');
    }
}
