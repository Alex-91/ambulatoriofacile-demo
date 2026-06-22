<?php
if (empty($menu_items) || !is_array($menu_items)) {
    $menu_items = session()->get('header_menu_items') ?? [];
}

$cron = $snapshot['cron'] ?? [];
$queue = $snapshot['queue'] ?? [];
$outcomes = $snapshot['outcomes'] ?? [];
$ultramsg = $snapshot['ultramsg'] ?? [];
$stateFiles = $snapshot['state_files'] ?? [];
$history = $snapshot['history'] ?? [];
$launchFeedback = $launchFeedback ?? null;
$manualDefaults = $manualDefaults ?? [];

$requestedTab = strtolower(trim((string)($_GET['tab'] ?? '')));
$activeTab = in_array($requestedTab, ['summary', 'automatic', 'manual', 'history'], true) ? $requestedTab : 'summary';
if (is_array($launchFeedback) && !empty($launchFeedback['message'])) {
    $activeTab = 'manual';
}

function monitor_status_label(?array $run): array
{
    $status = strtolower((string)($run['status'] ?? 'unknown'));

    return match ($status) {
        'completed' => [
            'class' => 'label-success',
            'text' => 'Completato',
            'panel' => 'success',
            'alert' => 'alert-success',
            'progress' => 'progress-bar-success',
        ],
        'completed_with_errors' => [
            'class' => 'label-warning',
            'text' => 'Completato con errori',
            'panel' => 'warning',
            'alert' => 'alert-warning',
            'progress' => 'progress-bar-warning',
        ],
        'fatal' => [
            'class' => 'label-danger',
            'text' => 'Errore fatale',
            'panel' => 'danger',
            'alert' => 'alert-danger',
            'progress' => 'progress-bar-danger',
        ],
        'running' => [
            'class' => 'label-primary',
            'text' => 'In esecuzione',
            'panel' => 'info',
            'alert' => 'alert-info',
            'progress' => 'progress-bar-info progress-bar-striped active',
        ],
        'started' => [
            'class' => 'label-default',
            'text' => 'Avviato',
            'panel' => 'info',
            'alert' => 'alert-info',
            'progress' => 'progress-bar-info progress-bar-striped active',
        ],
        default => [
            'class' => 'label-default',
            'text' => 'Non disponibile',
            'panel' => 'default',
            'alert' => 'alert-default',
            'progress' => 'progress-bar-info',
        ],
    };
}

function monitor_number($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float)$value, 0, ',', '.');
}

function monitor_payload($response)
{
    if (!is_array($response)) {
        return [];
    }

    if (isset($response['data']) && is_array($response['data'])) {
        return $response['data'];
    }

    return $response;
}

function monitor_pick($data, array $paths, string $default = '-'): string
{
    if (!is_array($data)) {
        return $default;
    }

    foreach ($paths as $path) {
        $current = $data;
        $found = true;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $found = false;
                break;
            }

            $current = $current[$segment];
        }

        if ($found && $current !== null && $current !== '') {
            return is_scalar($current) ? (string)$current : $default;
        }
    }

    return $default;
}

function monitor_run_target_dates(?array $run): string
{
    if (!is_array($run)) {
        return '-';
    }

    $dates = $run['target_dates'] ?? [];
    if (is_array($dates)) {
        $dates = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $dates), static fn(string $value): bool => $value !== ''));
        if ($dates !== []) {
            return implode(', ', $dates);
        }
    }

    $singleDate = trim((string)($run['batch']['context']['target_date'] ?? ''));
    return $singleDate !== '' ? $singleDate : '-';
}

function monitor_run_detail_rows(?array $run): array
{
    if (!is_array($run)) {
        return [];
    }

    $groups = [
        'preview_items' => ['status' => 'Dry-run pronto', 'class' => 'label-info'],
        'sent_details' => ['status' => 'Inviato', 'class' => 'label-success'],
        'failed_details' => ['status' => 'Errore invio', 'class' => 'label-danger'],
        'skipped_invalid_details' => ['status' => 'Numero non valido', 'class' => 'label-warning'],
        'already_sent_details' => ['status' => 'Gia inviato', 'class' => 'label-default'],
    ];

    $rows = [];
    foreach ($groups as $key => $meta) {
        foreach (($run[$key] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $recipient = trim((string)($item['recipient'] ?? ''));
            if ($recipient === '') {
                $recipient = trim((string)($item['cellulare'] ?? ''));
            }
            if ($recipient === '') {
                $recipient = trim((string)($item['telefono'] ?? ''));
            }

            $rows[] = [
                'timestamp' => (string)($item['timestamp'] ?? ''),
                'target_date' => (string)($item['target_date'] ?? ''),
                'id_appuntamento' => (int)($item['id_appuntamento'] ?? 0),
                'patient' => (string)($item['patient'] ?? ''),
                'recipient' => $recipient,
                'provider_id' => (string)($item['provider_id'] ?? ''),
                'message' => (string)($item['message'] ?? ''),
                'error' => (string)($item['error'] ?? ''),
                'response' => (string)($item['response'] ?? ''),
                'cellulare' => (string)($item['cellulare'] ?? ''),
                'telefono' => (string)($item['telefono'] ?? ''),
                'status' => $meta['status'],
                'status_class' => $meta['class'],
            ];
        }
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? '')));

    return $rows;
}

function monitor_run_counts(?array $run): array
{
    $base = [
        'candidates' => 0,
        'ready' => 0,
        'sent' => 0,
        'failed' => 0,
        'already_sent' => 0,
        'invalid' => 0,
        'handled' => 0,
        'remaining' => 0,
        'processed' => 0,
        'percent' => 0,
    ];

    if (!is_array($run)) {
        return $base;
    }

    $mode = strtolower((string)($run['mode'] ?? ''));
    $batches = $run['batches'] ?? [];
    $hasBatches = is_array($batches) && $batches !== [];

    if ($hasBatches) {
        foreach ($batches as $batch) {
            if (!is_array($batch)) {
                continue;
            }

            $context = $batch['completed']['context'] ?? $batch['context'] ?? [];
            $base['candidates'] += (int)($context['candidates'] ?? 0);
            $base['sent'] += (int)($context['sent'] ?? $batch['sent_items'] ?? 0);
            $base['failed'] += (int)($context['failed'] ?? $batch['failed_items'] ?? 0);
            $base['already_sent'] += (int)($context['already_sent'] ?? $batch['already_sent_items'] ?? 0);
            $base['invalid'] += (int)($context['skipped_invalid_recipient'] ?? $batch['skipped_invalid_items'] ?? 0);
            $base['ready'] += count($batch['preview_items'] ?? []);
            $base['processed'] += (int)($context['processed'] ?? 0);
        }
    } else {
        $context = $run['completed']['context'] ?? $run['batch']['context'] ?? [];
        $base['candidates'] = (int)($context['candidates'] ?? 0);
        $base['sent'] = (int)($context['sent'] ?? $run['sent_items'] ?? 0);
        $base['failed'] = (int)($context['failed'] ?? $run['failed_items'] ?? 0);
        $base['already_sent'] = (int)($context['already_sent'] ?? $run['already_sent_items'] ?? 0);
        $base['invalid'] = (int)($context['skipped_invalid_recipient'] ?? $run['skipped_invalid_items'] ?? 0);
        $base['ready'] = count($run['preview_items'] ?? []);
        $base['processed'] = (int)($context['processed'] ?? 0);
    }

    if ($base['processed'] === 0) {
        $base['processed'] = $mode === 'send'
            ? ($base['sent'] + $base['failed'])
            : $base['ready'];
    }

    $base['handled'] = $mode === 'send'
        ? ($base['sent'] + $base['failed'] + $base['already_sent'] + $base['invalid'])
        : ($base['ready'] + $base['already_sent'] + $base['invalid']);

    $base['remaining'] = max(0, $base['candidates'] - $base['handled']);
    $status = strtolower((string)($run['status'] ?? ''));
    $base['percent'] = $base['candidates'] > 0
        ? (int)round(min(100, ($base['handled'] / $base['candidates']) * 100))
        : (in_array($status, ['completed', 'completed_with_errors', 'fatal'], true) ? 100 : 0);

    return $base;
}

