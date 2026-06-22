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
    <style>
        .sms-metric-card {
            border: 1px solid #dfe7ef;
            border-radius: 8px;
            background: #fff;
            padding: 16px;
            margin-bottom: 15px;
            min-height: 132px;
        }

        .sms-metric-label {
            color: #6b7c93;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .sms-metric-value {
            color: #1f2d3d;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.15;
            margin-top: 10px;
        }

        .sms-metric-helper {
            color: #6f7d8c;
            font-size: 12px;
            margin-top: 8px;
        }

        .sms-dashboard-table > tbody > tr > td,
        .sms-dashboard-table > thead > tr > th {
            vertical-align: middle;
        }

        .sms-dashboard-empty {
            padding: 18px;
            border: 1px dashed #c7d2de;
            border-radius: 8px;
            color: #6f7d8c;
            background: #fbfcfe;
        }

        .sms-chip {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eef4fb;
            color: #2f5f89;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body class="skin-blue sidebar-mini">
<?php
    $smsDashboard = is_array($smsDashboard ?? null) ? $smsDashboard : [];
    $provider = is_array($smsDashboard['provider'] ?? null) ? $smsDashboard['provider'] : [];
    $spaceSummary = is_array($smsDashboard['space'] ?? null) ? $smsDashboard['space'] : [];
    $selectedSummary = is_array($smsDashboard['selected'] ?? null) ? $smsDashboard['selected'] : [];
    $recentRows = is_array($smsDashboard['recent_rows'] ?? null) ? $smsDashboard['recent_rows'] : [];

    $formatDateTime = static function (?string $value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i');
        } catch (Throwable $e) {
            $timestamp = strtotime($value);
            return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
        }
    };

    $formatDate = static function (?string $value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }

        try {
            return (new DateTimeImmutable($value))->format('d/m/Y');
        } catch (Throwable $e) {
            $timestamp = strtotime($value);
            return $timestamp ? date('d/m/Y', $timestamp) : $value;
        }
    };
