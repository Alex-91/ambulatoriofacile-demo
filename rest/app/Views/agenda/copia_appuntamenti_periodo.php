<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Copia appuntamenti per periodo | Agenda</title>
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
        .autocomplete-box {
            border: 1px solid #ddd;
            border-top: 0;
            max-height: 220px;
            overflow-y: auto;
            background: #fff;
            position: absolute;
            z-index: 999;
            width: calc(100% - 30px);
            display: none;
        }
        .autocomplete-item {
            padding: 8px 10px;
            cursor: pointer;
            border-bottom: 1px solid #f1f1f1;
        }
        .autocomplete-item:hover {
            background: #f7f7f7;
        }
        .result-box {
            margin-top: 15px;
            display: none;
        }
        .result-box pre {
            white-space: pre-wrap;
            background: #f8f8f8;
            border: 1px solid #ddd;
            padding: 10px;
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
            <h1>Copia appuntamenti per periodo</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Copia appuntamenti per periodo</li>
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
                            <h3 class="box-title"><i class="fa fa-clone"></i> Copia paziente su stesso slot nel periodo</h3>
                        </div>

                        <div class="box-body">
                            <form id="formCopiaPeriodo" onsubmit="return false;">
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
                                        <label>Giorno di riferimento</label>
                                        <input type="date" name="giorno_riferimento" id="giorno_riferimento" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Slot da copiare</label>
                                        <select name="slot_ora_inizio" id="slot_ora_inizio" class="form-control">
                                            <option value="">Seleziona prima giorno e dottore</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 form-group" style="position:relative;">
                                        <label>Paziente</label>
                                        <input type="hidden" name="id_paziente" id="id_paziente">
                                        <input type="text" id="search_paziente" class="form-control" placeholder="Cerca paziente per nome/cognome">
                                        <div id="autocompletePazienti" class="autocomplete-box"></div>
                                    </div>

                                    <div class="col-md-3 form-group">
                                        <label>Data inizio</label>
                                        <input type="date" name="data_inizio" id="data_inizio" class="form-control">
                                    </div>

                                    <div class="col-md-3 form-group">
                                        <label>Data fine</label>
                                        <input type="date" name="data_fine" id="data_fine" class="form-control">
                                    </div>
                                </div>

                                <div class="alert alert-info">
                                    Il sistema usera lo stesso slot selezionato nel giorno di riferimento e provera a inserire
                                    l'appuntamento solo nei giorni del periodo che cadono nello stesso giorno della settimana.
                                    Se uno slot e gia occupato o non esiste, verra segnalato nel riepilogo finale.
                                </div>

                                <button type="button" class="btn btn-primary" id="btnEseguiCopiaPeriodo">
                                    <i class="fa fa-play"></i> Esegui copia periodo
                                </button>
                            </form>

                            <div class="result-box" id="resultBox">
                                <h4>Esito operazione</h4>
                                <pre id="resultText"></pre>
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

<script>
$(function () {
    var giornoRiferimentoHasAgenda = false;
    var giornoRiferimentoMessage = '';

    function caricaSlotGiorno() {
        var idDot = $('#id_dot').val();
        var data = $('#giorno_riferimento').val();

        $('#slot_ora_inizio').html('<option value="">Caricamento...</option>');
        giornoRiferimentoHasAgenda = false;
        giornoRiferimentoMessage = '';

        if (!idDot || !data) {
            $('#slot_ora_inizio').html('<option value="">Seleziona prima giorno e dottore</option>');
            return;
        }

        $.get("<?= base_url('agenda/orari-giorno-copia') ?>", {
            id_dot: idDot,
            data: data
        }, function(res) {
            var html = '';

            if (!res.status || !res.has_agenda || !res.rows || !res.rows.length) {
                giornoRiferimentoHasAgenda = false;
                giornoRiferimentoMessage = res.message || 'Prima devi creare l\'agenda per il giorno di riferimento.';
                $('#slot_ora_inizio').html('<option value="">Nessuno slot disponibile</option>');
                return;
            }

            giornoRiferimentoHasAgenda = true;
            giornoRiferimentoMessage = '';
            html += '<option value="">Seleziona slot</option>';

            $.each(res.rows, function(i, row) {
                html += '<option value="' + row.ora_inizio_label + '">' +
                    row.ora_inizio_label + ' - ' + row.ora_fine_label + ' [' + row.stato + ']' +
                    '</option>';
            });

            $('#slot_ora_inizio').html(html);
        }, 'json').fail(function() {
            giornoRiferimentoHasAgenda = false;
            giornoRiferimentoMessage = 'Errore durante il caricamento degli slot del giorno di riferimento.';
            $('#slot_ora_inizio').html('<option value="">Errore caricamento</option>');
        });
    }

    $('#id_dot, #giorno_riferimento').on('change', function() {
        caricaSlotGiorno();
    });

    caricaSlotGiorno();

    var timer = null;

    $('#search_paziente').on('keyup', function() {
        clearTimeout(timer);

        var term = $(this).val();
        var idDot = $('#id_dot').val();

        if (!term || term.length < 2) {
            $('#autocompletePazienti').hide().html('');
            return;
        }

        timer = setTimeout(function() {
            $.get("<?= base_url('agenda/cerca-pazienti') ?>", {
                id_dot: idDot,
                term: term
            }, function(res) {
                var html = '';

                if (!res.status || !res.rows || !res.rows.length) {
                    $('#autocompletePazienti').show().html('<div class="autocomplete-item">Nessun paziente trovato</div>');
                    return;
                }

                $.each(res.rows, function(i, row) {
                    var nome = $.trim((row.cognome || '') + ' ' + (row.nome || ''));
                    html += '<div class="autocomplete-item paz-row" data-id="' + row.id_paziente + '" data-label="' + nome.replace(/"/g, '&quot;') + '">' + nome + '</div>';
                });

                $('#autocompletePazienti').show().html(html);
            }, 'json');
        }, 300);
    });

    $(document).on('click', '.paz-row', function() {
        $('#id_paziente').val($(this).data('id'));
        $('#search_paziente').val($(this).data('label'));
        $('#autocompletePazienti').hide().html('');
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#search_paziente, #autocompletePazienti').length) {
            $('#autocompletePazienti').hide().html('');
        }
    });

    $('#btnEseguiCopiaPeriodo').on('click', function() {
        if (!giornoRiferimentoHasAgenda) {
            alert(giornoRiferimentoMessage || 'Prima devi creare l\'agenda per il giorno di riferimento.');
            return;
        }

        if (!$('#slot_ora_inizio').val()) {
            alert('Seleziona lo slot da copiare.');
            return;
        }

        if (!$('#id_paziente').val()) {
            alert('Seleziona il paziente.');
            return;
        }

        $.post("<?= base_url('agenda/esegui-copia-appuntamenti-periodo') ?>", $('#formCopiaPeriodo').serialize(), function(res) {
            if (!res.status) {
                alert(res.message || 'Errore');
                return;
            }

            var result = res.result || {};
            var righe = [];

            righe.push(res.message || 'Operazione completata');
            righe.push('');

            righe.push('Creati: ' + (result.creati || 0));
            righe.push('Giorni bloccati: ' + ((result.giorni_bloccati || []).length));
            righe.push('Slot gia pieni: ' + ((result.gia_pieni || []).length));
            righe.push('Slot non trovati: ' + ((result.slot_non_trovati || []).length));
            righe.push('');

            if ((result.giorni_bloccati || []).length) {
                righe.push('--- Giorni bloccati ---');
                $.each(result.giorni_bloccati, function(i, val) {
                    righe.push(val);
                });
                righe.push('');
            }

            if ((result.gia_pieni || []).length) {
                righe.push('--- Slot gia pieni ---');
                $.each(result.gia_pieni, function(i, row) {
                    righe.push((row.data || '') + ' ' + (row.ora || ''));
                });
                righe.push('');
            }

            if ((result.slot_non_trovati || []).length) {
                righe.push('--- Slot non trovati ---');
                $.each(result.slot_non_trovati, function(i, row) {
                    righe.push((row.data || '') + ' ' + (row.ora || ''));
                });
            }

            $('#resultText').text(righe.join('\n'));
            $('#resultBox').show();
            alert(res.message || 'Operazione completata');
        }, 'json').fail(function(xhr) {
            var msg = 'Errore durante l\'esecuzione della copia.';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            } else if (xhr && xhr.responseText) {
                var text = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                if (text) {
                    msg = text.substring(0, 300);
                }
            }

            alert(msg);
        });
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>
