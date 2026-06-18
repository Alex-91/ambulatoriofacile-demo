<?php
$locationCatalogList = array_values(is_array($locationCatalog ?? null) ? $locationCatalog : []);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestione slot extra | Agenda</title>
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
            background: rgba(0,0,0,.45);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .loading-box {
            background: #fff;
            border-radius: 6px;
            padding: 18px 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,.25);
            font-size: 15px;
            color: #333;
        }

        .giorni-box label {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .result-box {
            margin-top: 20px;
        }

        .result-box pre {
            white-space: pre-wrap;
            background: #f7f7f7;
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 4px;
        }
    </style>
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <h1>Gestione slot extra</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Gestione slot extra</li>
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
                            <h3 class="box-title"><i class="fa fa-plus-square"></i> Inserimento massivo slot extra</h3>
                        </div>

                        <div class="box-body">
                            <form id="formSlotExtra">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label>Dottore / Infermiere</label>
                                        <select name="id_dot" id="id_dot" class="form-control">
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

                                    <div class="col-md-4 form-group">
                                        <label>Data inizio</label>
                                        <input type="date" name="data_inizio" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Data fine</label>
                                        <input type="date" name="data_fine" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Ora inizio fascia extra</label>
                                        <input type="time" name="ora_inizio" class="form-control" value="08:00">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Ora fine fascia extra</label>
                                        <input type="time" name="ora_fine" class="form-control" value="09:00">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Durata slot extra (minuti)</label>
                                        <input type="number" name="durata_slot" class="form-control" value="15" min="1" step="1">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Sede</label>
                                        <select name="id_amb_legacy" id="slot_extra_id_amb_legacy" class="form-control">
                                            <option value="">Seleziona sede</option>
                                            <?php foreach ($locationCatalogList as $locationRow): ?>
                                                <?php $optionAmbId = (int)($locationRow['id_amb_legacy'] ?? 0); ?>
                                                <?php if ($optionAmbId <= 0): ?>
                                                    <?php continue; ?>
                                                <?php endif; ?>
                                                <option value="<?= esc($optionAmbId) ?>">
                                                    <?= esc($locationRow['nome'] ?? '') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="ambulatorio" id="slot_extra_ambulatorio">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Stanza</label>
                                        <select name="id_stanza" id="slot_extra_id_stanza" class="form-control" disabled>
                                            <option value="">Prima scegli la sede</option>
                                        </select>
                                        <input type="hidden" name="stanza" id="slot_extra_stanza">
                                    </div>

                                    <div class="col-md-12 form-group">
                                        <label>Giorni da elaborare</label>
                                        <div class="giorni-box">
                                            <label><input type="checkbox" name="giorni[]" value="1"> Lunedì</label>
                                            <label><input type="checkbox" name="giorni[]" value="2"> Martedì</label>
                                            <label><input type="checkbox" name="giorni[]" value="3"> Mercoledì</label>
                                            <label><input type="checkbox" name="giorni[]" value="4"> Giovedì</label>
                                            <label><input type="checkbox" name="giorni[]" value="5"> Venerdì</label>
                                            <label><input type="checkbox" name="giorni[]" value="6"> Sabato</label>
                                            <label><input type="checkbox" name="giorni[]" value="7"> Domenica</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <button type="button" class="btn btn-primary" id="btnEseguiSlotExtra">
                                        <i class="fa fa-play"></i> Inserisci slot extra nel periodo
                                    </button>
                                </div>
                            </form>

                            <div id="resultBox" class="result-box" style="display:none;">
                                <div class="alert alert-info">
                                    <strong>Esito elaborazione</strong>
                                </div>
                                <pre id="resultText"></pre>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning">
                        Il sistema crea slot consecutivi dalla <strong>ora inizio</strong> alla <strong>ora fine</strong> usando la <strong>durata scelta</strong>. Gli slot sovrapposti sono consentiti: vengono saltati solo quelli gia presenti con lo stesso identico orario.
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="slotExtraLoadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <i class="fa fa-spinner fa-spin"></i>
        <span id="slotExtraLoadingText">Elaborazione in corso...</span>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>

<script>
$(function () {
    var locationCatalog = <?= json_encode($locationCatalogList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var locationCatalogById = {};
    var roomMetaById = {};

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
            $('#slotExtraLoadingText').text(text || 'Elaborazione in corso...');
            $('#slotExtraLoadingOverlay').css('display', 'flex');
            $('#btnEseguiSlotExtra, #id_dot').prop('disabled', true);
            return;
        }

        $('#slotExtraLoadingOverlay').hide();
        $('#btnEseguiSlotExtra, #id_dot').prop('disabled', false);
    }

    function normalizeText(value) {
        return $.trim(value == null ? '' : String(value));
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function getRoomsForAmbulatorio(idAmb) {
        var row = locationCatalogById[idAmb] || null;
        return row && Array.isArray(row.stanze) ? row.stanze : [];
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

    function syncExtraLocation() {
        var idAmb = parseInt($('#slot_extra_id_amb_legacy').val(), 10) || 0;
        var selectedRoomId = $('#slot_extra_id_stanza').val() || '';
        var options = buildStanzaOptions(idAmb, selectedRoomId);
        var roomRow = null;
        var locationRow = locationCatalogById[idAmb] || null;

        $('#slot_extra_id_stanza').html(options.html);
        $('#slot_extra_id_stanza').val(selectedRoomId);
        if ($('#slot_extra_id_stanza').val() !== String(selectedRoomId)) {
            selectedRoomId = '';
        }

        $('#slot_extra_id_stanza').prop('disabled', options.disabled);
        $('#slot_extra_ambulatorio').val(locationRow ? normalizeText(locationRow.nome) : '');

        if (selectedRoomId) {
            roomRow = roomMetaById[parseInt(selectedRoomId, 10) || 0] || null;
        }
        $('#slot_extra_stanza').val(roomRow ? normalizeText(roomRow.nome) : '');
    }

    function renderResult(result) {
        var lines = [];

        lines.push('Giorni elaborati: ' + (result.processed_days || 0));
        lines.push('Slot inseriti: ' + (result.inserted || 0));
        lines.push('');

        if (result.inseriti && result.inseriti.length) {
            lines.push('SLOT INSERITI');
            $.each(result.inseriti, function(i, row) {
                lines.push('- ' + row.data + '  ' + row.ora_inizio + ' - ' + row.ora_fine);
            });
            lines.push('');
        }

        if (result.giorni_bloccati && result.giorni_bloccati.length) {
            lines.push('GIORNI BLOCCATI');
            $.each(result.giorni_bloccati, function(i, row) {
                lines.push('- ' + row);
            });
            lines.push('');
        }

        if (result.giorni_senza_config && result.giorni_senza_config.length) {
            lines.push('GIORNI SENZA CONFIGURAZIONE VALIDA');
            $.each(result.giorni_senza_config, function(i, row) {
                lines.push('- ' + row.data + ' -> ' + row.motivo);
            });
            lines.push('');
        }

        if (result.collisioni && result.collisioni.length) {
            lines.push('SLOT GIA PRESENTI');
            $.each(result.collisioni, function(i, row) {
                lines.push('- ' + row.data + '  ' + row.ora_inizio + ' - ' + row.ora_fine + ' -> ' + row.motivo);
            });
            lines.push('');
        }

        $('#resultText').text(lines.join("\n"));
        $('#resultBox').show();
    }

    $('#btnEseguiSlotExtra').on('click', function () {
        var giorniSelezionati = $('input[name="giorni[]"]:checked').length;
        if (!giorniSelezionati) {
            alert('Seleziona almeno un giorno della settimana.');
            return;
        }

        var oraInizio = $('input[name="ora_inizio"]').val();
        var oraFine = $('input[name="ora_fine"]').val();
        var durata = parseInt($('input[name="durata_slot"]').val(), 10) || 0;

        if (!oraInizio || !oraFine) {
            alert('Compila ora inizio e ora fine della fascia extra.');
            return;
        }

        if (oraFine <= oraInizio) {
            alert('L\'ora fine deve essere successiva all\'ora inizio.');
            return;
        }

        if (durata <= 0) {
            alert('Inserisci una durata slot valida.');
            return;
        }

        // I campi disabled non vengono inclusi nella submit.
        var payload = $('#formSlotExtra').serialize();
        setLoading(true, 'Inserimento slot extra in corso...');

        $.post("<?= base_url('agenda/esegui-slot-extra-periodo') ?>", payload, function (res) {
            alert(res.message || 'Operazione completata');

            if (res.status && res.result) {
                renderResult(res.result);
            }
        }, 'json').fail(function () {
            alert('Errore durante l\'inserimento degli slot extra.');
        }).always(function () {
            setLoading(false);
        });
    });

    $('#slot_extra_id_amb_legacy').on('change', function () {
        syncExtraLocation();
    });

    $('#slot_extra_id_stanza').on('change', function () {
        syncExtraLocation();
    });

    $('#id_dot').on('change', function () {
        window.location.href = "<?= base_url('agenda/gestione-slot-extra') ?>?id_dot=" + $(this).val();
    });

    syncExtraLocation();
});
</script>
</body>
</html>
