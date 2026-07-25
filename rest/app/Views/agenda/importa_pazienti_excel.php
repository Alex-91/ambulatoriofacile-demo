<?php
$previewContext = is_array($previewContext ?? null) ? $previewContext : null;
$previewColumns = is_array($previewContext['columns'] ?? null) ? $previewContext['columns'] : [];
$previewRows = is_array($previewContext['preview_rows'] ?? null) ? $previewContext['preview_rows'] : [];
$columnMapping = is_array($columnMapping ?? null) ? $columnMapping : [];
$importResult = is_array($importResult ?? null) ? $importResult : null;
$errors = is_array($errors ?? null) ? $errors : [];
$mappingWarnings = is_array($mappingWarnings ?? null) ? $mappingWarnings : [];
$targetFieldDefinitions = is_array($targetFieldDefinitions ?? null) ? $targetFieldDefinitions : [];
$previewWarnings = is_array($previewContext['warnings'] ?? null) ? $previewContext['warnings'] : [];
$importWarnings = is_array($importResult['warnings'] ?? null) ? $importResult['warnings'] : [];
$associateAllDoctors = !empty($associateAllDoctors);
$allWarnings = array_values(array_unique(array_merge($previewWarnings, $mappingWarnings, $importWarnings)));
$previewSampleValues = [];

