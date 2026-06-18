<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Storico memo | Agenda</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />

    <style>
        .memo-card {
            border: 1px solid #d2d6de;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            background: #fff;
        }

        .memo-card .titolo {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .memo-card .meta {
            font-size: 12px;
            color: #666;
            margin-bottom: 10px;
        }

        .memo-card .riga {
            margin-bottom: 6px;
        }

        .memo-label {
            font-weight: 600;
        }

        .memo-note {
            white-space: pre-line;
        }

        .box-tools-custom {
            margin-top: 25px;
            text-align: right;
        }

        .pagination-wrapper {
            text-align: center;
            margin-top: 20px;
        }

        .pagination {
            margin: 0;
        }

        @media (max-width: 991px) {
            .box-tools-custom {
                margin-top: 0;
                margin-bottom: 15px;
                text-align: left;
            }
        }
    </style>
</head>
<body class="skin-blue sidebar-mini">
<?php
    $page = max(1, (int)($page ?? 1));
    $perPage = max(1, (int)($perPage ?? 20));
    $total = (int)($total ?? 0);
    $lastPage = max(1, (int)($lastPage ?? 1));
    $selectedDot = (int)($selectedDot ?? 0);
    $lockToCurrentDoctor = !empty($lockToCurrentDoctor);
    $searchTerm = trim((string)($searchTerm ?? ''));

    $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
    $to   = $total > 0 ? min($page * $perPage, $total) : 0;
    $formatMemoDate = static function ($value, bool $withTime = false): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (!$withTime) {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return $value;
            }

            return date('d/m/Y', $timestamp);
        }

        try {
            $utc = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            $rome = $utc->setTimezone(new \DateTimeZone('Europe/Rome'));
            return $rome->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                return $value;
            }

            return date('d/m/Y H:i', $timestamp);
        }
    };

    $prevPage = max(1, $page - 1);
    $nextPage = min($lastPage, $page + 1);
    $startPage = max(1, $page - 2);
    $endPage   = min($lastPage, $page + 2);

    $buildStoricoMemoUrl = static function (int $targetPage) use ($selectedDot, $searchTerm): string {
        $params = [
            'id_dot' => $selectedDot,
            'page'   => $targetPage,
        ];

        if ($searchTerm !== '') {
            $params['search'] = $searchTerm;
        }

        return base_url('agenda/storico-memo?' . http_build_query($params));
    };
