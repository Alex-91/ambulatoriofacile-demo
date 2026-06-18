<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\OtpDeliveryLogModel;

class OtpStats extends BaseController
{
    private array $userContactCache = [];

    public function index()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $menuAdmin = session()->get('menuDataAdmin');
        $menu_items = $menuAdmin['result'] ?? [];

        $model = new OtpDeliveryLogModel();
        [$from, $to, $fromAt, $toAt] = $this->resolveDateRange();
        $latestLoginEmailDay = $model->tableExists()
            ? $model->getLatestSuccessfulLoginEmailDay('1970-01-01 00:00:00', date('Y-m-d') . ' 23:59:59')
            : null;
        $hasLoginEmailSearch = $this->hasLoginEmailSearch();
        $selectedLoginEmailDay = $this->resolveLoginEmailDay($latestLoginEmailDay ?? $to);
        $selectedLoginEmailSeenFlag = $this->resolveLoginEmailSeenFlag();

        $hasTrackingTable = $model->tableExists();
        $trackingStartAt = $hasTrackingTable ? $model->getTrackingStartAt() : null;

        $channelSummary = $hasTrackingTable ? $this->indexChannelRows($model->getChannelSummary($fromAt, $toAt)) : [];
        $purposeSummary = $hasTrackingTable ? $this->buildPurposeRows($model->getPurposeChannelSummary($fromAt, $toAt)) : [];
        $dailySummary = $hasTrackingTable ? $this->buildDailyRows($model->getDailySummary($fromAt, $toAt)) : [];
        $loginEmailDayStats = ($hasTrackingTable && $hasLoginEmailSearch)
            ? $this->applyLoginEmailSeenFilter($this->buildLoginEmailDayStats(
                $model->getSuccessfulLoginEmailRowsUntil($selectedLoginEmailDay . ' 23:59:59'),
                $selectedLoginEmailDay
            ), $selectedLoginEmailSeenFlag)
            : $this->applyLoginEmailSeenFilter($this->emptyLoginEmailDayStats($selectedLoginEmailDay), $selectedLoginEmailSeenFlag);

        $pushStats = $channelSummary['push'] ?? $this->emptyChannelStats('Notifica push');
        $emailStats = $channelSummary['email'] ?? $this->emptyChannelStats('Email');

        $totalSuccess = 0;
        $totalFailed = 0;
        $totalAttempts = 0;

        foreach ($channelSummary as $stats) {
            $totalSuccess += (int)$stats['success_count'];
            $totalFailed += (int)$stats['failed_count'];
            $totalAttempts += (int)$stats['total_count'];
        }

