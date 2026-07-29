<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= esc(($pageTitle ?? 'Eliminazione massiva appuntamenti') . ' | AmbulatorioFacile') ?></title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css">
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css">
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css">
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css">
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css">

    <style>
        .bulk-delete-intro {
            border-left: 4px solid #dd4b39;
        }

        .bulk-patient-search-wrap {
            position: relative;
        }

        .bulk-patient-results {
            position: absolute;
            top: 100%;
            right: 0;
            left: 0;
            z-index: 20;
            display: none;
            max-height: 280px;
            margin-top: 4px;
            overflow-y: auto;
            border: 1px solid #d2d6de;
            border-radius: 4px;
            background: #fff;
            box-shadow: 0 8px 22px rgba(31, 45, 61, 0.16);
        }

        .bulk-patient-result {
            display: block;
            width: 100%;
            padding: 10px 12px;
            border: 0;
            border-bottom: 1px solid #edf1f4;
            background: #fff;
            color: #333;
            text-align: left;
        }

        .bulk-patient-result:last-child {
            border-bottom: 0;
        }

        .bulk-patient-result:hover,
        .bulk-patient-result:focus {
            background: #f4f9fc;
            outline: none;
        }

        .bulk-patient-result-name {
            display: block;
            font-weight: 700;
        }

        .bulk-patient-result-meta {
            display: block;
            margin-top: 2px;
            color: #6c7a86;
            font-size: 12px;
        }

        .bulk-selected-patient {
            display: none;
            margin: 15px 0;
            padding: 13px 15px;
            border: 1px solid #b8d7ea;
            border-radius: 5px;
            background: #f4fafd;
        }

        .bulk-selected-patient-name {
            color: #245269;
            font-size: 16px;
            font-weight: 700;
        }

        .bulk-selected-patient-meta {
            margin-top: 3px;
            color: #607d8b;
        }

        .bulk-appointments-table th,
        .bulk-appointments-table td {
            vertical-align: middle !important;
        }

        .bulk-appointments-table .bulk-appointment-note {
            min-width: 180px;
            white-space: normal;
        }

        .bulk-selection-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #ecf0f5;
        }

        .bulk-selection-count {
            color: #63717d;
            font-weight: 600;
        }

        .bulk-loading {
            display: none;
            margin-left: 8px;
            color: #607d8b;
        }

        .bulk-confirm-summary {
            margin-bottom: 15px;
            padding: 12px;
            border-radius: 4px;
            background: #fff4f2;
            color: #9f3125;
        }

        @media (max-width: 767px) {
            .bulk-selection-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .bulk-selection-bar .btn {
                width: 100%;
            }
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
            <h1>Eliminazione massiva appuntamenti</h1>
            <ol class="breadcrumb">
                <li><a href="<?= base_url('agenda') ?>"><i class="fa fa-calendar"></i> Agenda</a></li>
                <li class="active">Elimina appuntamenti</li>
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
                    <div class="callout callout-danger bulk-delete-intro">
                        <h4><i class="fa fa-exclamation-triangle"></i> Operazione riservata agli amministratori</h4>
                        <p>
                            Cerca un paziente, controlla gli appuntamenti futuri presenti nello spazio e seleziona
                            soltanto quelli da eliminare. Gli slot collegati verranno liberati automaticamente.
                        </p>
                    </div>

                    <div id="bulkStatusMessage" class="alert" style="display:none;"></div>

                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-search"></i> Cerca paziente</h3>
                        </div>
                        <div class="box-body">
                            <div class="row">
                                <div class="col-sm-9">
                                    <label for="bulkPatientSearch">Paziente</label>
                                    <div class="bulk-patient-search-wrap">
                                        <input
                                            type="text"
                                            id="bulkPatientSearch"
                                            class="form-control"
                                            autocomplete="off"
                                            placeholder="Cognome, nome, codice fiscale, telefono..."
                                        >
                                        <div id="bulkPatientResults" class="bulk-patient-results"></div>
                                    </div>
                                    <p class="help-block">Digita almeno 2 caratteri. Sono proposti solo pazienti con appuntamenti futuri.</p>
                                </div>
                                <div class="col-sm-3">
                                    <label>&nbsp;</label>
                                    <button type="button" id="btnClearBulkPatient" class="btn btn-default btn-block" disabled>
                                        <i class="fa fa-times"></i> Pulisci ricerca
                                    </button>
                                </div>
                            </div>

                            <input type="hidden" id="bulkPatientId" value="">

                            <div id="bulkSelectedPatient" class="bulk-selected-patient">
                                <div id="bulkSelectedPatientName" class="bulk-selected-patient-name"></div>
                                <div id="bulkSelectedPatientMeta" class="bulk-selected-patient-meta"></div>
                            </div>
                        </div>
                    </div>

                    <div class="box box-danger">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-calendar-times-o"></i> Appuntamenti futuri</h3>
                            <span id="bulkLoading" class="bulk-loading">
                                <i class="fa fa-spinner fa-spin"></i> Caricamento...
                            </span>
                        </div>
                        <div class="box-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped bulk-appointments-table">
                                    <thead>
                                        <tr>
                                            <th style="width:42px;">
                                                <input type="checkbox" id="bulkCheckAll" disabled>
                                            </th>
                                            <th>Data</th>
                                            <th>Orario</th>
                                            <th>Professionista</th>
                                            <th>Tipo / motivo</th>
                                            <th>Note</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bulkAppointmentsBody">
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">
                                                Cerca e seleziona un paziente per caricare gli appuntamenti.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="bulk-selection-bar">
                                <div id="bulkSelectionCount" class="bulk-selection-count">Nessun appuntamento selezionato</div>
                                <button type="button" id="btnOpenBulkDelete" class="btn btn-danger" disabled>
                                    <i class="fa fa-trash"></i> Elimina appuntamenti selezionati
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<form id="bulkDeleteSecurityForm" style="display:none;">
    <?= csrf_field() ?>
</form>

<div class="modal fade" id="bulkDeleteConfirmModal" tabindex="-1" role="dialog" aria-labelledby="bulkDeleteConfirmTitle">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Chiudi">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="bulkDeleteConfirmTitle">
                    <i class="fa fa-exclamation-triangle text-red"></i> Conferma eliminazione
                </h4>
            </div>
            <div class="modal-body">
                <div id="bulkConfirmSummary" class="bulk-confirm-summary"></div>
                <p>
                    Gli appuntamenti verranno annullati e gli slot dell’agenda torneranno disponibili.
                    Questa operazione non può essere annullata da questa schermata.
                </p>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" id="bulkDeleteAcknowledgement">
                        Ho verificato il paziente e gli appuntamenti selezionati.
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Annulla</button>
                <button type="button" id="btnConfirmBulkDelete" class="btn btn-danger" disabled>
                    <i class="fa fa-trash"></i> Elimina definitivamente
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>

<script>
$(function () {
    var patientSearchTimer = null;
    var patientSearchRequest = null;
    var appointmentsRequest = null;
    var selectedPatient = null;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function patientLabel(row) {
        var special = $.trim(String(row.paz_spec || ''));
        var label = $.trim(String(row.label || ''));
        if (label === '') {
            label = $.trim(String(row.cognome || '') + ' ' + String(row.nome || ''));
        }
        return special !== '' ? special : (label || 'Paziente');
    }

    function patientMeta(row) {
        var parts = [];
        var fiscalCode = $.trim(String(row.cod_fis || ''));
        var phone = $.trim(String(row.cellulare || row.telefono || ''));
        var email = $.trim(String(row.email || ''));

        if (fiscalCode !== '') {
            parts.push('CF: ' + fiscalCode);
        }
        if (phone !== '') {
            parts.push(phone);
        }
        if (email !== '') {
            parts.push(email);
        }

        return parts.join(' · ');
    }

    function showStatus(type, message) {
        $('#bulkStatusMessage')
            .removeClass('alert-success alert-danger alert-warning alert-info')
            .addClass('alert-' + type)
            .text(message)
            .show();
    }

    function hideStatus() {
        $('#bulkStatusMessage').hide().text('');
    }

    function setLoading(isLoading) {
        $('#bulkLoading').toggle(!!isLoading);
        $('#bulkPatientSearch, #btnClearBulkPatient, .bulk-appointment-check, #bulkCheckAll, #btnOpenBulkDelete')
            .prop('disabled', !!isLoading);

        if (!isLoading) {
            $('#btnClearBulkPatient').prop('disabled', !selectedPatient);
            updateSelection();
        }
    }

    function renderPatientResults(rows) {
        var html = '';

        if (!rows || !rows.length) {
            html = '<div class="bulk-patient-result text-muted">Nessun paziente con appuntamenti futuri trovato.</div>';
            $('#bulkPatientResults').html(html).show();
            return;
        }

        $.each(rows, function (index, row) {
            html += '<button type="button" class="bulk-patient-result" data-index="' + index + '">';
            html += '<span class="bulk-patient-result-name">' + escapeHtml(patientLabel(row)) + '</span>';
            if (patientMeta(row) !== '') {
                html += '<span class="bulk-patient-result-meta">' + escapeHtml(patientMeta(row)) + '</span>';
            }
            html += '</button>';
        });

        $('#bulkPatientResults').data('rows', rows).html(html).show();
    }

    function formatDate(value) {
        var parts = String(value || '').substring(0, 10).split('-');
        if (parts.length !== 3) {
            return value || '';
        }
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function formatTime(row) {
        var start = $.trim(String(row.ora_inizio_label || ''));
        var end = $.trim(String(row.ora_fine_label || ''));
        if (start === '') {
            start = String(row.ora_inizio || '').substring(11, 16);
        }
        if (end === '') {
            end = String(row.ora_fine || '').substring(11, 16);
        }
        return end !== '' && end !== start ? start + ' - ' + end : start;
    }

    function appointmentType(row) {
        return $.trim(String(row.tipo_visita_label || row.motivo_visita || ''));
    }

    function renderAppointments(rows) {
        var html = '';

        if (!rows || !rows.length) {
            html = '<tr><td colspan="6" class="text-center text-muted">Nessun appuntamento futuro per questo paziente.</td></tr>';
            $('#bulkAppointmentsBody').html(html);
            $('#bulkCheckAll').prop('checked', false).prop('disabled', true);
            updateSelection();
            return;
        }

        $.each(rows, function (_, row) {
            html += '<tr>';
            html += '<td><input type="checkbox" class="bulk-appointment-check" value="' + escapeHtml(row.id_appuntamento) + '"></td>';
            html += '<td><strong>' + escapeHtml(formatDate(row.data_slot)) + '</strong></td>';
            html += '<td>' + escapeHtml(formatTime(row)) + '</td>';
            html += '<td>' + escapeHtml(row.doctor_label || '') + '</td>';
            html += '<td>' + escapeHtml(appointmentType(row) || '—') + '</td>';
            html += '<td class="bulk-appointment-note">' + escapeHtml(row.note || '—') + '</td>';
            html += '</tr>';
        });

        $('#bulkAppointmentsBody').html(html);
        $('#bulkCheckAll').prop('checked', false).prop('disabled', false);
        updateSelection();
    }

    function selectedAppointmentIds() {
        var ids = [];
        $('.bulk-appointment-check:checked').each(function () {
            var id = parseInt($(this).val(), 10);
            if (id > 0) {
                ids.push(id);
            }
        });
        return ids;
    }

    function updateSelection() {
        var total = $('.bulk-appointment-check').length;
        var selected = selectedAppointmentIds().length;
        var label = selected === 1
            ? '1 appuntamento selezionato'
            : selected + ' appuntamenti selezionati';

        $('#bulkSelectionCount').text(selected > 0 ? label : 'Nessun appuntamento selezionato');
        $('#btnOpenBulkDelete').prop('disabled', selected === 0);
        $('#bulkCheckAll').prop('checked', total > 0 && selected === total);
    }

    function loadAppointments() {
        var patientId = parseInt($('#bulkPatientId').val(), 10);
        if (!patientId) {
            return;
        }

        if (appointmentsRequest && appointmentsRequest.readyState !== 4) {
            appointmentsRequest.abort();
        }

        setLoading(true);
        appointmentsRequest = $.get("<?= base_url('agenda/elimina-appuntamenti-massivo/appuntamenti') ?>", {
            id_paziente: patientId
        }, function (res) {
            if (!res.status) {
                showStatus('danger', res.message || 'Errore durante il caricamento degli appuntamenti.');
                renderAppointments([]);
                return;
            }
            renderAppointments(res.rows || []);
        }, 'json').fail(function (xhr, status) {
            if (status === 'abort') {
                return;
            }
            var message = xhr.responseJSON && xhr.responseJSON.message
                ? xhr.responseJSON.message
                : 'Errore durante il caricamento degli appuntamenti.';
            showStatus('danger', message);
            renderAppointments([]);
        }).always(function () {
            setLoading(false);
        });
    }

    function selectPatient(row) {
        selectedPatient = row;
        $('#bulkPatientId').val(parseInt(row.id_paziente, 10) || 0);
        $('#bulkPatientSearch').val(patientLabel(row));
        $('#bulkSelectedPatientName').text(patientLabel(row));
        $('#bulkSelectedPatientMeta').text(patientMeta(row) || 'Paziente selezionato');
        $('#bulkSelectedPatient').show();
        $('#bulkPatientResults').hide().empty().removeData('rows');
        $('#btnClearBulkPatient').prop('disabled', false);
        hideStatus();
        loadAppointments();
    }

    function clearPatient() {
        selectedPatient = null;
        $('#bulkPatientId').val('');
        $('#bulkPatientSearch').val('').focus();
        $('#bulkSelectedPatient').hide();
        $('#bulkSelectedPatientName, #bulkSelectedPatientMeta').text('');
        $('#bulkPatientResults').hide().empty().removeData('rows');
        $('#bulkAppointmentsBody').html(
            '<tr><td colspan="6" class="text-center text-muted">Cerca e seleziona un paziente per caricare gli appuntamenti.</td></tr>'
        );
        $('#bulkCheckAll').prop('checked', false).prop('disabled', true);
        $('#btnClearBulkPatient').prop('disabled', true);
        hideStatus();
        updateSelection();
    }

    $('#bulkPatientSearch').on('input', function () {
        var term = $.trim($(this).val());

        if (selectedPatient && term !== patientLabel(selectedPatient)) {
            selectedPatient = null;
            $('#bulkPatientId').val('');
            $('#bulkSelectedPatient').hide();
            $('#btnClearBulkPatient').prop('disabled', true);
            renderAppointments([]);
        }

        window.clearTimeout(patientSearchTimer);
        if (term.length < 2) {
            $('#bulkPatientResults').hide().empty();
            return;
        }

        patientSearchTimer = window.setTimeout(function () {
            if (patientSearchRequest && patientSearchRequest.readyState !== 4) {
                patientSearchRequest.abort();
            }

            patientSearchRequest = $.get("<?= base_url('agenda/elimina-appuntamenti-massivo/cerca-pazienti') ?>", {
                term: term
            }, function (res) {
                if (!res.status) {
                    showStatus('danger', res.message || 'Errore durante la ricerca del paziente.');
                    return;
                }
                renderPatientResults(res.rows || []);
            }, 'json').fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                showStatus('danger', 'Errore durante la ricerca del paziente.');
            });
        }, 250);
    });

    $(document).on('click', '.bulk-patient-result[data-index]', function () {
        var rows = $('#bulkPatientResults').data('rows') || [];
        var row = rows[parseInt($(this).attr('data-index'), 10)];
        if (row) {
            selectPatient(row);
        }
    });

    $(document).on('click', function (event) {
        if (!$(event.target).closest('.bulk-patient-search-wrap').length) {
            $('#bulkPatientResults').hide();
        }
    });

    $('#btnClearBulkPatient').on('click', clearPatient);

    $('#bulkCheckAll').on('change', function () {
        $('.bulk-appointment-check').prop('checked', $(this).is(':checked'));
        updateSelection();
    });

    $(document).on('change', '.bulk-appointment-check', updateSelection);

    $('#btnOpenBulkDelete').on('click', function () {
        var ids = selectedAppointmentIds();
        if (!selectedPatient || !ids.length) {
            return;
        }

        $('#bulkConfirmSummary').text(
            'Stai per eliminare ' + ids.length + (ids.length === 1 ? ' appuntamento di ' : ' appuntamenti di ') +
            patientLabel(selectedPatient) + '.'
        );
        $('#bulkDeleteAcknowledgement').prop('checked', false);
        $('#btnConfirmBulkDelete').prop('disabled', true);
        $('#bulkDeleteConfirmModal').modal('show');
    });

    $('#bulkDeleteAcknowledgement').on('change', function () {
        $('#btnConfirmBulkDelete').prop('disabled', !$(this).is(':checked'));
    });

    $('#btnConfirmBulkDelete').on('click', function () {
        var ids = selectedAppointmentIds();
        var patientId = parseInt($('#bulkPatientId').val(), 10);
        var csrfInput = $('#bulkDeleteSecurityForm input[type="hidden"]').first();
        var payload = {
            id_paziente: patientId,
            appointment_ids: ids
        };

        if (csrfInput.length) {
            payload[csrfInput.attr('name')] = csrfInput.val();
        }

        if (!patientId || !ids.length || !$('#bulkDeleteAcknowledgement').is(':checked')) {
            return;
        }

        $('#btnConfirmBulkDelete').prop('disabled', true).html(
            '<i class="fa fa-spinner fa-spin"></i> Eliminazione...'
        );

        $.post("<?= base_url('agenda/elimina-appuntamenti-massivo/elimina') ?>", payload, function (res) {
            if (res.csrf_hash && csrfInput.length) {
                csrfInput.val(res.csrf_hash);
            }

            if (!res.status) {
                showStatus('danger', res.message || 'Errore durante l\'eliminazione.');
                return;
            }

            $('#bulkDeleteConfirmModal').modal('hide');
            showStatus('success', res.message || 'Appuntamenti eliminati e agenda aggiornata.');
            loadAppointments();
        }, 'json').fail(function (xhr) {
            var res = xhr.responseJSON || {};
            if (res.csrf_hash && csrfInput.length) {
                csrfInput.val(res.csrf_hash);
            }
            showStatus('danger', res.message || 'Errore durante l\'eliminazione.');
        }).always(function () {
            $('#btnConfirmBulkDelete').html(
                '<i class="fa fa-trash"></i> Elimina definitivamente'
            );
            if ($('#bulkDeleteAcknowledgement').is(':checked')) {
                $('#btnConfirmBulkDelete').prop('disabled', false);
            }
        });
    });
});
</script>
</body>
</html>