?>
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
                    <?php if (!empty($appointmentNotificationsAvailable)): ?>
                        <div class="alert alert-info">
                            La gestione unificata di `SMS / WhatsApp / reminder` ora sta nel centro notifiche dello spazio.
                            Questa pagina resta solo per la configurazione legacy della conferma appuntamenti via risposta paziente.
                            <br>
                            <a href="<?= portal_tenant_space_url('notifiche-appuntamenti') ?>" class="btn btn-default btn-sm" style="margin-top:8px;">
                                <i class="fa fa-commenting"></i> Apri centro notifiche appuntamenti
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="box box-info">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-bar-chart"></i> Dashboard SMS inviati</h3>
                        </div>

                        <div class="box-body">
                            <?php if (($provider['channel'] ?? 'wa') !== 'sms'): ?>
                                <div class="alert alert-warning">
                                    Il canale reminder configurato adesso e <?= esc((string) ($provider['channel_label'] ?? 'WhatsApp')) ?>.
                                    La dashboard qui sotto mostra comunque lo storico degli invii SMS gia effettuati.
                                </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-3 col-sm-6">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">Provider attuale</div>
                                        <div class="sms-metric-value" style="font-size:22px;">
                                            <?= esc((string) ($provider['provider_label'] ?? 'Aruba SMS')) ?>
                                        </div>
                                        <div class="sms-metric-helper">
                                            Canale: <?= esc((string) ($provider['channel_label'] ?? 'SMS')) ?>
                                            <?php if (!empty($provider['sms_sender'])): ?>
                                                <br>Sender: <?= esc((string) $provider['sms_sender']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-3 col-sm-6">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">SMS inviati nello spazio</div>
                                        <div class="sms-metric-value"><?= (int) ($spaceSummary['total_sent'] ?? 0) ?></div>
                                        <div class="sms-metric-helper">Storico totale disponibile</div>
                                    </div>
                                </div>

                                <div class="col-md-3 col-sm-6">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">Ultimi 30 giorni spazio</div>
                                        <div class="sms-metric-value"><?= (int) ($spaceSummary['sent_recent_days'] ?? 0) ?></div>
                                        <div class="sms-metric-helper">Invii SMS recenti dello spazio</div>
                                    </div>
                                </div>

                                <div class="col-md-3 col-sm-6">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">Dottori con invii</div>
                                        <div class="sms-metric-value"><?= (int) ($spaceSummary['active_doctors'] ?? 0) ?></div>
                                        <div class="sms-metric-helper">Con almeno un SMS inviato</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 col-sm-6">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">SMS del dottore selezionato</div>
                                        <div class="sms-metric-value"><?= (int) ($selectedSummary['total_sent'] ?? 0) ?></div>
                                        <div class="sms-metric-helper">Storico totale per il profilo selezionato</div>
                                    </div>
                                </div>

                                <div class="col-md-4 col-sm-6">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">Ultimi 30 giorni dottore</div>
                                        <div class="sms-metric-value"><?= (int) ($selectedSummary['sent_recent_days'] ?? 0) ?></div>
                                        <div class="sms-metric-helper">Invii recenti per il profilo selezionato</div>
                                    </div>
                                </div>

                                <div class="col-md-4 col-sm-12">
                                    <div class="sms-metric-card">
                                        <div class="sms-metric-label">Ultimo invio registrato</div>
                                        <div class="sms-metric-value" style="font-size:22px;">
                                            <?= esc($formatDateTime((string) ($selectedSummary['last_sent_at'] ?? ''))) ?>
                                        </div>
                                        <div class="sms-metric-helper">Riferito al dottore selezionato</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="box box-default">
                                        <div class="box-header with-border">
                                            <h3 class="box-title">Cronologia ultimi 14 giorni</h3>
                                        </div>
                                        <div class="box-body" style="padding:0;">
                                            <?php if (!empty($selectedSummary['daily_rows'])): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-striped sms-dashboard-table" style="margin-bottom:0;">
                                                        <thead>
                                                        <tr>
                                                            <th>Giorno invio</th>
                                                            <th class="text-right">SMS</th>
                                                        </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach (($selectedSummary['daily_rows'] ?? []) as $row): ?>
                                                            <tr>
                                                                <td><?= esc($formatDate((string) ($row['day'] ?? ''))) ?></td>
                                                                <td class="text-right"><span class="sms-chip"><?= (int) ($row['count'] ?? 0) ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="sms-dashboard-empty">
                                                    Nessun invio SMS registrato negli ultimi giorni per il dottore selezionato.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="box box-default">
                                        <div class="box-header with-border">
                                            <h3 class="box-title">Ripartizione per dottore</h3>
                                        </div>
                                        <div class="box-body" style="padding:0;">
                                            <?php if (!empty($spaceSummary['by_doctor'])): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-striped sms-dashboard-table" style="margin-bottom:0;">
                                                        <thead>
                                                        <tr>
                                                            <th>Dottore</th>
                                                            <th class="text-right">SMS inviati</th>
                                                        </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach (($spaceSummary['by_doctor'] ?? []) as $row): ?>
                                                            <tr>
                                                                <td><?= esc((string) ($row['doctor_label'] ?? '')) ?></td>
                                                                <td class="text-right"><span class="sms-chip"><?= (int) ($row['count'] ?? 0) ?></span></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php else: ?>
                                                <div class="sms-dashboard-empty">
                                                    Nessun invio SMS disponibile nello storico dello spazio.
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="box box-default" style="margin-bottom:0;">
                                <div class="box-header with-border">
                                    <h3 class="box-title">Ultimi invii SMS</h3>
                                </div>
                                <div class="box-body" style="padding:0;">
                                    <?php if (!empty($recentRows)): ?>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-striped sms-dashboard-table" style="margin-bottom:0;">
                                                <thead>
                                                <tr>
                                                    <th>Inviato il</th>
                                                    <th>Dottore</th>
                                                    <th>Paziente</th>
                                                    <th>Appuntamento</th>
                                                    <th>Destinatario</th>
                                                    <th>Provider ID</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($recentRows as $row): ?>
                                                    <tr>
                                                        <td><?= esc($formatDateTime((string) ($row['sent_at'] ?? ''))) ?></td>
                                                        <td><?= esc((string) ($row['doctor_label'] ?? '')) ?></td>
                                                        <td><?= esc((string) ($row['patient_label'] ?? '')) ?></td>
                                                        <td>
                                                            <?= esc($formatDate((string) ($row['target_date'] ?? ''))) ?>
                                                            <?php if (!empty($row['target_time'])): ?>
                                                                <br><small class="text-muted">Ore <?= esc((string) $row['target_time']) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= esc((string) ($row['recipient'] ?? '')) ?></td>
                                                        <td><?= esc((string) (($row['provider_id'] ?? '') !== '' ? $row['provider_id'] : '-')) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="sms-dashboard-empty" style="margin:15px;">
                                            Nessun invio SMS registrato per il filtro selezionato.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

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