        return view('admin/otp_stats', [
            'menu_items' => $menu_items,
            'pageTitle' => 'Statistiche OTP',
            'fromDate' => $from,
            'toDate' => $to,
            'hasLoginEmailSearch' => $hasLoginEmailSearch,
            'selectedLoginEmailDay' => $selectedLoginEmailDay,
            'selectedLoginEmailSeenFlag' => $selectedLoginEmailSeenFlag,
            'latestLoginEmailDay' => $latestLoginEmailDay,
            'hasTrackingTable' => $hasTrackingTable,
            'trackingStartAt' => $trackingStartAt,
            'pushStats' => $pushStats,
            'emailStats' => $emailStats,
            'channelSummary' => $channelSummary,
            'purposeSummary' => $purposeSummary,
            'dailySummary' => $dailySummary,
            'loginEmailDayStats' => $loginEmailDayStats,
            'totalSuccess' => $totalSuccess,
            'totalFailed' => $totalFailed,
            'totalAttempts' => $totalAttempts,
        ]);
    }

    public function exportLoginEmailCsv()
    {
        $guard = $this->ensureAdmin();
        if ($guard !== null) {
            return $guard;
        }

        $model = new OtpDeliveryLogModel();
        $latestLoginEmailDay = $model->tableExists()
            ? $model->getLatestSuccessfulLoginEmailDay('1970-01-01 00:00:00', date('Y-m-d') . ' 23:59:59')
            : null;
        $selectedLoginEmailDay = $this->resolveLoginEmailDay($latestLoginEmailDay);
        $selectedLoginEmailSeenFlag = $this->resolveLoginEmailSeenFlag();

        if (!$model->tableExists()) {
            return redirect()->to(site_url('admin/otp-statistiche'))->with('error', 'Tabella statistiche OTP non disponibile.');
        }

        $dayStats = $this->applyLoginEmailSeenFilter($this->buildLoginEmailDayStats(
            $model->getSuccessfulLoginEmailRowsUntil($selectedLoginEmailDay . ' 23:59:59'),
            $selectedLoginEmailDay
        ), $selectedLoginEmailSeenFlag);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return redirect()->to(site_url('admin/otp-statistiche'))->with('error', 'Impossibile generare il CSV.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['email', 'nome', 'cognome', 'cellulare'], ';');

        foreach ($dayStats['visible_emails'] as $emailRow) {
            if (empty($emailRow['is_plain'])) {
                continue;
            }

            fputcsv($handle, [
                (string)($emailRow['email'] ?? ''),
                (string)($emailRow['nome'] ?? ''),
                (string)($emailRow['cognome'] ?? ''),
                (string)($emailRow['cellulare'] ?? ''),
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="otp-login-email-lista-' . $selectedLoginEmailDay . '-' . $this->loginEmailSeenFlagLabelForFile($selectedLoginEmailSeenFlag) . '.csv"')
            ->setBody($csv !== false ? $csv : '');
    }

    private function ensureAdmin()
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

    private function hasLoginEmailSearch(): bool
    {
        $value = strtolower(trim((string)($this->request->getGet('mail_search') ?? '')));

        return in_array($value, ['1', 'true', 'yes'], true);
    }

    private function resolveLoginEmailSeenFlag(): string
    {
        $value = strtolower(trim((string)($this->request->getGet('mail_seen_flag') ?? 'all')));

        return in_array($value, ['all', 'new', 'seen'], true) ? $value : 'all';
    }

    private function resolveDateRange(): array
    {
        $today = date('Y-m-d');
        $defaultFrom = date('Y-m-01');

        $from = trim((string)($this->request->getGet('from') ?? $defaultFrom));
        $to = trim((string)($this->request->getGet('to') ?? $today));

        if (!$this->isValidDate($from)) {
            $from = $defaultFrom;
        }

        if (!$this->isValidDate($to)) {
            $to = $today;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
    }

    private function resolveLoginEmailDay(?string $fallback = null): string
    {
        $defaultDay = $this->isValidDate((string)$fallback) ? (string)$fallback : date('Y-m-d');
        $value = trim((string)($this->request->getGet('login_email_day') ?? $defaultDay));

        if (!$this->isValidDate($value)) {
            return $defaultDay;
        }

        return $value;
    }

    private function isValidDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function indexChannelRows(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $channel = strtolower(trim((string)($row['channel'] ?? '')));
            if ($channel === '') {
                continue;
            }

            $summary[$channel] = [
                'label' => $this->channelLabel($channel),
                'success_count' => (int)($row['success_count'] ?? 0),
                'failed_count' => (int)($row['failed_count'] ?? 0),
                'total_count' => (int)($row['total_count'] ?? 0),
            ];
        }

        return $summary;
    }

    private function buildPurposeRows(array $rows): array
    {
        $order = [
            'login_mfa' => 1,
            'password_reset' => 2,
            'password_expired' => 3,
            'password_change' => 4,
        ];

        $summary = [];

        foreach ($rows as $row) {
            $purpose = strtolower(trim((string)($row['purpose'] ?? '')));
            $channel = strtolower(trim((string)($row['channel'] ?? '')));

            if ($purpose === '') {
                continue;
            }

            if (!isset($summary[$purpose])) {
                $summary[$purpose] = [
                    'purpose' => $purpose,
                    'label' => $this->purposeLabel($purpose),
                    'push_success' => 0,
                    'push_failed' => 0,
                    'email_success' => 0,
                    'email_failed' => 0,
                    'total_count' => 0,
                    'sort_order' => $order[$purpose] ?? 99,
                ];
            }

            $successCount = (int)($row['success_count'] ?? 0);
            $failedCount = (int)($row['failed_count'] ?? 0);
            $summary[$purpose]['total_count'] += (int)($row['total_count'] ?? 0);

            if ($channel === 'push') {
                $summary[$purpose]['push_success'] += $successCount;
                $summary[$purpose]['push_failed'] += $failedCount;
            } elseif ($channel === 'email') {
                $summary[$purpose]['email_success'] += $successCount;
                $summary[$purpose]['email_failed'] += $failedCount;
            }
        }

        uasort($summary, static function (array $left, array $right): int {
            if ($left['sort_order'] === $right['sort_order']) {
                return strcmp((string)$left['label'], (string)$right['label']);
            }

            return $left['sort_order'] <=> $right['sort_order'];
        });

        return array_values($summary);
    }

    private function buildDailyRows(array $rows): array
    {
        $summary = [];

        foreach ($rows as $row) {
            $day = trim((string)($row['day_key'] ?? ''));
            $channel = strtolower(trim((string)($row['channel'] ?? '')));

            if ($day === '') {
                continue;
            }

            if (!isset($summary[$day])) {
                $summary[$day] = [
                    'day_key' => $day,
                    'push_success' => 0,
                    'push_failed' => 0,
                    'email_success' => 0,
                    'email_failed' => 0,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'total_count' => 0,
                ];
            }

            $successCount = (int)($row['success_count'] ?? 0);
            $failedCount = (int)($row['failed_count'] ?? 0);
            $totalCount = (int)($row['total_count'] ?? 0);

            if ($channel === 'push') {
                $summary[$day]['push_success'] += $successCount;
                $summary[$day]['push_failed'] += $failedCount;
            } elseif ($channel === 'email') {
                $summary[$day]['email_success'] += $successCount;
                $summary[$day]['email_failed'] += $failedCount;
            }

            $summary[$day]['total_success'] += $successCount;
            $summary[$day]['total_failed'] += $failedCount;
            $summary[$day]['total_count'] += $totalCount;
        }

        krsort($summary);

        return array_values($summary);
    }

    private function emptyChannelStats(string $label): array
    {
        return [
            'label' => $label,
            'success_count' => 0,
            'failed_count' => 0,
            'total_count' => 0,
        ];
    }

    private function emptyLoginEmailDayStats(string $day): array
    {
        return [
            'day_key' => $day,
            'total_sent' => 0,
            'unique_email_count' => 0,
            'new_email_count' => 0,
            'seen_email_count' => 0,
            'unresolved_count' => 0,
            'emails' => [],
            'visible_total_sent' => 0,
            'visible_unique_email_count' => 0,
            'visible_plain_email_count' => 0,
            'visible_unresolved_count' => 0,
            'visible_emails' => [],
            'selected_seen_flag' => 'all',
        ];
    }

    private function buildLoginEmailDayStats(array $rows, string $selectedDay): array
    {
        $firstSeenByKey = [];
        $dayStats = $this->emptyLoginEmailDayStats($selectedDay);

        foreach ($rows as $row) {
            $createdAt = trim((string)($row['created_at'] ?? ''));
            $day = substr($createdAt, 0, 10);
            if (!$this->isValidDate($day)) {
                continue;
            }

            $meta = $this->decodeMetaJson((string)($row['meta_json'] ?? ''));
            $emailInfo = $this->extractLoginEmailInfo($row, $meta);
            if ($emailInfo === null) {
                continue;
            }

            $emailKey = $emailInfo['key'];
            $userContact = $this->lookupUserContact((int)($row['user_id'] ?? 0));

            if (!isset($firstSeenByKey[$emailKey])) {
                $firstSeenByKey[$emailKey] = [
                    'first_day' => $day,
                    'email' => $emailInfo['email'],
                    'is_plain' => $emailInfo['is_plain'],
                ];
            } elseif (!$firstSeenByKey[$emailKey]['is_plain'] && $emailInfo['is_plain']) {
                $firstSeenByKey[$emailKey]['email'] = $emailInfo['email'];
                $firstSeenByKey[$emailKey]['is_plain'] = true;
            }

            if ($day !== $selectedDay) {
                continue;
            }

            if (!isset($dayStats['emails'][$emailKey])) {
                $dayStats['emails'][$emailKey] = [
                    'email_key' => $emailKey,
                    'email' => $emailInfo['email'],
                    'sent_count' => 0,
                    'is_first_time' => false,
                    'is_plain' => $emailInfo['is_plain'],
                    'nome' => '',
                    'cognome' => '',
                    'cellulare' => '',
                ];
            } elseif (!$dayStats['emails'][$emailKey]['is_plain'] && $emailInfo['is_plain']) {
                $dayStats['emails'][$emailKey]['email'] = $emailInfo['email'];
                $dayStats['emails'][$emailKey]['is_plain'] = true;
            }

            $dayStats['emails'][$emailKey] = $this->mergeUserContactIntoEmailRow(
                $dayStats['emails'][$emailKey],
                $userContact
            );
            $dayStats['emails'][$emailKey]['sent_count']++;
            $dayStats['total_sent']++;

            if (($firstSeenByKey[$emailKey]['first_day'] ?? '') === $selectedDay) {
                $dayStats['emails'][$emailKey]['is_first_time'] = true;
            }
        }

        foreach ($dayStats['emails'] as $emailKey => &$emailRow) {
            if (!empty($firstSeenByKey[$emailKey]['is_plain'])) {
                $emailRow['email'] = (string)$firstSeenByKey[$emailKey]['email'];
                $emailRow['is_plain'] = true;
            }
        }
        unset($emailRow);

        uasort($dayStats['emails'], static function (array $left, array $right): int {
            if ($left['sent_count'] === $right['sent_count']) {
                $leftSurname = mb_strtolower(trim((string)($left['cognome'] ?? '')));
                $rightSurname = mb_strtolower(trim((string)($right['cognome'] ?? '')));
                if ($leftSurname !== $rightSurname) {
                    return $leftSurname <=> $rightSurname;
                }

                $leftName = mb_strtolower(trim((string)($left['nome'] ?? '')));
                $rightName = mb_strtolower(trim((string)($right['nome'] ?? '')));
                if ($leftName !== $rightName) {
                    return $leftName <=> $rightName;
                }

                return strcmp((string)$left['email'], (string)$right['email']);
            }

            return $right['sent_count'] <=> $left['sent_count'];
        });

        $dayStats['emails'] = array_values($dayStats['emails']);
        $dayStats['unique_email_count'] = count($dayStats['emails']);
        $dayStats['new_email_count'] = count(array_filter(
            $dayStats['emails'],
            static fn(array $emailRow): bool => !empty($emailRow['is_first_time'])
        ));
        $dayStats['seen_email_count'] = $dayStats['unique_email_count'] - $dayStats['new_email_count'];
        $dayStats['unresolved_count'] = count(array_filter(
            $dayStats['emails'],
            static fn(array $emailRow): bool => empty($emailRow['is_plain'])
        ));

        return $dayStats;
    }

    private function applyLoginEmailSeenFilter(array $dayStats, string $selectedFlag): array
    {
        $visibleEmails = array_values(array_filter(
            $dayStats['emails'] ?? [],
            static function (array $emailRow) use ($selectedFlag): bool {
                if ($selectedFlag === 'new') {
                    return !empty($emailRow['is_first_time']);
                }

                if ($selectedFlag === 'seen') {
                    return empty($emailRow['is_first_time']);
                }

                return true;
            }
        ));

        $visibleTotalSent = 0;
        foreach ($visibleEmails as $emailRow) {
            $visibleTotalSent += (int)($emailRow['sent_count'] ?? 0);
        }

        $dayStats['selected_seen_flag'] = $selectedFlag;
        $dayStats['visible_emails'] = $visibleEmails;
        $dayStats['visible_total_sent'] = $visibleTotalSent;
        $dayStats['visible_unique_email_count'] = count($visibleEmails);
        $dayStats['visible_plain_email_count'] = count(array_filter(
            $visibleEmails,
            static fn(array $emailRow): bool => !empty($emailRow['is_plain'])
        ));
        $dayStats['visible_unresolved_count'] = count(array_filter(
            $visibleEmails,
            static fn(array $emailRow): bool => empty($emailRow['is_plain'])
        ));

        return $dayStats;
    }

    private function decodeMetaJson(string $metaJson): array
    {
        $metaJson = trim($metaJson);
        if ($metaJson === '') {
            return [];
        }

        try {
            $decoded = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function extractLoginEmailInfo(array $row, array $meta): ?array
    {
        $rawEmail = $this->normalizeEmail((string)($meta['email_address'] ?? ''));
        $emailHash = strtolower(trim((string)($meta['email_hash'] ?? '')));
        $maskedEmail = trim((string)($meta['masked_email'] ?? ''));

        if ($rawEmail === '') {
            $rawEmail = $this->resolveUserEmailFromHistory(
                (int)($row['user_id'] ?? 0),
                $emailHash,
                $maskedEmail
            );
        }

        $key = '';
        if ($emailHash !== '') {
            $key = 'hash:' . $emailHash;
        } elseif ($rawEmail !== '') {
            $key = 'email:' . $rawEmail;
        } elseif ($maskedEmail !== '') {
            $key = 'masked:' . strtolower($maskedEmail);
        }

        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'email' => $rawEmail !== '' ? $rawEmail : ($maskedEmail !== '' ? $maskedEmail : 'Email non disponibile'),
            'is_plain' => $rawEmail !== '',
        ];
    }

    private function resolveUserEmailFromHistory(int $userId, string $expectedHash, string $maskedEmail): string
    {
        $candidate = $this->lookupUserContact($userId)['email'];
        if ($candidate === '') {
            return '';
        }

        if ($expectedHash !== '' && hash('sha256', $candidate) !== $expectedHash) {
            return '';
        }

        if ($expectedHash === '' && $maskedEmail !== '' && $this->maskEmail($candidate) !== strtolower($maskedEmail)) {
            return '';
        }

        return $candidate;
    }

    private function lookupUserContact(int $userId): array
    {
        if ($userId <= 0) {
            return $this->emptyUserContact();
        }

        if (array_key_exists($userId, $this->userContactCache)) {
            return $this->userContactCache[$userId];
        }

        $row = $this->db->query("
            SELECT
                email_plain,
                nome_plain,
                cognome_plain,
                cellulare_plain
            FROM (
                SELECT
                    CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR) AS email_plain,
                    CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR) AS nome_plain,
                    CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR) AS cognome_plain,
                    CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR) AS cellulare_plain,
                    1 AS sort_order
                FROM dap02_clients c
                WHERE c.id_user = ?
                UNION ALL
                SELECT
                    CAST(AES_DECRYPT(UNHEX(p.email), @key_str, p.vector_id) AS CHAR) AS email_plain,
                    CAST(AES_DECRYPT(UNHEX(p.nome), @key_str, p.vector_id) AS CHAR) AS nome_plain,
                    CAST(AES_DECRYPT(UNHEX(p.cognome), @key_str, p.vector_id) AS CHAR) AS cognome_plain,
                    CAST(AES_DECRYPT(UNHEX(p.cellulare), @key_str, p.vector_id) AS CHAR) AS cellulare_plain,
                    2 AS sort_order
                FROM dap03_personale p
                WHERE p.id_user = ?
            ) contacts
            WHERE (
                email_plain IS NOT NULL
                AND TRIM(email_plain) <> ''
            ) OR (
                nome_plain IS NOT NULL
                AND TRIM(nome_plain) <> ''
            ) OR (
                cognome_plain IS NOT NULL
                AND TRIM(cognome_plain) <> ''
            ) OR (
                cellulare_plain IS NOT NULL
                AND TRIM(cellulare_plain) <> ''
            )
            ORDER BY sort_order ASC
            LIMIT 1
        ", [$userId, $userId])->getRowArray();

        $contact = [
            'email' => $this->normalizeEmail((string)($row['email_plain'] ?? '')),
            'nome' => $this->normalizeTextField((string)($row['nome_plain'] ?? '')),
            'cognome' => $this->normalizeTextField((string)($row['cognome_plain'] ?? '')),
            'cellulare' => $this->normalizeTextField((string)($row['cellulare_plain'] ?? '')),
        ];

        $this->userContactCache[$userId] = $contact;

        return $contact;
    }

    private function emptyUserContact(): array
    {
        return [
            'email' => '',
            'nome' => '',
            'cognome' => '',
            'cellulare' => '',
        ];
    }

    private function mergeUserContactIntoEmailRow(array $emailRow, array $userContact): array
    {
        foreach (['nome', 'cognome', 'cellulare'] as $field) {
            $emailRow[$field] = $this->mergeDistinctTextValues(
                (string)($emailRow[$field] ?? ''),
                (string)($userContact[$field] ?? '')
            );
        }

        return $emailRow;
    }

    private function mergeDistinctTextValues(string $current, string $candidate): string
    {
        $candidate = $this->normalizeTextField($candidate);
        if ($candidate === '') {
            return $this->normalizeTextField($current);
        }

        $current = trim($current);
        if ($current === '') {
            return $candidate;
        }

        $values = array_values(array_filter(array_map(
            static fn(string $value): string => trim($value),
            explode(' / ', $current)
        ), static fn(string $value): bool => $value !== ''));

        foreach ($values as $value) {
            if (mb_strtolower($value) === mb_strtolower($candidate)) {
                return implode(' / ', $values);
            }
        }

        $values[] = $candidate;

        return implode(' / ', $values);
    }

    private function normalizeTextField(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', $value);

        return trim((string)($normalized ?? $value));
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function maskEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }

        [$local, $domain] = explode('@', $email, 2);
        $localLen = mb_strlen($local);

        if ($localLen <= 2) {
            $maskedLocal = mb_substr($local, 0, 1) . '*';
        } else {
            $maskedLocal = mb_substr($local, 0, 2) . str_repeat('*', max(1, $localLen - 2));
        }

        return strtolower($maskedLocal . '@' . $domain);
    }

    private function loginEmailSeenFlagLabelForFile(string $flag): string
    {
        return match ($flag) {
            'new' => 'mai-viste',
            'seen' => 'gia-viste',
            default => 'tutte',
        };
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'push' => 'Notifica push',
            'email' => 'Email',
            'sms' => 'SMS',
            'wa' => 'WhatsApp',
            default => strtoupper($channel),
        };
    }

    private function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'login_mfa' => 'Login MFA',
            'password_reset' => 'Reset password',
            'password_expired' => 'Password scaduta',
            'password_change' => 'Cambio password profilo',
            default => ucwords(str_replace('_', ' ', $purpose)),
        };
    }
}
