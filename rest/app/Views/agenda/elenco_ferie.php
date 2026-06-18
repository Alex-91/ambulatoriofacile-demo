<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Elenco ferie | Agenda</title>
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
            <h1>Elenco ferie</h1>
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
                            <h3 class="box-title"><i class="fa fa-calendar-times-o"></i> Giorni ferie inseriti</h3>
                        </div>

                        <div class="box-body">
                            <form method="get" action="<?= base_url('agenda/elenco-ferie') ?>" class="row" style="margin-bottom:15px;">
                                <div class="col-md-6 form-group">
                                    <label>Medico / Infermiere</label>
                                    <select name="id_dot" class="form-control" onchange="this.form.submit()">
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
                            </form>

                            <div class="form-group">
                                <button type="button" class="btn btn-danger" id="btnDeleteSelected">
                                    <i class="fa fa-trash"></i> Elimina selezionati
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-striped">
                                    <thead>
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" id="checkAll">
                                        </th>
                                        <th>Data</th>
                                        <th>Motivo</th>
                                        <th>Inserita il</th>
                                        <th style="width:120px;">Azioni</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (!empty($rows)): ?>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" class="chkRow" value="<?= (int)$row['id_giorno_bloccato'] ?>">
                                                </td>
                                                <td><?= esc($row['data_agenda']) ?></td>
                                                <td><?= esc($row['motivo'] ?? '') ?></td>
                                                <td><?= esc($row['created_at'] ?? '') ?></td>
                                                <td>
                                                    <button
                                                        type="button"
                                                        class="btn btn-xs btn-danger btnDeleteSingle"
                                                        data-id="<?= (int)$row['id_giorno_bloccato'] ?>">
                                                        <i class="fa fa-trash"></i> Elimina
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">Nessun giorno ferie trovato.</td>
                                        </tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (($lastPage ?? 1) > 1): ?>
                                <nav>
                                    <ul class="pagination">
                                        <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                                            <li class="<?= ((int)$page === $p ? 'active' : '') ?>">
                                                <a href="<?= base_url('agenda/elenco-ferie?id_dot=' . urlencode((string)$selectedDot) . '&page=' . $p) ?>">
                                                    <?= $p ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>

                            <p class="text-muted">
                                Totale record: <?= (int)($total ?? 0) ?> | Pagina <?= (int)($page ?? 1) ?> di <?= (int)($lastPage ?? 1) ?>
                            </p>
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
    $('#checkAll').on('change', function() {
        $('.chkRow').prop('checked', $(this).is(':checked'));
    });

    $(document).on('click', '.btnDeleteSingle', function() {
        var id = $(this).data('id');

        if (!confirm('Vuoi eliminare questo giorno ferie?')) {
            return;
        }

        $.post("<?= base_url('agenda/elimina-giorno-ferie') ?>", {
            id_giorno_bloccato: id
        }, function(res) {
            alert(res.message || 'Operazione completata');
            if (res.status) {
                location.reload();
            }
        }, 'json').fail(function() {
            alert('Errore durante l\'eliminazione.');
        });
    });

    $('#btnDeleteSelected').on('click', function() {
        var ids = $('.chkRow:checked').map(function() {
            return $(this).val();
        }).get();

        if (!ids.length) {
            alert('Seleziona almeno un record.');
            return;
        }

        if (!confirm('Vuoi eliminare i giorni ferie selezionati?')) {
            return;
        }

        $.post("<?= base_url('agenda/elimina-giorni-ferie-selezionati') ?>", {
            ids: ids
        }, function(res) {
            alert(res.message || 'Operazione completata');
            if (res.status) {
                location.reload();
            }
        }, 'json').fail(function() {
            alert('Errore durante l\'eliminazione multipla.');
        });
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
</body>
</html>