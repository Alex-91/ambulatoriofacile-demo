<!DOCTYPE html>
<html>
<head>
 <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <meta charset="UTF-8">
    <?php $patientPageTitle = 'Gestione pazienti'; ?>
    <title><?= esc($patientPageTitle . (' | AmbulatoriCLOUD')) ?></title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
    <style>
        .patient-list-footer {
            margin-top: 15px;
        }

        .patient-list-summary {
            color: #666;
            line-height: 30px;
        }

        .pagination-wrapper {
            text-align: right;
        }

        .pagination-wrapper .pagination {
            margin: 0;
        }

        @media (max-width: 767px) {
            .patient-list-summary,
            .pagination-wrapper {
                text-align: left;
            }

            .pagination-wrapper {
                margin-top: 10px;
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
            <h1>Gestione pazienti</h1>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-2">
                    <div class="box box-solid">
                        <div class="box-header with-border">
                            <h3 class="box-title">Menu</h3>
                        </div>
                       <div class="box-body no-padding">
    <?= view('agenda/partials/menu_laterale', ['menuAgenda' => $menuAgenda ?? []]) ?>
</div>
                    </div>
                </div>

                <div class="col-md-10">
                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title">Elenco pazienti</h3>
                            <div class="box-tools">
                                <button type="button" class="btn btn-success btn-sm" id="btnNuovoPaziente">
                                    <i class="fa fa-plus"></i> Nuovo paziente
                                </button>
                            </div>
                        </div>
                        <div class="box-body">
                            <div class="row" style="margin-bottom:15px;">
                                <div class="col-md-4">
                                    <label>Medico</label>
                                    <select id="id_dot" class="form-control">
                                        <?php foreach (($medici ?? []) as $m): ?>
                                            <?php
                                            $idDot = is_object($m) ? $m->id_dot : $m['id_dot'];
                                            $label = is_object($m)
                                                ? ($m->label ?? (($m->cognome ?? '') . ' ' . ($m->nome ?? '')))
                                                : ($m['label'] ?? (($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? '')));
                                            ?>
<option value="<?= esc($idDot) ?>" <?= ((int)$selectedDot === (int)$idDot ? 'selected' : '') ?>>                                                <?= esc($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-5">
                                    <label>Cerca</label>
                                    <input type="text" id="searchTerm" class="form-control" placeholder="Cognome, nome, codice fiscale, telefono...">
                                </div>

                                <div class="col-md-3">
                                    <label>&nbsp;</label>
                                    <button type="button" class="btn btn-primary btn-block" id="btnCercaPazienti">
                                        <i class="fa fa-search"></i> Cerca
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Cognome</th>
                                            <th>Nome</th>
                                            <th>Telefono</th>
                                            <th>Cellulare</th>
                                            <th>Email</th>
                                            <th>Cod. fiscale</th>
                                            <th style="width:140px;">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tabellaPazientiBody">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">Caricamento...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="row patient-list-footer">
                                <div class="col-sm-6">
                                    <div id="tabellaPazientiSummary" class="patient-list-summary text-muted"></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="pagination-wrapper" id="paginationWrapper"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="modal fade" id="pazienteModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title" id="pazienteModalTitle">Nuovo paziente</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="id_paziente">

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label>Cognome *</label>
                        <input type="text" id="cognome" class="form-control">
                    </div>
                    <div class="col-md-6 form-group">
                        <label>Nome *</label>
                        <input type="text" id="nome" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Data nascita</label>
                        <input type="date" id="data_nascita" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Codice fiscale</label>
                        <input type="text" id="cod_fis" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Comune nascita</label>
                        <input type="text" id="comune_nascita" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Provincia nascita</label>
                        <input type="text" id="provincia_nascita" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Telefono</label>
                        <input type="text" id="telefono" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Cellulare</label>
                        <input type="text" id="cellulare" class="form-control">
                    </div>

                    <div class="col-md-6 form-group">
                        <label>Email</label>
                        <input type="email" id="email" class="form-control">
                    </div>
                    <div class="col-md-6 form-group">
                        <label>Indirizzo</label>
                        <input type="text" id="indirizzo" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Citta</label>
                        <input type="text" id="citta" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>CAP</label>
                        <input type="text" id="cap" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Provincia</label>
                        <input type="text" id="provincia" class="form-control">
                    </div>

                    <div class="col-md-6 form-group">
                        <label>Residenza indirizzo</label>
                        <input type="text" id="residenza_indirizzo" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Residenza comune</label>
                        <input type="text" id="residenza_comune" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Residenza CAP</label>
                        <input type="text" id="residenza_cap" class="form-control">
                    </div>

                    <div class="col-md-6 form-group">
                        <label>Residenza provincia</label>
                        <input type="text" id="residenza_provincia" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Paziente speciale</label>
                        <input type="text" id="paz_spec" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Bloccato</label>
                        <select id="bloccato" class="form-control">
                            <option value="0">No</option>
                            <option value="1">Si</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger pull-left" id="btnEliminaPaziente" style="display:none;">
                    <i class="fa fa-trash"></i> Elimina
                </button>
                <button type="button" class="btn btn-default" data-dismiss="modal">Chiudi</button>
                <button type="button" class="btn btn-primary" id="btnSalvaPaziente">
                    <i class="fa fa-save"></i> Salva
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/2.4.18/js/adminlte.min.js"></script>

<script>
var currentPage = 1;
var lastPage = 1;
var patientRowsById = {};

function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : text).html();
}

function renderSummary(total, from, to) {
    total = parseInt(total || 0, 10);
    from = parseInt(from || 0, 10);
    to = parseInt(to || 0, 10);

    if (total <= 0) {
        $('#tabellaPazientiSummary').text('Nessun paziente trovato');
        return;
    }

    $('#tabellaPazientiSummary').text('Visualizzati ' + from + ' - ' + to + ' di ' + total + ' pazienti');
}

function renderPagination(page, last) {
    var html = '';

    if (!last || last <= 1) {
        $('#paginationWrapper').html('');
        return;
    }

    var start = Math.max(1, page - 2);
    var end = Math.min(last, page + 2);

    html += '<ul class="pagination pagination-sm">';
    html += '<li class="' + (page <= 1 ? 'disabled' : '') + '"><a href="#" data-page="1">&laquo;</a></li>';
    html += '<li class="' + (page <= 1 ? 'disabled' : '') + '"><a href="#" data-page="' + Math.max(1, page - 1) + '">&lsaquo;</a></li>';

    for (var i = start; i <= end; i++) {
        html += '<li class="' + (i === page ? 'active' : '') + '"><a href="#" data-page="' + i + '">' + i + '</a></li>';
    }

    html += '<li class="' + (page >= last ? 'disabled' : '') + '"><a href="#" data-page="' + Math.min(last, page + 1) + '">&rsaquo;</a></li>';
    html += '<li class="' + (page >= last ? 'disabled' : '') + '"><a href="#" data-page="' + last + '">&raquo;</a></li>';
    html += '</ul>';

    $('#paginationWrapper').html(html);
}

function resetFormPaziente() {
    $('#id_paziente').val('');
    $('#cognome,#nome,#data_nascita,#cod_fis,#comune_nascita,#provincia_nascita,#indirizzo,#citta,#cap,#provincia,#residenza_indirizzo,#residenza_comune,#residenza_cap,#residenza_provincia,#telefono,#cellulare,#email,#paz_spec').val('');
    $('#bloccato').val('0');
    $('#btnEliminaPaziente').hide();
    $('#pazienteModalTitle').text('Nuovo paziente');
}

function cachePatientRows(rows) {
    patientRowsById = {};

    $.each(rows || [], function(_, row) {
        var id = parseInt((row && row.id_paziente) || 0, 10) || 0;
        if (!id) {
            return;
        }

        patientRowsById[id] = row;
    });
}

function fillPazienteForm(row) {
    $('#id_paziente').val(row.id_paziente || '');
    $('#cognome').val(row.cognome || '');
    $('#nome').val(row.nome || '');
    $('#data_nascita').val(row.data_nascita || '');
    $('#cod_fis').val(row.cod_fis || '');
    $('#comune_nascita').val(row.comune_nascita || '');
    $('#provincia_nascita').val(row.provincia_nascita || '');
    $('#indirizzo').val(row.indirizzo || '');
    $('#citta').val(row.citta || '');
    $('#cap').val(row.cap || '');
    $('#provincia').val(row.provincia || '');
    $('#residenza_indirizzo').val(row.residenza_indirizzo || '');
    $('#residenza_comune').val(row.residenza_comune || '');
    $('#residenza_cap').val(row.residenza_cap || '');
    $('#residenza_provincia').val(row.residenza_provincia || '');
    $('#telefono').val(row.telefono || '');
    $('#cellulare').val(row.cellulare || '');
    $('#email').val(row.email || '');
    $('#paz_spec').val(row.paz_spec || '');
    $('#bloccato').val(row.bloccato || 0);

    $('#pazienteModalTitle').text('Modifica paziente');
    $('#btnEliminaPaziente').show();
}

function caricaPazienti(page) {
    currentPage = parseInt(page || 1, 10) || 1;
    var searchTerm = $.trim($('#searchTerm').val() || '');
    $('#searchTerm').val(searchTerm);
    patientRowsById = {};
    $('#tabellaPazientiBody').html('<tr><td colspan="7" class="text-center text-muted">Caricamento...</td></tr>');
    $('#tabellaPazientiSummary').text('Caricamento...');

    $.get("<?= base_url('agenda/lista-pazienti') ?>", {
        id_dot: $('#id_dot').val(),
        term: searchTerm,
        page: currentPage
    }, function(res) {
        var html = '';

        if (!res.status) {
            $('#tabellaPazientiBody').html('<tr><td colspan="7" class="text-center text-danger">' + escapeHtml(res.message || 'Errore nel caricamento') + '</td></tr>');
            $('#tabellaPazientiSummary').text('');
            $('#paginationWrapper').html('');
            return;
        }

        currentPage = parseInt(res.page || 1, 10) || 1;
        lastPage = parseInt(res.lastPage || 1, 10) || 1;

        if (!res.rows || !res.rows.length) {
            patientRowsById = {};
            $('#tabellaPazientiBody').html('<tr><td colspan="7" class="text-center text-muted">Nessun paziente trovato</td></tr>');
            renderSummary(res.total || 0, res.from || 0, res.to || 0);
            renderPagination(currentPage, lastPage);
            return;
        }

        cachePatientRows(res.rows);

        $.each(res.rows, function(i, row) {
            html += '<tr>';
            html += '<td>' + escapeHtml(row.cognome || '') + '</td>';
            html += '<td>' + escapeHtml(row.nome || '') + '</td>';
            html += '<td>' + escapeHtml(row.telefono || '') + '</td>';
            html += '<td>' + escapeHtml(row.cellulare || '') + '</td>';
            html += '<td>' + escapeHtml(row.email || '') + '</td>';
            html += '<td>' + escapeHtml(row.cod_fis || '') + '</td>';
            html += '<td>';
            html += '<button type="button" class="btn btn-xs btn-primary btnModificaPaziente" data-id="' + row.id_paziente + '"><i class="fa fa-pencil"></i></button> ';
            html += '<button type="button" class="btn btn-xs btn-danger btnEliminaPazienteRiga" data-id="' + row.id_paziente + '"><i class="fa fa-trash"></i></button>';
            html += '</td>';
            html += '</tr>';
        });

        $('#tabellaPazientiBody').html(html);
        renderSummary(res.total || 0, res.from || 0, res.to || 0);
        renderPagination(currentPage, lastPage);
    }, 'json').fail(function() {
        patientRowsById = {};
        $('#tabellaPazientiBody').html('<tr><td colspan="7" class="text-center text-danger">Errore durante il caricamento dei pazienti</td></tr>');
        $('#tabellaPazientiSummary').text('');
        $('#paginationWrapper').html('');
    });
}

function caricaDettaglioPaziente(idPaziente) {
    idPaziente = parseInt(idPaziente || 0, 10) || 0;

    if (idPaziente > 0 && patientRowsById[idPaziente]) {
        fillPazienteForm(patientRowsById[idPaziente]);
        $('#pazienteModal').modal('show');
        return;
    }

    $.get("<?= base_url('agenda/get-paziente') ?>/" + idPaziente, {
        id_dot: $('#id_dot').val()
    }, function(res) {
        if (!res.status || !res.row) {
            alert(res.message || 'Paziente non trovato');
            return;
        }

        var row = res.row;
        if (row && row.id_paziente) {
            patientRowsById[parseInt(row.id_paziente, 10) || 0] = row;
        }

        fillPazienteForm(row);
        $('#pazienteModal').modal('show');
    }, 'json').fail(function(xhr) {
        var message = 'Impossibile caricare il dettaglio del paziente.';

        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        alert(message);
    });
}

function salvaPaziente() {
    var existingPatientId = $.trim($('#id_paziente').val() || '');

    $.post("<?= base_url('agenda/salva-paziente-gestione') ?>", {
        id_paziente: $('#id_paziente').val(),
        id_dot: $('#id_dot').val(),
        cognome: $('#cognome').val(),
        nome: $('#nome').val(),
        data_nascita: $('#data_nascita').val(),
        cod_fis: $('#cod_fis').val(),
        comune_nascita: $('#comune_nascita').val(),
        provincia_nascita: $('#provincia_nascita').val(),
        indirizzo: $('#indirizzo').val(),
        citta: $('#citta').val(),
        cap: $('#cap').val(),
        provincia: $('#provincia').val(),
        residenza_indirizzo: $('#residenza_indirizzo').val(),
        residenza_comune: $('#residenza_comune').val(),
        residenza_cap: $('#residenza_cap').val(),
        residenza_provincia: $('#residenza_provincia').val(),
        telefono: $('#telefono').val(),
        cellulare: $('#cellulare').val(),
        email: $('#email').val(),
        paz_spec: $('#paz_spec').val(),
        bloccato: $('#bloccato').val()
    }, function(res) {
        alert(res.message || 'Operazione completata');
        if (res.status) {
            $('#pazienteModal').modal('hide');
            caricaPazienti(existingPatientId !== '' ? currentPage : 1);
        }
    }, 'json');
}

function eliminaPaziente(idPaziente) {
    if (!confirm('Vuoi eliminare questo paziente?')) {
        return;
    }

    $.post("<?= base_url('agenda/elimina-paziente') ?>", {
        id_paziente: idPaziente,
        id_dot: $('#id_dot').val()
    }, function(res) {
        alert(res.message || 'Operazione completata');
        if (res.status) {
            $('#pazienteModal').modal('hide');
            caricaPazienti(currentPage);
        }
    }, 'json');
}

$(function() {
    caricaPazienti(1);

    $('#btnCercaPazienti').on('click', function() {
        caricaPazienti(1);
    });

    $('#id_dot').on('change', function() {
        caricaPazienti(1);
    });

    $('#searchTerm').on('keyup', function(e) {
        if (e.keyCode === 13) {
            caricaPazienti(1);
        }
    });

    $('#btnNuovoPaziente').on('click', function() {
        resetFormPaziente();
        $('#pazienteModal').modal('show');
    });

    $('#btnSalvaPaziente').on('click', salvaPaziente);

    $(document).on('click', '.btnModificaPaziente', function() {
        resetFormPaziente();
        caricaDettaglioPaziente($(this).data('id'));
    });

    $(document).on('click', '.btnEliminaPazienteRiga', function() {
        eliminaPaziente($(this).data('id'));
    });

    $('#btnEliminaPaziente').on('click', function() {
        eliminaPaziente($('#id_paziente').val());
    });

    $(document).on('click', '#paginationWrapper a[data-page]', function(e) {
        e.preventDefault();

        var page = parseInt($(this).data('page'), 10);
        if (!page || $(this).parent().hasClass('disabled') || $(this).parent().hasClass('active')) {
            return;
        }

        caricaPazienti(page);
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>