function monitor_run_last_error(?array $run): ?array
{
    if (!is_array($run)) {
        return null;
    }

    if (!empty($run['fatal']['context']) || !empty($run['fatal']['timestamp'])) {
        return [
            'timestamp' => (string)($run['fatal']['timestamp'] ?? ''),
            'message' => 'Errore fatale',
            'detail' => (string)(($run['fatal']['context']['message'] ?? '') ?: 'Il job si e` interrotto prima del completamento.'),
            'context' => $run['fatal']['context'] ?? [],
        ];
    }

    $failed = $run['failed_details'] ?? [];
    if (is_array($failed) && $failed !== []) {
        $last = $failed[count($failed) - 1];
        return [
            'timestamp' => (string)($last['timestamp'] ?? ''),
            'message' => 'Invio promemoria fallito',
            'detail' => (string)(($last['error'] ?? '') ?: ($last['response'] ?? '') ?: 'Errore non specificato.'),
            'context' => $last,
        ];
    }

    return null;
}

function monitor_run_mode_label(?array $run): string
{
    $mode = strtolower((string)($run['mode'] ?? ''));
    return $mode === 'send' ? 'Invio reale' : ($mode === 'dry-run' ? 'Controllo senza invio' : '-');
}

function monitor_run_channel_label(?array $run): string
{
    $channel = strtolower((string)($run['channel'] ?? $run['batch']['context']['channel'] ?? ''));
    return $channel === 'sms' ? 'SMS' : ($channel === 'wa' ? 'WhatsApp' : '-');
}

function monitor_run_delay_ms(?array $run): ?int
{
    if (!is_array($run)) {
        return null;
    }

    $value = $run['multi_batch']['context']['delay_ms']
        ?? $run['batch']['context']['delay_ms']
        ?? null;

    return $value === null ? null : (int)$value;
}

function monitor_run_started_at(?array $run): string
{
    return is_array($run) ? (string)($run['started_at'] ?? '-') : '-';
}

function monitor_run_finished_at(?array $run): string
{
    if (!is_array($run)) {
        return '-';
    }

    return (string)($run['completed']['timestamp'] ?? $run['fatal']['timestamp'] ?? '-');
}

$lastRun = $cron['last_run'] ?? null;
$lastSendRun = $cron['last_send_run'] ?? null;
$automaticRun = $cron['running_automatic_run'] ?? $cron['last_automatic_run'] ?? null;
$automaticSendRun = $cron['last_automatic_send_run'] ?? null;
$manualRun = $cron['running_manual_run'] ?? $cron['last_manual_run'] ?? null;
$manualDryRun = $cron['last_manual_dry_run'] ?? null;
$manualSendRun = $cron['last_manual_send_run'] ?? null;

$automaticStatusMeta = monitor_status_label($automaticRun);
$automaticCounts = monitor_run_counts($automaticRun);
$automaticRows = array_slice(monitor_run_detail_rows($automaticRun), 0, 40);
$automaticLastError = monitor_run_last_error($automaticRun);
$automaticLastEvent = $automaticRows[0] ?? null;
$automaticLogLines = array_slice($automaticRun['raw_lines'] ?? [], -60);

$manualStatusMeta = monitor_status_label($manualRun);
$manualCounts = monitor_run_counts($manualRun);
$manualRows = array_slice(monitor_run_detail_rows($manualRun), 0, 60);
$manualLastError = monitor_run_last_error($manualRun);
$manualLastEvent = $manualRows[0] ?? null;
$manualLogLines = array_slice($manualRun['raw_lines'] ?? [], -60);

$manualDryStatusMeta = monitor_status_label($manualDryRun);
$manualDryCounts = monitor_run_counts($manualDryRun);
$manualSendStatusMeta = monitor_status_label($manualSendRun);
$manualSendCounts = monitor_run_counts($manualSendRun);

$ultramsgStatusPayload = monitor_payload($ultramsg['status'] ?? []);
$ultramsgMePayload = monitor_payload($ultramsg['me'] ?? []);
$ultramsgInstanceStatus = monitor_pick($ultramsgStatusPayload, ['accountStatus.status', 'accountStatus', 'status.accountStatus', 'status.status', 'status', 'state']);
$ultramsgInstancePhone = monitor_pick($ultramsgMePayload, ['number', 'phone', 'id', 'wid', 'name']);

$currentChannel = strtolower((string)($automaticRun['channel'] ?? $lastRun['channel'] ?? $manualDefaults['channel'] ?? 'wa'));
$currentChannelLabel = $currentChannel === 'sms' ? 'SMS' : 'WhatsApp';
$isWaChannel = $currentChannel === 'wa';
$ultramsgStatusKey = strtolower(trim($ultramsgInstanceStatus));
$gatewayState = 'warning';
$gatewayValue = $isWaChannel ? 'Da verificare' : 'Non usato';
$gatewayHelp = $isWaChannel
    ? 'Verifica il gateway WhatsApp.'
    : 'Il canale attivo adesso e` ' . $currentChannelLabel . '.';

if (empty($ultramsg['available'])) {
    $gatewayState = $isWaChannel ? 'danger' : 'warning';
    $gatewayValue = $isWaChannel ? 'Non disponibile' : 'Non usato';
    $gatewayHelp = (string)($ultramsg['error'] ?? 'Gateway WhatsApp non disponibile.');
} elseif (!$isWaChannel) {
    $gatewayState = 'warning';
    $gatewayValue = 'Pronto ma non attivo';
    $gatewayHelp = 'Il canale attivo adesso e` ' . $currentChannelLabel . '.';
} elseif (
    str_contains($ultramsgStatusKey, 'connected')
    || str_contains($ultramsgStatusKey, 'authenticated')
    || str_contains($ultramsgStatusKey, 'ready')
    || str_contains($ultramsgStatusKey, 'online')
    || str_contains($ultramsgStatusKey, 'active')
) {
    $gatewayState = 'success';
    $gatewayValue = 'Connesso';
    $gatewayHelp = $ultramsgInstancePhone !== '-' ? ('Numero: ' . $ultramsgInstancePhone) : 'Gateway raggiungibile.';
} elseif (
    str_contains($ultramsgStatusKey, 'disconnect')
    || str_contains($ultramsgStatusKey, 'offline')
    || str_contains($ultramsgStatusKey, 'expired')
    || str_contains($ultramsgStatusKey, 'inactive')
    || str_contains($ultramsgStatusKey, 'unauthor')
) {
    $gatewayState = 'danger';
    $gatewayValue = 'Non connesso';
    $gatewayHelp = 'Stato gateway: ' . ($ultramsgInstanceStatus !== '-' ? $ultramsgInstanceStatus : 'non disponibile');
} elseif ($ultramsgInstanceStatus !== '-') {
    $gatewayValue = ucfirst($ultramsgInstanceStatus);
    $gatewayHelp = 'Verifica se il gateway e` pronto per l invio.';
}

$recentErrorCount = count($cron['recent_errors'] ?? []);
$hasRunningRun = !empty($cron['running_automatic_run']) || !empty($cron['running_manual_run']);
$autoRefreshSeconds = 15;

$summaryCards = [
    [
        'state' => !empty($cron['running_automatic_run']) ? 'info' : (!empty($cron['ran_today_automatic']) ? 'success' : 'warning'),
        'title' => 'Automatico di oggi',
        'value' => !empty($cron['running_automatic_run']) ? 'In corso' : (!empty($cron['ran_today_automatic']) ? 'Partito' : 'Da verificare'),
        'help' => $automaticRun ? ('Ultimo avvio: ' . monitor_run_started_at($automaticRun)) : 'Nessun run automatico letto nel log.',
        'icon' => !empty($cron['running_automatic_run']) ? 'fa-spinner' : (!empty($cron['ran_today_automatic']) ? 'fa-check-circle' : 'fa-clock-o'),
    ],
    [
        'state' => $automaticSendRun === null
            ? 'warning'
            : (monitor_status_label($automaticSendRun)['panel']),
        'title' => 'Ultimo invio auto',
        'value' => $automaticSendRun === null
            ? 'Mai eseguito'
            : (monitor_number(monitor_run_counts($automaticSendRun)['sent']) . ' inviati'),
        'help' => $automaticSendRun === null
            ? 'Non risulta ancora nessun invio automatico reale.'
            : ('Date target: ' . monitor_run_target_dates($automaticSendRun)),
        'icon' => 'fa-paper-plane',
    ],
    [
        'state' => !empty($cron['running_manual_run'])
            ? 'info'
            : ($manualRun === null ? 'default' : $manualStatusMeta['panel']),
        'title' => 'Lancio manuale',
        'value' => !empty($cron['running_manual_run'])
            ? 'In corso'
            : ($manualRun === null ? 'Non avviato' : $manualStatusMeta['text']),
        'help' => $manualRun ? ('Ultimo avvio: ' . monitor_run_started_at($manualRun)) : 'Nessun run manuale nel log.',
        'icon' => 'fa-play-circle',
    ],
    [
        'state' => $gatewayState,
        'title' => 'Gateway WhatsApp',
        'value' => $gatewayValue,
        'help' => $gatewayHelp,
        'icon' => 'fa-plug',
    ],
];

