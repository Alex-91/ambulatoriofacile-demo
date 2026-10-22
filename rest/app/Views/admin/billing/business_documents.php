<?php $documents = $listing['documents'] ?? []; ?>
<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fatture a strutture</title>
<?php foreach (['public/bootstrap/css/bootstrap.min.css','public/dist/css/AdminLTE.css','public/dist/css/skins/_all-skins.min.css','public/assets/css/billing-ts-ui.css'] as $css): ?><link rel="stylesheet" href="<?= base_url($css) ?>"><?php endforeach; ?>
</head><body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione"><div class="wrapper">
<?= view('partials/header', ['menu_items'=>$menu_items]) ?>
<div class="content-wrapper"><section class="content"><div class="row">
<aside class="col-md-3"><?= view('partials/sidebar_admin', ['menu_items'=>$menu_items]) ?></aside>
<main class="col-md-9">
<h1>Fatture a strutture</h1>
<p>Fatture professionali intestate a cliniche, aziende ed enti.</p>
<p><a class="btn btn-info" href="<?= site_url('admin/fatturazione-strutture/sdi') ?>">Invio SDI · dimostrazione</a> <span class="text-muted">XML / Portale AdE, PEC e provider API</span></p>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endforeach; ?>
<?php if (empty($listing['table_available'])): ?>
<div class="alert alert-warning"><?= esc($listing['schema_message'] ?? 'Archivio fatturazione non disponibile.') ?></div>
<?php else: ?>
<p><a class="btn btn-primary" href="<?= site_url('admin/fatturazione-strutture/nuovo') ?>">Nuova fattura a struttura</a>
<a class="btn btn-default" href="<?= site_url('admin/fatturazione-scadenzario') ?>">Scadenzario</a></p>
<div class="box box-primary"><div class="box-body table-responsive">
<table class="table table-striped"><thead><tr><th>Numero / data</th><th>Ragione sociale</th><th>Totale</th><th>Stato</th><th>Azioni</th></tr></thead><tbody>
<?php foreach ($documents as $document): $id = (int) $document['id_billing_document']; ?>
<tr><td><?= esc($document['document_number'] ?: 'Bozza') ?><br><?= esc($document['issue_date']) ?></td>
<td><?= esc($document['patient_name']) ?></td><td>€ <?= number_format((float) $document['amount_total'], 2, ',', '.') ?></td>
<td><?= esc($listing['local_state_labels'][$document['local_state']] ?? $document['local_state']) ?><br><small><?= esc($listing['payment_status_labels'][$document['payment_status']] ?? '') ?></small></td>
<td><a target="_blank" rel="noopener" class="btn btn-default btn-sm" href="<?= site_url('admin/fatturazione-strutture/modifica/'.$id) ?>">Apri</a>
<a target="_blank" rel="noopener" class="btn btn-default btn-sm" href="<?= \App\Services\BillingExportFormat::pdfUrl((int) $id) ?>">PDF</a>
<form method="post" action="<?= site_url('admin/fatturazione-documenti/elimina/'.$id) ?>" style="display:inline" onsubmit="return confirm('Cancellare dagli elenchi? Documento e numero saranno conservati. Eventuali invii fiscali non vengono annullati.');"><?= csrf_field() ?><button type="submit" class="btn btn-danger btn-sm">Cancella</button></form></td></tr>
<?php endforeach; ?>
<?php if (!$documents): ?><tr><td colspan="5">Nessuna fattura a strutture. Crea la prima fattura inserendo i dati del destinatario e le prestazioni.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if (count($documents) >= 250): ?><p>Mostrate le ultime 250 fatture a strutture. L’archivio generale e i report includono anche i documenti precedenti.</p><?php endif; ?>
<?php endif; ?>
</main></div></section></div></div>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script>
<script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
</body></html>
