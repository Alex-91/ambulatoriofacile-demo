<?php

namespace App\Libraries;

use Config\Database;

class WhatsappReminderMonitor
{
    private const DEFAULT_ULTRAMSG_URL = 'https://api.ultramsg.com/instance123914/messages/chat';

    private \CodeIgniter\Database\BaseConnection $db;
    private string $cronLogFile;
    private string $stateDir;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->cronLogFile = WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'cron_send_appointment_reminders.log';
        $this->stateDir = WRITEPATH . 'reminder_state' . DIRECTORY_SEPARATOR;
    }

    public function snapshot(int $days = 7): array
    {
        $days = max(1, min($days, 90));

        return [
            'generated_at' => date('c'),
            'cron' => $this->buildCronSnapshot(),
            'state_files' => $this->buildStateFilesSnapshot(),
            'queue' => $this->buildUpcomingQueueSnapshot(),
            'history' => $this->buildHistorySnapshot(30),
            'outcomes' => $this->buildOutcomeSnapshot($days),
            'ultramsg' => $this->buildUltraMsgSnapshot(),
        ];
    }

    private function buildCronSnapshot(): array
    {
        if (!is_file($this->cronLogFile)) {
            return [
                'available' => false,
                'path' => $this->cronLogFile,
                'runs' => [],
                'recent_errors' => [],
                'tail_lines' => [],
                'last_run' => null,
                'last_dry_run' => null,
                'last_send_run' => null,
                'last_automatic_run' => null,
                'last_automatic_dry_run' => null,
                'last_automatic_send_run' => null,
                'last_manual_run' => null,
                'last_manual_dry_run' => null,
                'last_manual_send_run' => null,
                'running_run' => null,
                'running_automatic_run' => null,
                'running_manual_run' => null,
                'ran_today' => false,
                'ran_today_automatic' => false,
            ];
        }

        $lines = file($this->cronLogFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [
                'available' => false,
                'path' => $this->cronLogFile,
                'error' => 'Impossibile leggere il file log.',
                'runs' => [],
                'recent_errors' => [],
                'tail_lines' => [],
                'last_run' => null,
                'last_dry_run' => null,
                'last_send_run' => null,
                'last_automatic_run' => null,
                'last_automatic_dry_run' => null,
                'last_automatic_send_run' => null,
                'last_manual_run' => null,
                'last_manual_dry_run' => null,
                'last_manual_send_run' => null,
                'running_run' => null,
                'running_automatic_run' => null,
                'running_manual_run' => null,
                'ran_today' => false,
                'ran_today_automatic' => false,
            ];
        }

        $runs = [];
        $currentIndex = null;
        $recentErrors = [];

        foreach ($lines as $line) {
            $event = $this->parseCronLogLine($line);
            if ($event === null) {
                continue;
            }

            if ($event['level'] === 'ERROR') {
                $recentErrors[] = $event;
            }

            if ($event['message'] === 'Diagnostica ambiente cron.') {
                $runs[] = $this->newRunSnapshot($event);
                $currentIndex = array_key_last($runs);
                $runs[$currentIndex]['raw_lines'][] = $event['raw'];
                continue;
            }

            if ($currentIndex === null) {
                $runs[] = $this->newRunSnapshot($event);
                $currentIndex = array_key_last($runs);
            }

            $runs[$currentIndex]['raw_lines'][] = $event['raw'];

            if ($event['message'] === 'Avvio batch promemoria multi-data.') {
                $runs[$currentIndex]['multi_batch'] = [
                    'timestamp' => $event['timestamp'],
                    'context' => $event['context'] ?? [],
                ];
                $runs[$currentIndex]['status'] = 'running';
                continue;
            }

            if ($event['message'] === 'Avvio batch promemoria appuntamenti.') {
                $batch = $this->newBatchSnapshot($event);
                $runs[$currentIndex]['batches'][] = $batch;
                $runs[$currentIndex]['_current_batch_index'] = array_key_last($runs[$currentIndex]['batches']);
                $runs[$currentIndex]['batch'] = [
                    'timestamp' => $event['timestamp'],
                    'context' => $event['context'] ?? [],
                ];
                $runs[$currentIndex]['status'] = 'running';
                continue;
            }

            if ($event['message'] === 'Dry-run: promemoria pronto.') {
                $detail = $this->buildRunDetailFromEvent($event);
                $runs[$currentIndex]['preview_items'][] = $detail;
                $this->attachDetailToBatch($runs[$currentIndex], $detail, 'preview_items');
                continue;
            }

            if ($event['message'] === 'Promemoria inviato con successo.') {
                $runs[$currentIndex]['sent_items']++;
                $detail = $this->buildRunDetailFromEvent($event);
                $runs[$currentIndex]['sent_details'][] = $detail;
                $this->attachDetailToBatch($runs[$currentIndex], $detail, 'sent_details', 'sent_items');
                continue;
            }

            if ($event['message'] === 'Invio promemoria fallito.') {
                $runs[$currentIndex]['failed_items']++;
                $detail = $this->buildRunDetailFromEvent($event);
                $runs[$currentIndex]['failed_details'][] = $detail;
                $this->attachDetailToBatch($runs[$currentIndex], $detail, 'failed_details', 'failed_items');
                continue;
            }

            if ($event['message'] === 'Appuntamento saltato: nessun numero mobile valido.') {
                $runs[$currentIndex]['skipped_invalid_items']++;
                $detail = $this->buildRunDetailFromEvent($event);
                $runs[$currentIndex]['skipped_invalid_details'][] = $detail;
                $this->attachDetailToBatch($runs[$currentIndex], $detail, 'skipped_invalid_details', 'skipped_invalid_items');
                continue;
            }

            if ($event['message'] === 'Appuntamento gia inviato in precedenza, salto.') {
                $runs[$currentIndex]['already_sent_items']++;
                $detail = $this->buildRunDetailFromEvent($event);
                $runs[$currentIndex]['already_sent_details'][] = $detail;
                $this->attachDetailToBatch($runs[$currentIndex], $detail, 'already_sent_details', 'already_sent_items');
                continue;
            }

            if ($event['message'] === 'Batch promemoria completato.') {
                $completed = [
                    'timestamp' => $event['timestamp'],
                    'context' => $event['context'] ?? [],
                ];
                $this->completeBatchSnapshot($runs[$currentIndex], $completed);
                if ($runs[$currentIndex]['multi_batch'] === null) {
                    $runs[$currentIndex]['completed'] = $completed;
                    $runs[$currentIndex]['status'] = ((int)(($event['context']['failed'] ?? 0))) > 0
                        ? 'completed_with_errors'
                        : 'completed';
                }
                continue;
            }

            if ($event['message'] === 'Batch promemoria multi-data completato.') {
                $runs[$currentIndex]['completed'] = [
                    'timestamp' => $event['timestamp'],
                    'context' => $event['context'] ?? [],
                ];
                $runs[$currentIndex]['status'] = ((int)(($event['context']['failed'] ?? 0))) > 0
                    ? 'completed_with_errors'
                    : 'completed';
                continue;
            }

            if ($event['message'] === 'Errore fatale durante il batch promemoria.') {
                $runs[$currentIndex]['fatal'] = [
                    'timestamp' => $event['timestamp'],
                    'context' => $event['context'] ?? [],
                ];
                $runs[$currentIndex]['status'] = 'fatal';
            }
        }

        foreach ($runs as &$run) {
            $run = $this->finalizeRunSnapshot($run);
        }
        unset($run);

        $runs = array_reverse($runs);
        $recentErrors = array_slice(array_reverse($recentErrors), 0, 10);
        $tailLines = array_slice($lines, -120);

        $lastRun = $runs[0] ?? null;
        $lastDryRun = null;
        $lastSendRun = null;
        $lastAutomaticRun = null;
        $lastAutomaticDryRun = null;
        $lastAutomaticSendRun = null;
        $lastManualRun = null;
        $lastManualDryRun = null;
        $lastManualSendRun = null;
        $runningRun = null;
        $runningAutomaticRun = null;
        $runningManualRun = null;
        foreach ($runs as $run) {
            $mode = strtolower((string)($run['mode'] ?? ''));
            if ($lastDryRun === null && $mode === 'dry-run') {
                $lastDryRun = $run;
            }
            if ($lastSendRun === null && $mode === 'send') {
                $lastSendRun = $run;
            }

            if ($runningRun === null && in_array((string)($run['status'] ?? ''), ['started', 'running'], true)) {
                $runningRun = $run;
            }

            if (empty($run['is_manual'])) {
                if ($lastAutomaticRun === null) {
                    $lastAutomaticRun = $run;
                }
                if ($lastAutomaticDryRun === null && $mode === 'dry-run') {
                    $lastAutomaticDryRun = $run;
                }
                if ($lastAutomaticSendRun === null && $mode === 'send') {
                    $lastAutomaticSendRun = $run;
                }
                if ($runningAutomaticRun === null && in_array((string)($run['status'] ?? ''), ['started', 'running'], true)) {
                    $runningAutomaticRun = $run;
                }
            }

            if (!empty($run['is_manual'])) {
                if ($lastManualRun === null) {
                    $lastManualRun = $run;
                }
                if ($lastManualDryRun === null && $mode === 'dry-run') {
                    $lastManualDryRun = $run;
                }
                if ($lastManualSendRun === null && $mode === 'send') {
                    $lastManualSendRun = $run;
                }
                if ($runningManualRun === null && in_array((string)($run['status'] ?? ''), ['started', 'running'], true)) {
                    $runningManualRun = $run;
                }
            }

            if (
                $lastSendRun !== null
                && $lastDryRun !== null
                && $lastAutomaticRun !== null
                && $lastAutomaticDryRun !== null
                && $lastAutomaticSendRun !== null
                && $lastManualRun !== null
                && $lastManualDryRun !== null
                && $lastManualSendRun !== null
                && $runningRun !== null
                && $runningAutomaticRun !== null
                && $runningManualRun !== null
            ) {
                break;
            }
        }

        $ranToday = false;
        $today = date('Y-m-d');
        if ($lastRun !== null && str_starts_with((string)$lastRun['started_at'], $today)) {
            $ranToday = true;
        }

        $ranTodayAutomatic = false;
        if ($lastAutomaticRun !== null && str_starts_with((string)$lastAutomaticRun['started_at'], $today)) {
            $ranTodayAutomatic = true;
        }

        return [
            'available' => true,
            'path' => $this->cronLogFile,
            'runs' => array_slice($runs, 0, 10),
            'history_by_target_date' => $this->buildDailyHistoryFromRuns($runs),
            'recent_errors' => $recentErrors,
            'tail_lines' => $tailLines,
            'last_run' => $lastRun,
            'last_dry_run' => $lastDryRun,
            'last_send_run' => $lastSendRun,
            'last_automatic_run' => $lastAutomaticRun,
            'last_automatic_dry_run' => $lastAutomaticDryRun,
            'last_automatic_send_run' => $lastAutomaticSendRun,
            'last_manual_run' => $lastManualRun,
            'last_manual_dry_run' => $lastManualDryRun,
            'last_manual_send_run' => $lastManualSendRun,
            'running_run' => $runningRun,
            'running_automatic_run' => $runningAutomaticRun,
            'running_manual_run' => $runningManualRun,
            'ran_today' => $ranToday,
            'ran_today_automatic' => $ranTodayAutomatic,
        ];
    }

    private function buildStateFilesSnapshot(): array
    {
        if (!is_dir($this->stateDir)) {
            return [
                'available' => false,
                'path' => $this->stateDir,
                'files' => [],
                'latest' => null,
            ];
        }

        $files = glob($this->stateDir . 'appointment_reminders_*.json') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $summary = [];
        foreach (array_slice($files, 0, 20) as $file) {
            $json = file_get_contents($file);
            $decoded = is_string($json) ? json_decode($json, true) : null;
            $sent = is_array($decoded['sent'] ?? null) ? $decoded['sent'] : [];
            $name = basename($file, '.json');
            $date = null;
            $channel = null;
            if (preg_match('/appointment_reminders_([a-z]+)_(\d{4}-\d{2}-\d{2})$/', $name, $m)) {
                $channel = $m[1];
                $date = $m[2];
            }

            $summary[] = [
                'file' => basename($file),
                'path' => $file,
                'mtime' => @date('Y-m-d H:i:s', (int)@filemtime($file)),
                'channel' => $channel,
                'target_date' => $date,
                'sent_count' => count($sent),
            ];
        }

        return [
            'available' => true,
            'path' => $this->stateDir,
            'files' => $summary,
            'latest' => $summary[0] ?? null,
        ];
    }

    private function buildHistorySnapshot(int $limitDays = 30): array
    {
        $history = $this->buildCronSnapshot()['history_by_target_date'] ?? [];
        $stateFiles = $this->buildStateFilesSnapshot()['files'] ?? [];

        $byDate = [];
        foreach ($history as $row) {
            $date = (string)($row['target_date'] ?? '');
            if ($date === '') {
                continue;
            }

            $byDate[$date] = $row;
        }

        foreach ($stateFiles as $file) {
            $date = (string)($file['target_date'] ?? '');
            if ($date === '') {
                continue;
            }

            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'target_date' => $date,
                    'last_started_at' => null,
                    'last_completed_at' => null,
                    'last_mode' => null,
                    'last_status' => 'state_only',
                    'runs_total' => 0,
                    'send_runs' => 0,
                    'dry_runs' => 0,
                    'candidates' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'already_sent' => 0,
                    'invalid_recipients' => 0,
                ];
            }

            $byDate[$date]['state_sent_count'] = (int)($file['sent_count'] ?? 0);
            $byDate[$date]['state_mtime'] = (string)($file['mtime'] ?? '');
        }

        usort($byDate, static fn(array $a, array $b): int => strcmp((string)($b['target_date'] ?? ''), (string)($a['target_date'] ?? '')));

        return [
            'days' => $limitDays,
            'rows' => array_slice(array_values($byDate), 0, $limitDays),
        ];
    }

    private function buildUpcomingQueueSnapshot(): array
    {
        $this->prepareDatabaseSession();
        $daysAhead = $this->defaultDaysAhead();
        $targetDate = date('Y-m-d', strtotime('+' . $daysAhead . ' day'));

        $doctorNameSql = $this->doctorFieldSql('p');
        $sql = "
            SELECT
                a.id_appuntamento,
                a.cellulare,
                a.telefono,
                a.stato,
                s.id_dot,
                DATE_FORMAT(s.ora_inizio, '%H:%i') AS ora_label,
                COALESCE({$doctorNameSql}, CONCAT('ID ', s.id_dot)) AS doctor_label
            FROM dap12_agenda_appuntamenti a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            INNER JOIN dap39_sms_dot sms
                ON sms.id_dot = s.id_dot
            LEFT JOIN dap03_personale p
                ON p.legacy_id_dot = s.id_dot
               AND p.tipo IN (1, 2)
            WHERE s.data_slot = ?
              AND a.stato <> 'ANNULLATO'
            ORDER BY s.id_dot ASC, s.ora_inizio ASC, a.id_appuntamento ASC
        ";

        $rows = $this->db->query($sql, [$targetDate])->getResultArray();

        $valid = 0;
        $invalid = 0;
        $byDoctor = [];

        foreach ($rows as $row) {
            $recipient = $this->selectRecipient($row);
            $doctorLabel = trim((string)($row['doctor_label'] ?? 'ID ' . ($row['id_dot'] ?? '')));
            if (!isset($byDoctor[$doctorLabel])) {
                $byDoctor[$doctorLabel] = [
                    'doctor_label' => $doctorLabel,
                    'id_dot' => (int)($row['id_dot'] ?? 0),
                    'total' => 0,
                    'valid' => 0,
                    'invalid' => 0,
                ];
            }

            $byDoctor[$doctorLabel]['total']++;
            if ($recipient !== null) {
                $valid++;
                $byDoctor[$doctorLabel]['valid']++;
            } else {
                $invalid++;
                $byDoctor[$doctorLabel]['invalid']++;
            }
        }

        usort($byDoctor, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

        $enabledDoctors = (int)($this->db->table('dap39_sms_dot')->countAllResults() ?? 0);
        $enabledWithConfirmation = (int)($this->db->table('dap39_sms_dot')->where('conferma', 1)->countAllResults() ?? 0);

        return [
            'days_ahead' => $daysAhead,
            'target_date' => $targetDate,
            'enabled_doctors' => $enabledDoctors,
            'enabled_with_confirmation' => $enabledWithConfirmation,
            'candidates' => count($rows),
            'valid_recipients' => $valid,
            'invalid_recipients' => $invalid,
            'by_doctor' => array_slice(array_values($byDoctor), 0, 20),
        ];
    }

    private function buildOutcomeSnapshot(int $days): array
    {
        $likeRows = $this->db->query("
            SELECT
                id_appuntamento,
                id_dot,
                cognome,
                nome,
                stato,
                note
            FROM dap12_agenda_appuntamenti
            WHERE note LIKE '%CONFERMATO WA IL%'
               OR note LIKE '%CONFERMATO TRAMITE WA IL%'
               OR note LIKE '%ANNULLATO%TRAMITE WA IL%'
            ORDER BY id_appuntamento DESC
            LIMIT 4000
        ")->getResultArray();

        $allConfirmations = 0;
        $allCancellations = 0;
        $recentConfirmations = 0;
        $recentCancellations = 0;
        $recentEvents = [];

        $cutoff = new \DateTimeImmutable('-' . $days . ' days');

        foreach ($likeRows as $row) {
            $parsed = WhatsappAppointmentNote::parseLatestOutcome((string)($row['note'] ?? ''));
            if ($parsed === null) {
                continue;
            }

            if ($parsed['action'] === 'confirm') {
                $allConfirmations++;
            } elseif ($parsed['action'] === 'cancel') {
                $allCancellations++;
            }

            if ($parsed['occurred_at'] >= $cutoff) {
                if ($parsed['action'] === 'confirm') {
                    $recentConfirmations++;
                } elseif ($parsed['action'] === 'cancel') {
                    $recentCancellations++;
                }
            }

            $recentEvents[] = [
                'action' => $parsed['action'],
                'occurred_at' => $parsed['occurred_at']->format('Y-m-d H:i:s'),
                'id_appuntamento' => (int)($row['id_appuntamento'] ?? 0),
                'id_dot' => (int)($row['id_dot'] ?? 0),
                'patient' => trim((string)($row['cognome'] ?? '') . ' ' . (string)($row['nome'] ?? '')),
                'stato' => (string)($row['stato'] ?? ''),
            ];
        }

        usort($recentEvents, static fn(array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));

        return [
            'days' => $days,
            'all_confirmations' => $allConfirmations,
            'all_cancellations' => $allCancellations,
            'recent_confirmations' => $recentConfirmations,
            'recent_cancellations' => $recentCancellations,
            'recent_events' => array_slice($recentEvents, 0, 30),
        ];
    }

    private function buildUltraMsgSnapshot(): array
    {
        $sendUrl = trim((string)(env('SMS_ULTRAMSG_URL') ?: self::DEFAULT_ULTRAMSG_URL));
        $token = trim((string)(env('SMS_API_TOKEN') ?: ''));
        $instanceId = $this->parseUltraMsgInstanceId($sendUrl);

        if ($token === '' || $instanceId === null) {
            return [
                'available' => false,
                'configured_url' => $sendUrl,
                'instance_id' => $instanceId,
                'error' => 'Configurazione UltraMsg incompleta.',
            ];
        }

        $baseUrl = 'https://api.ultramsg.com/' . $instanceId;

        $status = $this->fetchUltraMsgJson($baseUrl . '/instance/status', ['token' => $token]);
        $me = $this->fetchUltraMsgJson($baseUrl . '/instance/me', ['token' => $token]);
        $statistics = $this->fetchUltraMsgJson($baseUrl . '/messages/statistics', ['token' => $token]);
        $recentAll = $this->fetchUltraMsgJson($baseUrl . '/messages', [
            'token' => $token,
            'status' => 'all',
            'limit' => 15,
            'sort' => 'desc',
        ]);
        $queue = $this->fetchUltraMsgJson($baseUrl . '/messages', [
            'token' => $token,
            'status' => 'queue',
            'limit' => 10,
            'sort' => 'desc',
        ]);
        $unsent = $this->fetchUltraMsgJson($baseUrl . '/messages', [
            'token' => $token,
            'status' => 'unsent',
            'limit' => 10,
            'sort' => 'desc',
        ]);
        $invalid = $this->fetchUltraMsgJson($baseUrl . '/messages', [
            'token' => $token,
            'status' => 'invalid',
            'limit' => 10,
            'sort' => 'desc',
        ]);

        return [
            'available' => true,
            'configured_url' => $sendUrl,
            'instance_id' => $instanceId,
            'status' => $status,
            'me' => $me,
            'statistics' => $statistics,
            'statistics_flat' => $this->flattenUltraMsgNumbers($statistics['data'] ?? $statistics),
            'recent_all' => $this->normalizeUltraMsgMessages($recentAll['data'] ?? $recentAll),
            'queue_messages' => $this->normalizeUltraMsgMessages($queue['data'] ?? $queue),
            'unsent_messages' => $this->normalizeUltraMsgMessages($unsent['data'] ?? $unsent),
            'invalid_messages' => $this->normalizeUltraMsgMessages($invalid['data'] ?? $invalid),
        ];
    }

    private function parseCronLogLine(string $line): ?array
    {
        if (!preg_match('/^\[(?<timestamp>[^\]]+)\]\s+(?<level>[A-Z]+)\s+(?<rest>.*)$/', $line, $m)) {
            return null;
        }

        $timestamp = $m['timestamp'];
        $level = strtoupper(trim($m['level']));
        $rest = $m['rest'];
        $context = null;
        $message = $rest;

        $jsonStart = strpos($rest, ' {');
        if ($jsonStart !== false) {
            $candidate = substr($rest, $jsonStart + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                $context = $decoded;
                $message = substr($rest, 0, $jsonStart);
            }
        }

        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => trim($message),
            'context' => $context,
            'raw' => $line,
        ];
    }

    private function newRunSnapshot(array $event): array
    {
        $diagnostics = is_array($event['context'] ?? null) ? $event['context'] : [];

        return [
            'started_at' => $event['timestamp'] ?? null,
            'diagnostics' => $diagnostics,
            'batch' => null,
            'batches' => [],
            'multi_batch' => null,
            'completed' => null,
            'fatal' => null,
            'sent_items' => 0,
            'failed_items' => 0,
            'skipped_invalid_items' => 0,
            'already_sent_items' => 0,
            'preview_items' => [],
            'sent_details' => [],
            'failed_details' => [],
            'skipped_invalid_details' => [],
            'already_sent_details' => [],
            'raw_lines' => [],
            'status' => 'started',
            'mode' => '',
            'channel' => '',
            'target_dates' => [],
            'is_manual' => $this->isManualDiagnostics($diagnostics),
            '_current_batch_index' => null,
        ];
    }

    private function newBatchSnapshot(array $event): array
    {
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        return [
            'started_at' => $event['timestamp'] ?? null,
            'target_date' => (string)($context['target_date'] ?? ''),
            'context' => $context,
            'completed' => null,
            'sent_items' => 0,
            'failed_items' => 0,
            'skipped_invalid_items' => 0,
            'already_sent_items' => 0,
            'preview_items' => [],
            'sent_details' => [],
            'failed_details' => [],
            'skipped_invalid_details' => [],
            'already_sent_details' => [],
            'status' => 'running',
        ];
    }

    private function buildRunDetailFromEvent(array $event): array
    {
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        return [
            'timestamp' => (string)($event['timestamp'] ?? ''),
            'target_date' => (string)($context['target_date'] ?? ''),
            'id_appuntamento' => (int)($context['id_appuntamento'] ?? 0),
            'patient' => (string)($context['patient'] ?? ''),
            'recipient' => (string)($context['recipient'] ?? ''),
            'channel' => (string)($context['channel'] ?? ''),
            'provider_id' => (string)($context['provider_id'] ?? ''),
            'message' => (string)($context['message'] ?? ''),
            'error' => (string)($context['error'] ?? ''),
            'response' => is_scalar($context['response'] ?? null) ? (string)$context['response'] : '',
            'cellulare' => (string)($context['cellulare'] ?? ''),
            'telefono' => (string)($context['telefono'] ?? ''),
        ];
    }

    private function attachDetailToBatch(array &$run, array $detail, string $detailKey, ?string $counterKey = null): void
    {
        $batchIndex = $this->resolveBatchIndex($run, (string)($detail['target_date'] ?? ''));
        if ($batchIndex === null || !isset($run['batches'][$batchIndex])) {
            return;
        }

        $run['batches'][$batchIndex][$detailKey][] = $detail;
        if ($counterKey !== null) {
            $run['batches'][$batchIndex][$counterKey]++;
        }
    }

    private function resolveBatchIndex(array $run, string $targetDate): ?int
    {
        if ($targetDate !== '') {
            foreach (array_reverse(array_keys($run['batches'])) as $index) {
                if (($run['batches'][$index]['target_date'] ?? '') === $targetDate) {
                    return (int)$index;
                }
            }
        }

        return isset($run['_current_batch_index']) ? (int)$run['_current_batch_index'] : null;
    }

    private function completeBatchSnapshot(array &$run, array $completed): void
    {
        $context = is_array($completed['context'] ?? null) ? $completed['context'] : [];
        $batchIndex = $this->resolveBatchIndex($run, (string)($context['target_date'] ?? ''));
        if ($batchIndex === null || !isset($run['batches'][$batchIndex])) {
            return;
        }

        $run['batches'][$batchIndex]['completed'] = $completed;
        $run['batches'][$batchIndex]['status'] = ((int)($context['failed'] ?? 0)) > 0
            ? 'completed_with_errors'
            : 'completed';
        $run['batch'] = [
            'timestamp' => $run['batches'][$batchIndex]['started_at'],
            'context' => $run['batches'][$batchIndex]['context'] ?? [],
        ];
    }

    private function finalizeRunSnapshot(array $run): array
    {
        if ($run['batch'] === null && !empty($run['batches'])) {
            $lastBatch = $run['batches'][array_key_last($run['batches'])];
            $run['batch'] = [
                'timestamp' => $lastBatch['started_at'] ?? null,
                'context' => $lastBatch['context'] ?? [],
            ];
        }

        if ($run['completed'] === null && empty($run['multi_batch']) && !empty($run['batches'])) {
            $lastBatch = $run['batches'][array_key_last($run['batches'])];
            if (!empty($lastBatch['completed'])) {
                $run['completed'] = $lastBatch['completed'];
                $run['status'] = (string)($lastBatch['status'] ?? $run['status']);
            }
        }

        $targetDates = [];
        if (!empty($run['multi_batch']['context']['target_dates']) && is_array($run['multi_batch']['context']['target_dates'])) {
            foreach ($run['multi_batch']['context']['target_dates'] as $targetDate) {
                $targetDate = trim((string)$targetDate);
                if ($targetDate !== '') {
                    $targetDates[] = $targetDate;
                }
            }
        }

        foreach ($run['batches'] as $batch) {
            $targetDate = trim((string)($batch['target_date'] ?? ''));
            if ($targetDate !== '') {
                $targetDates[] = $targetDate;
            }
        }

        $targetDates = array_values(array_unique($targetDates));
        $run['target_dates'] = $targetDates;

        $run['mode'] = (string)(
            $run['multi_batch']['context']['mode']
            ?? $run['batch']['context']['mode']
            ?? ''
        );
        $run['channel'] = (string)(
            $run['multi_batch']['context']['channel']
            ?? $run['batch']['context']['channel']
            ?? ''
        );

        unset($run['_current_batch_index']);

        return $run;
    }

    private function isManualDiagnostics(array $diagnostics): bool
    {
        $requestUri = strtolower(trim((string)($diagnostics['request_uri'] ?? '')));
        $origin = strtolower(trim((string)($diagnostics['reminder_origin'] ?? '')));

        if ($origin === 'admin-manual' || $origin === 'admin_manual') {
            return true;
        }

        if ($requestUri !== '' && (str_contains($requestUri, 'origin=admin-manual') || str_contains($requestUri, 'origin=admin_manual'))) {
            return true;
        }

        return false;
    }

    private function defaultDaysAhead(): int
    {
        return 6;
    }

    private function buildDailyHistoryFromRuns(array $runs): array
    {
        $byDate = [];

        foreach ($runs as $run) {
            $batches = !empty($run['batches']) && is_array($run['batches']) ? $run['batches'] : [];
            if ($batches === [] && !empty($run['batch']['context']['target_date'])) {
                $batches[] = [
                    'started_at' => $run['batch']['timestamp'] ?? ($run['started_at'] ?? null),
                    'target_date' => (string)$run['batch']['context']['target_date'],
                    'context' => $run['batch']['context'] ?? [],
                    'completed' => $run['completed'] ?? null,
                    'sent_items' => (int)($run['sent_items'] ?? 0),
                    'failed_items' => (int)($run['failed_items'] ?? 0),
                    'already_sent_items' => (int)($run['already_sent_items'] ?? 0),
                    'skipped_invalid_items' => (int)($run['skipped_invalid_items'] ?? 0),
                    'status' => (string)($run['status'] ?? ''),
                ];
            }

            foreach ($batches as $batch) {
                $targetDate = (string)($batch['target_date'] ?? '');
                if ($targetDate === '') {
                    continue;
                }

                if (!isset($byDate[$targetDate])) {
                    $byDate[$targetDate] = [
                        'target_date' => $targetDate,
                        'last_started_at' => null,
                        'last_completed_at' => null,
                        'last_mode' => null,
                        'last_status' => null,
                        'runs_total' => 0,
                        'send_runs' => 0,
                        'dry_runs' => 0,
                        'candidates' => 0,
                        'sent' => 0,
                        'failed' => 0,
                        'already_sent' => 0,
                        'invalid_recipients' => 0,
                    ];
                }

                $row = &$byDate[$targetDate];
                $row['runs_total']++;
                $row['last_started_at'] = $row['last_started_at'] ?? (string)($batch['started_at'] ?? $run['started_at'] ?? '');
                $row['last_completed_at'] = $row['last_completed_at'] ?? (string)($batch['completed']['timestamp'] ?? $run['completed']['timestamp'] ?? $run['fatal']['timestamp'] ?? '');
                $row['last_mode'] = $row['last_mode'] ?? (string)($batch['context']['mode'] ?? $run['mode'] ?? '');
                $row['last_status'] = $row['last_status'] ?? (string)($batch['status'] ?? $run['status'] ?? '');

                $mode = strtolower((string)($batch['context']['mode'] ?? $run['mode'] ?? ''));
                if ($mode === 'send') {
                    $row['send_runs']++;
                } elseif ($mode === 'dry-run') {
                    $row['dry_runs']++;
                }

                $completed = $batch['completed']['context'] ?? [];
                $row['candidates'] += (int)($completed['candidates'] ?? $batch['context']['candidates'] ?? 0);
                $row['sent'] += (int)($completed['sent'] ?? $batch['sent_items'] ?? 0);
                $row['failed'] += (int)($completed['failed'] ?? $batch['failed_items'] ?? 0);
                $row['already_sent'] += (int)($completed['already_sent'] ?? $batch['already_sent_items'] ?? 0);
                $row['invalid_recipients'] += (int)($completed['skipped_invalid_recipient'] ?? $batch['skipped_invalid_items'] ?? 0);

                unset($row);
            }
        }

        usort($byDate, static fn(array $a, array $b): int => strcmp((string)($b['target_date'] ?? ''), (string)($a['target_date'] ?? '')));

        return array_values($byDate);
    }

    private function doctorFieldSql(string $alias): string
    {
        $title = $this->decryptExpr($alias . '.qualifica', $alias . '.vector_id');
        $surname = $this->decryptExpr($alias . '.cognome', $alias . '.vector_id');
        $name = $this->decryptExpr($alias . '.nome', $alias . '.vector_id');

        return "CONCAT(TRIM(COALESCE({$title}, '')), ' ', TRIM(COALESCE({$surname}, '')), ' ', TRIM(COALESCE({$name}, '')))";
    }

    private function prepareDatabaseSession(): void
    {
        $key = (string)(env('DB_ENCRYPTION_KEY') ?: '');
        $mode = (string)(env('DB_ENCRYPTION_MODE') ?: 'aes-256-cbc');

        if ($key === '') {
            return;
        }

        $this->db->query("SET @key_str = SHA2(" . $this->db->escape($key) . ", 512)");
        $this->db->query("SET block_encryption_mode = " . $this->db->escape($mode));
    }

    private function decryptExpr(string $fieldExpr, string $vectorExpr): string
    {
        return "CONVERT(CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }

    private function selectRecipient(array $row): ?string
    {
        $cell = $this->normalizeRecipient((string)($row['cellulare'] ?? ''));
        if ($cell !== null) {
            return $cell;
        }

        return $this->normalizeRecipient((string)($row['telefono'] ?? ''));
    }

    private function normalizeRecipient(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '.' || $raw === '0') {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        if (str_starts_with($digits, '+39')) {
            $local = substr($digits, 3);
        } elseif (str_starts_with($digits, '39')) {
            $local = substr($digits, 2);
        } else {
            $local = ltrim($digits, '+');
        }

        if (!preg_match('/^3[0-9]{8,9}$/', $local)) {
            return null;
        }

        return '+39' . $local;
    }

    private function parseUltraMsgInstanceId(string $sendUrl): ?string
    {
        if (preg_match('#https://api\.ultramsg\.com/([^/]+)/#', $sendUrl, $m)) {
            return $m[1];
        }

        return null;
    }

    private function fetchUltraMsgJson(string $url, array $query): array
    {
        $fullUrl = $url . '?' . http_build_query($query);

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'url' => $fullUrl,
                'error' => 'cURL non disponibile.',
            ];
        }

        $ch = curl_init($fullUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'url' => $fullUrl,
                'status' => $status,
                'error' => $error !== '' ? $error : 'Errore sconosciuto cURL.',
            ];
        }

        $decoded = json_decode($body, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'url' => $fullUrl,
            'status' => $status,
            'data' => $decoded,
            'raw' => $body,
            'error' => ($status >= 200 && $status < 300) ? null : ('HTTP ' . $status),
        ];
    }

    private function flattenUltraMsgNumbers($data): array
    {
        $out = [];
        $this->flattenUltraMsgNumbersRecursive($data, '', $out);
        return $out;
    }

    private function flattenUltraMsgNumbersRecursive($data, string $prefix, array &$out): void
    {
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_numeric($value)) {
                $out[$path] = $value + 0;
                continue;
            }

            if (is_array($value)) {
                $this->flattenUltraMsgNumbersRecursive($value, $path, $out);
            }
        }
    }

    private function normalizeUltraMsgMessages($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (array_is_list($data)) {
            return array_slice(array_map([$this, 'normalizeUltraMsgMessageRow'], $data), 0, 20);
        }

        foreach (['messages', 'results', 'data'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_slice(array_map([$this, 'normalizeUltraMsgMessageRow'], $data[$key]), 0, 20);
            }
        }

        return [];
    }

    private function normalizeUltraMsgMessageRow($row): array
    {
        if (!is_array($row)) {
            return [
                'id' => null,
                'status' => null,
                'to' => null,
                'body' => is_scalar($row) ? (string)$row : null,
                'datetime' => null,
            ];
        }

        return [
            'id' => $row['id'] ?? $row['message_id'] ?? null,
            'status' => $row['status'] ?? $row['message_status'] ?? $row['state'] ?? null,
            'to' => $row['to'] ?? $row['chatId'] ?? $row['chat_id'] ?? $row['receiver'] ?? null,
            'body' => $row['body'] ?? $row['message'] ?? $row['text'] ?? null,
            'datetime' => $row['datetime'] ?? $row['time'] ?? $row['timestamp'] ?? null,
        ];
    }
}