$summaryLines = [];
$summaryAlertClass = 'alert-success';

if (!empty($cron['running_automatic_run'])) {
    $summaryLines[] = 'L invio automatico e` in corso e la pagina si aggiorna da sola ogni ' . $autoRefreshSeconds . ' secondi.';
}

if (empty($cron['ran_today_automatic']) && empty($cron['running_automatic_run'])) {
    $summaryLines[] = 'Oggi non risulta ancora partito nessun run automatico.';
    $summaryAlertClass = 'alert-warning';
}

if ($automaticRun && ($automaticRun['status'] ?? '') === 'fatal') {
    $summaryLines[] = 'L ultimo run automatico si e` fermato con errore.';
    $summaryAlertClass = 'alert-danger';
} elseif ($automaticRun && ($automaticRun['status'] ?? '') === 'completed_with_errors') {
    $summaryLines[] = 'L ultimo run automatico e` terminato con errori di invio.';
    if ($summaryAlertClass === 'alert-success') {
        $summaryAlertClass = 'alert-warning';
    }
}

if (!empty($cron['running_manual_run'])) {
    $summaryLines[] = 'C e` un lancio manuale in esecuzione.';
    if ($summaryAlertClass === 'alert-success') {
        $summaryAlertClass = 'alert-info';
    }
}

if (is_array($launchFeedback) && empty($launchFeedback['ok'])) {
    $summaryLines[] = 'L ultimo tentativo di avvio manuale non e` partito correttamente.';
    $summaryAlertClass = 'alert-danger';
}

if ($isWaChannel && $gatewayState === 'danger') {
    $summaryLines[] = 'Il gateway WhatsApp non risulta pronto per inviare.';
    $summaryAlertClass = 'alert-danger';
} elseif ($isWaChannel && $gatewayState === 'warning') {
    $summaryLines[] = 'Il gateway WhatsApp richiede un controllo.';
    if ($summaryAlertClass === 'alert-success') {
        $summaryAlertClass = 'alert-warning';
    }
}

if ($recentErrorCount > 0) {
    $summaryLines[] = 'Nel log sono presenti ' . $recentErrorCount . ' errori recenti.';
    if ($summaryAlertClass === 'alert-success') {
        $summaryAlertClass = 'alert-warning';
    }
}

