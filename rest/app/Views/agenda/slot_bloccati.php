<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Slot bloccati | Agenda</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
        .status-chip {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-chip-active {
            background: #fcf8e3;
            color: #8a6d3b;
        }

        .status-chip-orphan {
            background: #f2dede;
            color: #a94442;
        }

        .table td,
        .table th {
            vertical-align: middle !important;
        }

        .slot-meta {
            color: #666;
            font-size: 12px;
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
            <h1>Slot bloccati</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li><a href="<?= base_url('agenda') ?>">Agenda</a></li>
                <li class="active">Slot bloccati</li>
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
                    <?php if (session()->getFlashdata('success')): ?>
                        <div class="alert alert-success">
                            <?= esc((string)session()->getFlashdata('success')) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (session()->getFlashdata('error')): ?>
                        <div class="alert alert-danger">
                            <?= esc((string)session()->getFlashdata('error')) ?>
                        </div>
                    <?php endif; ?>

                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title">
                                <i class="fa fa-unlock-alt"></i> Controllo blocchi temporanei slot
                            </h3>
                        </div>

                        <div class="box-body">
                            <form method="get" action="<?= base_url('agenda/slot-bloccati') ?>" class="row">
                                <div class="col-md-6 form-group">
                                    <label for="id_dot">Dottore</label>
                                    <select name="id_dot" id="id_dot" class="form-control">
                                        <option value="0">Seleziona un dottore</option>
                                        <?php foreach (($medici ?? []) as $medico): ?>
                                            <?php
                                            $idDot = (int)($medico->id_dot ?? 0);
                                            $label = trim((string)($medico->label ?? ''));
                                            ?>
                                            <option value="<?= $idDot ?>" <?= ((int)($selectedDot ?? 0) === $idDot) ? 'selected' : '' ?>>
                                                <?= esc($label !== '' ? $label : ('Dottore #' . $idDot)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 form-group" style="padding-top:25px;">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa fa-filter"></i> Filtra
                                    </button>
                                    <a href="<?= base_url('agenda/slot-bloccati') ?>" class="btn btn-default">
                                        <i class="fa fa-undo"></i> Reset
                                    </a>
                                </div>
                            </form>

                            <div class="alert alert-info" style="margin-bottom:20px;">
                                La ricerca parte solo dopo aver selezionato un dottore, cosi la pagina resta veloce anche con molti professionisti.
                            </div>

                            <?php if (empty($hasSearched ?? false)): ?>
                                <div class="alert alert-warning" style="margin-bottom:0;">
                                    Seleziona un dottore e premi <b>Filtra</b> per vedere eventuali slot bloccati.
                                </div>
                            <?php elseif (empty($rows ?? [])): ?>
                                <div class="alert alert-success" style="margin-bottom:0;">
                                    Nessuno slot bloccato trovato per il dottore selezionato.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped">
                                        <thead>
                                            <tr>
                                                <th>Data</th>
                                                <th>Ora</th>
                                                <th>Stato slot</th>
                                                <th>Lock</th>
                                                <th>Operatore</th>
                                                <th>Appuntamenti</th>
                                                <th style="width:140px;">Azione</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $row): ?>
                                                <?php
                                                $dataSlot = !empty($row['data_slot']) ? date('d/m/Y', strtotime((string)$row['data_slot'])) : '';
                                                $oraDa = !empty($row['ora_inizio']) ? date('H:i', strtotime((string)$row['ora_inizio'])) : '';
                                                $oraA = !empty($row['ora_fine']) ? date('H:i', strtotime((string)$row['ora_fine'])) : '';
                                                $hasActiveLock = !empty($row['is_active_lock']);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?= esc($dataSlot) ?>
                                                        <div class="slot-meta">ID slot <?= (int)($row['id_slot'] ?? 0) ?></div>
                                                    </td>
                                                    <td><?= esc(trim($oraDa . ' - ' . $oraA, ' -')) ?></td>
                                                    <td><?= esc((string)($row['stato'] ?? '')) ?></td>
                                                    <td>
                                                        <?php if ($hasActiveLock): ?>
                                                            <span class="status-chip status-chip-active">Lock attivo</span>
                                                            <div class="slot-meta">
                                                                Da <?= esc(!empty($row['locked_at']) ? date('d/m/Y H:i:s', strtotime((string)$row['locked_at'])) : '') ?><br>
                                                                Fino a <?= esc(!empty($row['expires_at']) ? date('d/m/Y H:i:s', strtotime((string)$row['expires_at'])) : '') ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="status-chip status-chip-orphan">Blocco orfano</span>
                                                            <div class="slot-meta">Nessun lock attivo trovato</div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($row['id_ope_lock'])): ?>
                                                            #<?= (int)$row['id_ope_lock'] ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= (int)($row['appuntamenti_attivi'] ?? 0) ?></td>
                                                    <td>
                                                        <form method="post" action="<?= base_url('agenda/sblocca-slot-bloccato') ?>" onsubmit="return confirm('Confermi lo sblocco di questo slot?');">
                                                            <input type="hidden" name="id_slot" value="<?= (int)($row['id_slot'] ?? 0) ?>">
                                                            <input type="hidden" name="id_dot_filter" value="<?= (int)($selectedDot ?? 0) ?>">
                                                            <button type="submit" class="btn btn-warning btn-sm">
                                                                <i class="fa fa-unlock"></i> Sblocca
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
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
</body>
</html>
