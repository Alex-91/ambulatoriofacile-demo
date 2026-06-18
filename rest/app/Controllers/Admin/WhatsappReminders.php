<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\WhatsappReminderMonitor;

class WhatsappReminders extends BaseController
{
    public function index()
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        $days = max(1, min(90, (int)($this->request->getGet('days') ?? 7)));
        $monitor = new WhatsappReminderMonitor();
        $snapshot = $monitor->snapshot($days);

        return view('admin/whatsapp_reminders', [
            'menu_items' => $this->resolveMenuItems(),
            'pageTitle' => 'Stato reminder WhatsApp',
            'days' => $days,
            'snapshot' => $snapshot,
            'launchFeedback' => session()->getFlashdata('reminder_launch_feedback'),
            'manualDefaults' => [
                'target_date' => date('Y-m-d', strtotime('+' . $this->defaultDaysAhead() . ' day')),
                'start_date' => date('Y-m-d', strtotime('+' . $this->defaultDaysAhead() . ' day')),
                'days_count' => 1,
                'delay_ms' => (int)(env('SMS_BATCH_DELAY_MS') ?: 900000),
                'channel' => strtolower((string)(env('REMINDER_CHANNEL') ?: 'wa')),
            ],
        ]);
    }

    public function launch()
    {
        if ($redirect = $this->guardAdmin()) {
            return $redirect;
        }

        $mode = strtolower(trim((string)$this->request->getPost('mode')));
        $channel = strtolower(trim((string)$this->request->getPost('channel')));
        $targetDate = trim((string)$this->request->getPost('target_date'));
        if ($targetDate === '') {
            $targetDate = trim((string)$this->request->getPost('start_date'));
        }
        $confirmedTargetDate = trim((string)$this->request->getPost('confirm_target_date'));
        $delayMs = max(0, (int)$this->request->getPost('delay_ms'));
        $doctor = trim((string)$this->request->getPost('doctor'));
        $limitRaw = trim((string)$this->request->getPost('limit'));
        $forceRecipient = trim((string)$this->request->getPost('force_recipient'));

        if (!in_array($mode, ['dry-run', 'send'], true)) {
            return $this->redirectWithLaunchFeedback(false, 'Modalita non valida.');
        }

        if (!in_array($channel, ['wa', 'sms'], true)) {
            return $this->redirectWithLaunchFeedback(false, 'Canale non valido.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            return $this->redirectWithLaunchFeedback(false, 'Giorno non valido. Usa YYYY-MM-DD.');
        }

        if ($confirmedTargetDate !== $targetDate) {
            return $this->redirectWithLaunchFeedback(false, 'Conferma data mancante o non coerente. Riapri il lancio manuale e conferma il giorno da eseguire.');
        }

        $token = trim((string)(env('CRON_ACCESS_TOKEN') ?: ''));
        if ($token === '') {
            return $this->redirectWithLaunchFeedback(false, 'CRON_ACCESS_TOKEN non configurato nel .env.');
        }

        $params = [
            'date' => $targetDate,
            'delay-ms' => $delayMs,
            'channel' => $channel,
            'origin' => 'admin-manual',
        ];

        if ($doctor !== '') {
            $params['doctor'] = $doctor;
        }

        if ($limitRaw !== '') {
            $params['limit'] = max(1, (int)$limitRaw);
        }

        if ($forceRecipient !== '') {
            $params['force-recipient'] = $forceRecipient;
        }

        $params['mode'] = $mode;
        $runnerUrl = site_url('admin/whatsapp-reminders/run');
        $result = $this->triggerBackgroundReminderJob($runnerUrl, $token, $params);

        if (!$result['ok']) {
            return $this->redirectWithLaunchFeedback(false, 'Lancio non riuscito: ' . ($result['error'] ?? 'errore sconosciuto.'));
        }

        $message = sprintf(
            'Batch %s avviato in background per il giorno %s, canale %s.',
            $mode,
            $targetDate,
            strtoupper($channel)
        );

        if (!empty($params['limit'])) {
            $message .= ' Limit: ' . (int)$params['limit'] . '.';
        }

        if (!empty($params['doctor'])) {
            $message .= ' Filtro dottori: ' . $params['doctor'] . '.';
        }

        if (!empty($params['force-recipient'])) {
            $message .= ' Destinatario forzato: ' . $params['force-recipient'] . '.';
        }

        return $this->redirectWithLaunchFeedback(true, $message);
    }

    public function run()
    {
        $token = trim((string)(env('CRON_ACCESS_TOKEN') ?: ''));
        $providedToken = trim((string)($this->request->getGet('token') ?: $this->request->getHeaderLine('X-Cron-Token')));
        if ($token === '' || $providedToken === '' || !hash_equals($token, $providedToken)) {
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        $mode = strtolower(trim((string)$this->request->getGet('mode')));
        if (!in_array($mode, ['dry-run', 'send'], true)) {
            return $this->response->setStatusCode(400)->setBody('Mode non valido.');
        }

        if (session_status() === PHP_SESSION_ACTIVE && function_exists('session_write_close')) {
            @session_write_close();
        }

        $rootDir = rtrim(ROOTPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        require_once $rootDir . 'cron_web_auth.php';

        cronPrepareBackgroundExecution(
            $mode === 'send'
                ? 'Live reminder batch avviato.'
                : 'Dry-run reminder batch avviato.'
        );

        if (!defined('REMINDER_WEB_ALLOWED')) {
            define('REMINDER_WEB_ALLOWED', true);
        }

        $argv = cronBuildArgvFromHttp([
            $rootDir . 'cron_send_appointment_reminders.php',
            $mode === 'send' ? '--send' : '--dry-run',
        ], [
            'date',
            'start-date',
            'days-count',
            'days-ahead',
            'doctor',
            'limit',
            'delay-ms',
            'force-recipient',
            'channel',
        ]);

        require $rootDir . 'cron_send_appointment_reminders.php';
        exit(0);
    }

    private function guardAdmin()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return redirect()->to('/login');
        }

        if (session()->get('is_admin') !== true && (int)($me->tipo ?? 0) !== 1) {
            return redirect()->to('/');
        }

        return null;
    }

    private function resolveMenuItems(): array
    {
        $menuItems = session()->get('header_menu_items') ?? [];
        $adminMenu = session()->get('menuDataAdmin');
        if (!empty($adminMenu['result'])) {
            $menuItems = $adminMenu['result'];
        }

        return is_array($menuItems) ? $menuItems : [];
    }

    private function redirectWithLaunchFeedback(bool $ok, string $message)
    {
        session()->setFlashdata('reminder_launch_feedback', [
            'ok' => $ok,
            'message' => $message,
        ]);

        return redirect()->to(site_url('admin/whatsapp-reminders') . '?tab=manual');
    }

    private function triggerBackgroundReminderJob(string $url, string $token, array $params): array
    {
        $requestParams = $params;
        $requestParams['token'] = $token;
        $separator = str_contains($url, '?') ? '&' : '?';
        $fullUrl = $this->alignUrlToCurrentRequestOrigin($url . $separator . http_build_query($requestParams));

        return $this->performBackgroundHttpRequest($fullUrl, $token, true);
    }

    private function performBackgroundHttpRequest(string $fullUrl, string $token, bool $allowSingleRedirect): array
    {
        if (function_exists('curl_init')) {
            $responseHeaders = [];
            $ch = curl_init($fullUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_HTTPHEADER => [
                    'X-Cron-Token: ' . $token,
                    'X-Reminder-Origin: admin-manual',
                ],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                    $responseHeaders[] = $headerLine;
                    return strlen($headerLine);
                },
            ]);

            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($body === false) {
                return [
                    'ok' => false,
                    'error' => $error !== '' ? $error : 'Errore cURL.',
                    'url' => $effectiveUrl !== '' ? $effectiveUrl : $fullUrl,
                ];
            }

            $requestUrl = $effectiveUrl !== '' ? $effectiveUrl : $fullUrl;
            if ($allowSingleRedirect && in_array($status, [301, 302, 307, 308], true)) {
                $redirectUrl = $this->extractRedirectUrl($requestUrl, $responseHeaders);
                if ($redirectUrl !== null) {
                    return $this->performBackgroundHttpRequest($redirectUrl, $token, false);
                }
            }

            if ($status < 200 || $status >= 300) {
                $detail = $this->summarizeHttpResponseBody($body);
                return [
                    'ok' => false,
                    'error' => 'HTTP ' . $status . ($detail !== '' ? (' - ' . $detail) : ''),
                    'url' => $requestUrl,
                ];
            }

            return [
                'ok' => true,
                'response' => $body,
                'url' => $requestUrl,
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'header' => "X-Cron-Token: {$token}\r\nX-Reminder-Origin: admin-manual\r\n",
            ],
        ]);

        $body = @file_get_contents($fullUrl, false, $context);
        if ($body === false) {
            return ['ok' => false, 'error' => 'file_get_contents fallito.'];
        }

        $status = 0;
        $headers = $http_response_header ?? [];
        foreach (($http_response_header ?? []) as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$headerLine, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }

        if ($allowSingleRedirect && in_array($status, [301, 302, 307, 308], true)) {
            $redirectUrl = $this->extractRedirectUrl($fullUrl, $headers);
            if ($redirectUrl !== null) {
                return $this->performBackgroundHttpRequest($redirectUrl, $token, false);
            }
        }

        if ($status < 200 || $status >= 300) {
            $detail = $this->summarizeHttpResponseBody($body);
            return [
                'ok' => false,
                'error' => $status > 0
                    ? ('HTTP ' . $status . ($detail !== '' ? (' - ' . $detail) : ''))
                    : 'Risposta HTTP non valida.',
                'response' => $body,
            ];
        }

        return ['ok' => true, 'response' => $body];
    }

    private function alignUrlToCurrentRequestOrigin(string $url): string
    {
        $target = parse_url($url);
        $current = parse_url((string)$this->request->getUri());
        if (!is_array($target) || !is_array($current)) {
            return $url;
        }

        $target['scheme'] = $current['scheme'] ?? ($target['scheme'] ?? 'http');
        $target['host'] = $current['host'] ?? ($target['host'] ?? '');
        if (isset($current['port'])) {
            $target['port'] = $current['port'];
        }

        return $this->buildUrlFromParts($target) ?: $url;
    }

    private function extractRedirectUrl(string $baseUrl, array $headers): ?string
    {
        foreach ($headers as $headerLine) {
            if (!is_string($headerLine)) {
                continue;
            }

            if (stripos($headerLine, 'Location:') !== 0) {
                continue;
            }

            $location = trim(substr($headerLine, 9));
            if ($location === '') {
                return null;
            }

            $resolved = $this->resolveUrlAgainstBase($baseUrl, $location);
            return $resolved !== '' ? $this->alignUrlToCurrentRequestOrigin($resolved) : null;
        }

        return null;
    }

    private function resolveUrlAgainstBase(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            return $location;
        }

        if (str_starts_with($location, '//')) {
            return ($base['scheme'] ?? 'https') . ':' . $location;
        }

        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'];
        $port = isset($base['port']) ? (':' . $base['port']) : '';

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $basePath = (string)($base['path'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        $dir = $dir === '' ? '' : $dir;

        return $scheme . '://' . $host . $port . $dir . '/' . ltrim($location, '/');
    }

    private function buildUrlFromParts(array $parts): string
    {
        if (empty($parts['host'])) {
            return '';
        }

        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? (':' . (int)$parts['port']) : '';
        $path = (string)($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? ('#' . $parts['fragment']) : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    private function summarizeHttpResponseBody(?string $body): string
    {
        $text = trim((string)$body);
        if ($text === '') {
            return '';
        }

        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($text, 0, 180)
            : substr($text, 0, 180);
    }

    private function defaultDaysAhead(): int
    {
        return 6;
    }
}
