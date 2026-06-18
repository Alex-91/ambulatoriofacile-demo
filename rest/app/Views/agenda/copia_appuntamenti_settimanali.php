<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Copia appuntamenti settimanale | Agenda</title>
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
</head>
<body class="skin-blue sidebar-mini">
<div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <h1>Copia appuntamenti settimanale</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Copia appuntamenti settimanale</li>
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
                            <h3 class="box-title"><i class="fa fa-calendar-plus-o"></i> Copia appuntamenti su tutte le settimane</h3>
                        </div>

                        <div class="box-body">
                            <form id="formCopiaSettimanale" onsubmit="return false;">
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
                                        <label>Giorno sorgente</label>
                                        <input type="date" name="data_sorgente" id="data_sorgente" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Data finale</label>
                                        <input type="date" name="data_fine" id="data_fine" class="form-control">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>Ora inizio</label>
                                        <select name="ora_inizio" id="ora_inizio" class="form-control">
                                            <option value="">Seleziona prima giorno e dottore</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label>Ora fine</label>
                                        <select name="ora_fine" id="ora_fine" class="form-control">
                                            <option value="">Seleziona prima giorno e dottore</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="alert alert-info">
                                    Verranno copiati tutti gli appuntamenti del giorno selezionato compresi nella fascia oraria scelta,
                                    e replicati ogni 7 giorni sullo stesso dottore fino alla data finale.
                                </div>

                                <button type="button" class="btn btn-primary" id="btnEseguiCopiaSettimanale">
                                    <i class="fa fa-play"></i> Esegui copia settimanale
                                </button>
                            </form>

                            <div id="resultBox" style="display:none; margin-top:15px;">
                                <h4>Esito operazione</h4>
                                <pre id="resultText" style="white-space:pre-wrap; background:#f8f8f8; border:1px solid #ddd; padding:10px;"></pre>
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
    function caricaOrari() {
        var idDot = $('#id_dot').val();
        var data = $('#data_sorgente').val();

        $('#ora_inizio').html('<option value="">Caricamento...</option>');
        $('#ora_fine').html('<option value="">Caricamento...</option>');

        if (!idDot || !data) {
            $('#ora_inizio').html('<option value="">Seleziona prima giorno e dottore</option>');
            $('#ora_fine').html('<option value="">Seleziona prima giorno e dottore</option>');
            return;
        }

        $.get("<?= base_url('agenda/orari-giorno-copia') ?>", {
            id_dot: idDot,
            data: data
        }, function(res) {
            if (!res.status || !res.rows || !res.rows.length) {
                $('#ora_inizio').html('<option value="">Nessun orario disponibile</option>');
                $('#ora_fine').html('<option value="">Nessun orario disponibile</option>');
                if (res.message) {
                    alert(res.message);
                }
                return;
            }

            var htmlStart = '<option value="">Seleziona ora inizio</option>';
            var htmlEnd   = '<option value="">Seleziona ora fine</option>';

            $.each(res.rows, function(i, row) {
                htmlStart += '<option value="' + row.ora_inizio_label + '">' + row.ora_inizio_label + '</option>';
                htmlEnd   += '<option value="' + row.ora_fine_label + '">' + row.ora_fine_label + '</option>';
            });

            $('#ora_inizio').html(htmlStart);
            $('#ora_fine').html(htmlEnd);
        }, 'json').fail(function() {
            $('#ora_inizio').html('<option value="">Errore caricamento</option>');
            $('#ora_fine').html('<option value="">Errore caricamento</option>');
        });
    }

    $('#id_dot, #data_sorgente').on('change', function() {
        caricaOrari();
    });

    caricaOrari();

    $('#btnEseguiCopiaSettimanale').on('click', function() {
        $.post("<?= base_url('agenda/esegui-copia-appuntamenti-settimanali') ?>", $('#formCopiaSettimanale').serialize(), function(res) {
            if (!res.status) {
                alert(res.message || 'Errore');
                return;
            }

            var result = res.result || {};
            var righe = [];

            righe.push(res.message || 'Operazione completata');
            righe.push('');
            righe.push('Appuntamenti sorgente: ' + (result.appuntamenti_sorgente || 0));
            righe.push('Creati: ' + (result.creati || 0));
            righe.push('Giorni bloccati: ' + ((result.giorni_bloccati || []).length));
            righe.push('Slot non trovati: ' + ((result.slot_non_trovati || []).length));
            righe.push('Slot già pieni: ' + ((result.gia_pieni || []).length));
            righe.push('');

            if ((result.giorni_bloccati || []).length) {
                righe.push('--- Giorni bloccati ---');
                $.each(result.giorni_bloccati, function(i, val) {
                    righe.push(val);
                });
                righe.push('');
            }

            if ((result.slot_non_trovati || []).length) {
                righe.push('--- Slot non trovati ---');
                $.each(result.slot_non_trovati, function(i, row) {
                    righe.push((row.data || '') + ' ' + (row.ora_inizio || '') + '-' + (row.ora_fine || '') + ' | ' + (row.paziente || ''));
                });
                righe.push('');
            }

            if ((result.gia_pieni || []).length) {
                righe.push('--- Slot già pieni ---');
                $.each(result.gia_pieni, function(i, row) {
                    righe.push((row.data || '') + ' ' + (row.ora_inizio || '') + '-' + (row.ora_fine || '') + ' | ' + (row.paziente || ''));
                });
            }

            $('#resultText').text(righe.join('\n'));
            $('#resultBox').show();
            alert(res.message || 'Operazione completata');
        }, 'json').fail(function() {
            alert('Errore durante l\'esecuzione.');
        });
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>