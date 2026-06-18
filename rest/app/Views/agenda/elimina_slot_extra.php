<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Elimina slot extra | Agenda</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
        .table td, .table th {
            vertical-align: middle !important;
        }

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

        .pagination-wrapper {
            text-align: center;
            margin-top: 15px;
        }

        .warning-box {
            margin-top: 15px;
        }

        .small-pre {
            max-height: 220px;
            overflow: auto;
            white-space: pre-wrap;
            background: #f7f7f7;
            border: 1px solid #ddd;
            padding: 10px;
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
            <h1>Elimina slot extra</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Elimina slot extra</li>
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
                    <div class="box box-danger">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-trash"></i> Gestione eliminazione slot extra</h3>
                        </div>

                        <div class="box-body">
                            <form id="formRicercaSlotExtra">
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

                                    <div class="col-md-3 form-group">
                                        <label>Data inizio</label>
                                        <input type="date" name="data_inizio" id="data_inizio" class="form-control">
                                    </div>

                                    <div class="col-md-3 form-group">
                                        <label>Data fine</label>
                                        <input type="date" name="data_fine" id="data_fine" class="form-control">
                                    </div>

                                    <div class="col-md-2 form-group">
                                        <label>&nbsp;</label>
                                        <button type="button" id="btnCercaSlotExtra" class="btn btn-primary form-control">
                                            Cerca
                                        </button>
                                    </div>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped" id="tabellaSlotExtra">
                                    <thead>
                                        <tr>
                                            <th style="width:40px;">
                                                <input type="checkbox" id="checkAllSlots">
                                            </th>
                                            <th>Data</th>
                                            <th>Ora inizio</th>
                                            <th>Ora fine</th>
                                            <th>Ambulatorio</th>
                                            <th>Stanza</th>
                                            <th>Stato</th>
                                            <th>Appuntamenti</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">Nessun dato caricato</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <button type="button" id="btnEliminaSelezionati" class="btn btn-danger">
                                        <i class="fa fa-trash"></i> Elimina selezionati
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <div class="pagination-wrapper" id="paginationWrapper"></div>
                                </div>
                            </div>

                            <div id="warningAppointments" class="warning-box" style="display:none;">
                                <div class="alert alert-warning">
                                    <strong>Attenzione:</strong> alcuni slot selezionati hanno appuntamenti attivi.
                                    Se continui, verranno eliminati anche gli appuntamenti collegati.
                                </div>
                                <div class="small-pre" id="warningAppointmentsText"></div>
                                <div style="margin-top:10px;">
                                    <button type="button" id="btnConfermaEliminazioneForzata" class="btn btn-warning">
                                        Continua comunque
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        Vengono mostrati solo gli slot con <strong>origine_slot = EXTRA</strong>.
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <i class="fa fa-spinner fa-spin"></i>
        <span id="loadingText">Elaborazione in corso...</span>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>

<script>
$(function () {
    var currentPage = 1;
    var lastPage = 1;
    var pendingForceDeleteIds = [];

    function setLoading(on, text) {
        if (on) {
            $('#loadingText').text(text || 'Elaborazione in corso...');
            $('#loadingOverlay').css('display', 'flex');
            $('button, input, select').prop('disabled', true);
            return;
        }

        $('#loadingOverlay').hide();
        $('button, input, select').prop('disabled', false);
    }

    function escapeHtml(text) {
        return $('<div>').text(text == null ? '' : text).html();
    }

    function getSelectedSlotIds() {
        var ids = [];
        $('.chk-slot:checked').each(function () {
            ids.push($(this).val());
        });
        return ids;
    }

    function renderTable(rows) {
        var $tbody = $('#tabellaSlotExtra tbody');
        $tbody.empty();

        if (!rows || !rows.length) {
            $tbody.html('<tr><td colspan="8" class="text-center text-muted">Nessuno slot extra trovato</td></tr>');
            return;
        }

        $.each(rows, function (_, row) {
            var tr = '';
            tr += '<tr>';
            tr += '<td><input type="checkbox" class="chk-slot" value="' + escapeHtml(row.id_slot) + '"></td>';
            tr += '<td>' + escapeHtml(row.data_slot || '') + '</td>';
            tr += '<td>' + escapeHtml((row.ora_inizio || '').substring(11,16)) + '</td>';
            tr += '<td>' + escapeHtml((row.ora_fine || '').substring(11,16)) + '</td>';
            tr += '<td>' + escapeHtml(row.ambulatorio || '') + '</td>';
            tr += '<td>' + escapeHtml(row.stanza || '') + '</td>';
            tr += '<td>' + escapeHtml(row.stato || '') + '</td>';
            tr += '<td>' + escapeHtml(row.appuntamenti_attivi || 0) + '</td>';
            tr += '</tr>';
            $tbody.append(tr);
        });
    }

    function renderPagination(page, last) {
        var html = '';
        if (last <= 1) {
            $('#paginationWrapper').html('');
            return;
        }

        var start = Math.max(1, page - 2);
        var end = Math.min(last, page + 2);

        html += '<ul class="pagination pagination-sm" style="margin:0;">';
        html += '<li class="' + (page <= 1 ? 'disabled' : '') + '"><a href="#" data-page="1">«</a></li>';
        html += '<li class="' + (page <= 1 ? 'disabled' : '') + '"><a href="#" data-page="' + Math.max(1, page - 1) + '">‹</a></li>';

        for (var i = start; i <= end; i++) {
            html += '<li class="' + (i === page ? 'active' : '') + '"><a href="#" data-page="' + i + '">' + i + '</a></li>';
        }

        html += '<li class="' + (page >= last ? 'disabled' : '') + '"><a href="#" data-page="' + Math.min(last, page + 1) + '">›</a></li>';
        html += '<li class="' + (page >= last ? 'disabled' : '') + '"><a href="#" data-page="' + last + '">»</a></li>';
        html += '</ul>';

        $('#paginationWrapper').html(html);
    }

    function loadRows(page) {
        currentPage = page || 1;
        $('#warningAppointments').hide();
        pendingForceDeleteIds = [];

        setLoading(true, 'Caricamento slot extra...');

        $.get("<?= base_url('agenda/lista-slot-extra') ?>", {
            id_dot: $('#id_dot').val(),
            data_inizio: $('#data_inizio').val(),
            data_fine: $('#data_fine').val(),
            page: currentPage
        }, function (res) {
            if (!res.status) {
                alert(res.message || 'Errore nel caricamento');
                return;
            }

            renderTable(res.rows || []);
            lastPage = parseInt(res.lastPage || 1, 10);
            renderPagination(parseInt(res.page || 1, 10), lastPage);
            $('#checkAllSlots').prop('checked', false);
        }, 'json').fail(function () {
            alert('Errore durante il caricamento degli slot extra.');
        }).always(function () {
            setLoading(false);
        });
    }

    function executeDelete(slotIds, forceDelete) {
        setLoading(true, forceDelete ? 'Eliminazione forzata in corso...' : 'Eliminazione in corso...');

        $.post("<?= base_url('agenda/elimina-slot-extra-selezionati') ?>", {
            id_dot: $('#id_dot').val(),
            slot_ids: slotIds,
            force_delete: forceDelete ? 1 : 0
        }, function (res) {
            if (res.requires_confirmation) {
                var lines = [];
                $.each(res.appointments || [], function (_, row) {
                    lines.push(
                        (row.data_slot || '') + ' ' +
                        ((row.ora_inizio || '').substring(11,16)) + '-' +
                        ((row.ora_fine || '').substring(11,16)) + ' | ' +
                        (row.cognome || '') + ' ' + (row.nome || '')
                    );
                });

                pendingForceDeleteIds = res.deletable_slot_ids || [];
                $('#warningAppointmentsText').text(lines.join("\n"));
                $('#warningAppointments').show();
                alert(res.message || 'Sono presenti appuntamenti attivi.');
                return;
            }

            alert(res.message || 'Eliminazione completata.');
            $('#warningAppointments').hide();
            pendingForceDeleteIds = [];
            loadRows(currentPage);
        }, 'json').fail(function () {
            alert('Errore durante l\'eliminazione.');
        }).always(function () {
            setLoading(false);
        });
    }

    $('#btnCercaSlotExtra').on('click', function () {
        loadRows(1);
    });

    $('#id_dot').on('change', function () {
        window.location.href = "<?= base_url('agenda/elimina-slot-extra') ?>?id_dot=" + $(this).val();
    });

    $('#checkAllSlots').on('change', function () {
        $('.chk-slot').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '#paginationWrapper a[data-page]', function (e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'), 10);
        if (!page || $(this).parent().hasClass('disabled') || $(this).parent().hasClass('active')) {
            return;
        }
        loadRows(page);
    });

    $('#btnEliminaSelezionati').on('click', function () {
        var ids = getSelectedSlotIds();

        if (!ids.length) {
            alert('Seleziona almeno uno slot extra da eliminare.');
            return;
        }

        if (!confirm('Vuoi eliminare gli slot extra selezionati?')) {
            return;
        }

        executeDelete(ids, false);
    });

    $('#btnConfermaEliminazioneForzata').on('click', function () {
        if (!pendingForceDeleteIds.length) {
            return;
        }

        if (!confirm('Confermi di voler eliminare anche gli appuntamenti collegati agli slot selezionati?')) {
            return;
        }

        executeDelete(pendingForceDeleteIds, true);
    });

    loadRows(1);
});
</script>
</body>
</html>