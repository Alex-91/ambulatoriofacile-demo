<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Visibilita operatori | Agenda</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <style>
        .target-grid {
            max-height: 520px;
            overflow: auto;
            border: 1px solid #d2d6de;
            border-radius: 4px;
            padding: 10px;
            background: #fff;
        }
        .target-row {
            padding: 8px 6px;
            border-bottom: 1px solid #f1f1f1;
        }
        .target-row:last-child {
            border-bottom: 0;
        }
        .role-badge {
            font-size: 11px;
            margin-left: 6px;
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
            <h1>Visibilita operatori Agenda</h1>
            <ol class="breadcrumb">
                <li><a href="<?= base_url('agenda') ?>"><i class="fa fa-dashboard"></i> Agenda</a></li>
                <li class="active">Visibilita operatori</li>
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
                            <h3 class="box-title"><i class="fa fa-eye"></i> Gestione visibilita</h3>
                        </div>
                        <div class="box-body">
                            <form id="formVisibilita">
                                <div class="row">
                                    <div class="col-md-7 form-group">
                                        <label>Operatore (Dottore / Infermiere)</label>
                                        <select id="id_ope" name="id_ope" class="form-control">
                                            <?php foreach (($operatori ?? []) as $o): ?>
                                                <?php
                                                    $idOpe = (int)($o['id_ope'] ?? 0);
                                                    $idRuo = (int)($o['id_ruo'] ?? 0);
                                                    $tipo  = ($idRuo === 5) ? 'Infermiere' : 'Dottore';
                                                    $lbl   = trim((string)($o['label_operatore'] ?? ''));
                                                ?>
                                                <option value="<?= esc($idOpe) ?>" <?= $idOpe === (int)$selectedOpe ? 'selected' : '' ?>>
                                                    <?= esc($lbl !== '' ? $lbl : ('Operatore #' . $idOpe)) ?> - <?= esc($tipo) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5 form-group">
                                        <label>&nbsp;</label>
                                        <div>
                                            <button type="button" class="btn btn-default" id="btnSelezionaTutti">
                                                <i class="fa fa-check-square-o"></i> Seleziona tutti
                                            </button>
                                            <button type="button" class="btn btn-default" id="btnDeselezionaTutti">
                                                <i class="fa fa-square-o"></i> Deseleziona tutti
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="target-grid">
                                    <?php if (empty($targets ?? [])): ?>
                                        <p class="text-muted">Nessun dottore/infermiere disponibile.</p>
                                    <?php else: ?>
                                        <?php foreach (($targets ?? []) as $t): ?>
                                            <?php
                                                $idDot = (int)($t['id_dot'] ?? 0);
                                                $idRuo = (int)($t['id_ruo'] ?? 0);
                                                $role  = ($idRuo === 5) ? 'Infermiere' : 'Dottore';
                                                $roleBadgeClass = ($idRuo === 5) ? 'label-success' : 'label-info';
                                                $checked = in_array($idDot, ($assegnati ?? []), true);
                                            ?>
                                            <div class="target-row">
                                                <label style="font-weight: normal; margin: 0;">
                                                    <input type="checkbox" name="id_dot[]" value="<?= esc($idDot) ?>" <?= $checked ? 'checked' : '' ?>>
                                                    <?= esc((string)($t['label'] ?? '')) ?>
                                                    <span class="label <?= esc($roleBadgeClass) ?> role-badge"><?= esc($role) ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="text-right" style="margin-top: 15px;">
                                    <button type="button" class="btn btn-primary" id="btnSalvaVisibilita">
                                        <i class="fa fa-save"></i> Salva visibilita
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/2.4.18/js/adminlte.min.js"></script>
<script src="<?= base_url('public/js/agenda-menu.js') ?>"></script>
<script>
$(function () {
    $('#id_ope').on('change', function () {
        window.location.href = "<?= base_url('agenda/visibilita-operatori') ?>?id_ope=" + $(this).val();
    });

    $('#btnSelezionaTutti').on('click', function () {
        $('input[name="id_dot[]"]').prop('checked', true);
    });

    $('#btnDeselezionaTutti').on('click', function () {
        $('input[name="id_dot[]"]').prop('checked', false);
    });

    $('#btnSalvaVisibilita').on('click', function () {
        $.post("<?= base_url('agenda/salva-visibilita-operatori') ?>", $('#formVisibilita').serialize(), function (res) {
            alert(res.message || 'Operazione completata');
        }, 'json');
    });
});
</script>
</body>
</html>
