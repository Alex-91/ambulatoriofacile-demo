<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gestione SMS appuntamenti | Agenda</title>
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
            <h1>Gestione SMS appuntamenti</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Gestione SMS appuntamenti</li>
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
                            <h3 class="box-title"><i class="fa fa-comment"></i> Abilita SMS appuntamenti</h3>
                        </div>

                        <div class="box-body">
                            <form id="formSmsAppuntamenti" onsubmit="return false;">
                                <div class="row">
                                    <div class="col-md-6 form-group">
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

                                    <div class="col-md-6 form-group">
                                        <label>Tipo SMS</label>
                                        <select name="conferma" id="conferma" class="form-control">
                                            <option value="0" <?= ((int)($configCorrente['conferma'] ?? 0) === 0 ? 'selected' : '') ?>>
                                                SMS senza conferma
                                            </option>
                                            <option value="1" <?= ((int)($configCorrente['conferma'] ?? 0) === 1 ? 'selected' : '') ?>>
                                                SMS con conferma
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div class="alert alert-info">
                                    Il dottore selezionato verrà abilitato all'invio SMS appuntamenti. Se già presente, il tipo SMS verrà aggiornato.
                                </div>

                                <button type="button" class="btn btn-primary" id="btnSalvaSms">
                                    <i class="fa fa-save"></i> Salva configurazione
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="box box-success">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-list"></i> Dottori abilitati</h3>
                        </div>

                        <div class="box-body">
                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                    <tr>
                                        <th>Dottore</th>
                                        <th>Conferma</th>
                                        <th style="width:140px;">Azioni</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!empty($abilitati)): ?>
                                        <?php foreach ($abilitati as $row): ?>
                                            <tr>
                                                <td><?= esc(trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? ''))) ?></td>
                                                <td><?= ((int)($row['conferma'] ?? 0) === 1 ? 'Sì' : 'No') ?></td>
                                                <td>
                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-danger btnDisattivaSms"
                                                        data-id="<?= (int)$row['id_sms'] ?>">
                                                        <i class="fa fa-times"></i> Disattiva
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center">Nessun dottore abilitato.</td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
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
    $('#id_dot').on('change', function () {
        var idDot = $(this).val();
        window.location.href = "<?= base_url('agenda/gestione-sms-appuntamenti') ?>?id_dot=" + encodeURIComponent(idDot);
    });

    $('#btnSalvaSms').on('click', function () {
        $.post("<?= base_url('agenda/salva-sms-appuntamenti') ?>", $('#formSmsAppuntamenti').serialize(), function (res) {
            alert(res.message || 'Operazione completata');
            if (res.status) {
                location.reload();
            }
        }, 'json').fail(function () {
            alert('Errore durante il salvataggio.');
        });
    });

    $('.btnDisattivaSms').on('click', function () {
        var idSms = $(this).data('id');

        if (!confirm('Vuoi disattivare gli SMS appuntamenti per questo dottore?')) {
            return;
        }

        $.post("<?= base_url('agenda/disattiva-sms-appuntamenti') ?>", {
            id_sms: idSms
        }, function (res) {
            alert(res.message || 'Operazione completata');
            if (res.status) {
                location.reload();
            }
        }, 'json').fail(function () {
            alert('Errore durante la disattivazione.');
        });
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>