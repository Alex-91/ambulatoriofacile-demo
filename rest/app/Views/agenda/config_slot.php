<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Configurazione slot | Agenda</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <style>
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.45);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .loading-box {
            background: #fff;
            border-radius: 6px;
            padding: 18px 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
            font-size: 15px;
            color: #333;
        }

        .loading-box .fa {
            margin-right: 8px;
        }

        .doctor-picker-card {
            max-width: 680px;
            margin: 0 auto 24px;
            padding: 22px 24px 20px;
            border: 1px solid #d9edf7;
            border-radius: 10px;
            background: linear-gradient(135deg, #f8fcff 0%, #edf7ff 100%);
            box-shadow: 0 8px 22px rgba(60, 141, 188, 0.14);
            text-align: center;
        }

        .doctor-picker-kicker {
            display: inline-block;
            margin-bottom: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #d9edf7;
            color: #2f6f91;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .doctor-picker-label {
            display: block;
            margin-bottom: 6px;
            color: #1f2d3d;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.25;
        }

        .doctor-picker-help {
            max-width: 520px;
            margin: 0 auto 16px;
            color: #5f6b77;
            font-size: 14px;
        }

        .doctor-picker-select {
            max-width: 460px;
            height: 46px;
            margin: 0 auto;
            border: 2px solid #3c8dbc;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 600;
            box-shadow: 0 0 0 3px rgba(60, 141, 188, 0.08);
        }

        .agenda-day-card {
            margin-bottom: 18px;
            border: 1px solid #dfe6ee;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }

        .agenda-day-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            background: linear-gradient(135deg, #f8fafc 0%, #eef4fa 100%);
            border-bottom: 1px solid #e5edf5;
        }

        .agenda-day-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #1f2d3d;
        }

        .agenda-day-help {
            display: block;
            margin-top: 4px;
            color: #6b7785;
            font-size: 12px;
        }

        .agenda-day-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-weight: 600;
            color: #32485d;
            white-space: nowrap;
        }

        .agenda-day-body {
            padding: 18px;
        }

        .agenda-day-empty {
            margin: 0;
            padding: 12px 14px;
            border-radius: 8px;
            background: #f6f9fc;
            color: #667789;
            font-size: 13px;
        }

        .fascia-row {
            display: grid;
            grid-template-columns: minmax(110px, 130px) minmax(110px, 130px) minmax(110px, 130px) minmax(170px, 1fr) minmax(110px, 140px) auto;
            gap: 12px;
            align-items: end;
            padding: 14px 0;
            border-bottom: 1px solid #edf2f7;
        }

        .fascia-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .fascia-label {
            display: block;
            margin-bottom: 6px;
            color: #4b5b6b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .fascia-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: flex-start;
        }

        .fascia-actions .btn {
            min-width: 38px;
        }

        .range-summary-note {
            margin-top: 12px;
            color: #6b7785;
            font-size: 12px;
        }

        @media (max-width: 991px) {
            .fascia-row {
                grid-template-columns: 1fr 1fr 1fr;
            }

            .fascia-actions {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 767px) {
            .doctor-picker-card {
                padding: 18px 16px;
            }

            .doctor-picker-label {
                font-size: 20px;
            }

            .doctor-picker-select {
                max-width: 100%;
                font-size: 16px;
            }

            .agenda-day-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .fascia-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="skin-blue sidebar-mini">
<?php
$giorniLabel = [
    1 => 'Lunedi',
    2 => 'Martedi',
    3 => 'Mercoledi',
    4 => 'Giovedi',
    5 => 'Venerdi',
    6 => 'Sabato',
    7 => 'Domenica',
];

$locationCatalogList = array_values(is_array($locationCatalog ?? null) ? $locationCatalog : []);
$normalizeLocationKey = static function ($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
};

$locationCatalogById = [];
$ambulatorioIdByName = [];
$roomMetaById = [];
$roomIdByAmbulatorioAndName = [];

foreach ($locationCatalogList as $locationRow) {
    $idAmbLegacy = (int)($locationRow['id_amb_legacy'] ?? 0);
    $nomeAmbulatorio = trim((string)($locationRow['nome'] ?? ''));
    if ($idAmbLegacy <= 0 || $nomeAmbulatorio === '') {
        continue;
    }

    $stanze = [];
    foreach (($locationRow['stanze'] ?? []) as $roomRow) {
        $idStanza = (int)($roomRow['id_stanza'] ?? 0);
        $nomeStanza = trim((string)($roomRow['nome'] ?? ''));
        if ($idStanza <= 0 || $nomeStanza === '') {
            continue;
        }

        $stanze[] = [
            'id_stanza' => $idStanza,
            'nome'      => $nomeStanza,
        ];
        $roomMetaById[$idStanza] = [
            'id_stanza'     => $idStanza,
            'id_amb_legacy' => $idAmbLegacy,
            'nome'          => $nomeStanza,
        ];
        $roomIdByAmbulatorioAndName[$idAmbLegacy][$normalizeLocationKey($nomeStanza)] = $idStanza;
    }

    $locationCatalogById[$idAmbLegacy] = [
        'id_amb_legacy' => $idAmbLegacy,
        'nome'          => $nomeAmbulatorio,
        'stanze'        => $stanze,
    ];
    $ambulatorioIdByName[$normalizeLocationKey($nomeAmbulatorio)] = $idAmbLegacy;
}

$resolveLocationSelection = static function (array $fascia) use (
    $normalizeLocationKey,
    $locationCatalogById,
    $ambulatorioIdByName,
    $roomMetaById,
    $roomIdByAmbulatorioAndName
): array {
    $idAmbLegacy = (int)($fascia['id_amb_legacy'] ?? 0);
    $idStanza = (int)($fascia['id_stanza'] ?? 0);
    $ambulatorio = trim((string)($fascia['ambulatorio'] ?? ''));
    $stanza = trim((string)($fascia['stanza'] ?? ''));

    if ($idAmbLegacy <= 0 && $ambulatorio !== '') {
        $idAmbLegacy = (int)($ambulatorioIdByName[$normalizeLocationKey($ambulatorio)] ?? 0);
    }

    if ($idStanza > 0 && isset($roomMetaById[$idStanza])) {
        $roomMeta = $roomMetaById[$idStanza];
        $idAmbLegacy = (int)($roomMeta['id_amb_legacy'] ?? 0);
        $stanza = (string)($roomMeta['nome'] ?? $stanza);
    }

    if ($idAmbLegacy > 0 && isset($locationCatalogById[$idAmbLegacy])) {
        $ambulatorio = (string)($locationCatalogById[$idAmbLegacy]['nome'] ?? $ambulatorio);

        if ($idStanza <= 0 && $stanza !== '') {
            $idStanza = (int)($roomIdByAmbulatorioAndName[$idAmbLegacy][$normalizeLocationKey($stanza)] ?? 0);
        }
    }

    if ($idStanza > 0 && isset($roomMetaById[$idStanza])) {
        $stanza = (string)($roomMetaById[$idStanza]['nome'] ?? $stanza);
    }

    return [
        'id_amb_legacy' => $idAmbLegacy > 0 ? (string)$idAmbLegacy : '',
        'id_stanza'     => $idStanza > 0 ? (string)$idStanza : '',
        'ambulatorio'   => $ambulatorio,
        'stanza'        => $stanza,
    ];
};

$formatTimeInput = static function ($value, string $fallback): string {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    return substr($value, 0, 5);
};

$giorniConfig = [];
foreach (($config['giorni'] ?? []) as $g) {
    $day = (int)($g['giorno_settimana'] ?? 0);
    if ($day < 1 || $day > 7) {
        continue;
    }

    $fasce = [];
    foreach (($g['fasce'] ?? []) as $fascia) {
        $rangeRow = [
            'ora_inizio'  => $formatTimeInput($fascia['ora_inizio'] ?? '', '08:00'),
            'ora_fine'    => $formatTimeInput($fascia['ora_fine'] ?? '', '12:00'),
            'durata_slot' => (string)((int)($fascia['durata_slot'] ?? 15) ?: 15),
            'id_amb_legacy' => (string)((int)($fascia['id_amb_legacy'] ?? 0) ?: ''),
            'id_stanza' => (string)((int)($fascia['id_stanza'] ?? 0) ?: ''),
            'ambulatorio' => trim((string)($fascia['ambulatorio'] ?? '')),
            'stanza' => trim((string)($fascia['stanza'] ?? '')),
        ];
        $fasce[] = array_merge($rangeRow, $resolveLocationSelection($rangeRow));
    }

    if (empty($fasce)) {
        $fasce[] = [
            'ora_inizio'  => '08:00',
            'ora_fine'    => '12:00',
            'durata_slot' => '15',
            'id_amb_legacy' => '',
            'id_stanza' => '',
            'ambulatorio' => '',
            'stanza' => '',
        ];
    }

    $giorniConfig[$day] = [
        'giorno_libero' => !empty($g['giorno_libero']),
        'fasce'         => $fasce,
    ];
}
?>
<div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <h1>Configurazione slot</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Configurazione slot</li>
            </ol>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-3">
                    <div class="box box-solid">
                        <div class="box-header with-border">
                            <h3 class="box-title">Menu</h3>
                        </div>
                        <div class="box-body no-padding">
                            <?= view('agenda/partials/menu_laterale', ['menuAgenda' => $menuAgenda ?? []]) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-9">
                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-clock-o"></i> Parametri generazione slot</h3>
                        </div>

                        <div class="box-body">
                            <form id="formConfigSlot">
                                <div class="row">
                                    <div class="col-md-12 form-group">
                                        <div class="doctor-picker-card">
                                            <div class="doctor-picker-kicker">
                                                <i class="fa fa-user-md"></i> Professionista
                                            </div>
                                            <label class="doctor-picker-label" for="id_dot">Seleziona il dottore o infermiere da configurare</label>
                                            <p class="doctor-picker-help">
                                                Ogni giorno puo avere piu fasce orarie con durate slot diverse. La rigenerazione agenda usera esattamente queste fasce.
                                            </p>
                                            <select name="id_dot" id="id_dot" class="form-control doctor-picker-select">
                                                <?php foreach (($medici ?? []) as $m): ?>
                                                    <?php
                                                    $idDot = is_object($m) ? (int)($m->id_dot ?? 0) : (int)($m['id_dot'] ?? 0);
                                                    $label = is_object($m)
                                                        ? (string)($m->label ?? trim(($m->cognome ?? '') . ' ' . ($m->nome ?? '')))
                                                        : (string)($m['label'] ?? trim(($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? '')));
                                                    ?>
                                                    <option value="<?= esc($idDot) ?>" <?= ((int)$selectedDot === $idDot ? 'selected' : '') ?>>
                                                        <?= esc($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label>Data inizio</label>
                                        <input type="date" name="data_inizio" class="form-control"
                                               value="<?= esc($config['data_inizio'] ?? date('Y-m-d')) ?>">
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label>Data fine</label>
                                        <input type="date" name="data_fine" class="form-control"
                                               value="<?= esc($config['data_fine'] ?? '') ?>">
                                        <small class="text-muted">Se vuota verra usato `2039-12-31`</small>
                                    </div>

                                    <div class="col-md-12 form-group">
                                        <label>Descrizione</label>
                                        <input type="text" name="descrizione" class="form-control"
                                               value="<?= esc($config['descrizione'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="alert alert-info">
                                    Per ogni giorno puoi inserire una o piu fasce. Esempio: `09:00-10:00` con slot da `15` minuti e `10:00-11:00` con slot da `5` minuti.
                                </div>

                                <?php for ($i = 1; $i <= 7; $i++): ?>
                                    <?php
                                    $dayConfig = $giorniConfig[$i] ?? [
                                        'giorno_libero' => true,
                                        'fasce' => [
                                            [
                                                'ora_inizio'  => '08:00',
                                                'ora_fine'    => '12:00',
                                                'durata_slot' => '15',
                                                'id_amb_legacy' => '',
                                                'id_stanza' => '',
                                                'ambulatorio' => '',
                                                'stanza' => '',
                                            ],
                                        ],
                                    ];
                                    ?>
                                    <div class="agenda-day-card" data-day="<?= $i ?>">
                                        <div class="agenda-day-header">
                                            <div>
                                                <h3 class="agenda-day-title"><?= esc($giorniLabel[$i]) ?></h3>
                                                <span class="agenda-day-help">Aggiungi una o piu fasce per questo giorno.</span>
                                            </div>
                                            <label class="agenda-day-toggle">
                                                <input type="checkbox"
                                                       class="giorno-libero-toggle"
                                                       name="giorni[<?= $i ?>][giorno_libero]"
                                                       value="1"
                                                       <?= !empty($dayConfig['giorno_libero']) ? 'checked' : '' ?>>
                                                Giorno libero
                                            </label>
                                        </div>
                                        <div class="agenda-day-body day-ranges-body">
                                            <div class="fasce-list">
                                                <?php foreach (($dayConfig['fasce'] ?? []) as $index => $fascia): ?>
                                                    <?php
                                                    $selectedAmbLegacy = (int)($fascia['id_amb_legacy'] ?? 0);
                                                    $selectedRoomId = (int)($fascia['id_stanza'] ?? 0);
                                                    $stanzeDisponibili = $locationCatalogById[$selectedAmbLegacy]['stanze'] ?? [];
                                                    ?>
                                                    <div class="fascia-row" data-index="<?= $index ?>">
                                                        <div class="form-group">
                                                            <label class="fascia-label">Inizio</label>
                                                            <input type="time"
                                                                   class="form-control"
                                                                   data-field="ora_inizio"
                                                                   name="giorni[<?= $i ?>][fasce][<?= $index ?>][ora_inizio]"
                                                                   value="<?= esc($fascia['ora_inizio'] ?? '08:00') ?>">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="fascia-label">Fine</label>
                                                            <input type="time"
                                                                   class="form-control"
                                                                   data-field="ora_fine"
                                                                   name="giorni[<?= $i ?>][fasce][<?= $index ?>][ora_fine]"
                                                                   value="<?= esc($fascia['ora_fine'] ?? '12:00') ?>">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="fascia-label">Durata slot</label>
                                                            <input type="number"
                                                                   min="1"
                                                                   step="1"
                                                                   class="form-control"
                                                                   data-field="durata_slot"
                                                                   name="giorni[<?= $i ?>][fasce][<?= $index ?>][durata_slot]"
                                                                   value="<?= esc($fascia['durata_slot'] ?? '15') ?>">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="fascia-label">Sede</label>
                                                            <select class="form-control"
                                                                    data-field="id_amb_legacy"
                                                                    name="giorni[<?= $i ?>][fasce][<?= $index ?>][id_amb_legacy]">
                                                                <option value="">Seleziona sede</option>
                                                                <?php foreach ($locationCatalogList as $locationRow): ?>
                                                                    <?php $optionAmbId = (int)($locationRow['id_amb_legacy'] ?? 0); ?>
                                                                    <?php if ($optionAmbId <= 0): ?>
                                                                        <?php continue; ?>
                                                                    <?php endif; ?>
                                                                    <option value="<?= esc($optionAmbId) ?>" <?= $selectedAmbLegacy === $optionAmbId ? 'selected' : '' ?>>
                                                                        <?= esc($locationRow['nome'] ?? '') ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <input type="hidden"
                                                                   data-field="ambulatorio"
                                                                   name="giorni[<?= $i ?>][fasce][<?= $index ?>][ambulatorio]"
                                                                   value="<?= esc($fascia['ambulatorio'] ?? '') ?>">
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="fascia-label">Stanza</label>
                                                            <select class="form-control"
                                                                    data-field="id_stanza"
                                                                    name="giorni[<?= $i ?>][fasce][<?= $index ?>][id_stanza]">
                                                                <option value="">
                                                                    <?= empty($stanzeDisponibili) ? ($selectedAmbLegacy > 0 ? 'Nessuna stanza disponibile' : 'Prima scegli la sede') : 'Seleziona stanza' ?>
                                                                </option>
                                                                <?php foreach ($stanzeDisponibili as $roomRow): ?>
                                                                    <?php $optionRoomId = (int)($roomRow['id_stanza'] ?? 0); ?>
                                                                    <?php if ($optionRoomId <= 0): ?>
                                                                        <?php continue; ?>
                                                                    <?php endif; ?>
                                                                    <option value="<?= esc($optionRoomId) ?>" <?= $selectedRoomId === $optionRoomId ? 'selected' : '' ?>>
                                                                        <?= esc($roomRow['nome'] ?? '') ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <input type="hidden"
                                                                   data-field="stanza"
                                                                   name="giorni[<?= $i ?>][fasce][<?= $index ?>][stanza]"
                                                                   value="<?= esc($fascia['stanza'] ?? '') ?>">
                                                        </div>
                                                        <div class="fascia-actions">
                                                            <button type="button" class="btn btn-success btn-add-range" title="Aggiungi fascia">
                                                                <i class="fa fa-plus"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-danger btn-remove-range" title="Rimuovi fascia">
                                                                <i class="fa fa-minus"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <p class="agenda-day-empty" style="display:none;">Questo giorno e segnato come libero. Togli la spunta per inserire una o piu fasce orarie.</p>
                                            <div class="range-summary-note">
                                                Le fasce non devono sovrapporsi. Ogni fascia genera slot in base alla sua durata.
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>

                                <div class="text-right">
                                    <button type="button" class="btn btn-success" id="btnSalvaEGeneraAgenda">
                                        <i class="fa fa-save"></i> Salva e genera agenda
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="agendaJobStatusBox" class="alert alert-info" style="display:none;">
                        <div id="agendaJobStatusTitle" style="font-size:16px; font-weight:600; margin-bottom:6px;">Rigenerazione agenda</div>
                        <div id="agendaJobStatusMessage">Operazione in coda.</div>
                        <div class="progress" style="margin:12px 0 8px;">
                            <div id="agendaJobProgressBar" class="progress-bar progress-bar-info" role="progressbar" style="width:0%;">0%</div>
                        </div>
                        <div id="agendaJobStatusMeta" class="small text-muted">Puoi continuare a usare l'applicativo mentre il job viene eseguito.</div>
                    </div>

                    <div class="alert alert-info">
                        Se nel periodo selezionato esistono gia slot o appuntamenti, il sistema crea un file di backup, elimina i vecchi dati e rigenera i nuovi slot. Per periodi molto ampi il backup viene salvato in CSV per evitare blocchi.
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="slotLoadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <i class="fa fa-spinner fa-spin"></i>
        <span id="slotLoadingText">Elaborazione in corso...</span>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>

<script>
$(function () {
    var locationCatalog = <?= json_encode($locationCatalogList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var locationCatalogById = {};
    var roomMetaById = {};
    var jobStatusUrl = "<?= base_url('agenda/rigenera-slot-config-status') ?>";
    var currentJobId = null;
    var jobPollTimer = null;
    var lastTerminalAlertKey = '';

    $.each(locationCatalog, function (_, locationRow) {
        var ambId = parseInt(locationRow.id_amb_legacy, 10) || 0;
        if (ambId <= 0) {
            return;
        }

        locationRow.stanze = Array.isArray(locationRow.stanze) ? locationRow.stanze : [];
        locationCatalogById[ambId] = locationRow;

        $.each(locationRow.stanze, function (_, roomRow) {
            var roomId = parseInt(roomRow.id_stanza, 10) || 0;
            if (roomId <= 0) {
                return;
            }

            roomMetaById[roomId] = $.extend({}, roomRow, {
                id_amb_legacy: ambId
            });
        });
    });

    function setLoading(on, text) {
        if (on) {
            $('#slotLoadingText').text(text || 'Elaborazione in corso...');
            $('#slotLoadingOverlay').css('display', 'flex');
            $('#btnSalvaEGeneraAgenda, #id_dot').prop('disabled', true);
            return;
        }

        $('#slotLoadingOverlay').hide();
        $('#id_dot').prop('disabled', false);
        $('#btnSalvaEGeneraAgenda').prop('disabled', currentJobId !== null && jobPollTimer !== null);
    }

    function setJobLocked(on) {
        $('#btnSalvaEGeneraAgenda').prop('disabled', on);
    }

    function isJobActive(job) {
        var status = String(job && job.status || '').toUpperCase();
        return status === 'QUEUED' || status === 'RUNNING';
    }

    function stopJobPolling() {
        if (jobPollTimer) {
            clearInterval(jobPollTimer);
            jobPollTimer = null;
        }
        currentJobId = null;
    }

    function fetchJobStatus(params, done, fail) {
        $.get(jobStatusUrl, params, function (res) {
            if (res && res.status) {
                done(res.job || null, res);
                return;
            }

            if (typeof fail === 'function') {
                fail(res);
            }
        }, 'json').fail(function (xhr) {
            if (typeof fail === 'function') {
                fail(xhr);
            }
        });
    }

    function renderJobStatus(job) {
        if (!job) {
            $('#agendaJobStatusBox').hide();
            return;
        }

        var status = String(job.status || '').toUpperCase();
        var title = 'Rigenerazione agenda';
        var boxClass = 'alert-info';
        var barClass = 'progress-bar-info';
        var percent = parseInt(job.progress_percent, 10) || 0;
        var message = job.progress_message || job.message || 'Operazione in corso.';
        var meta = [];

        if (status === 'QUEUED') {
            title = 'Rigenerazione agenda in coda';
        } else if (status === 'RUNNING') {
            title = 'Rigenerazione agenda in corso';
        } else if (status === 'COMPLETED') {
            title = 'Rigenerazione agenda completata';
            boxClass = 'alert-success';
            barClass = 'progress-bar-success';
            percent = 100;
            message = job.message || 'Operazione completata.';
        } else if (status === 'FAILED') {
            title = 'Rigenerazione agenda non completata';
            boxClass = 'alert-danger';
            barClass = 'progress-bar-danger';
            percent = percent > 0 ? percent : 100;
            message = job.error_message || 'Operazione non completata.';
        }

        if (isJobActive(job)) {
            meta.push('Puoi continuare a usare l\'applicativo mentre il job viene eseguito.');
        }
        if (job.backup_file) {
            meta.push('Backup: ' + job.backup_file + (job.backup_format ? ' (' + String(job.backup_format).toUpperCase() + ')' : ''));
        }
        if (status === 'COMPLETED') {
            meta.push('Slot creati: ' + (parseInt(job.inserted, 10) || 0) + '.');
        }

        $('#agendaJobStatusBox')
            .removeClass('alert-info alert-success alert-danger alert-warning')
            .addClass(boxClass)
            .show();
        $('#agendaJobStatusTitle').text(title);
        $('#agendaJobStatusMessage').text(message);
        $('#agendaJobStatusMeta').text(meta.join(' '));
        $('#agendaJobProgressBar')
            .removeClass('progress-bar-info progress-bar-success progress-bar-danger progress-bar-warning')
            .addClass(barClass)
            .css('width', percent + '%')
            .text(percent + '%');
    }

    function handleTerminalJob(job, silentAlert) {
        var alertKey = String(job.id_job || '') + ':' + String(job.status || '');
        if (!silentAlert && lastTerminalAlertKey !== alertKey) {
            lastTerminalAlertKey = alertKey;
            alert(job.error_message || job.message || 'Operazione completata.');
        }

        stopJobPolling();
        setJobLocked(false);
    }

    function trackJob(job, silentAlert) {
        if (!job) {
            $('#agendaJobStatusBox').hide();
            setJobLocked(false);
            return;
        }

        renderJobStatus(job);
        currentJobId = parseInt(job.id_job, 10) || null;

        if (!isJobActive(job)) {
            handleTerminalJob(job, silentAlert);
            return;
        }

        setJobLocked(true);

        if (jobPollTimer) {
            clearInterval(jobPollTimer);
        }

        jobPollTimer = setInterval(function () {
            if (!currentJobId) {
                stopJobPolling();
                return;
            }

            fetchJobStatus({ id_job: currentJobId }, function (nextJob) {
                if (!nextJob) {
                    stopJobPolling();
                    setJobLocked(false);
                    $('#agendaJobStatusBox').hide();
                    return;
                }

                renderJobStatus(nextJob);
                if (!isJobActive(nextJob)) {
                    handleTerminalJob(nextJob, false);
                }
            });
        }, 4000);
    }

    function loadActiveJobForSelectedDoctor() {
        var idDot = parseInt($('#id_dot').val(), 10) || 0;
        if (idDot <= 0) {
            return;
        }

        fetchJobStatus({ id_dot: idDot }, function (job) {
            if (!job) {
                $('#agendaJobStatusBox').hide();
                setJobLocked(false);
                return;
            }

            trackJob(job, true);
        });
    }

    function pad(value) {
        return value.toString().length === 1 ? '0' + value : value.toString();
    }

    function normalizeTime(value) {
        if (!value) {
            return '';
        }

        var parts = value.split(':');
        if (parts.length < 2) {
            return value;
        }

        return pad(parseInt(parts[0], 10) || 0) + ':' + pad(parseInt(parts[1], 10) || 0);
    }

    function addMinutes(value, minutes) {
        var normalized = normalizeTime(value);
        if (!normalized) {
            return '09:00';
        }

        var parts = normalized.split(':');
        var total = ((parseInt(parts[0], 10) || 0) * 60) + (parseInt(parts[1], 10) || 0) + minutes;
        if (total < 0) {
            total = 0;
        }
        if (total > (23 * 60 + 59)) {
            total = 23 * 60 + 59;
        }

        return pad(Math.floor(total / 60)) + ':' + pad(total % 60);
    }

    function normalizeText(value) {
        return $.trim(value == null ? '' : String(value));
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function normalizeLookupKey(value) {
        var text = normalizeText(value);
        return text ? text.toLocaleLowerCase() : '';
    }

    function getRoomsForAmbulatorio(idAmb) {
        var row = locationCatalogById[idAmb] || null;
        return row && Array.isArray(row.stanze) ? row.stanze : [];
    }

    function findAmbulatorioByName(name) {
        var lookup = normalizeLookupKey(name);
        var match = null;

        if (!lookup) {
            return null;
        }

        $.each(locationCatalog, function (_, locationRow) {
            if (normalizeLookupKey(locationRow.nome) === lookup) {
                match = locationRow;
                return false;
            }
        });

        return match;
    }

    function findRoomByName(idAmb, name) {
        var lookup = normalizeLookupKey(name);
        var match = null;

        if (!lookup || idAmb <= 0) {
            return null;
        }

        $.each(getRoomsForAmbulatorio(idAmb), function (_, roomRow) {
            if (normalizeLookupKey(roomRow.nome) === lookup) {
                match = roomRow;
                return false;
            }
        });

        return match;
    }

    function normalizeLocationValues(values) {
        var normalized = {
            id_amb_legacy: normalizeText(values && values.id_amb_legacy),
            id_stanza: normalizeText(values && values.id_stanza),
            ambulatorio: normalizeText(values && values.ambulatorio),
            stanza: normalizeText(values && values.stanza)
        };
        var ambId = parseInt(normalized.id_amb_legacy, 10) || 0;
        var roomId = parseInt(normalized.id_stanza, 10) || 0;
        var locationRow = null;
        var roomRow = null;

        if (ambId <= 0 && normalized.ambulatorio) {
            locationRow = findAmbulatorioByName(normalized.ambulatorio);
            ambId = locationRow ? (parseInt(locationRow.id_amb_legacy, 10) || 0) : 0;
        }

        if (roomId > 0) {
            roomRow = roomMetaById[roomId] || null;
            if (roomRow) {
                roomId = parseInt(roomRow.id_stanza, 10) || 0;
                if (ambId <= 0) {
                    ambId = parseInt(roomRow.id_amb_legacy, 10) || 0;
                }
            }
        }

        locationRow = locationCatalogById[ambId] || locationRow;
        if (locationRow) {
            normalized.ambulatorio = normalizeText(locationRow.nome);
        }

        if (!roomRow && ambId > 0 && normalized.stanza) {
            roomRow = findRoomByName(ambId, normalized.stanza);
            if (roomRow) {
                roomId = parseInt(roomRow.id_stanza, 10) || 0;
            }
        }

        if (roomRow) {
            normalized.stanza = normalizeText(roomRow.nome);
        }

        normalized.id_amb_legacy = ambId > 0 ? String(ambId) : '';
        normalized.id_stanza = roomId > 0 ? String(roomId) : '';

        return normalized;
    }

    function buildAmbulatorioOptions(selectedAmbId) {
        var html = '<option value="">Seleziona sede</option>';

        $.each(locationCatalog, function (_, locationRow) {
            var optionValue = String(parseInt(locationRow.id_amb_legacy, 10) || 0);
            if (!optionValue || optionValue === '0') {
                return;
            }

            html += '<option value="' + escapeHtml(optionValue) + '"' +
                (String(selectedAmbId) === optionValue ? ' selected' : '') +
                '>' + escapeHtml(normalizeText(locationRow.nome)) + '</option>';
        });

        return html;
    }

    function buildStanzaOptions(idAmb, selectedRoomId) {
        var rooms = getRoomsForAmbulatorio(idAmb);
        var placeholder = 'Prima scegli la sede';
        var html = '';

        if (idAmb > 0) {
            placeholder = rooms.length ? 'Seleziona stanza' : 'Nessuna stanza disponibile';
        }

        html += '<option value="">' + escapeHtml(placeholder) + '</option>';

        $.each(rooms, function (_, roomRow) {
            var optionValue = String(parseInt(roomRow.id_stanza, 10) || 0);
            if (!optionValue || optionValue === '0') {
                return;
            }

            html += '<option value="' + escapeHtml(optionValue) + '"' +
                (String(selectedRoomId) === optionValue ? ' selected' : '') +
                '>' + escapeHtml(normalizeText(roomRow.nome)) + '</option>';
        });

        return {
            html: html,
            disabled: idAmb <= 0 || rooms.length === 0
        };
    }

    function syncLocationRow($row, options) {
        options = $.extend({
            preserveLegacyText: false
        }, options || {});

        var $ambSelect = $row.find('select[data-field="id_amb_legacy"]');
        var $roomSelect = $row.find('select[data-field="id_stanza"]');
        var $ambHidden = $row.find('input[data-field="ambulatorio"]');
        var $roomHidden = $row.find('input[data-field="stanza"]');
        var values = normalizeLocationValues({
            id_amb_legacy: $ambSelect.val(),
            id_stanza: $roomSelect.val(),
            ambulatorio: $ambHidden.val(),
            stanza: $roomHidden.val()
        });
        var idAmb = parseInt(values.id_amb_legacy, 10) || 0;
        var stanzaOptions = null;
        var roomRow = null;

        $ambSelect.html(buildAmbulatorioOptions(values.id_amb_legacy));
        $ambSelect.val(values.id_amb_legacy);

        stanzaOptions = buildStanzaOptions(idAmb, values.id_stanza);
        $roomSelect.html(stanzaOptions.html);
        $roomSelect.val(values.id_stanza);
        if ($roomSelect.val() !== String(values.id_stanza)) {
            values.id_stanza = '';
        }

        if (values.id_amb_legacy) {
            $ambHidden.val((locationCatalogById[idAmb] || {}).nome || values.ambulatorio);
        } else {
            $ambHidden.val(options.preserveLegacyText ? values.ambulatorio : '');
        }

        if (values.id_stanza) {
            roomRow = roomMetaById[parseInt(values.id_stanza, 10) || 0] || null;
        }
        if (roomRow) {
            $roomHidden.val(roomRow.nome || values.stanza);
        } else {
            $roomHidden.val(options.preserveLegacyText ? values.stanza : '');
        }

        $roomSelect.prop('disabled', stanzaOptions.disabled);
    }

    function updateLocationDisabledState($row, disabled) {
        $row.find('select[data-field="id_amb_legacy"], input[data-field="ambulatorio"], input[data-field="stanza"]').prop('disabled', disabled);

        if (disabled) {
            $row.find('select[data-field="id_stanza"]').prop('disabled', true);
            return;
        }

        var idAmb = parseInt($row.find('select[data-field="id_amb_legacy"]').val(), 10) || 0;
        $row.find('select[data-field="id_stanza"]').prop('disabled', idAmb <= 0 || getRoomsForAmbulatorio(idAmb).length === 0);
    }

    function buildRangeRow(day, index, values) {
        values = normalizeLocationValues(values || {});
        var stanzaOptions = buildStanzaOptions(parseInt(values.id_amb_legacy, 10) || 0, values.id_stanza);

        return '' +
            '<div class="fascia-row" data-index="' + index + '">' +
                '<div class="form-group">' +
                    '<label class="fascia-label">Inizio</label>' +
                    '<input type="time" class="form-control" data-field="ora_inizio" name="giorni[' + day + '][fasce][' + index + '][ora_inizio]" value="' + (values.ora_inizio || '08:00') + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="fascia-label">Fine</label>' +
                    '<input type="time" class="form-control" data-field="ora_fine" name="giorni[' + day + '][fasce][' + index + '][ora_fine]" value="' + (values.ora_fine || '12:00') + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="fascia-label">Durata slot</label>' +
                    '<input type="number" min="1" step="1" class="form-control" data-field="durata_slot" name="giorni[' + day + '][fasce][' + index + '][durata_slot]" value="' + (values.durata_slot || '15') + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="fascia-label">Sede</label>' +
                    '<select class="form-control" data-field="id_amb_legacy" name="giorni[' + day + '][fasce][' + index + '][id_amb_legacy]">' +
                        buildAmbulatorioOptions(values.id_amb_legacy) +
                    '</select>' +
                    '<input type="hidden" data-field="ambulatorio" name="giorni[' + day + '][fasce][' + index + '][ambulatorio]" value="' + escapeHtml(values.ambulatorio || '') + '">' +
                '</div>' +
                '<div class="form-group">' +
                    '<label class="fascia-label">Stanza</label>' +
                    '<select class="form-control" data-field="id_stanza" name="giorni[' + day + '][fasce][' + index + '][id_stanza]"' + (stanzaOptions.disabled ? ' disabled' : '') + '>' +
                        stanzaOptions.html +
                    '</select>' +
                    '<input type="hidden" data-field="stanza" name="giorni[' + day + '][fasce][' + index + '][stanza]" value="' + escapeHtml(values.stanza || '') + '">' +
                '</div>' +
                '<div class="fascia-actions">' +
                    '<button type="button" class="btn btn-success btn-add-range" title="Aggiungi fascia"><i class="fa fa-plus"></i></button>' +
                    '<button type="button" class="btn btn-danger btn-remove-range" title="Rimuovi fascia"><i class="fa fa-minus"></i></button>' +
                '</div>' +
            '</div>';
    }

    function renumberDayRanges($dayCard) {
        var day = $dayCard.data('day');

        $dayCard.find('.fascia-row').each(function (index) {
            $(this).attr('data-index', index);
            $(this).find('[data-field]').each(function () {
                var field = $(this).data('field');
                $(this).attr('name', 'giorni[' + day + '][fasce][' + index + '][' + field + ']');
            });
        });
    }

    function getSuggestedValues($dayCard, $sourceRow) {
        var $reference = $sourceRow && $sourceRow.length ? $sourceRow : $dayCard.find('.fascia-row').last();
        var start = '08:00';
        var end = '12:00';
        var duration = '15';

        if ($reference.length) {
            start = normalizeTime($reference.find('[data-field="ora_fine"]').val()) || '08:00';
            end = addMinutes(start, 60);
            duration = $reference.find('[data-field="durata_slot"]').val() || '15';
        }

        return {
            ora_inizio: start,
            ora_fine: end,
            durata_slot: duration,
            id_amb_legacy: $reference.find('[data-field="id_amb_legacy"]').val() || '',
            id_stanza: $reference.find('[data-field="id_stanza"]').val() || '',
            ambulatorio: $reference.find('[data-field="ambulatorio"]').val() || '',
            stanza: $reference.find('[data-field="stanza"]').val() || ''
        };
    }

    function ensureAtLeastOneRange($dayCard) {
        if ($dayCard.find('.fascia-row').length > 0) {
            return;
        }

        var day = $dayCard.data('day');
        $dayCard.find('.fasce-list').append(buildRangeRow(day, 0, {
            ora_inizio: '08:00',
            ora_fine: '12:00',
            durata_slot: '15',
            id_amb_legacy: '',
            id_stanza: '',
            ambulatorio: '',
            stanza: ''
        }));
    }

    function refreshDayCard($dayCard) {
        var isFree = $dayCard.find('.giorno-libero-toggle').is(':checked');

        ensureAtLeastOneRange($dayCard);
        renumberDayRanges($dayCard);
        $dayCard.find('.fascia-row').each(function () {
            syncLocationRow($(this), { preserveLegacyText: true });
        });

        $dayCard.find('.day-ranges-body .fasce-list, .day-ranges-body .range-summary-note').toggle(!isFree);
        $dayCard.find('.agenda-day-empty').toggle(isFree);
        $dayCard.find('.day-ranges-body').find('input[type="time"], input[type="number"]').prop('disabled', isFree);
        $dayCard.find('.fascia-row').each(function () {
            updateLocationDisabledState($(this), isFree);
        });

        var rowCount = $dayCard.find('.fascia-row').length;
        $dayCard.find('.btn-remove-range').prop('disabled', rowCount <= 1);
    }

    $('.agenda-day-card').each(function () {
        refreshDayCard($(this));
    });

    $(document).on('change', '.giorno-libero-toggle', function () {
        refreshDayCard($(this).closest('.agenda-day-card'));
    });

    $(document).on('click', '.btn-add-range', function () {
        var $dayCard = $(this).closest('.agenda-day-card');
        var $sourceRow = $(this).closest('.fascia-row');
        var day = $dayCard.data('day');
        var nextIndex = $dayCard.find('.fascia-row').length;
        var values = getSuggestedValues($dayCard, $sourceRow);
        var $newRow = $(buildRangeRow(day, nextIndex, values));

        if ($sourceRow.length) {
            $newRow.insertAfter($sourceRow);
        } else {
            $dayCard.find('.fasce-list').append($newRow);
        }

        refreshDayCard($dayCard);
        $newRow.find('[data-field="ora_inizio"]').focus();
    });

    $(document).on('change', 'select[data-field="id_amb_legacy"]', function () {
        syncLocationRow($(this).closest('.fascia-row'));
    });

    $(document).on('change', 'select[data-field="id_stanza"]', function () {
        syncLocationRow($(this).closest('.fascia-row'));
    });

    $(document).on('click', '.btn-remove-range', function () {
        var $dayCard = $(this).closest('.agenda-day-card');
        var $rows = $dayCard.find('.fascia-row');

        if ($rows.length <= 1) {
            return;
        }

        $(this).closest('.fascia-row').remove();
        refreshDayCard($dayCard);
    });

    loadActiveJobForSelectedDoctor();

    $('#btnSalvaEGeneraAgenda').on('click', function () {
        if (!confirm('Vuoi salvare la configurazione e generare subito l\'agenda? Se nel periodo selezionato esistono gia slot o appuntamenti, il sistema creera un file di backup e rigenerera tutto. Per periodi molto ampi il backup verra salvato in CSV.')) {
            return;
        }

        var payload = $('#formConfigSlot').serialize();
        setLoading(true, 'Salvataggio configurazione e generazione agenda in corso...');

        $.post("<?= base_url('agenda/rigenera-slot-config') ?>", payload, function (res) {
            if (res && res.job) {
                trackJob(res.job, true);
            }

            alert(res.message || 'Operazione avviata.');
        }, 'json').fail(function () {
            alert('Errore durante il salvataggio e la generazione dell\'agenda.');
        }).always(function () {
            setLoading(false);
        });
    });

    $('#id_dot').on('change', function () {
        window.location.href = "<?= base_url('agenda/config-slot') ?>?id_dot=" + $(this).val();
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>