?>
<div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <h1>Storico memo</h1>
            <ol class="breadcrumb">
                <li>
                    <a href="#">
                        <i class="fa fa-dashboard"></i> Home
                    </a>
                </li>
                <li>
                    <a href="<?= base_url('agenda') ?>">Agenda</a>
                </li>
                <li class="active">Storico memo</li>
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
                            <h3 class="box-title">
                                <i class="fa fa-history"></i> Memo gia fatte
                            </h3>
                        </div>

                        <div class="box-body">
                            <form id="storicoMemoFilters" method="get" action="<?= base_url('agenda/storico-memo') ?>">
                                <input type="hidden" name="page" value="1">
                                <?php if ($lockToCurrentDoctor): ?>
                                    <input type="hidden" name="id_dot" value="<?= esc($selectedDot) ?>">
                                <?php endif; ?>

                                <div class="row" style="margin-bottom: 15px;">
                                    <div class="col-md-4 form-group">
                                        <label for="id_dot">Dottore / Infermiere</label>
                                        <select id="id_dot" <?= $lockToCurrentDoctor ? '' : 'name="id_dot"' ?> class="form-control" <?= $lockToCurrentDoctor ? 'disabled' : '' ?>>
                                            <?php foreach (($medici ?? []) as $m): ?>
                                                <?php
                                                    $idDot = is_object($m)
                                                        ? (int)($m->id_dot ?? 0)
                                                        : (int)($m['id_dot'] ?? 0);

                                                    $label = is_object($m)
                                                        ? (string)($m->label ?? trim(($m->cognome ?? '') . ' ' . ($m->nome ?? '')))
                                                        : (string)($m['label'] ?? trim(($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? '')));
                                                ?>
                                                <option value="<?= esc($idDot) ?>" <?= $selectedDot === $idDot ? 'selected' : '' ?>>
                                                    <?= esc($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($lockToCurrentDoctor): ?>
                                            <small class="text-muted">Per i dottori lo storico memo mostra solo il proprio profilo.</small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4 form-group">
                                        <label for="search">Cerca nome / cognome</label>
                                        <div class="input-group">
                                            <input type="search" id="search" name="search" class="form-control" value="<?= esc($searchTerm) ?>" placeholder="Es. Rossi Mario">
                                            <span class="input-group-btn">
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="fa fa-search"></i>
                                                </button>
                                            </span>
                                        </div>
                                        <?php if ($searchTerm !== ''): ?>
                                            <div style="margin-top: 6px;">
                                                <a href="<?= base_url('agenda/storico-memo?' . http_build_query(['id_dot' => $selectedDot, 'page' => 1])) ?>" class="btn btn-default btn-xs">
                                                    Azzera ricerca
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="box-tools-custom">
                                            <strong>Totale memo fatte:</strong> <?= $total ?>
                                            <?php if ($total > 0): ?>
                                                <br>
                                                <small>Visualizzate <?= $from ?> - <?= $to ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>

                            <?php if (empty($rows)): ?>
                                <div class="alert alert-info" style="margin-bottom: 0;">
                                    <?= $searchTerm !== ''
                                        ? 'Nessuna memo trovata per il nome/cognome cercato.'
                                        : 'Nessuna memo gia fatta per il dottore selezionato.' ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <div class="memo-card">
                                        <div class="titolo">
                                            <?= esc($row['cliente'] ?: 'Senza cliente') ?>
                                            <span class="label label-success">FATTA</span>
                                        </div>

                                        <div class="meta">
                                            Valida dal:
                                            <strong><?= esc($formatMemoDate($row['data_inizio_validita'] ?? '')) ?></strong>

                                            <?php if (!empty($row['created_at'])): ?>
                                                | Inserita il:
                                                <strong><?= esc($formatMemoDate($row['created_at'], true)) ?></strong>
                                            <?php endif; ?>

                                            <?php if (!empty($row['created_by_username'])): ?>
                                                | Utente:
                                                <strong><?= esc($row['created_by_username']) ?></strong>
                                            <?php endif; ?>

                                            <?php if (!empty($row['data_fatta'])): ?>
                                                | Segnata fatta il:
                                                <strong><?= esc($formatMemoDate($row['data_fatta'], true)) ?></strong>
                                            <?php endif; ?>
                                        </div>

                                        <div class="row">
                                            <div class="col-sm-6 riga">
                                                <span class="memo-label">Telefono:</span>
                                                <?= esc($row['telefono'] ?? '') ?>
                                            </div>
                                            <div class="col-sm-6 riga">
                                                <span class="memo-label">Cellulare:</span>
                                                <?= esc($row['cellulare'] ?? '') ?>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-sm-8 riga">
                                                <span class="memo-label">Indirizzo:</span>
                                                <?= esc($row['indirizzo'] ?? '') ?>
                                            </div>
                                            <div class="col-sm-4 riga">
                                                <span class="memo-label">Citta:</span>
                                                <?= esc($row['citta'] ?? '') ?>
                                            </div>
                                        </div>

                                        <div class="riga memo-note">
                                            <span class="memo-label">Note:</span><br>
                                            <?= nl2br(esc($row['note'] ?? '')) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($lastPage > 1): ?>
                                    <div class="pagination-wrapper">
                                        <ul class="pagination pagination-sm">

                                            <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a href="<?= $page <= 1 ? '#' : $buildStoricoMemoUrl(1) ?>">
                                                    &laquo;
                                                </a>
                                            </li>

                                            <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                                                <a href="<?= $page <= 1 ? '#' : $buildStoricoMemoUrl($prevPage) ?>">
                                                    &#8249;
                                                </a>
                                            </li>

                                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                                <li class="<?= $i === $page ? 'active' : '' ?>">
                                                    <a href="<?= $buildStoricoMemoUrl($i) ?>">
                                                        <?= $i ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <li class="<?= $page >= $lastPage ? 'disabled' : '' ?>">
                                                <a href="<?= $page >= $lastPage ? '#' : $buildStoricoMemoUrl($nextPage) ?>">
                                                    &#8250;
                                                </a>
                                            </li>

                                            <li class="<?= $page >= $lastPage ? 'disabled' : '' ?>">
                                                <a href="<?= $page >= $lastPage ? '#' : $buildStoricoMemoUrl($lastPage) ?>">
                                                    &raquo;
                                                </a>
                                            </li>

                                        </ul>
                                    </div>
                                <?php endif; ?>
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

<script>
$(function () {
    $('#id_dot').on('change', function () {
        $('#storicoMemoFilters').trigger('submit');
    });
});
</script>
</body>
</html>
