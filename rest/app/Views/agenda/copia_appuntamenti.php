<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Copia appuntamenti | Agenda</title>
    <meta content='width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' name='viewport'>

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container .select2-selection--single {
            height: 34px !important;
            border-radius: 0 !important;
            border-color: #d2d6de !important;
        }
        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 32px !important;
        }
        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 32px !important;
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
            <h1>Copia appuntamenti</h1>
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
                            <h3 class="box-title"><i class="fa fa-copy"></i> Inserisci lo stesso paziente su più slot liberi</h3>
                        </div>

                        <div class="box-body">
                            <form id="formCopiaAppuntamenti">
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
                                        <label>Giorno</label>
                                        <input type="date" name="data" id="data" class="form-control" value="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>

                                <div id="alertNoAgenda" class="alert alert-warning" style="display:none;"></div>

                                <div class="row">
                                    <div class="col-md-3 form-group">
                                        <label>Ora inizio</label>
                                        <select name="ora_inizio" id="ora_inizio" class="form-control" disabled>
                                            <option value="">Seleziona prima dottore e giorno</option>
                                        </select>
                                    </div>

                                    <div class="col-md-3 form-group">
                                        <label>Ora fine</label>
                                        <select name="ora_fine" id="ora_fine" class="form-control" disabled>
                                            <option value="">Seleziona prima dottore e giorno</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6 form-group">
                                        <label>Paziente</label>
                                        <select name="id_paziente" id="id_paziente" class="form-control" style="width:100%;"></select>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <button type="button" class="btn btn-primary" id="btnCopiaAppuntamenti" disabled>
                                        <i class="fa fa-copy"></i> Crea appuntamenti
                                    </button>
                                </div>
                            </form>

                            <hr>

                            <div id="esitoCopia" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

<script>
function resetOrari(msg) {
    $('#ora_inizio').html('<option value="">' + (msg || 'Nessun orario disponibile') + '</option>').prop('disabled', true);
    $('#ora_fine').html('<option value="">' + (msg || 'Nessun orario disponibile') + '</option>').prop('disabled', true);
    $('#btnCopiaAppuntamenti').prop('disabled', true);
}

function caricaOrari() {
    var idDot = $('#id_dot').val();
    var data  = $('#data').val();

    resetOrari('Caricamento...');
    $('#alertNoAgenda').hide().html('');

    if (!idDot || !data) {
        resetOrari('Seleziona dottore e giorno');
        return;
    }

    $.get("<?= base_url('agenda/orari-giorno-copia') ?>", {
        id_dot: idDot,
        data: data
    }, function(res) {
        if (!res.status) {
            resetOrari('Nessun orario disponibile');
            $('#alertNoAgenda').show().html(res.message || 'Errore nel caricamento degli orari.');
            return;
        }

        var rows = res.rows || [];

        if (!rows.length) {
            resetOrari('Nessun orario configurato');
            $('#alertNoAgenda').show().html(res.message || 'Prima devi creare l\'agenda per questo giorno.');
            return;
        }

        var htmlStart = '<option value="">Seleziona ora inizio</option>';
        var htmlEnd   = '<option value="">Seleziona ora fine</option>';

        rows.forEach(function(r) {
            htmlStart += '<option value="' + r.ora_inizio_label + '">' + r.ora_inizio_label + '</option>';
            htmlEnd   += '<option value="' + r.ora_fine_label + '">' + r.ora_fine_label + '</option>';
        });

        $('#ora_inizio').html(htmlStart).prop('disabled', false);
        $('#ora_fine').html(htmlEnd).prop('disabled', false);
        $('#btnCopiaAppuntamenti').prop('disabled', false);
        $('#alertNoAgenda').hide().html('');
    }, 'json');
}

function initAutocompletePazienti() {
    $('#id_paziente').select2({
        placeholder: 'Cerca paziente',
        minimumInputLength: 2,
        ajax: {
            url: "<?= base_url('agenda/cerca-pazienti') ?>",
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    term: params.term || '',
                    id_dot: $('#id_dot').val()
                };
            },
            processResults: function(data) {
                var rows = (data && data.rows) ? data.rows : [];

                return {
                    results: rows.map(function(r) {
                        var testo = (r.cognome || '') + ' ' + (r.nome || '');
                        if (r.cod_fis) {
                            testo += ' - ' + r.cod_fis;
                        }
                        return {
                            id: r.id_paziente,
                            text: testo
                        };
                    })
                };
            }
        }
    });
}

$(function () {
    initAutocompletePazienti();
    caricaOrari();

    $('#id_dot, #data').on('change', function () {
        $('#id_paziente').val(null).trigger('change');
        caricaOrari();
    });

    $('#btnCopiaAppuntamenti').on('click', function () {
        if ($(this).prop('disabled')) {
            return;
        }

        if (!$('#ora_inizio').val() || !$('#ora_fine').val()) {
            $('#esitoCopia').removeClass().addClass('alert alert-danger').html('Seleziona ora inizio e ora fine.').show();
            return;
        }

        if (!$('#id_paziente').val()) {
            $('#esitoCopia').removeClass().addClass('alert alert-danger').html('Seleziona il paziente.').show();
            return;
        }

        if ($('#ora_inizio').val() >= $('#ora_fine').val()) {
            $('#esitoCopia').removeClass().addClass('alert alert-danger').html('L\'ora fine deve essere successiva all\'ora inizio.').show();
            return;
        }

        if (!confirm('Confermi la creazione degli appuntamenti nella fascia selezionata?')) {
            return;
        }

        $.post("<?= base_url('agenda/esegui-copia-appuntamenti') ?>", $('#formCopiaAppuntamenti').serialize(), function(res) {
            if (!res.status) {
                $('#esitoCopia').removeClass().addClass('alert alert-danger').html(res.message || 'Errore').show();
                return;
            }

            var result = res.result || {};
            var html = '';
            html += '<strong>' + (res.message || 'Operazione completata') + '</strong><br>';
            html += 'Slot liberi trovati: ' + (result.totale_slot_liberi || 0) + '<br>';
            html += 'Appuntamenti creati: ' + (result.creati || 0) + '<br>';
            html += 'Saltati: ' + (result.saltati || 0);

            $('#esitoCopia').removeClass().addClass('alert alert-success').html(html).show();
        }, 'json');
    });
});
</script>

<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>