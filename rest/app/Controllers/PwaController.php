<?php

namespace App\Controllers;

class PwaController extends BaseController
{
    public function loginManifest()
    {
        helper(['portal', 'url']);

        $publicBaseUrl = rtrim(portal_public_access_url(''), '/');
        $appBaseUrl = rtrim(base_url('/'), '/');

        $manifest = [
            'id' => '/login-pwa',
            'name' => 'AmbulatorioFacile',
            'short_name' => 'Ambulatorio',
            'start_url' => $publicBaseUrl . '/login?app=1',
            'scope' => $publicBaseUrl . '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#2c8895',
            'icons' => [
                [
                    'src' => $appBaseUrl . '/public/assets/images/pwa-icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => $appBaseUrl . '/public/assets/images/pwa-icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                ],
                [
                    'src' => $appBaseUrl . '/public/assets/images/pwa-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $payload = '{}';
        }

        return $this->response
            ->setContentType('application/manifest+json')
            ->setBody($payload);
    }

    public function loginServiceWorker()
    {
        helper('url');

        $serviceWorkerUrl = json_encode(base_url('sw.js'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($serviceWorkerUrl === false) {
            $serviceWorkerUrl = '"/sw.js"';
        }

        $script = 'importScripts(' . $serviceWorkerUrl . ');';

        return $this->response
            ->setHeader('Service-Worker-Allowed', '/')
            ->setContentType('application/javascript')
            ->setBody($script);
    }
}
