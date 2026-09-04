<!DOCTYPE html>
<html>
<head>
    <?php
        $patientAssetVersion = static function (string $relativePath): string {
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
            $absolutePath = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedPath;
            $mtime = is_file($absolutePath) ? @filemtime($absolutePath) : false;

            return $mtime ? '?v=' . rawurlencode((string) $mtime) : '';
        };
    ?>
    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/css/italian-address-autocomplete.css') . $patientAssetVersion('public/css/italian-address-autocomplete.css') ?>" rel="stylesheet" type="text/css" />
    <meta charset="UTF-8">
    <?php $patientPageTitle = 'Gestione pazienti'; ?>
    <title><?= esc($patientPageTitle . (' | AmbulatorioFacile')) ?></title>
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

        .patient-search-autocomplete {
            position: relative;
        }

        .patient-search-suggestions {
            position: absolute;
            top: 100%;
            right: 0;
            left: 0;
            z-index: 1060;
            display: none;
            max-height: 290px;
            margin-top: 2px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #d2d6de;
            border-radius: 3px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, .15);
        }

        .patient-search-suggestion {
            display: block;
            width: 100%;
            padding: 9px 12px;
            overflow: hidden;
            color: #333;
            text-align: left;
            background: #fff;
            border: 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .patient-search-suggestion:last-child {
            border-bottom: 0;
        }

        .patient-search-suggestion:hover,
        .patient-search-suggestion:focus {
            color: #1f4e6e;
            background: #f4f8fa;
            outline: 0;
        }

        .patient-search-suggestion-name {
            display: block;
            font-weight: 600;
        }

        .patient-search-suggestion-detail {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            color: #777;
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .patient-search-suggestion-empty {
            padding: 9px 12px;
            color: #777;
            font-size: 12px;
        }

        .patient-form-section-title {
            margin: 8px 0 12px;
            padding-top: 8px;
            border-top: 1px solid #ecf0f5;
            color: #607d8b;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .patient-form-section-title:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }

        .fiscal-code-input-group .input-group-addon {
            background: #fff;
            white-space: nowrap;
        }

        .fiscal-code-input-group .checkbox-inline {
            padding-top: 0;
            font-weight: 400;
        }

        #cfValidationFeedback {
            min-height: 18px;
            margin-bottom: 0;
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
                                <?php if (!empty($patientExcelImportEnabled)): ?>
                                <a
                                    href="<?= base_url('agenda/importa-pazienti-excel?id_dot=' . (int) $selectedDot) ?>"
                                    class="btn btn-default btn-sm"
                                    style="margin-right:6px;"
                                >
                                    <i class="fa fa-upload"></i> Importa Excel
                                </a>
                                <?php endif; ?>
                                <button type="button" class="btn btn-success btn-sm" id="btnNuovoPaziente">
                                    <i class="fa fa-plus"></i> Nuovo paziente
                                </button>
                            </div>
                        </div>
                        <div class="box-body">
                            <form id="formCercaPazienti" action="#" method="get">
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
                                    <div class="patient-search-autocomplete">
                                        <input
                                            type="text"
                                            id="searchTerm"
                                            class="form-control"
                                            placeholder="Cognome, nome, codice fiscale, telefono..."
                                            autocomplete="off"
                                            role="combobox"
                                            aria-autocomplete="list"
                                            aria-expanded="false"
                                            aria-controls="patientSearchSuggestions"
                                        >
                                        <div id="patientSearchSuggestions" class="patient-search-suggestions" role="listbox" aria-label="Suggerimenti pazienti"></div>
                                    </div>
                                </div>

                                <div class="col-md-3">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary btn-block" id="btnCercaPazienti">
                                        <i class="fa fa-search"></i> Cerca
                                    </button>
                                </div>
                            </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Cognome / Denominazione</th>
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

                <p class="help-block" style="margin-top:0;">
                    <i class="fa fa-info-circle"></i>
                    Comune, provincia e CAP sono indipendenti e facoltativi: puoi compilarne anche uno solo oppure inserire liberamente un valore non suggerito.
                </p>

                <div class="row">
                    <div class="col-md-12">
                        <div class="patient-form-section-title">Anagrafica</div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label>Denominazione</label>
                        <input type="text" id="denominazione" class="form-control" placeholder="Utile per import Excel o persone giuridiche">
                        <p class="help-block" style="margin-bottom:0;">
                            Per persone fisiche compila nome e cognome. Per anagrafiche importate o soggetti giuridici puoi usare anche solo la denominazione.
                        </p>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Cognome</label>
                        <input type="text" id="cognome" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Nome</label>
                        <input type="text" id="nome" class="form-control">
                    </div>

                    <div class="col-md-6 form-group" id="codFisFormGroup">
                        <label>Codice fiscale</label>
                        <div class="input-group fiscal-code-input-group">
                            <input type="text" id="cod_fis" class="form-control" maxlength="24" autocomplete="off" autocapitalize="characters">
                            <span class="input-group-addon">
                                <label class="checkbox-inline" title="Consente il salvataggio anche se il codice fiscale non supera i controlli">
                                    <input type="checkbox" id="ignore_cf_validation" value="1">
                                    Ignora controllo CF
                                </label>
                            </span>
                        </div>
                        <p class="help-block" id="cfValidationFeedback" aria-live="polite"></p>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Partita IVA</label>
                        <input type="text" id="partita_iva" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Data nascita</label>
                        <input type="date" id="data_nascita" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Comune nascita</label>
                        <input type="text" id="comune_nascita" class="form-control" placeholder="Cerca comune">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Provincia nascita</label>
                        <input type="text" id="provincia_nascita" class="form-control" placeholder="Nome o sigla">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Cliente attivo (Excel)</label>
                        <select id="cliente_attivo" class="form-control">
                            <option value="1">Sì</option>
                            <option value="0">No</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <div class="patient-form-section-title">Assegnazione Medici</div>
                    </div>
                    <div class="col-md-12">
                        <div class="checkbox" style="margin:0 0 4px;">
                            <label>
                                <input type="checkbox" id="associate_all_doctors" value="1">
                                Rendi questa anagrafica disponibile a tutti i medici dello spazio
                            </label>
                        </div>
                        <p class="help-block" style="margin:0 0 12px;">
                            Il popup appuntamento continua a chiedere solo i campi essenziali, ma la scheda paziente viene condivisa con tutto lo spazio agenda.
                        </p>
                    </div>

                    <div class="col-md-12">
                        <div class="patient-form-section-title">Contatti E Amministrazione</div>
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Telefono</label>
                        <input type="text" id="telefono" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Cellulare</label>
                        <input type="text" id="cellulare" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Email</label>
                        <input type="email" id="email" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Email PEC</label>
                        <input type="email" id="email_pec" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Banca</label>
                        <input type="text" id="banca" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>Condizioni di pagamento</label>
                        <input type="text" id="condizioni_pagamento" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label>Codice ufficio/destinatario</label>
                        <input type="text" id="codice_destinatario" class="form-control">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>IVA differita</label>
                        <select id="iva_differita" class="form-control">
                            <option value="0">No</option>
                            <option value="1">Sì</option>
                        </select>
                    </div>

                    <?php if (!empty($patientSmsReminderPreferenceAvailable)): ?>
                    <div class="col-md-4">
                        <div class="checkbox" style="margin:0 0 4px;">
                            <label>
                                <input type="checkbox" id="appointment_reminder_sms_enabled" value="1">
                                Attiva promemoria appuntamento via SMS per questo paziente
                            </label>
                        </div>
                        <p class="help-block" style="margin:0 0 12px;">
                            La preferenza viene riutilizzata anche nei prossimi appuntamenti del paziente.
                        </p>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-12 form-group">
                        <label>Note cliente</label>
                        <textarea id="note_cliente" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="col-md-12">
                        <div class="patient-form-section-title">Residenza</div>
                        <p class="help-block" style="margin:0 0 10px;">
                            Questo indirizzo viene usato per la fatturazione. Se è vuoto viene usato il domicilio.
                        </p>
                    </div>
                    <div class="col-md-5 form-group">
                        <label>Indirizzo</label>
                        <input type="text" id="residenza_indirizzo" class="form-control">
                    </div>
                    <div class="col-md-2 form-group">
                        <label>Nr. civico</label>
                        <input type="text" id="residenza_nr_civico" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Comune</label>
                        <input type="text" id="residenza_comune" class="form-control" placeholder="Cerca comune">
                    </div>
                    <div class="col-md-1 form-group">
                        <label>CAP</label>
                        <input type="text" id="residenza_cap" class="form-control" placeholder="CAP" inputmode="numeric">
                    </div>
                    <div class="col-md-1 form-group">
                        <label>Provincia</label>
                        <input type="text" id="residenza_provincia" class="form-control" placeholder="Sigla">
                    </div>

                    <div class="col-md-12">
                        <div class="patient-form-section-title">Domicilio</div>
                    </div>
                    <div class="col-md-5 form-group">
                        <label>Indirizzo</label>
                        <input type="text" id="domicilio_indirizzo" class="form-control">
                    </div>
                    <div class="col-md-2 form-group">
                        <label>Nr. civico</label>
                        <input type="text" id="domicilio_nr_civico" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Comune</label>
                        <input type="text" id="domicilio_comune" class="form-control" placeholder="Cerca comune">
                    </div>
                    <div class="col-md-1 form-group">
                        <label>CAP</label>
                        <input type="text" id="domicilio_cap" class="form-control" placeholder="CAP" inputmode="numeric">
                    </div>
                    <div class="col-md-1 form-group">
                        <label>Provincia</label>
                        <input type="text" id="domicilio_provincia" class="form-control" placeholder="Sigla">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Paziente speciale</label>
                        <input type="text" id="paz_spec" class="form-control">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Bloccato in agenda</label>
                        <select id="bloccato" class="form-control">
                            <option value="0">No</option>
                            <option value="1">Sì</option>
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
<script src="<?= base_url('public/js/italian-address-autocomplete.js') . $patientAssetVersion('public/js/italian-address-autocomplete.js') ?>"></script>

<script>
var currentPage = 1;
var lastPage = 1;
var patientRowsById = {};
var patientSearchSuggestionsById = {};
var patientSearchSuggestionTimer = null;
var patientSearchSuggestionXhr = null;
var patientSearchSuggestionRequestId = 0;
var selectedPatientId = 0;
var fiscalCodeValidationTimer = null;
var fiscalCodeValidationXhr = null;
var fiscalCodeValidationRequestId = 0;

function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : text).html();
}

function getPatientSearchLabel(row) {
    var primary = $.trim((row && (row.cognome || row.denominazione)) || '');
    var firstName = $.trim((row && row.nome) || '');
    return $.trim(primary + ' ' + firstName) || 'Paziente senza nominativo';
}

function hidePatientSearchSuggestions() {
    if (patientSearchSuggestionTimer) {
        clearTimeout(patientSearchSuggestionTimer);
        patientSearchSuggestionTimer = null;
    }

    patientSearchSuggestionRequestId += 1;
    patientSearchSuggestionsById = {};
    $('#patientSearchSuggestions').empty().hide();
    $('#searchTerm').attr('aria-expanded', 'false');
}

function renderPatientSearchSuggestions(rows) {
    var html = '';
    var visibleRows = (rows || []).slice(0, 8);

    patientSearchSuggestionsById = {};

    if (!visibleRows.length) {
        $('#patientSearchSuggestions').html('<div class="patient-search-suggestion-empty">Nessun paziente trovato</div>').show();
        $('#searchTerm').attr('aria-expanded', 'true');
        return;
    }

    $.each(visibleRows, function(_, row) {
        var id = parseInt((row && row.id_paziente) || 0, 10) || 0;
        if (!id) {
            return;
        }

        patientSearchSuggestionsById[id] = row;

        var details = [];
        if (row.cod_fis) {
            details.push('CF ' + row.cod_fis);
        }
        if (row.cellulare || row.telefono) {
            details.push(row.cellulare || row.telefono);
        }

        html += '<button type="button" class="patient-search-suggestion" role="option" data-id="' + id + '">';
        html += '<span class="patient-search-suggestion-name">' + escapeHtml(getPatientSearchLabel(row)) + '</span>';
        if (details.length) {
            html += '<span class="patient-search-suggestion-detail">' + escapeHtml(details.join(' · ')) + '</span>';
        }
        html += '</button>';
    });

    if (html === '') {
        html = '<div class="patient-search-suggestion-empty">Nessun paziente trovato</div>';
    }

    $('#patientSearchSuggestions').html(html).show();
    $('#searchTerm').attr('aria-expanded', 'true');
}

function loadPatientSearchSuggestions(term) {
    var normalizedTerm = $.trim(term || '');
    var requestId;

    if (normalizedTerm.length < 2 || !$('#id_dot').val()) {
        hidePatientSearchSuggestions();
        return;
    }

    if (patientSearchSuggestionXhr && patientSearchSuggestionXhr.readyState !== 4) {
        patientSearchSuggestionXhr.abort();
    }

    requestId = ++patientSearchSuggestionRequestId;
    patientSearchSuggestionXhr = $.get("<?= base_url('agenda/lista-pazienti') ?>", {
        id_dot: $('#id_dot').val(),
        term: normalizedTerm,
        page: 1
    }, function(res) {
        if (requestId !== patientSearchSuggestionRequestId || $.trim($('#searchTerm').val() || '') !== normalizedTerm) {
            return;
        }

        if (!res || !res.status) {
            hidePatientSearchSuggestions();
            return;
        }

        renderPatientSearchSuggestions(res.rows || []);
    }, 'json').fail(function(xhr, status) {
        if (status !== 'abort' && requestId === patientSearchSuggestionRequestId) {
            hidePatientSearchSuggestions();
        }
    });
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

function fiscalCodeValidationPayload() {
    return {
        id_paziente: $('#id_paziente').val(),
        id_dot: $('#id_dot').val(),
        cod_fis: $('#cod_fis').val(),
        cognome: $('#cognome').val(),
        nome: $('#nome').val(),
        data_nascita: $('#data_nascita').val(),
        comune_nascita: $('#comune_nascita').val(),
        provincia_nascita: $('#provincia_nascita').val()
    };
}

function renderFiscalCodeValidation(state, message) {
    var $group = $('#codFisFormGroup');
    var $feedback = $('#cfValidationFeedback');

    $group.removeClass('has-error has-success');
    $feedback.removeClass('text-danger text-success text-warning text-muted');

    if (state === 'error') {
        $group.addClass('has-error');
        $feedback.addClass('text-danger');
    } else if (state === 'success') {
        $group.addClass('has-success');
        $feedback.addClass('text-success');
    } else if (state === 'warning') {
        $feedback.addClass('text-warning');
    } else {
        $feedback.addClass('text-muted');
    }

    $feedback.text(message || '');
}

function requestFiscalCodeValidation(showAlert) {
    var deferred = $.Deferred();
    var code = $.trim($('#cod_fis').val() || '').toUpperCase();
    var requestId;

    $('#cod_fis').val(code);

    if (code === '') {
        renderFiscalCodeValidation('', '');
        deferred.resolve(true);
        return deferred.promise();
    }

    if ($('#ignore_cf_validation').is(':checked')) {
        renderFiscalCodeValidation('warning', 'Controllo disattivato per questo salvataggio.');
        deferred.resolve(true);
        return deferred.promise();
    }

    if (fiscalCodeValidationXhr && fiscalCodeValidationXhr.readyState !== 4) {
        fiscalCodeValidationXhr.abort();
    }

    requestId = ++fiscalCodeValidationRequestId;
    renderFiscalCodeValidation('', 'Controllo del codice fiscale in corso...');

    fiscalCodeValidationXhr = $.post(
        "<?= base_url('agenda/verifica-codice-fiscale') ?>",
        fiscalCodeValidationPayload(),
        null,
        'json'
    ).done(function(res) {
        var valid;
        var message;

        if (requestId !== fiscalCodeValidationRequestId) {
            return;
        }

        valid = !!(res && res.status);
        message = (res && res.message) || (valid
            ? 'Codice fiscale valido.'
            : 'Il codice fiscale non supera i controlli.');

        if (res && res.normalized) {
            $('#cod_fis').val(res.normalized);
        }

        renderFiscalCodeValidation(
            valid && res && res.existing_patient_found ? 'warning' : (valid ? 'success' : 'error'),
            message
        );
        if (!valid && showAlert) {
            alert(message);
        }
        deferred.resolve(valid);
    }).fail(function(xhr, status) {
        var message;

        if (status === 'abort' || requestId !== fiscalCodeValidationRequestId) {
            return;
        }

        message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
            ? xhr.responseJSON.message
            : 'Impossibile completare il controllo del codice fiscale.';
        renderFiscalCodeValidation('error', message);
        if (showAlert) {
            alert(message);
        }
        deferred.resolve(false);
    });

    return deferred.promise();
}

function scheduleFiscalCodeValidation() {
    if (fiscalCodeValidationTimer) {
        clearTimeout(fiscalCodeValidationTimer);
    }

    fiscalCodeValidationTimer = setTimeout(function() {
        requestFiscalCodeValidation(false);
    }, 350);
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
    $('#denominazione,#cognome,#nome,#data_nascita,#cod_fis,#partita_iva,#comune_nascita,#provincia_nascita,#residenza_indirizzo,#residenza_nr_civico,#residenza_comune,#residenza_cap,#residenza_provincia,#domicilio_indirizzo,#domicilio_nr_civico,#domicilio_comune,#domicilio_cap,#domicilio_provincia,#telefono,#cellulare,#email,#email_pec,#banca,#condizioni_pagamento,#codice_destinatario,#note_cliente,#paz_spec').val('');
    $('#bloccato').val('0');
    $('#cliente_attivo').val('1');
    $('#iva_differita').val('0');
    $('#appointment_reminder_sms_enabled').prop('checked', false);
    $('#associate_all_doctors').prop('checked', false);
    $('#ignore_cf_validation').prop('checked', false);
    renderFiscalCodeValidation('', '');
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
    $('#denominazione').val(row.denominazione || '');
    $('#cognome').val(row.cognome || '');
    $('#nome').val(row.nome || '');
    $('#data_nascita').val(row.data_nascita || '');
    $('#cod_fis').val(row.cod_fis || '');
    $('#partita_iva').val(row.partita_iva || '');
    $('#comune_nascita').val(row.comune_nascita || '');
    $('#provincia_nascita').val(row.provincia_nascita || '');
    $('#residenza_indirizzo').val(row.residenza_indirizzo || '');
    $('#residenza_nr_civico').val(row.residenza_nr_civico || '');
    $('#residenza_comune').val(row.residenza_comune || '');
    $('#residenza_cap').val(row.residenza_cap || '');
    $('#residenza_provincia').val(row.residenza_provincia || '');
    $('#domicilio_indirizzo').val(row.domicilio_indirizzo || '');
    $('#domicilio_nr_civico').val(row.domicilio_nr_civico || '');
    $('#domicilio_comune').val(row.domicilio_comune || '');
    $('#domicilio_cap').val(row.domicilio_cap || '');
    $('#domicilio_provincia').val(row.domicilio_provincia || '');
    $('#telefono').val(row.telefono || '');
    $('#cellulare').val(row.cellulare || '');
    $('#email').val(row.email || '');
    $('#email_pec').val(row.email_pec || '');
    $('#banca').val(row.banca || '');
    $('#condizioni_pagamento').val(row.condizioni_pagamento || '');
    $('#codice_destinatario').val(row.codice_destinatario || '');
    $('#iva_differita').val((row.iva_differita || 0).toString());
    $('#note_cliente').val(row.note_cliente || '');
    $('#paz_spec').val(row.paz_spec || '');
    $('#bloccato').val(row.bloccato || 0);
    $('#cliente_attivo').val((row.cliente_attivo == null ? 1 : row.cliente_attivo).toString() === '0' ? '0' : '1');
    $('#appointment_reminder_sms_enabled').prop('checked', parseInt(row.appointment_reminder_sms_enabled || 0, 10) === 1);
    $('#associate_all_doctors').prop('checked', parseInt(row.associate_all_doctors || 0, 10) === 1);
    $('#ignore_cf_validation').prop('checked', false);
    renderFiscalCodeValidation('', '');

    $('#pazienteModalTitle').text('Modifica paziente');
    $('#btnEliminaPaziente').show();
}

function caricaPazienti(page) {
    currentPage = parseInt(page || 1, 10) || 1;
    var searchTerm = $.trim($('#searchTerm').val() || '');
    var selectedId = parseInt(selectedPatientId || 0, 10) || 0;
    $('#searchTerm').val(searchTerm);
    patientRowsById = {};
    $('#tabellaPazientiBody').html('<tr><td colspan="7" class="text-center text-muted">Caricamento...</td></tr>');
    $('#tabellaPazientiSummary').text('Caricamento...');

    $.get("<?= base_url('agenda/lista-pazienti') ?>", {
        id_dot: $('#id_dot').val(),
        term: searchTerm,
        id_paziente: selectedId,
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
            var primaryLabel = row.cognome || row.denominazione || '';
            html += '<tr>';
            html += '<td>' + escapeHtml(primaryLabel);
            if (parseInt(row.associate_all_doctors || 0, 10) === 1) {
                html += '<div style="margin-top:4px;"><span class="label label-info">Tutti i medici</span></div>';
            }
            html += '</td>';
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
    requestFiscalCodeValidation(true).done(function(valid) {
        if (valid) {
            inviaPaziente();
        }
    });
}

function inviaPaziente(confirmExistingPatientUpdate) {
    var existingPatientId = $.trim($('#id_paziente').val() || '');

    $.post("<?= base_url('agenda/salva-paziente-gestione') ?>", {
        id_paziente: $('#id_paziente').val(),
        id_dot: $('#id_dot').val(),
        denominazione: $('#denominazione').val(),
        cognome: $('#cognome').val(),
        nome: $('#nome').val(),
        data_nascita: $('#data_nascita').val(),
        cod_fis: $('#cod_fis').val(),
        confirm_existing_patient_update: confirmExistingPatientUpdate ? 1 : 0,
        ignore_cf_validation: $('#ignore_cf_validation').is(':checked') ? 1 : 0,
        partita_iva: $('#partita_iva').val(),
        comune_nascita: $('#comune_nascita').val(),
        provincia_nascita: $('#provincia_nascita').val(),
        address_payload_complete: 1,
        residenza_indirizzo: $('#residenza_indirizzo').val(),
        residenza_nr_civico: $('#residenza_nr_civico').val(),
        residenza_comune: $('#residenza_comune').val(),
        residenza_cap: $('#residenza_cap').val(),
        residenza_provincia: $('#residenza_provincia').val(),
        domicilio_indirizzo: $('#domicilio_indirizzo').val(),
        domicilio_nr_civico: $('#domicilio_nr_civico').val(),
        domicilio_comune: $('#domicilio_comune').val(),
        domicilio_cap: $('#domicilio_cap').val(),
        domicilio_provincia: $('#domicilio_provincia').val(),
        telefono: $('#telefono').val(),
        cellulare: $('#cellulare').val(),
        email: $('#email').val(),
        email_pec: $('#email_pec').val(),
        banca: $('#banca').val(),
        condizioni_pagamento: $('#condizioni_pagamento').val(),
        codice_destinatario: $('#codice_destinatario').val(),
        iva_differita: $('#iva_differita').val(),
        note_cliente: $('#note_cliente').val(),
        appointment_reminder_sms_enabled: $('#appointment_reminder_sms_enabled').is(':checked') ? 1 : 0,
        associate_all_doctors: $('#associate_all_doctors').is(':checked') ? 1 : 0,
        cliente_attivo: $('#cliente_attivo').val(),
        paz_spec: $('#paz_spec').val(),
        bloccato: $('#bloccato').val()
    }, function(res) {
        if (res && res.requires_existing_patient_confirmation) {
            if (window.confirm(res.message || 'Il paziente è già presente. Vuoi aggiornare l\'anagrafica esistente?')) {
                inviaPaziente(true);
            }
            return;
        }

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
    if (window.ItalianAddressAutocomplete) {
        window.ItalianAddressAutocomplete.init({
            dataUrl: "<?= base_url('public/data/italian-addresses.json') . $patientAssetVersion('public/data/italian-addresses.json') ?>",
            groups: [
                { municipality: '#comune_nascita', province: '#provincia_nascita' },
                { municipality: '#residenza_comune', province: '#residenza_provincia', postalCode: '#residenza_cap' },
                { municipality: '#domicilio_comune', province: '#domicilio_provincia', postalCode: '#domicilio_cap' }
            ]
        });
    }

    caricaPazienti(1);

    $('#formCercaPazienti').on('submit', function(e) {
        e.preventDefault();
        hidePatientSearchSuggestions();
        caricaPazienti(1);
    });

    $('#searchTerm').on('input', function() {
        var term = $(this).val() || '';
        selectedPatientId = 0;

        if (patientSearchSuggestionTimer) {
            clearTimeout(patientSearchSuggestionTimer);
            patientSearchSuggestionTimer = null;
        }

        if ($.trim(term).length < 2) {
            hidePatientSearchSuggestions();
            return;
        }

        patientSearchSuggestionTimer = setTimeout(function() {
            loadPatientSearchSuggestions(term);
        }, 250);
    }).on('keydown', function(e) {
        if (e.key === 'Escape') {
            hidePatientSearchSuggestions();
        }
    });

    $('#id_dot').on('change', function() {
        selectedPatientId = 0;
        hidePatientSearchSuggestions();
        caricaPazienti(1);
    });

    $(document).on('mousedown', '.patient-search-suggestion', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var id = parseInt($(this).data('id'), 10) || 0;
        var row = patientSearchSuggestionsById[id];

        if (!row) {
            return;
        }

        selectedPatientId = id;
        $('#searchTerm').val(getPatientSearchLabel(row));
        hidePatientSearchSuggestions();
        caricaPazienti(1);
    });

    $(document).on('mousedown', function(e) {
        if (!$(e.target).closest('.patient-search-autocomplete').length) {
            hidePatientSearchSuggestions();
        }
    });

    $('#btnNuovoPaziente').on('click', function() {
        resetFormPaziente();
        $('#pazienteModal').modal('show');
    });

    $('#btnSalvaPaziente').on('click', salvaPaziente);

    $('#cod_fis,#cognome,#nome,#data_nascita,#comune_nascita,#provincia_nascita').on('input change', function() {
        if ($.trim($('#cod_fis').val() || '') !== '' && !$('#ignore_cf_validation').is(':checked')) {
            scheduleFiscalCodeValidation();
        }
    });

    $('#cod_fis').on('blur', function() {
        $(this).val($.trim($(this).val() || '').toUpperCase());
    });

    $('#ignore_cf_validation').on('change', function() {
        if ($(this).is(':checked')) {
            fiscalCodeValidationRequestId += 1;
            if (fiscalCodeValidationXhr && fiscalCodeValidationXhr.readyState !== 4) {
                fiscalCodeValidationXhr.abort();
            }
            renderFiscalCodeValidation('warning', 'Controllo disattivato per questo salvataggio.');
            return;
        }

        scheduleFiscalCodeValidation();
    });

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

