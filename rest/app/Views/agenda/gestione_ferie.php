<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestione ferie | Agenda</title>
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
            <h1>Gestione ferie</h1>
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
                            <h3 class="box-title"><i class="fa fa-suitcase"></i> Inserimento ferie periodo</h3>
                        </div>

                        <div class="box-body">
                            <form id="formFerie" onsubmit="return false;">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label>Medico / Infermiere</label>
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
                                        <label>Data inizio ferie</label>
                                        <input type="date" name="data_inizio" id="data_inizio" class="form-control">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Data fine ferie</label>
                                        <input type="date" name="data_fine" id="data_fine" class="form-control">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-12 form-group">
                                        <label>Motivo</label>
                                        <input type="text" name="motivo" id="motivo" class="form-control" value="Ferie" maxlength="255">
                                    </div>
                                </div>

                                <div class="alert alert-warning">
                                    Verranno bloccati solo i giorni senza prenotazioni attive. I giorni già bloccati o con appuntamenti verranno segnalati nel riepilogo.
                                </div>

                                <div class="form-group">
                                    <button type="button" class="btn btn-danger" id="btnBloccaFerie">
                                        <i class="fa fa-lock"></i> Inserisci ferie
                                    </button>
                                </div>
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
    $('#btnBloccaFerie').on('click', function() {
        $.post("<?= base_url('agenda/salva-ferie-periodo') ?>", $('#formFerie').serialize(), function(res) {
            if (!res.status) {
                alert(res.message || 'Errore');
                return;
            }

            var result = res.result || {};
            var righe = [];

            righe.push(res.message || 'Operazione completata');
            righe.push('');
            righe.push('Giorni bloccati: ' + (result.bloccati || 0));
            righe.push('Giorni già bloccati: ' + ((result.gia_bloccati || []).length));
            righe.push('Giorni con prenotazioni: ' + ((result.con_prenotazioni || []).length));
            righe.push('');

            if ((result.gia_bloccati || []).length) {
                righe.push('--- Giorni già bloccati ---');
                $.each(result.gia_bloccati, function(i, val) {
                    righe.push(val);
                });
                righe.push('');
            }

            if ((result.con_prenotazioni || []).length) {
                righe.push('--- Giorni con prenotazioni ---');
                $.each(result.con_prenotazioni, function(i, row) {
                    righe.push((row.data || '') + ' - prenotazioni: ' + (row.prenotazioni || 0));
                });
            }

            $('#resultText').text(righe.join('\n'));
            $('#resultBox').show();
            alert(res.message || 'Operazione completata');
        }, 'json').fail(function() {
            alert('Errore durante l\'inserimento ferie.');
        });
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>