foreach ($previewColumns as $previewColumn) {
    $columnIndex = (int) ($previewColumn['index'] ?? 0);
    if ($columnIndex <= 0) {
        continue;
    }

    $previewSampleValues[$columnIndex] = '';
    foreach ($previewRows as $previewRow) {
        $sampleValue = trim((string) (($previewRow['values'][$columnIndex] ?? '')));
        if ($sampleValue !== '') {
            $previewSampleValues[$columnIndex] = $sampleValue;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <link href="<?= base_url('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <meta charset="UTF-8">
    <title><?= esc(($pageTitle ?? 'Importa pazienti da Excel') . ' | AmbulatorioFacile') ?></title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" />
    <link href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css" rel="stylesheet" />
    <link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" />
    <style>
        .import-intro-box {
            border: 1px solid #dce8ee;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fbfd 0%, #f0f7fa 100%);
            padding: 18px 20px;
            margin-bottom: 16px;
        }

        .import-stat {
            display: inline-block;
            margin: 0 8px 8px 0;
            padding: 6px 10px;
            border-radius: 999px;
            background: #edf6f9;
            color: #1e6770;
            font-size: 12px;
            font-weight: 700;
        }

        .import-help-list {
            margin: 12px 0 0;
            padding-left: 18px;
            color: #58717a;
        }

        .mapping-table td,
        .mapping-table th {
            vertical-align: middle !important;
        }

        .mapping-column-chip {
            display: inline-block;
            min-width: 34px;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eef4f8;
            color: #36515a;
            font-weight: 700;
            text-align: center;
        }

        .mapping-sample {
            color: #617983;
            font-size: 12px;
            line-height: 1.45;
        }

        .result-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .result-card {
            border: 1px solid #dde8ec;
            border-radius: 12px;
            padding: 14px;
            background: #fff;
        }

        .result-card strong {
            display: block;
            font-size: 26px;
            line-height: 1;
            color: #23414a;
            margin-bottom: 8px;
        }

        .result-card span {
            color: #5e7780;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .preview-table th,
        .preview-table td {
            white-space: nowrap;
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
            <h1>Importa pazienti da Excel</h1>
            <p class="text-muted" style="margin:8px 0 0 0;">
                Carica il file Excel, controlla il mapping delle colonne e importa o aggiorna l anagrafica pazienti senza modificare il popup appuntamento.
            </p>
        </section>

        <section class="content">
            <div class="row">
                <div class="col-md-2">
                    <div class="box box-solid">
                        <div class="box-header with-border">
                            <h3 class="box-title">Menu</h3>
                        </div>
                        <div class="box-body no-padding">
                            <?= view('agenda/partials/menu_laterale', [
                                'menuAgenda' => $menuAgenda ?? [],
                                'patientExcelImportEnabled' => true,
                            ]) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-10">
                    <?php if ($errors !== []): ?>
                        <div class="alert alert-danger">
                            <strong>Importazione non completata.</strong>
                            <ul style="margin:8px 0 0 18px; padding:0;">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= esc((string) $error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($allWarnings !== []): ?>
                        <div class="alert alert-warning">
                            <strong>Controlli consigliati prima di confermare</strong>
                            <ul style="margin:8px 0 0 18px; padding:0;">
                                <?php foreach ($allWarnings as $warning): ?>
                                    <li><?= esc((string) $warning) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="import-intro-box">
                        <h3 style="margin:0 0 8px 0;">Flusso guidato</h3>
                        <p style="margin:0; color:#526c74;">
                            Il sistema legge il primo foglio del workbook, mantiene l ordine originale delle colonne e propone automaticamente i campi paziente piu coerenti. Le celle vuote non sovrascrivono i dati gia presenti.
                        </p>
                        <ul class="import-help-list">
                            <li>Aggiorno pazienti esistenti solo quando trovo un match forte su codice fiscale o partita IVA.</li>
                            <li>Se non trovo match, creo un nuovo paziente collegandolo al medico selezionato.</li>
                            <li>Il popup di gestione appuntamento resta invariato: qui lavori solo sull anagrafica completa.</li>
                        </ul>
                    </div>

                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title">1. Carica e analizza il file Excel</h3>
                        </div>
                        <form method="post" action="<?= base_url('agenda/importa-pazienti-excel/preview') ?>" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <div class="box-body">
                                <div class="row">
                                    <div class="col-md-4 form-group">
                                        <label>Medico di riferimento</label>
                                        <select name="id_dot" class="form-control" required>
                                            <?php foreach (($medici ?? []) as $medico): ?>
                                                <?php
                                                $idDot = is_object($medico) ? (int) ($medico->id_dot ?? 0) : (int) ($medico['id_dot'] ?? 0);
                                                $label = is_object($medico)
                                                    ? ($medico->label ?? trim((string) (($medico->cognome ?? '') . ' ' . ($medico->nome ?? ''))))
                                                    : ($medico['label'] ?? trim((string) (($medico['cognome'] ?? '') . ' ' . ($medico['nome'] ?? ''))));
                                                ?>
                                                <option value="<?= esc((string) $idDot) ?>" <?= $idDot === (int) $selectedDot ? 'selected' : '' ?>>
                                                    <?= esc((string) $label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="help-block" style="margin-bottom:0;">I nuovi pazienti verranno collegati a questo medico.</p>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="checkbox" style="margin:30px 0 4px;">
                                            <label>
                                                <input type="checkbox" name="associate_all_doctors" value="1" <?= $associateAllDoctors ? 'checked' : '' ?>>
                                                Associa tutti i medici
                                            </label>
                                        </div>
                                        <p class="help-block" style="margin:0;">
                                            Condivide i pazienti importati con tutto lo spazio agenda.
                                        </p>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label>File Excel</label>
                                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xlsm,.xltx,.xltm" required>
                                        <p class="help-block" style="margin-bottom:0;">
                                            Formati supportati: <code>.xlsx</code>, <code>.xlsm</code>. Limite upload: <?= esc((string) ($maxUploadFileSizeLabel ?? '3MB')) ?>.
                                        </p>
                                    </div>
                                    <div class="col-md-2 form-group">
                                        <label>&nbsp;</label>
                                        <button type="submit" class="btn btn-primary btn-block">
                                            <i class="fa fa-search"></i> Analizza file
                                        </button>
                                        <a href="<?= base_url('agenda/gestione-pazienti?id_dot=' . (int) $selectedDot) ?>" class="btn btn-default btn-block" style="margin-top:8px;">
                                            <i class="fa fa-arrow-left"></i> Torna a gestione pazienti
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <?php if ($previewContext !== null): ?>
                        <div class="box box-info">
                            <div class="box-header with-border">
                                <h3 class="box-title">2. Controlla file e mapping</h3>
                            </div>
                            <div class="box-body">
                                <span class="import-stat">File: <?= esc((string) ($previewContext['original_name'] ?? 'Excel caricato')) ?></span>
                                <span class="import-stat">Foglio: <?= esc((string) ($previewContext['sheet_name'] ?? 'Foglio 1')) ?></span>
                                <span class="import-stat">Intestazione rilevata: riga <?= esc((string) ((int) ($previewContext['header_row_number'] ?? 1))) ?></span>
                                <span class="import-stat">Righe dati: <?= esc((string) ((int) ($previewContext['data_row_count'] ?? 0))) ?></span>

                                <p class="text-muted" style="margin:14px 0 0;">
                                    Ogni colonna viene mostrata nell ordine originale del file. Puoi assegnarla a un campo paziente oppure ignorarla.
                                </p>

                                <form method="post" action="<?= base_url('agenda/importa-pazienti-excel/conferma') ?>" style="margin-top:16px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="import_token" value="<?= esc((string) ($previewContext['token'] ?? ''), 'attr') ?>">

                                    <div class="row" style="margin-bottom:12px;">
                                        <div class="col-md-4 form-group">
                                            <label>Medico finale importazione</label>
                                            <select name="id_dot" class="form-control" required>
                                                <?php foreach (($medici ?? []) as $medico): ?>
                                                    <?php
                                                    $idDot = is_object($medico) ? (int) ($medico->id_dot ?? 0) : (int) ($medico['id_dot'] ?? 0);
                                                    $label = is_object($medico)
                                                        ? ($medico->label ?? trim((string) (($medico->cognome ?? '') . ' ' . ($medico->nome ?? ''))))
                                                        : ($medico['label'] ?? trim((string) (($medico['cognome'] ?? '') . ' ' . ($medico['nome'] ?? ''))));
                                                    ?>
                                                    <option value="<?= esc((string) $idDot) ?>" <?= $idDot === (int) $selectedDot ? 'selected' : '' ?>>
                                                        <?= esc((string) $label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="checkbox" style="margin:24px 0 4px;">
                                                <label>
                                                    <input type="checkbox" name="associate_all_doctors" value="1" <?= $associateAllDoctors ? 'checked' : '' ?>>
                                                    Associa tutti i medici
                                                </label>
                                            </div>
                                            <p class="help-block" style="margin:0;">
                                                Mantiene il paziente disponibile per tutto lo spazio agenda.
                                            </p>
                                        </div>
                                        <div class="col-md-5">
                                            <p class="help-block" style="margin:24px 0 0;">
                                                Le celle vuote vengono ignorate e non cancellano il valore gia presente sul paziente. Se il sistema trova un match forte su codice fiscale o partita IVA aggiorna il paziente esistente, altrimenti crea una nuova anagrafica.
                                            </p>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped mapping-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:90px;">Ordine</th>
                                                    <th>Colonna Excel</th>
                                                    <th style="width:340px;">Campo paziente</th>
                                                    <th>Primo valore visto</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($previewColumns as $column): ?>
                                                    <?php
                                                    $columnIndex = (int) ($column['index'] ?? 0);
                                                    $selectedField = (string) ($columnMapping[$columnIndex] ?? ($previewContext['default_mapping'][$columnIndex] ?? ''));
                                                    $sampleValue = (string) ($previewSampleValues[$columnIndex] ?? '');
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <span class="mapping-column-chip">
                                                                <?= esc((string) ($column['letter'] ?? '')) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <strong><?= esc((string) ($column['header'] ?? ('Colonna ' . $columnIndex))) ?></strong>
                                                            <div class="text-muted" style="font-size:12px;">
                                                                Posizione Excel: <?= esc((string) $columnIndex) ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <select name="column_mapping[<?= esc((string) $columnIndex, 'attr') ?>]" class="form-control">
                                                                <option value="">Ignora questa colonna</option>
                                                                <?php foreach ($targetFieldDefinitions as $fieldKey => $fieldDefinition): ?>
                                                                    <option value="<?= esc((string) $fieldKey, 'attr') ?>" <?= $selectedField === (string) $fieldKey ? 'selected' : '' ?>>
                                                                        <?= esc((string) ($fieldDefinition['label'] ?? $fieldKey)) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                        <td class="mapping-sample">
                                                            <?= $sampleValue !== '' ? esc($sampleValue) : '<span class="text-muted">Nessun valore nel campione</span>' ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:16px;">
                                        <div class="text-muted" style="font-size:12px; line-height:1.5;">
                                            Suggerimento: lascia almeno <strong>codice fiscale</strong> o <strong>partita IVA</strong> mappati se vuoi aggiornare le anagrafiche gia esistenti in modo sicuro.
                                        </div>
                                        <button type="submit" class="btn btn-success">
                                            <i class="fa fa-upload"></i> Importa pazienti
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="box box-default">
                            <div class="box-header with-border">
                                <h3 class="box-title">Anteprima righe lette dal file</h3>
                            </div>
                            <div class="box-body">
                                <?php if ($previewRows === []): ?>
                                    <p class="text-muted" style="margin:0;">Il file e stato letto ma non contiene righe dati dopo l intestazione.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-striped preview-table">
                                            <thead>
                                                <tr>
                                                    <th>Riga Excel</th>
                                                    <?php foreach ($previewColumns as $column): ?>
                                                        <th><?= esc((string) ($column['header'] ?? 'Colonna')) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($previewRows as $previewRow): ?>
                                                    <tr>
                                                        <td><?= esc((string) ((int) ($previewRow['row_number'] ?? 0))) ?></td>
                                                        <?php foreach ($previewColumns as $column): ?>
                                                            <?php $columnIndex = (int) ($column['index'] ?? 0); ?>
                                                            <td><?= esc((string) ($previewRow['values'][$columnIndex] ?? '')) ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($importResult !== null): ?>
                        <div class="box box-success">
                            <div class="box-header with-border">
                                <h3 class="box-title">3. Esito importazione</h3>
                            </div>
                            <div class="box-body">
                                <div class="result-grid">
                                    <div class="result-card">
                                        <strong><?= esc((string) ((int) ($importResult['rows_examined'] ?? 0))) ?></strong>
                                        <span>righe lette</span>
                                    </div>
                                    <div class="result-card">
                                        <strong><?= esc((string) ((int) ($importResult['created_count'] ?? 0))) ?></strong>
                                        <span>nuovi pazienti</span>
                                    </div>
                                    <div class="result-card">
                                        <strong><?= esc((string) ((int) ($importResult['updated_count'] ?? 0))) ?></strong>
                                        <span>pazienti aggiornati</span>
                                    </div>
                                    <div class="result-card">
                                        <strong><?= esc((string) ((int) ($importResult['skipped_count'] ?? 0))) ?></strong>
                                        <span>righe saltate</span>
                                    </div>
                                    <div class="result-card">
                                        <strong><?= esc((string) ((int) ($importResult['error_count'] ?? 0))) ?></strong>
                                        <span>righe con errore</span>
                                    </div>
                                </div>

                                <?php if (!empty($importResult['errors'])): ?>
                                    <div class="alert alert-warning" style="margin-top:18px; margin-bottom:0;">
                                        <strong>Alcune righe richiedono attenzione</strong>
                                        <ul style="margin:8px 0 0 18px; padding:0;">
                                            <?php foreach ((array) $importResult['errors'] as $rowError): ?>
                                                <li>
                                                    Riga <?= esc((string) ((int) ($rowError['row_number'] ?? 0))) ?>:
                                                    <?= esc((string) ($rowError['message'] ?? 'Errore non specificato')) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php elseif ((int) ($importResult['rows_examined'] ?? 0) > 0): ?>
                                    <div class="alert alert-success" style="margin-top:18px; margin-bottom:0;">
                                        Importazione completata. Puoi tornare alla gestione pazienti per controllare l anagrafica aggiornata.
                                    </div>
                                <?php endif; ?>

                                <div style="margin-top:16px;">
                                    <a href="<?= base_url('agenda/gestione-pazienti?id_dot=' . (int) $selectedDot) ?>" class="btn btn-primary">
                                        <i class="fa fa-users"></i> Apri gestione pazienti
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/admin-lte/2.4.18/js/adminlte.min.js"></script>
</body>
</html>
