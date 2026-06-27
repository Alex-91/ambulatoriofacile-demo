<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Tipi visita | Agenda</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
        .visit-types-hero {
            background: linear-gradient(135deg, #f8fcfc 0%, #eef7f8 55%, #fff7ef 100%);
            border: 1px solid #d6e7ea;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 18px;
        }

        .visit-types-hero h3 {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 700;
            color: #233336;
        }

        .visit-types-hero p {
            margin: 0 0 14px;
            color: #55696d;
            line-height: 1.6;
        }

        .visit-types-card {
            border: 1px solid #e4ecee;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 12px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(35, 58, 62, 0.05);
        }

        .visit-types-card.is-inactive {
            opacity: 0.8;
            border-color: #efd9a7;
            background: #fffaf1;
        }

        .visit-types-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .visit-types-card-title {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #243235;
        }

        .visit-types-card-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .visit-types-card-color {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            border-radius: 999px;
            border: 1px solid rgba(31, 45, 61, 0.14);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.28);
        }

        .visit-types-card-meta {
            margin-top: 4px;
            color: #617478;
            font-size: 13px;
        }

        .visit-types-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .visit-types-status.is-active {
            background: #e5f6ea;
            color: #1d7a48;
        }

        .visit-types-status.is-inactive {
            background: #fff3d8;
            color: #916a15;
        }

        .visit-types-actions {
            margin-top: 12px;
        }

        .visit-types-actions .btn {
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .visit-types-empty {
            border: 1px dashed #c9dade;
            border-radius: 14px;
            padding: 22px;
            text-align: center;
            color: #607277;
            background: #fbfdfd;
        }

        .visit-types-form-box .help-block {
            margin-top: 0;
        }

        .visit-type-color-picker {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .visit-type-color-palette {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(42px, 1fr));
            gap: 10px;
        }

        .visit-type-color-swatch {
            height: 42px;
            border: 1px solid #d7e4ea;
            border-radius: 12px;
            background: var(--visit-type-color, #3C8DBC);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.24);
            cursor: pointer;
            transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
        }

        .visit-type-color-swatch:hover,
        .visit-type-color-swatch:focus {
            transform: translateY(-1px);
            border-color: #9fb8c6;
            box-shadow: 0 8px 18px rgba(31, 45, 61, 0.12);
            outline: none;
        }

        .visit-type-color-swatch.is-selected {
            border-color: #1f2d3d;
            box-shadow: 0 0 0 3px rgba(31, 45, 61, 0.16), 0 10px 22px rgba(31, 45, 61, 0.12);
        }

        .visit-type-color-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .visit-type-color-current {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #455b63;
        }

        .visit-type-color-current-sample {
            width: 20px;
            height: 20px;
            border-radius: 999px;
            border: 1px solid rgba(31, 45, 61, 0.14);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.32);
        }

        .visit-type-color-custom {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .visit-type-color-custom-label {
            color: #6a7c82;
            font-size: 12px;
            font-weight: 600;
        }

        .visit-type-color-native {
            width: 44px;
            height: 32px;
            padding: 0;
            border: 1px solid #d7e4ea;
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
        }

        @media (max-width: 767px) {
            .visit-type-color-footer {
                align-items: stretch;
            }

            .visit-type-color-custom {
                width: 100%;
                justify-content: space-between;
                margin-left: 0;
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
            <h1>Tipi visita</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Tipi visita</li>
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
                            <?= view('agenda/partials/menu_laterale', [
                                'menuAgenda' => $menuAgenda ?? [],
                                'visitTypesFeatureEnabled' => !empty($visitTypesFeatureEnabled),
                            ]) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-9">
                    <div id="visitTypesFlash" style="display:none;"></div>

                    <div class="visit-types-hero">
                        <h3>Configura una volta, prenota piu veloce</h3>
                        <p>
                            Qui decidi nome e durata dei tipi visita. Quando in agenda scegli un tipo visita,
                            il sistema controlla se ci sono abbastanza slot consecutivi liberi e li occupa con un unico appuntamento.
                        </p>
                        <a href="<?= base_url('agenda') ?>" class="btn btn-primary">
                            <i class="fa fa-calendar"></i> Vai in agenda
                        </a>
                    </div>

                    <div class="row">
                        <div class="col-md-7">
                            <div class="box box-primary">
                                <div class="box-header with-border">
                                    <h3 class="box-title"><i class="fa fa-list-alt"></i> Elenco tipi visita</h3>
                                </div>
                                <div class="box-body">
                                    <div id="visitTypesList"></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <div class="box box-success visit-types-form-box">
                                <div class="box-header with-border">
                                    <h3 class="box-title"><i class="fa fa-pencil"></i> Nuovo o modifica</h3>
                                </div>
                                <div class="box-body">
                                    <p class="help-block">
                                        Usa durate coerenti con la tua griglia agenda. Per esempio, con slot da 15 minuti una visita da 45 occupa 3 slot consecutivi.
                                    </p>

                                    <input type="hidden" id="visitTypeId" value="">

                                    <div class="form-group">
                                        <label for="visitTypeName">Nome tipo visita</label>
                                        <input type="text" id="visitTypeName" class="form-control" placeholder="Es. Controllo 45 minuti">
                                    </div>

                                    <div class="form-group">
                                        <label for="visitTypeDuration">Durata in minuti</label>
                                        <input type="number" id="visitTypeDuration" class="form-control" min="5" step="5" placeholder="45">
                                    </div>

                                    <div class="form-group">
                                        <label for="visitTypeColorCustom">Colore slot agenda</label>
                                        <div class="visit-type-color-picker">
                                            <input type="hidden" id="visitTypeColor" value="">
                                            <div id="visitTypeColorPalette" class="visit-type-color-palette"></div>
                                            <div class="visit-type-color-footer">
                                                <div class="visit-type-color-current">
                                                    <span id="visitTypeColorSample" class="visit-type-color-current-sample"></span>
                                                    <span>Colore selezionato <strong id="visitTypeColorValue">#3C8DBC</strong></span>
                                                </div>
                                                <label class="visit-type-color-custom" for="visitTypeColorCustom">
                                                    <span class="visit-type-color-custom-label">Personalizza</span>
                                                    <input type="color" id="visitTypeColorCustom" class="visit-type-color-native" value="#3c8dbc">
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group" style="margin-bottom:0;">
                                        <button type="button" class="btn btn-success btn-block" id="btnSaveVisitType">
                                            <i class="fa fa-save"></i> Salva tipo visita
                                        </button>
                                        <button type="button" class="btn btn-default btn-block" id="btnCancelVisitTypeEdit" style="display:none; margin-top:8px;">
                                            <i class="fa fa-times"></i> Annulla modifica
                                        </button>
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

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
<script>
var visitTypesRows = <?= json_encode(array_values($visitTypes ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var visitTypeColorPalette = ['#3C8DBC', '#16A085', '#5E72E4', '#EB6B56', '#8E44AD', '#F39C12', '#27AE60', '#C0392B', '#2C82C9', '#D35400'];

function escapeHtmlVisitTypes(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function normalizeVisitTypeColor(value) {
    var normalized = $.trim(String(value || '')).toUpperCase();
    return /^#[0-9A-F]{6}$/.test(normalized) ? normalized : '';
}

function getSuggestedVisitTypeColor() {
    return visitTypeColorPalette[visitTypesRows.length % visitTypeColorPalette.length] || '#3C8DBC';
}

function setVisitTypeColor(value) {
    var normalized = normalizeVisitTypeColor(value) || getSuggestedVisitTypeColor();

    $('#visitTypeColor').val(normalized);
    $('#visitTypeColorCustom').val(normalized.toLowerCase());
    $('#visitTypeColorSample').css('background', normalized);
    $('#visitTypeColorValue').text(normalized);

    $('#visitTypeColorPalette').find('.visit-type-color-swatch').each(function() {
        var $swatch = $(this);
        $swatch.toggleClass('is-selected', String($swatch.data('color') || '').toUpperCase() === normalized);
    });
}

function renderVisitTypeColorPalette() {
    var html = '';

    $.each(visitTypeColorPalette, function(_, color) {
        html += '<button type="button" class="visit-type-color-swatch"'
            + ' data-color="' + escapeHtmlVisitTypes(color) + '"'
            + ' style="--visit-type-color:' + escapeHtmlVisitTypes(color) + ';"'
            + ' aria-label="Seleziona colore ' + escapeHtmlVisitTypes(color) + '"></button>';
    });

    $('#visitTypeColorPalette').html(html);
}

function showVisitTypesFlash(type, message) {
    var css = 'alert-info';
    if (type === 'success') {
        css = 'alert-success';
    } else if (type === 'error') {
        css = 'alert-danger';
    } else if (type === 'warning') {
        css = 'alert-warning';
    }

    $('#visitTypesFlash')
        .removeClass('alert-success alert-danger alert-warning alert-info')
        .addClass('alert ' + css)
        .html(escapeHtmlVisitTypes(message || ''))
        .show();
}

function resetVisitTypeForm() {
    $('#visitTypeId').val('');
    $('#visitTypeName').val('');
    $('#visitTypeDuration').val('');
    setVisitTypeColor(getSuggestedVisitTypeColor());
    $('#btnCancelVisitTypeEdit').hide();
}

function fillVisitTypeForm(row) {
    $('#visitTypeId').val((row && row.id_tipo_visita) || '');
    $('#visitTypeName').val((row && row.nome) || '');
    $('#visitTypeDuration').val((row && row.durata_minuti) || '');
    setVisitTypeColor((row && row.colore) || '');
    $('#btnCancelVisitTypeEdit').show();
    $('#visitTypeName').trigger('focus');
}

function renderVisitTypesList() {
    var html = '';

    if (!visitTypesRows.length) {
        html = '<div class="visit-types-empty">'
            + '<div><strong>Nessun tipo visita configurato.</strong></div>'
            + '<div style="margin-top:6px;">Crea il primo tipo visita per far comparire la scelta nel popup appuntamento.</div>'
            + '</div>';
        $('#visitTypesList').html(html);
        return;
    }

    for (var i = 0; i < visitTypesRows.length; i++) {
        var row = visitTypesRows[i] || {};
        var isActive = parseInt(row.attivo || 0, 10) === 1;
        var rowColor = normalizeVisitTypeColor(row.colore) || getSuggestedVisitTypeColor();
        html += '<div class="visit-types-card' + (isActive ? '' : ' is-inactive') + '">';
        html += '  <div class="visit-types-card-header">';
        html += '    <div>';
        html += '      <div class="visit-types-card-title-row">';
        html += '          <span class="visit-types-card-color" style="background:' + escapeHtmlVisitTypes(rowColor) + ';"></span>';
        html += '          <h4 class="visit-types-card-title">' + escapeHtmlVisitTypes(row.nome || '') + '</h4>';
        html += '      </div>';
        html += '      <div class="visit-types-card-meta">' + escapeHtmlVisitTypes((row.durata_minuti || 0) + ' minuti consecutivi') + '</div>';
        html += '    </div>';
        html += '    <span class="visit-types-status ' + (isActive ? 'is-active' : 'is-inactive') + '">'
            + (isActive ? 'Attivo' : 'Disattivo') + '</span>';
        html += '  </div>';
        html += '  <div class="visit-types-actions">';
        html += '    <button type="button" class="btn btn-primary btn-sm js-edit-visit-type" data-id="' + escapeHtmlVisitTypes(row.id_tipo_visita || 0) + '">';
        html += '      <i class="fa fa-pencil"></i> Modifica';
        html += '    </button>';
        html += '    <button type="button" class="btn btn-default btn-sm js-toggle-visit-type" data-id="' + escapeHtmlVisitTypes(row.id_tipo_visita || 0) + '" data-attivo="' + (isActive ? '0' : '1') + '">';
        html += '      <i class="fa ' + (isActive ? 'fa-ban' : 'fa-check') + '"></i> ' + (isActive ? 'Disattiva' : 'Riattiva');
        html += '    </button>';
        html += '  </div>';
        html += '</div>';
    }

    $('#visitTypesList').html(html);
}

function findVisitTypeById(id) {
    id = parseInt(id || 0, 10) || 0;
    for (var i = 0; i < visitTypesRows.length; i++) {
        if ((parseInt(visitTypesRows[i].id_tipo_visita || 0, 10) || 0) === id) {
            return visitTypesRows[i];
        }
    }
    return null;
}

function updateVisitTypesRows(rows) {
    visitTypesRows = $.isArray(rows) ? rows : [];
    renderVisitTypesList();
}

$(function() {
    renderVisitTypeColorPalette();
    renderVisitTypesList();
    setVisitTypeColor(getSuggestedVisitTypeColor());

    $('#btnSaveVisitType').on('click', function() {
        var nome = $.trim($('#visitTypeName').val() || '');
        var durata = parseInt($('#visitTypeDuration').val() || 0, 10) || 0;
        var colore = normalizeVisitTypeColor($('#visitTypeColor').val());

        if (nome === '') {
            showVisitTypesFlash('warning', 'Inserisci il nome del tipo visita.');
            $('#visitTypeName').trigger('focus');
            return;
        }

        if (durata <= 0) {
            showVisitTypesFlash('warning', 'Inserisci una durata valida in minuti.');
            $('#visitTypeDuration').trigger('focus');
            return;
        }

        if (colore === '') {
            showVisitTypesFlash('warning', 'Seleziona un colore valido per il tipo visita.');
            return;
        }

        $.post("<?= base_url('agenda/salva-tipo-visita') ?>", {
            id_tipo_visita: $('#visitTypeId').val(),
            nome: nome,
            durata_minuti: durata,
            colore: colore,
            attivo: 1
        }, function(res) {
            if (!res || res.status !== true) {
                showVisitTypesFlash('error', (res && res.message) ? res.message : 'Errore durante il salvataggio del tipo visita.');
                return;
            }

            updateVisitTypesRows(res.rows || []);
            resetVisitTypeForm();
            showVisitTypesFlash('success', res.message || 'Tipo visita salvato correttamente.');
        }, 'json').fail(function(xhr) {
            var message = 'Errore di rete durante il salvataggio del tipo visita.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            showVisitTypesFlash('error', message);
        });
    });

    $('#btnCancelVisitTypeEdit').on('click', function() {
        resetVisitTypeForm();
    });

    $('#visitTypeColorPalette').on('click', '.visit-type-color-swatch', function() {
        setVisitTypeColor($(this).data('color') || '');
    });

    $('#visitTypeColorCustom').on('input change', function() {
        setVisitTypeColor($(this).val() || '');
    });

    $('#visitTypesList').on('click', '.js-edit-visit-type', function() {
        var row = findVisitTypeById($(this).data('id'));
        if (!row) {
            showVisitTypesFlash('error', 'Tipo visita non trovato.');
            return;
        }
        fillVisitTypeForm(row);
    });

    $('#visitTypesList').on('click', '.js-toggle-visit-type', function() {
        var id = parseInt($(this).data('id') || 0, 10) || 0;
        var attivo = parseInt($(this).data('attivo') || 0, 10) || 0;

        $.post("<?= base_url('agenda/toggle-tipo-visita') ?>", {
            id_tipo_visita: id,
            attivo: attivo
        }, function(res) {
            if (!res || res.status !== true) {
                showVisitTypesFlash('error', (res && res.message) ? res.message : 'Errore durante l aggiornamento del tipo visita.');
                return;
            }

            updateVisitTypesRows(res.rows || []);
            if (parseInt($('#visitTypeId').val() || 0, 10) === id) {
                resetVisitTypeForm();
            }
            showVisitTypesFlash('success', res.message || 'Tipo visita aggiornato correttamente.');
        }, 'json').fail(function(xhr) {
            var message = 'Errore di rete durante l aggiornamento del tipo visita.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            showVisitTypesFlash('error', message);
        });
    });
});
</script>
</body>
</html>
