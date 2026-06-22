<?php

namespace App\Controllers;

use App\Services\NotificationService;

class PushController extends BaseController
{
    protected NotificationService $notifications;

    public function __construct()
    {
        $this->notifications = new NotificationService();
    }

    private function currentUserId(): int
    {
        $sessionUserId = (int)(session()->get('userId') ?? 0);
        if ($sessionUserId > 0) {
            return $sessionUserId;
        }

        $me = session()->get('utente_sess');
        if (is_object($me) && !empty($me->id_user)) {
            return (int)$me->id_user;
        }

        return 0;
    }

    public function publicKey()
    {
        return $this->response->setJSON([
            'key' => env('VAPID_PUBLIC_KEY', ''),
        ]);
    }

    public function subscribe()
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok'    => false,
                'error' => 'Not logged in',
            ]);
        }

        $body = $this->request->getJSON(true) ?? [];
        $sub = $body['subscription'] ?? $body;
        $keys = $sub['keys'] ?? [];

        $endpoint = trim((string)($sub['endpoint'] ?? ''));
        $p256dh = trim((string)($keys['p256dh'] ?? ($body['p256dh'] ?? '')));
        $auth = trim((string)($keys['auth'] ?? ($body['auth'] ?? '')));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'    => false,
                'error' => 'Invalid subscription payload',
            ]);
        }

        $meta = $this->buildDeviceMeta($body);

        if ((int)$meta['is_mobile'] !== 1) {
            return $this->response->setJSON([
                'ok'          => true,
                'ignored'     => true,
                'device_type' => $meta['device_type'],
                'reason'      => 'desktop_ignored',
            ]);
        }

        $id = $this->notifications->registerSubscription(
            $userId,
            $endpoint,
            $p256dh,
            $auth,
            $meta,
            true
        );

        return $this->response->setJSON([
            'ok'          => true,
            'id'          => $id,
            'device_type' => $meta['device_type'],
            'single'      => true,
        ]);
    }

    public function test()
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok'    => false,
                'error' => 'Not logged in',
            ]);
        }

        $payload = [
            'type'  => 'test',
            'title' => 'AmbulatorioFacile',
            'body'  => 'Le notifiche push sono attive.',
            'tag'   => 'push-test',
            'icon'  => NotificationService::notificationIconUrl(),
            'badge' => NotificationService::notificationBadgeUrl(),
            'data'  => [
                'url' => base_url('profilo'),
            ],
        ];

        $result = $this->notifications->sendToUser($userId, $payload, 'test');

        return $this->response->setJSON([
            'ok'     => !empty($result['ok']),
            'result' => $result,
        ]);
    }

    public function syncPermission()
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON([
                'ok'    => false,
                'error' => 'Not logged in',
            ]);
        }

        $json = $this->request->getJSON(true);
        if (!is_array($json)) {
            $json = [];
        }

        $permission = strtolower(trim((string)(
            $this->request->getPost('permission')
            ?? ($json['permission'] ?? '')
            ?? ''
        )));
        $endpoint = trim((string)(
            $this->request->getPost('endpoint')
            ?? ($json['endpoint'] ?? '')
            ?? ''
        ));

        if ($permission === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'ok'    => false,
                'error' => 'Missing permission state',
            ]);
        }

        $deactivated = false;
        if ($permission === 'denied' && $endpoint !== '') {
            $row = (new \App\Models\PushSubscriptionModel())->findByEndpoint($endpoint);
            if ($row) {
                // Browser/device-level deny makes the endpoint unusable for every account linked to it.
                $this->notifications->deactivateEndpoint($endpoint);
                $deactivated = true;
            }
        }

        return $this->response->setJSON([
            'ok'          => true,
            'permission'  => $permission,
            'deactivated' => $deactivated,
        ]);
    }

    public function debugUser(int $userId)
    {
        $rows = (new \App\Models\PushSubscriptionModel())
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->orderBy('id', 'DESC')
            ->findAll();

        if (empty($rows)) {
            return $this->response->setJSON([
                'ok'         => true,
                'activeSubs' => [],
                'debugSend'  => 'no active subscriptions',
            ]);
        }

        $result = $this->notifications->sendToUser(
            $userId,
            [
                'type'  => 'debug',
                'title' => 'AmbulatorioFacile',
                'body'  => 'Debug notifica push.',
                'tag'   => 'debug-' . time(),
                'data'  => ['url' => base_url('profilo')],
            ],
            'debug'
        );

        return $this->response->setJSON([
            'ok'         => !empty($result['ok']),
            'activeSubs' => array_map(static function (array $r): array {
                return [
                    'id'          => (int)$r['id'],
                    'device_name' => (string)($r['device_name'] ?? ''),
                    'device_os'   => (string)($r['device_os'] ?? ''),
                    'is_mobile'   => (int)($r['is_mobile'] ?? 0),
                ];
            }, $rows),
            'debugSend' => $result,
        ]);
    }

    private function buildDeviceMeta(array $body = []): array
    {
        $ua = $this->request->getUserAgent();
        $uaString = $ua->getAgentString();
        $platform = (string)($ua->getPlatform() ?? '');
        $browser = (string)($ua->getBrowser() ?? '');

        $s = strtolower($uaString);
        $isTablet = preg_match('/ipad|tablet|sm\\-t|kindle|silk|playbook/', $s) === 1
            || (str_contains($s, 'android') && !str_contains($s, 'mobile'));
        $isPhone = !$isTablet && preg_match('/iphone|ipod|android.*mobile|mobile safari|mobi/', $s) === 1;
        $isMobile = $isPhone || $isTablet;

        $deviceInfo = $body['deviceInfo'] ?? [];

        $label = (string)($body['deviceLabel'] ?? $body['device_label'] ?? '');
        $brand = (string)($deviceInfo['brand'] ?? '');
        $model = (string)($deviceInfo['model'] ?? '');

        if ($model === '' && str_contains($s, 'iphone')) {
            $model = 'iPhone';
        } elseif ($model === '' && str_contains($s, 'ipad')) {
            $model = 'iPad';
        }

        if ($brand === '' && ($model === 'iPhone' || $model === 'iPad')) {
            $brand = 'Apple';
        }

        if ($label === '') {
            $label = trim($brand . ' ' . $model);
        }
        if ($label === '') {
            $label = $isMobile ? 'Smartphone' : 'Browser';
        }

        return [
            'ua'           => $uaString,
            'browser'      => $browser,
            'device_os'    => $platform !== '' ? $platform : ($isMobile ? 'Mobile' : 'Desktop'),
            'device_type'  => $isPhone ? 'phone' : ($isTablet ? 'tablet' : 'desktop'),
            'is_mobile'    => $isMobile ? 1 : 0,
            'device_name'  => mb_substr($label, 0, 100),
            'device_label' => mb_substr($label, 0, 120),
            'device_brand' => $brand !== '' ? mb_substr($brand, 0, 64) : null,
            'device_model' => $model !== '' ? mb_substr($model, 0, 64) : null,
        ];
    }
}