if ($summaryLines === []) {
    $summaryLines[] = 'Controllo automatico, invii manuali e gateway risultano coerenti con gli ultimi dati raccolti.';
}
?>
<html>
<head>
  <meta charset="UTF-8">
  <title>AmbulatorioFacile | Stato reminder WhatsApp</title>
  <meta content="width=device-width, initial-scale=1" name="viewport">
  <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
  <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet">
  <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet">
  <style>
    .metric-kv { font-size: 13px; margin-bottom: 8px; }
    .metric-kv strong { display: inline-block; min-width: 170px; color: #444; }
    .status-card {
      border: 1px solid #e3e7eb;
      border-left-width: 5px;
      border-radius: 8px;
      background: #fff;
      padding: 16px;
      min-height: 150px;
      margin-bottom: 15px;
      box-shadow: 0 1px 2px rgba(0,0,0,.05);
    }
    .status-card__icon {
      font-size: 22px;
      margin-bottom: 10px;
    }
    .status-card__title {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: #6b7785;
      margin-bottom: 8px;
    }
    .status-card__value {
      font-size: 24px;
      font-weight: 700;
      line-height: 1.2;
      margin-bottom: 8px;
    }
    .status-card__help {
      font-size: 13px;
      color: #66717d;
      line-height: 1.45;
    }
    .status-card--success { border-left-color: #00a65a; }
    .status-card--success .status-card__icon,
    .status-card--success .status-card__value { color: #00a65a; }
    .status-card--warning { border-left-color: #f39c12; }
    .status-card--warning .status-card__icon,
    .status-card--warning .status-card__value { color: #f39c12; }
    .status-card--danger { border-left-color: #dd4b39; }
    .status-card--danger .status-card__icon,
    .status-card--danger .status-card__value { color: #dd4b39; }
    .status-card--info { border-left-color: #00c0ef; }
    .status-card--info .status-card__icon,
    .status-card--info .status-card__value { color: #00c0ef; }
    .status-card--default { border-left-color: #9aa4af; }
    .status-card--default .status-card__icon,
    .status-card--default .status-card__value { color: #6b7785; }
    .run-hero {
      border: 1px solid #e5e5e5;
      border-left-width: 6px;
      border-radius: 8px;
      background: #fff;
      padding: 18px;
      margin-bottom: 15px;
      box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .run-hero--success { border-left-color: #00a65a; }
    .run-hero--warning { border-left-color: #f39c12; }
    .run-hero--danger { border-left-color: #dd4b39; }
    .run-hero--info { border-left-color: #00c0ef; }
    .run-hero--default { border-left-color: #9aa4af; }
    .run-hero__header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
    }
    .run-hero__kicker {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: #6b7785;
      margin-bottom: 4px;
    }
    .run-hero__title {
      font-size: 28px;
      font-weight: 700;
      line-height: 1.1;
      margin: 0 0 6px 0;
    }
    .run-hero__sub {
      color: #66717d;
      margin: 0;
    }
    .run-hero__progress {
      margin: 16px 0 10px 0;
    }
    .run-hero__caption {
      font-size: 13px;
      color: #66717d;
      margin-bottom: 0;
    }
    .run-mini-card {
      border: 1px solid #e5e5e5;
      border-radius: 8px;
      background: #fafbfd;
      padding: 14px 15px;
      margin-bottom: 15px;
      min-height: 130px;
    }
    .run-mini-card__label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .4px;
      color: #6b7785;
      margin-bottom: 8px;
    }
    .run-mini-card__value {
      font-size: 24px;
      font-weight: 700;
      margin-bottom: 8px;
      line-height: 1.15;
    }
    .run-mini-card__help {
      font-size: 13px;
      color: #66717d;
      line-height: 1.45;
    }
    .run-meta-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 18px;
      margin-top: 14px;
    }
    .run-meta-grid__item {
      font-size: 13px;
      line-height: 1.45;
    }
    .run-meta-grid__item strong {
      display: block;
      color: #444;
      margin-bottom: 2px;
    }
    .run-stats .small-box {
      margin-bottom: 15px;
    }
    .run-stats .small-box h3 {
      font-size: 28px;
    }
    .table-condensed td, .table-condensed th {
      font-size: 12px;
    }
    .json-box {
      white-space: pre-wrap;
      font-family: monospace;
      font-size: 12px;
      background: #f7f7f7;
      border: 1px solid #ddd;
      padding: 10px;
      border-radius: 4px;
      max-height: 320px;
      overflow: auto;
    }
    .log-tail {
      white-space: pre-wrap;
      font-family: monospace;
      font-size: 12px;
      background: #111;
      color: #eee;
      padding: 12px;
      border-radius: 4px;
      max-height: 360px;
      overflow: auto;
    }
    .section-note {
      font-size: 13px;
      color: #66717d;
      margin-top: 6px;
    }
    .auto-refresh-note {
      display: inline-block;
      margin-left: 8px;
      font-size: 12px;
      color: #3c8dbc;
    }
    @media (max-width: 991px) {
      .run-hero__header {
        display: block;
      }
      .run-meta-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">
  <?= view('partials/header', ['menu_items' => $menu_items]) ?>

  <div class="content-wrapper">
    <section class="content-header">
      <h1>Stato reminder WhatsApp <small>Automatico, lanci manuali e controlli di invio</small></h1>
      <ol class="breadcrumb">
        <li><a href="<?= site_url('admin') ?>"><i class="fa fa-dashboard"></i> Admin</a></li>
        <li class="active">Reminder WhatsApp</li>
      </ol>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-3">
          <?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?>
        </div>

        <div class="col-md-9">
          <?php if (is_array($launchFeedback) && !empty($launchFeedback['message'])): ?>
            <div class="alert <?= !empty($launchFeedback['ok']) ? 'alert-success' : 'alert-danger' ?>">
              <?= esc((string)$launchFeedback['message']) ?>
            </div>
          <?php endif; ?>

          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">Aggiornamento schermata</h3>
            </div>
            <div class="box-body">
              <form method="get" action="<?= site_url('admin/whatsapp-reminders') ?>" class="form-inline">
                <input type="hidden" name="tab" id="refreshTabInput" value="<?= esc($activeTab) ?>">
                <div class="form-group">
                  <label for="days">Risposte pazienti ultimi giorni</label>
                  <select name="days" id="days" class="form-control" style="margin-left:8px;">
                    <?php foreach ([1, 3, 7, 15, 30, 60, 90] as $option): ?>
                      <option value="<?= $option ?>" <?= $days === $option ? 'selected' : '' ?>><?= $option ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-left:8px;">
                  <i class="fa fa-refresh"></i> Aggiorna
                </button>
                <span class="text-muted" style="margin-left:12px;">
                  Snapshot generato il <?= esc(date('d/m/Y H:i', strtotime((string)($snapshot['generated_at'] ?? 'now')))) ?>
                </span>
                <?php if ($hasRunningRun): ?>
                  <span class="auto-refresh-note">
                    Aggiornamento automatico ogni <?= (int)$autoRefreshSeconds ?> secondi
                  </span>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <div class="nav-tabs-custom">
            <ul class="nav nav-tabs">
              <li class="<?= $activeTab === 'summary' ? 'active' : '' ?>"><a href="#tab-summary" data-toggle="tab" data-tab-key="summary">Situazione</a></li>
              <li class="<?= $activeTab === 'automatic' ? 'active' : '' ?>"><a href="#tab-automatic" data-toggle="tab" data-tab-key="automatic">Automatico</a></li>
              <li class="<?= $activeTab === 'manual' ? 'active' : '' ?>"><a href="#tab-manual" data-toggle="tab" data-tab-key="manual">Invio manuale</a></li>
              <li class="<?= $activeTab === 'history' ? 'active' : '' ?>"><a href="#tab-history" data-toggle="tab" data-tab-key="history">Storico e dettagli</a></li>
            </ul>

            <div class="tab-content">
              <div class="tab-pane <?= $activeTab === 'summary' ? 'active' : '' ?>" id="tab-summary">
                <div class="alert <?= esc($summaryAlertClass) ?>" style="margin-bottom:15px;">
                  <strong>Situazione attuale:</strong>
                  <ul style="margin:8px 0 0 18px; padding:0;">
                    <?php foreach ($summaryLines as $line): ?>
                      <li><?= esc($line) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>

                <div class="row">
                  <?php foreach ($summaryCards as $card): ?>
                    <div class="col-md-3 col-sm-6">
                      <div class="status-card status-card--<?= esc($card['state']) ?>">
                        <div class="status-card__icon"><i class="fa <?= esc($card['icon']) ?>"></i></div>
                        <div class="status-card__title"><?= esc($card['title']) ?></div>
                        <div class="status-card__value"><?= esc($card['value']) ?></div>
                        <div class="status-card__help"><?= esc($card['help']) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="run-hero run-hero--<?= esc($automaticStatusMeta['panel']) ?>">
                      <div class="run-hero__header">
                        <div>
                          <div class="run-hero__kicker">Automatico giornaliero</div>
                          <h3 class="run-hero__title"><?= esc($automaticStatusMeta['text']) ?></h3>
                          <p class="run-hero__sub">
                            <?= $automaticRun ? esc('Target: ' . monitor_run_target_dates($automaticRun) . ' | Modalita: ' . monitor_run_mode_label($automaticRun)) : 'Nessun run automatico disponibile.' ?>
                          </p>
                        </div>
                        <span class="label <?= esc($automaticStatusMeta['class']) ?>"><?= esc($automaticStatusMeta['text']) ?></span>
                      </div>
                      <div class="run-hero__progress">
                        <div class="progress" style="margin-bottom:8px;">
                          <div class="progress-bar <?= esc($automaticStatusMeta['progress']) ?>" role="progressbar" style="width: <?= (int)$automaticCounts['percent'] ?>%;">
                            <?= (int)$automaticCounts['percent'] ?>%
                          </div>
                        </div>
                        <p class="run-hero__caption">
                          <?= monitor_number($automaticCounts['handled']) ?> gestiti su <?= monitor_number($automaticCounts['candidates']) ?>
                          <?php if ($automaticCounts['remaining'] > 0): ?>
                            | mancanti <?= monitor_number($automaticCounts['remaining']) ?>
                          <?php endif; ?>
                        </p>
                      </div>
                      <div class="run-meta-grid">
                        <div class="run-meta-grid__item"><strong>Avviato</strong><?= esc(monitor_run_started_at($automaticRun)) ?></div>
                        <div class="run-meta-grid__item"><strong>Terminato</strong><?= esc(monitor_run_finished_at($automaticRun)) ?></div>
                        <div class="run-meta-grid__item"><strong>Canale</strong><?= esc(monitor_run_channel_label($automaticRun)) ?></div>
                        <div class="run-meta-grid__item"><strong>Pausa</strong><?= monitor_number(monitor_run_delay_ms($automaticRun)) ?> ms</div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="run-hero run-hero--<?= esc($manualStatusMeta['panel']) ?>">
                      <div class="run-hero__header">
                        <div>
                          <div class="run-hero__kicker">Ultimo lancio manuale</div>
                          <h3 class="run-hero__title"><?= esc($manualStatusMeta['text']) ?></h3>
                          <p class="run-hero__sub">
                            <?= $manualRun ? esc('Target: ' . monitor_run_target_dates($manualRun) . ' | Modalita: ' . monitor_run_mode_label($manualRun)) : 'Nessun run manuale disponibile.' ?>
                          </p>
                        </div>
                        <span class="label <?= esc($manualStatusMeta['class']) ?>"><?= esc($manualStatusMeta['text']) ?></span>
                      </div>
                      <div class="run-hero__progress">
                        <div class="progress" style="margin-bottom:8px;">
                          <div class="progress-bar <?= esc($manualStatusMeta['progress']) ?>" role="progressbar" style="width: <?= (int)$manualCounts['percent'] ?>%;">
                            <?= (int)$manualCounts['percent'] ?>%
                          </div>
                        </div>
                        <p class="run-hero__caption">
                          <?= monitor_number($manualCounts['handled']) ?> gestiti su <?= monitor_number($manualCounts['candidates']) ?>
                          <?php if ($manualCounts['remaining'] > 0): ?>
                            | mancanti <?= monitor_number($manualCounts['remaining']) ?>
                          <?php endif; ?>
                        </p>
                      </div>
                      <div class="run-meta-grid">
                        <div class="run-meta-grid__item"><strong>Avviato</strong><?= esc(monitor_run_started_at($manualRun)) ?></div>
                        <div class="run-meta-grid__item"><strong>Terminato</strong><?= esc(monitor_run_finished_at($manualRun)) ?></div>
                        <div class="run-meta-grid__item"><strong>Canale</strong><?= esc(monitor_run_channel_label($manualRun)) ?></div>
                        <div class="run-meta-grid__item"><strong>Pausa</strong><?= monitor_number(monitor_run_delay_ms($manualRun)) ?> ms</div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="box box-warning">
                      <div class="box-header with-border">
                        <h3 class="box-title">Risposte pazienti</h3>
                      </div>
                      <div class="box-body">
                        <div class="metric-kv"><strong>Conferme ultimi <?= (int)$days ?> giorni:</strong> <?= monitor_number($outcomes['recent_confirmations'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Annullamenti ultimi <?= (int)$days ?> giorni:</strong> <?= monitor_number($outcomes['recent_cancellations'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Conferme totali:</strong> <?= monitor_number($outcomes['all_confirmations'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Annullamenti totali:</strong> <?= monitor_number($outcomes['all_cancellations'] ?? 0) ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="box box-danger">
                      <div class="box-header with-border">
                        <h3 class="box-title">Errori recenti</h3>
                      </div>
                      <div class="box-body">
                        <?php if (!empty($cron['recent_errors'])): ?>
                          <?php foreach (array_slice($cron['recent_errors'], 0, 3) as $row): ?>
                            <div style="margin-bottom:12px;">
                              <strong><?= esc((string)($row['timestamp'] ?? '-')) ?></strong><br>
                              <?= esc((string)($row['message'] ?? '-')) ?>
                              <?php if (!empty($row['context'])): ?>
                                <div class="section-note"><?= esc(json_encode($row['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
                              <?php endif; ?>
                            </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <p class="text-muted" style="margin-bottom:0;">Nessun errore recente nel log.</p>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="tab-pane <?= $activeTab === 'automatic' ? 'active' : '' ?>" id="tab-automatic">
                <div class="run-hero run-hero--<?= esc($automaticStatusMeta['panel']) ?>">
                  <div class="run-hero__header">
                    <div>
                      <div class="run-hero__kicker">Monitor invio automatico</div>
                      <h3 class="run-hero__title"><?= esc($automaticStatusMeta['text']) ?></h3>
                      <p class="run-hero__sub">
                        <?php if ($automaticRun): ?>
                          <?= esc('Run del ' . monitor_run_started_at($automaticRun) . ' | Date target: ' . monitor_run_target_dates($automaticRun)) ?>
                        <?php else: ?>
                          Nessun run automatico trovato nel log.
                        <?php endif; ?>
                      </p>
                    </div>
                    <span class="label <?= esc($automaticStatusMeta['class']) ?>"><?= esc($automaticStatusMeta['text']) ?></span>
                  </div>

                  <div class="run-hero__progress">
                    <div class="progress" style="margin-bottom:8px;">
                      <div class="progress-bar <?= esc($automaticStatusMeta['progress']) ?>" role="progressbar" style="width: <?= (int)$automaticCounts['percent'] ?>%;">
                        <?= (int)$automaticCounts['percent'] ?>%
                      </div>
                    </div>
                    <p class="run-hero__caption">
                      Gestiti <?= monitor_number($automaticCounts['handled']) ?> su <?= monitor_number($automaticCounts['candidates']) ?>
                      <?php if ($automaticCounts['remaining'] > 0): ?>
                        | mancanti <?= monitor_number($automaticCounts['remaining']) ?>
                      <?php endif; ?>
                      <?php if (!empty($cron['running_automatic_run'])): ?>
                        | aggiornamento automatico ogni <?= (int)$autoRefreshSeconds ?> secondi
                      <?php endif; ?>
                    </p>
                  </div>

                  <div class="run-meta-grid">
                    <div class="run-meta-grid__item"><strong>Partito oggi</strong><?= !empty($cron['ran_today_automatic']) ? 'Si' : 'No' ?></div>
                    <div class="run-meta-grid__item"><strong>Modalita</strong><?= esc(monitor_run_mode_label($automaticRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Canale</strong><?= esc(monitor_run_channel_label($automaticRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Pausa</strong><?= monitor_number(monitor_run_delay_ms($automaticRun)) ?> ms</div>
                    <div class="run-meta-grid__item"><strong>Avviato</strong><?= esc(monitor_run_started_at($automaticRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Terminato</strong><?= esc(monitor_run_finished_at($automaticRun)) ?></div>
                  </div>
                </div>

                <div class="row run-stats">
                  <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-aqua">
                      <div class="inner">
                        <h3><?= monitor_number($automaticCounts['candidates']) ?></h3>
                        <p>Candidati</p>
                      </div>
                      <div class="icon"><i class="fa fa-users"></i></div>
                    </div>
                  </div>
                  <div class="col-md-3 col-sm-6">
                    <div class="small-box bg-green">
                      <div class="inner">
                        <h3><?= monitor_number(($automaticRun && strtolower((string)($automaticRun['mode'] ?? '')) === 'send') ? $automaticCounts['sent'] : $automaticCounts['ready']) ?></h3>
                        <p><?= ($automaticRun && strtolower((string)($automaticRun['mode'] ?? '')) === 'send') ? 'Inviati' : 'Pronti' ?></p>
                      </div>
                      <div class="icon"><i class="fa fa-paper-plane"></i></div>
                    </div>
                  </div>
                  <div class="col-md-2 col-sm-6">
                    <div class="small-box bg-red">
                      <div class="inner">
                        <h3><?= monitor_number($automaticCounts['failed']) ?></h3>
                        <p>Errori invio</p>
                      </div>
                      <div class="icon"><i class="fa fa-exclamation-triangle"></i></div>
                    </div>
                  </div>
                  <div class="col-md-2 col-sm-6">
                    <div class="small-box bg-yellow">
                      <div class="inner">
                        <h3><?= monitor_number($automaticCounts['invalid']) ?></h3>
                        <p>Scarti numero</p>
                      </div>
                      <div class="icon"><i class="fa fa-ban"></i></div>
                    </div>
                  </div>
                  <div class="col-md-2 col-sm-6">
                    <div class="small-box bg-gray">
                      <div class="inner">
                        <h3><?= monitor_number($automaticCounts['already_sent']) ?></h3>
                        <p>Gia inviati</p>
                      </div>
                      <div class="icon"><i class="fa fa-history"></i></div>
                    </div>
                  </div>
                </div>

                <?php if ($automaticLastError): ?>
                  <div class="alert alert-danger">
                    <strong>Ultimo errore:</strong>
                    <?= esc((string)$automaticLastError['message']) ?>
                    <?php if (!empty($automaticLastError['timestamp'])): ?>
                      del <?= esc((string)$automaticLastError['timestamp']) ?>
                    <?php endif; ?>
                    <div class="section-note"><?= esc((string)$automaticLastError['detail']) ?></div>
                  </div>
                <?php endif; ?>

                <div class="row">
                  <div class="col-md-6">
                    <div class="box box-info">
                      <div class="box-header with-border">
                        <h3 class="box-title">Ultimo evento utile</h3>
                      </div>
                      <div class="box-body">
                        <?php if ($automaticLastEvent): ?>
                          <div class="metric-kv"><strong>Quando:</strong> <?= esc((string)$automaticLastEvent['timestamp']) ?></div>
                          <div class="metric-kv"><strong>Esito:</strong> <?= esc((string)$automaticLastEvent['status']) ?></div>
                          <div class="metric-kv"><strong>Data target:</strong> <?= esc((string)($automaticLastEvent['target_date'] ?: '-')) ?></div>
                          <div class="metric-kv"><strong>Appuntamento:</strong> #<?= monitor_number($automaticLastEvent['id_appuntamento'] ?? 0) ?></div>
                          <div class="metric-kv"><strong>Paziente:</strong> <?= esc((string)($automaticLastEvent['patient'] ?: '-')) ?></div>
                          <div class="metric-kv"><strong>Destinatario:</strong> <?= esc((string)($automaticLastEvent['recipient'] ?: '-')) ?></div>
                        <?php else: ?>
                          <p class="text-muted">Nessun evento disponibile per l ultimo run automatico.</p>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="box box-success">
                      <div class="box-header with-border">
                        <h3 class="box-title">Ultimo invio automatico reale</h3>
                      </div>
                      <div class="box-body">
                        <?php if ($automaticSendRun): ?>
                          <?php $automaticSendCounts = monitor_run_counts($automaticSendRun); ?>
                          <p><span class="label <?= esc(monitor_status_label($automaticSendRun)['class']) ?>"><?= esc(monitor_status_label($automaticSendRun)['text']) ?></span></p>
                          <div class="metric-kv"><strong>Avviato:</strong> <?= esc(monitor_run_started_at($automaticSendRun)) ?></div>
                          <div class="metric-kv"><strong>Date target:</strong> <?= esc(monitor_run_target_dates($automaticSendRun)) ?></div>
                          <div class="metric-kv"><strong>Inviati:</strong> <?= monitor_number($automaticSendCounts['sent']) ?></div>
                          <div class="metric-kv"><strong>Falliti:</strong> <?= monitor_number($automaticSendCounts['failed']) ?></div>
                          <div class="metric-kv"><strong>Scarti numero:</strong> <?= monitor_number($automaticSendCounts['invalid']) ?></div>
                          <div class="metric-kv"><strong>Gia inviati:</strong> <?= monitor_number($automaticSendCounts['already_sent']) ?></div>
                          <div class="metric-kv"><strong>Terminato:</strong> <?= esc(monitor_run_finished_at($automaticSendRun)) ?></div>
                        <?php else: ?>
                          <p class="text-muted">Non risulta ancora nessun invio automatico in modalita send.</p>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="box box-primary">
                  <div class="box-header with-border">
                    <h3 class="box-title">Dettaglio ultimo run automatico</h3>
                  </div>
                  <div class="box-body table-responsive no-padding">
                    <table class="table table-striped table-condensed">
                      <thead>
                        <tr>
                          <th>Quando</th>
                          <th>Esito</th>
                          <th>Data target</th>
                          <th>Appuntamento</th>
                          <th>Paziente</th>
                          <th>Destinatario</th>
                          <th>Dettaglio</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($automaticRows as $row): ?>
                          <tr>
                            <td><?= esc((string)($row['timestamp'] ?? '-')) ?></td>
                            <td><span class="label <?= esc((string)($row['status_class'] ?? 'label-default')) ?>"><?= esc((string)($row['status'] ?? '-')) ?></span></td>
                            <td><?= esc((string)($row['target_date'] ?: '-')) ?></td>
                            <td>#<?= monitor_number($row['id_appuntamento'] ?? 0) ?></td>
                            <td><?= esc((string)($row['patient'] ?: '-')) ?></td>
                            <td><?= esc((string)($row['recipient'] ?: '-')) ?></td>
                            <td>
                              <?php
                                $detailParts = [];
                                if (!empty($row['provider_id'])) {
                                    $detailParts[] = 'provider_id: ' . (string)$row['provider_id'];
                                }
                                if (!empty($row['error'])) {
                                    $detailParts[] = 'errore: ' . (string)$row['error'];
                                }
                                if (!empty($row['response'])) {
                                    $detailParts[] = 'response: ' . (string)$row['response'];
                                }
                              ?>
                              <?php if (!empty($row['message']) && ($row['status'] ?? '') === 'Dry-run pronto'): ?>
                                <details>
                                  <summary>Mostra messaggio</summary>
                                  <div class="json-box"><?= esc((string)$row['message']) ?></div>
                                </details>
                              <?php else: ?>
                                <?= esc($detailParts !== [] ? implode(' | ', $detailParts) : '-') ?>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if ($automaticRows === []): ?>
                          <tr><td colspan="7" class="text-muted">Nessun dettaglio disponibile per l ultimo run automatico.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="box box-default">
                  <div class="box-header with-border">
                    <h3 class="box-title">Log ultimo run automatico</h3>
                  </div>
                  <div class="box-body">
                    <?php if ($automaticLogLines !== []): ?>
                      <div class="log-tail"><?= esc(implode("\n", $automaticLogLines)) ?></div>
                    <?php else: ?>
                      <p class="text-muted">Nessun estratto log disponibile per l ultimo run automatico.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="tab-pane <?= $activeTab === 'manual' ? 'active' : '' ?>" id="tab-manual">
                <div class="box box-warning">
                  <div class="box-header with-border">
                    <h3 class="box-title">Avvio manuale reminder</h3>
                  </div>
                  <div class="box-body">
                    <div class="alert alert-info">
                      <strong>Come leggerlo:</strong> <code>Controllo senza invio</code> verifica cosa partirebbe,
                      <code>Invio reale</code> spedisce davvero i messaggi,
                      <code>Pausa</code> e` il tempo fra un invio e il successivo.
                    </div>

                    <form method="post" action="<?= site_url('admin/whatsapp-reminders/launch') ?>" id="manualReminderLaunchForm" data-confirmed="0">
                      <?php if (function_exists('csrf_field')): ?>
                        <?= csrf_field() ?>
                      <?php endif; ?>
                      <input type="hidden" name="confirm_target_date" id="confirm_target_date" value="">

                      <div class="row">
                        <div class="col-md-3">
                          <div class="form-group">
                            <label for="mode">Modalita</label>
                            <select name="mode" id="mode" class="form-control">
                              <option value="dry-run">Controllo senza invio</option>
                              <option value="send">Invio reale</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="form-group">
                            <label for="channel">Canale</label>
                            <select name="channel" id="channel" class="form-control">
                              <option value="wa" <?= (($manualDefaults['channel'] ?? 'wa') === 'wa') ? 'selected' : '' ?>>WhatsApp</option>
                              <option value="sms" <?= (($manualDefaults['channel'] ?? 'wa') === 'sms') ? 'selected' : '' ?>>SMS</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-md-6">
                          <div class="form-group">
                            <label for="target_date">Giorno appuntamenti</label>
                            <input type="date" name="target_date" id="target_date" class="form-control" value="<?= esc((string)($manualDefaults['target_date'] ?? $manualDefaults['start_date'] ?? '')) ?>">
                            <p class="help-block" style="margin-bottom:0;">Prima dell avvio ti verra chiesta una conferma esplicita della data selezionata.</p>
                          </div>
                        </div>
                      </div>

                      <div class="row">
                        <div class="col-md-3">
                          <div class="form-group">
                            <label for="delay_ms">Pausa tra messaggi (ms)</label>
                            <input type="number" min="0" step="1000" name="delay_ms" id="delay_ms" class="form-control" value="<?= esc((string)($manualDefaults['delay_ms'] ?? 900000)) ?>">
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="form-group">
                            <label for="limit">Massimo invii</label>
                            <input type="number" min="1" name="limit" id="limit" class="form-control" placeholder="opzionale">
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="form-group">
                            <label for="doctor">Filtro dottori (ID)</label>
                            <input type="text" name="doctor" id="doctor" class="form-control" placeholder="es. 67,72">
                          </div>
                        </div>
                        <div class="col-md-3">
                          <div class="form-group">
                            <label for="force_recipient">Destinatario di test</label>
                            <input type="text" name="force_recipient" id="force_recipient" class="form-control" placeholder="+393331234567">
                          </div>
                        </div>
                      </div>

                      <button type="submit" class="btn btn-warning">
                        <i class="fa fa-play"></i> Avvia lancio manuale
                      </button>
                    </form>
                  </div>
                </div>

                <div class="run-hero run-hero--<?= esc($manualStatusMeta['panel']) ?>">
                  <div class="run-hero__header">
                    <div>
                      <div class="run-hero__kicker">Monitor lancio manuale</div>
                      <h3 class="run-hero__title"><?= esc($manualStatusMeta['text']) ?></h3>
                      <p class="run-hero__sub">
                        <?php if ($manualRun): ?>
                          <?= esc('Run del ' . monitor_run_started_at($manualRun) . ' | Date target: ' . monitor_run_target_dates($manualRun)) ?>
                        <?php else: ?>
                          Nessun lancio manuale trovato nel log.
                        <?php endif; ?>
                      </p>
                    </div>
                    <span class="label <?= esc($manualStatusMeta['class']) ?>"><?= esc($manualStatusMeta['text']) ?></span>
                  </div>

                  <div class="run-hero__progress">
                    <div class="progress" style="margin-bottom:8px;">
                      <div class="progress-bar <?= esc($manualStatusMeta['progress']) ?>" role="progressbar" style="width: <?= (int)$manualCounts['percent'] ?>%;">
                        <?= (int)$manualCounts['percent'] ?>%
                      </div>
                    </div>
                    <p class="run-hero__caption">
                      Gestiti <?= monitor_number($manualCounts['handled']) ?> su <?= monitor_number($manualCounts['candidates']) ?>
                      <?php if ($manualCounts['remaining'] > 0): ?>
                        | mancanti <?= monitor_number($manualCounts['remaining']) ?>
                      <?php endif; ?>
                      <?php if (!empty($cron['running_manual_run'])): ?>
                        | aggiornamento automatico ogni <?= (int)$autoRefreshSeconds ?> secondi
                      <?php endif; ?>
                    </p>
                  </div>

                  <div class="run-meta-grid">
                    <div class="run-meta-grid__item"><strong>Modalita</strong><?= esc(monitor_run_mode_label($manualRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Canale</strong><?= esc(monitor_run_channel_label($manualRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Avviato</strong><?= esc(monitor_run_started_at($manualRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Terminato</strong><?= esc(monitor_run_finished_at($manualRun)) ?></div>
                    <div class="run-meta-grid__item"><strong>Pausa</strong><?= monitor_number(monitor_run_delay_ms($manualRun)) ?> ms</div>
                    <div class="run-meta-grid__item"><strong>Target</strong><?= esc(monitor_run_target_dates($manualRun)) ?></div>
                  </div>
                </div>

                <?php if ($manualLastError): ?>
                  <div class="alert alert-danger">
                    <strong>Ultimo errore del lancio manuale:</strong>
                    <?= esc((string)$manualLastError['message']) ?>
                    <?php if (!empty($manualLastError['timestamp'])): ?>
                      del <?= esc((string)$manualLastError['timestamp']) ?>
                    <?php endif; ?>
                    <div class="section-note"><?= esc((string)$manualLastError['detail']) ?></div>
                  </div>
                <?php endif; ?>

                <div class="row">
                  <div class="col-md-6">
                    <div class="run-mini-card">
                      <div class="run-mini-card__label">Ultimo controllo manuale</div>
                      <div class="run-mini-card__value"><?= esc($manualDryStatusMeta['text']) ?></div>
                      <?php if ($manualDryRun): ?>
                        <div class="run-mini-card__help">
                          <?= esc('Target: ' . monitor_run_target_dates($manualDryRun)) ?><br>
                          Pronti: <?= monitor_number($manualDryCounts['ready']) ?> |
                          Scarti: <?= monitor_number($manualDryCounts['invalid']) ?> |
                          Gia inviati: <?= monitor_number($manualDryCounts['already_sent']) ?>
                        </div>
                      <?php else: ?>
                        <div class="run-mini-card__help">Nessun controllo manuale registrato.</div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="run-mini-card">
                      <div class="run-mini-card__label">Ultimo invio manuale reale</div>
                      <div class="run-mini-card__value"><?= esc($manualSendStatusMeta['text']) ?></div>
                      <?php if ($manualSendRun): ?>
                        <div class="run-mini-card__help">
                          <?= esc('Target: ' . monitor_run_target_dates($manualSendRun)) ?><br>
                          Inviati: <?= monitor_number($manualSendCounts['sent']) ?> |
                          Errori: <?= monitor_number($manualSendCounts['failed']) ?> |
                          Scarti: <?= monitor_number($manualSendCounts['invalid']) ?>
                        </div>
                      <?php else: ?>
                        <div class="run-mini-card__help">Nessun invio manuale reale registrato.</div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="box box-primary">
                  <div class="box-header with-border">
                    <h3 class="box-title">Dettaglio ultimo lancio manuale</h3>
                  </div>
                  <div class="box-body table-responsive no-padding">
                    <table class="table table-striped table-condensed">
                      <thead>
                        <tr>
                          <th>Quando</th>
                          <th>Esito</th>
                          <th>Data target</th>
                          <th>Appuntamento</th>
                          <th>Paziente</th>
                          <th>Destinatario</th>
                          <th>Dettaglio</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($manualRows as $row): ?>
                          <tr>
                            <td><?= esc((string)($row['timestamp'] ?? '-')) ?></td>
                            <td><span class="label <?= esc((string)($row['status_class'] ?? 'label-default')) ?>"><?= esc((string)($row['status'] ?? '-')) ?></span></td>
                            <td><?= esc((string)($row['target_date'] ?: '-')) ?></td>
                            <td>#<?= monitor_number($row['id_appuntamento'] ?? 0) ?></td>
                            <td><?= esc((string)($row['patient'] ?: '-')) ?></td>
                            <td><?= esc((string)($row['recipient'] ?: '-')) ?></td>
                            <td>
                              <?php
                                $detailParts = [];
                                if (!empty($row['provider_id'])) {
                                    $detailParts[] = 'provider_id: ' . (string)$row['provider_id'];
                                }
                                if (!empty($row['error'])) {
                                    $detailParts[] = 'errore: ' . (string)$row['error'];
                                }
                                if (!empty($row['response'])) {
                                    $detailParts[] = 'response: ' . (string)$row['response'];
                                }
                              ?>
                              <?php if (!empty($row['message']) && ($row['status'] ?? '') === 'Dry-run pronto'): ?>
                                <details>
                                  <summary>Mostra messaggio</summary>
                                  <div class="json-box"><?= esc((string)$row['message']) ?></div>
                                </details>
                              <?php else: ?>
                                <?= esc($detailParts !== [] ? implode(' | ', $detailParts) : '-') ?>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if ($manualRows === []): ?>
                          <tr><td colspan="7" class="text-muted">Nessun dettaglio disponibile per l ultimo lancio manuale.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="box box-default">
                  <div class="box-header with-border">
                    <h3 class="box-title">Log ultimo lancio manuale</h3>
                  </div>
                  <div class="box-body">
                    <?php if ($manualLogLines !== []): ?>
                      <div class="log-tail"><?= esc(implode("\n", $manualLogLines)) ?></div>
                    <?php else: ?>
                      <p class="text-muted">Nessun estratto log disponibile per l ultimo lancio manuale.</p>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="tab-pane <?= $activeTab === 'history' ? 'active' : '' ?>" id="tab-history">
                <div class="row">
                  <div class="col-md-6">
                    <div class="box box-default">
                      <div class="box-header with-border">
                        <h3 class="box-title">Storico per giorno</h3>
                      </div>
                      <div class="box-body table-responsive no-padding">
                        <table class="table table-striped table-condensed">
                          <thead>
                            <tr>
                              <th>Data target</th>
                              <th>Ultimo stato</th>
                              <th>Ultima modalita</th>
                              <th>Run</th>
                              <th>Inviati</th>
                              <th>Falliti</th>
                              <th>Scarti</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach (($history['rows'] ?? []) as $row): ?>
                              <tr>
                                <td><?= esc((string)($row['target_date'] ?? '-')) ?></td>
                                <td><?= esc((string)($row['last_status'] ?? '-')) ?></td>
                                <td><?= esc((string)($row['last_mode'] ?? '-')) ?></td>
                                <td><?= monitor_number($row['runs_total'] ?? 0) ?></td>
                                <td><?= monitor_number($row['sent'] ?? 0) ?></td>
                                <td><?= monitor_number($row['failed'] ?? 0) ?></td>
                                <td><?= monitor_number($row['invalid_recipients'] ?? 0) ?></td>
                              </tr>
                            <?php endforeach; ?>
                            <?php if (empty($history['rows'])): ?>
                              <tr><td colspan="7" class="text-muted">Nessuno storico disponibile.</td></tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="box box-default">
                      <div class="box-header with-border">
                        <h3 class="box-title">File stato anti-duplicato</h3>
                      </div>
                      <div class="box-body table-responsive no-padding">
                        <table class="table table-striped table-condensed">
                          <thead>
                            <tr>
                              <th>Data target</th>
                              <th>Canale</th>
                              <th>Registrati</th>
                              <th>Aggiornato</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach (($stateFiles['files'] ?? []) as $row): ?>
                              <tr>
                                <td><?= esc((string)($row['target_date'] ?? '-')) ?></td>
                                <td><?= esc((string)($row['channel'] ?? '-')) ?></td>
                                <td><?= monitor_number($row['sent_count'] ?? 0) ?></td>
                                <td><?= esc((string)($row['mtime'] ?? '-')) ?></td>
                              </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stateFiles['files'])): ?>
                              <tr><td colspan="4" class="text-muted">Nessun file stato trovato.</td></tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row">
                  <div class="col-md-6">
                    <div class="box box-success">
                      <div class="box-header with-border">
                        <h3 class="box-title">Promemoria previsti per <?= esc((string)($queue['target_date'] ?? '-')) ?></h3>
                      </div>
                      <div class="box-body">
                        <div class="metric-kv"><strong>Appuntamenti da controllare:</strong> <?= monitor_number($queue['candidates'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Con numero valido:</strong> <?= monitor_number($queue['valid_recipients'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Scarti numero:</strong> <?= monitor_number($queue['invalid_recipients'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Dottori abilitati:</strong> <?= monitor_number($queue['enabled_doctors'] ?? 0) ?></div>
                        <div class="metric-kv"><strong>Dottori con conferma:</strong> <?= monitor_number($queue['enabled_with_confirmation'] ?? 0) ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6">
                    <div class="box box-primary">
                      <div class="box-header with-border">
                        <h3 class="box-title">Gateway WhatsApp (UltraMsg)</h3>
                      </div>
                      <div class="box-body">
                        <?php if (!empty($ultramsg['available'])): ?>
                          <div class="metric-kv"><strong>Instance ID:</strong> <?= esc((string)($ultramsg['instance_id'] ?? '-')) ?></div>
                          <div class="metric-kv"><strong>Stato istanza:</strong> <?= esc($ultramsgInstanceStatus) ?></div>
                          <div class="metric-kv"><strong>Numero/account:</strong> <?= esc($ultramsgInstancePhone) ?></div>
                          <div class="metric-kv"><strong>Queue API:</strong> <?= monitor_number($ultramsg['statistics_flat']['queue'] ?? $ultramsg['statistics_flat']['messages.queue'] ?? null) ?></div>
                          <div class="metric-kv"><strong>Unsent API:</strong> <?= monitor_number($ultramsg['statistics_flat']['unsent'] ?? $ultramsg['statistics_flat']['messages.unsent'] ?? null) ?></div>
                          <div class="metric-kv"><strong>Invalid API:</strong> <?= monitor_number($ultramsg['statistics_flat']['invalid'] ?? $ultramsg['statistics_flat']['messages.invalid'] ?? null) ?></div>
                          <details style="margin-top:10px;">
                            <summary>Apri dettaglio tecnico</summary>
                            <div class="json-box"><?= esc(json_encode([
                                'status' => $ultramsg['status'] ?? null,
                                'me' => $ultramsg['me'] ?? null,
                                'statistics' => $ultramsg['statistics'] ?? null,
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
                          </details>
                        <?php else: ?>
                          <p class="text-danger" style="margin-bottom:0;"><?= esc((string)($ultramsg['error'] ?? 'UltraMsg non configurato.')) ?></p>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="box box-success">
                  <div class="box-header with-border">
                    <h3 class="box-title">Ultime risposte ricevute</h3>
                  </div>
                  <div class="box-body table-responsive no-padding">
                    <table class="table table-striped table-condensed">
                      <thead>
                        <tr>
                          <th>Quando</th>
                          <th>Azione</th>
                          <th>Appuntamento</th>
                          <th>Paziente</th>
                          <th>ID dottore</th>
                          <th>Stato appuntamento</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach (($outcomes['recent_events'] ?? []) as $row): ?>
                          <tr>
                            <td><?= esc((string)($row['occurred_at'] ?? '-')) ?></td>
                            <td>
                              <?php if (($row['action'] ?? '') === 'confirm'): ?>
                                <span class="label label-success">Conferma</span>
                              <?php else: ?>
                                <span class="label label-danger">Annullamento</span>
                              <?php endif; ?>
                            </td>
                            <td>#<?= monitor_number($row['id_appuntamento'] ?? 0) ?></td>
                            <td><?= esc((string)($row['patient'] ?? '-')) ?></td>
                            <td><?= monitor_number($row['id_dot'] ?? 0) ?></td>
                            <td><?= esc((string)($row['stato'] ?? '-')) ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($outcomes['recent_events'])): ?>
                          <tr><td colspan="6" class="text-muted">Nessun evento recente trovato nelle note appuntamento.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="box box-danger">
                  <div class="box-header with-border">
                    <h3 class="box-title">Errori recenti del log</h3>
                  </div>
                  <div class="box-body table-responsive no-padding">
                    <table class="table table-striped table-condensed">
                      <thead>
                        <tr>
                          <th>Quando</th>
                          <th>Messaggio</th>
                          <th>Dettagli</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach (($cron['recent_errors'] ?? []) as $row): ?>
                          <tr>
                            <td><?= esc((string)($row['timestamp'] ?? '-')) ?></td>
                            <td><?= esc((string)($row['message'] ?? '-')) ?></td>
                            <td><div class="json-box"><?= esc(json_encode($row['context'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($cron['recent_errors'])): ?>
                          <tr><td colspan="3" class="text-muted">Nessun errore recente nel log.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>

                <div class="box box-default">
                  <div class="box-header with-border">
                    <h3 class="box-title">Ultime righe del log generale</h3>
                  </div>
                  <div class="box-body">
                    <div class="log-tail"><?= esc(implode("\n", $cron['tail_lines'] ?? [])) ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</div>

<div class="modal fade" id="manualReminderConfirmModal" tabindex="-1" role="dialog" aria-labelledby="manualReminderConfirmTitle">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Chiudi"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="manualReminderConfirmTitle">Conferma lancio manuale reminder</h4>
      </div>
      <div class="modal-body">
        <p>Stai per avviare il batch manuale con questi parametri:</p>
        <ul style="padding-left:18px; margin-bottom:10px;">
          <li><strong>Data appuntamenti:</strong> <span id="manualReminderConfirmDate">-</span></li>
          <li><strong>Modalita:</strong> <span id="manualReminderConfirmMode">-</span></li>
          <li><strong>Canale:</strong> <span id="manualReminderConfirmChannel">-</span></li>
        </ul>
        <p class="text-warning" style="margin-bottom:0;"><strong>Conferma solo se il giorno e corretto.</strong></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Annulla</button>
        <button type="button" class="btn btn-warning" id="manualReminderConfirmSubmit">
          <i class="fa fa-check"></i> Conferma e avvia
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') ?>"></script>
<script>
  (function() {
    var manualForm = $('#manualReminderLaunchForm');
    var manualConfirmModal = $('#manualReminderConfirmModal');
    var manualConfirmInput = $('#confirm_target_date');
    var manualTargetDate = $('#target_date');
    var manualMode = $('#mode');
    var manualChannel = $('#channel');

    function formatManualReminderDate(dateValue) {
      if (!dateValue || !/^\d{4}-\d{2}-\d{2}$/.test(dateValue)) {
        return dateValue || '-';
      }

      var parts = dateValue.split('-');
      return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function manualModeLabel() {
      return manualMode.find('option:selected').text() || '-';
    }

    function manualChannelLabel() {
      return manualChannel.find('option:selected').text() || '-';
    }

    function resetManualReminderConfirmation() {
      manualForm.attr('data-confirmed', '0');
      manualConfirmInput.val('');
    }

    if (manualForm.length) {
      manualForm.on('submit', function(event) {
        if (manualForm.attr('data-confirmed') === '1') {
          return true;
        }

        var targetDateValue = $.trim(manualTargetDate.val() || '');
        if (!targetDateValue) {
          window.alert('Seleziona prima il giorno appuntamenti da eseguire.');
          manualTargetDate.trigger('focus');
          event.preventDefault();
          return false;
        }

        event.preventDefault();
        $('#manualReminderConfirmDate').text(formatManualReminderDate(targetDateValue));
        $('#manualReminderConfirmMode').text(manualModeLabel());
        $('#manualReminderConfirmChannel').text(manualChannelLabel());
        manualConfirmModal.modal('show');
        return false;
      });

      $('#manualReminderConfirmSubmit').on('click', function() {
        var targetDateValue = $.trim(manualTargetDate.val() || '');
        manualConfirmInput.val(targetDateValue);
        manualForm.attr('data-confirmed', '1');
        manualConfirmModal.modal('hide');
        if (manualForm.length && manualForm.get(0)) {
          manualForm.get(0).submit();
        }
      });

      manualTargetDate.on('change input', resetManualReminderConfirmation);
      manualMode.on('change', resetManualReminderConfirmation);
      manualChannel.on('change', resetManualReminderConfirmation);
      manualConfirmModal.on('hidden.bs.modal', function() {
        if (manualForm.attr('data-confirmed') !== '1') {
          manualConfirmInput.val('');
        }
      });
    }

    $('.nav-tabs a[data-toggle="tab"]').on('shown.bs.tab', function(event) {
      var tabKey = $(event.target).data('tab-key');
      if (!tabKey) {
        return;
      }

      $('#refreshTabInput').val(tabKey);
      if (window.history && window.history.replaceState) {
        var search = window.location.search.replace(/^\?/, '');
        var parts = search ? search.split('&') : [];
        var nextParts = [];
        var replaced = false;

        for (var i = 0; i < parts.length; i++) {
          if (!parts[i]) {
            continue;
          }

          var pair = parts[i].split('=');
          if (decodeURIComponent(pair[0] || '') === 'tab') {
            nextParts.push('tab=' + encodeURIComponent(tabKey));
            replaced = true;
            continue;
          }

          nextParts.push(parts[i]);
        }

        if (!replaced) {
          nextParts.push('tab=' + encodeURIComponent(tabKey));
        }

        var nextSearch = nextParts.length ? ('?' + nextParts.join('&')) : '';
        var nextUrl = window.location.pathname + nextSearch + window.location.hash;
        window.history.replaceState(null, document.title, nextUrl);
      }
    });

    var refreshSeconds = <?= $hasRunningRun ? (int)$autoRefreshSeconds : 0 ?>;
    if (refreshSeconds > 0) {
      window.setTimeout(function() {
        window.location.reload();
      }, refreshSeconds * 1000);
    }
  })();
</script>
</body>
</html>
