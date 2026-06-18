<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Repair slot extra ricorrenti | Agenda</title>
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
            background: rgba(0, 0, 0, .45);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .loading-box {
            background: #fff;
            border-radius: 6px;
            padding: 18px 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .25);
            font-size: 15px;
            color: #333;
        }

        .result-box pre {
            white-space: pre-wrap;
            background: #f7f7f7;
            border: 1px solid #ddd;
            padding: 12px;
            border-radius: 4px;
            max-height: 520px;
            overflow: auto;
        }

        .warning-box {
            margin-top: 15px;
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
            <h1>Repair slot extra ricorrenti</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Repair slot extra ricorrenti</li>
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
                            <h3 class="box-title"><i class="fa fa-wrench"></i> Sync ricorrenze extra da legacy</h3>
                        </div>

                        <div class="box-body">
                            <form id="formRecurringRepair">
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
                                        <label>Database legacy</label>
                                        <input type="text" name="source_db" class="form-control" value="<?= esc($defaultSourceDb ?? 'farmacia') ?>">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Data inizio</label>
                                        <input type="date" name="date_from" class="form-control" value="<?= esc($defaultDateFrom ?? date('Y-m-d')) ?>">
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label>Data fine</label>
                                        <input type="date" name="date_to" class="form-control" value="<?= esc($defaultDateTo ?? date('Y-m-d')) ?>">
                                    </div>
                                </div>

                                <div class="text-right">
                                    <button type="button" class="btn btn-default" id="btnDryRun">
                                        <i class="fa fa-search"></i> Esegui dry-run
                                    </button>
                                    <button type="button" class="btn btn-danger" id="btnApply">
                                        <i class="fa fa-check"></i> Applica repair
                                    </button>
                                </div>
                            </form>

                            <div class="alert alert-warning warning-box">
                                Il repair inserisce gli slot extra ricorrenti mancanti dal legacy e rimuove solo i tail slot di configurazione sovrapposti se sono liberi. Gli slot con appuntamenti attivi non vengono cancellati.
                            </div>

                            <div id="resultBox" class="result-box" style="display:none;">
                                <div class="alert alert-info">
                                    <strong>Esito elaborazione</strong>
                                </div>
                                <pre id="resultText"></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="repairLoadingOverlay" class="loading-overlay">
    <div class="loading-box">
        <i class="fa fa-spinner fa-spin"></i>
        <span id="repairLoadingText">Elaborazione in corso...</span>
    </div>
</div>

<script src="https://code.jquery.com/jquery-2.1.4.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>

<script>
$(function () {
    function setLoading(on, text) {
        if (on) {
            $('#repairLoadingText').text(text || 'Elaborazione in corso...');
            $('#repairLoadingOverlay').css('display', 'flex');
            $('#btnDryRun, #btnApply, #id_dot').prop('disabled', true);
            return;
        }

        $('#repairLoadingOverlay').hide();
        $('#btnDryRun, #btnApply, #id_dot').prop('disabled', false);
    }

    function renderResult(payload) {
        $('#resultText').text(JSON.stringify(payload, null, 2));
        $('#resultBox').show();
    }

    function submitRepair(applyMode) {
        var data = $('#formRecurringRepair').serializeArray();
        data.push({ name: 'apply', value: applyMode ? '1' : '0' });

        if (applyMode && !confirm('Confermi di voler applicare davvero il repair sul dottore selezionato?')) {
            return;
        }

        setLoading(true, applyMode ? 'Applicazione repair in corso...' : 'Dry-run in corso...');

        $.ajax({
            url: "<?= base_url('agenda/repair-recurring-extra-slots') ?>",
            method: 'POST',
            data: $.param(data),
            dataType: 'json'
        }).done(function (res) {
            renderResult(res);
            if (res && res.message) {
                alert(res.message);
            }
        }).fail(function (xhr) {
            var message = 'Errore durante l\'esecuzione del repair.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }

            renderResult(xhr.responseJSON || { status: false, message: message });
            alert(message);
        }).always(function () {
            setLoading(false);
        });
    }

    $('#btnDryRun').on('click', function () {
        submitRepair(false);
    });

    $('#btnApply').on('click', function () {
        submitRepair(true);
    });
});
</script>
</body>
</html>
