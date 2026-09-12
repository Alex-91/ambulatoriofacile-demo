<?php
$lab=is_array($lab ?? null) ? $lab : [];
$profiles=['SITE_A_PRIVATE'=>'Sede A · privato (fittizio)','SITE_B_SSR'=>'Sede B · SSR (fittizio)'];
$labels=['draft'=>'Bozza','prepared'=>'Artefatti simulati pronti','signed'=>'Firma simulata','pending'=>'Operazione simulata in attesa',
    'published'=>'Pubblicazione simulata','rejected'=>'Scarto simulato','superseded'=>'Sostituito nella simulazione','deleted'=>'Cancellato nella simulazione'];
$commands=['prepare'=>'Prepara artefatti simulati','sign'=>'Simula firma','revise'=>'Apri revisione sintetica',
    'begin_create'=>'Simula richiesta creazione','begin_replace'=>'Simula richiesta sostituzione','begin_metadata'=>'Simula aggiornamento metadati',
    'begin_delete'=>'Simula richiesta cancellazione','accepted'=>'Simula ricezione (non finale)','rejected'=>'Simula rifiuto',
    'timeout'=>'Simula timeout','confirm_ok'=>'Simula conferma finale positiva','confirm_ko'=>'Simula conferma finale negativa',
    'import_signed_snapshot'=>'Snapshot verificato dal laboratorio applicativo isolato'];
?>
<!doctype html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FSE | Laboratorio Toscana</title>
<link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet"><link href="<?= base_url('public/assets/fontawesome/css/all.min.css') ?>" rel="stylesheet"><link href="<?= base_url('public/assets/fontawesome/css/v4-shims.min.css') ?>" rel="stylesheet"><link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet"><link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet">
<style>.fse-lab-actions form{display:inline-block;margin:4px 4px 4px 0}.fse-lab-card{border:1px solid #ddd;padding:16px;margin:16px 0}.fse-lab-id{overflow-wrap:anywhere}</style></head>
<body class="skin-blue sidebar-mini"><div class="wrapper"><?= view('partials/header',['menu_items'=>$menu_items ?? []]) ?><div class="content-wrapper"><section class="content"><div class="row"><aside class="col-md-3"><?= view('partials/sidebar_admin',['menu_items'=>$menu_items ?? []]) ?></aside><main class="col-md-9"><div class="box box-info"><div class="box-body">
<h1>Laboratorio Toscana</h1><div class="alert alert-warning"><strong>Solo documenti sintetici e risposte simulate.</strong> Nessun invio alla Regione, nessuna firma clinica, nessun referto reale modificato. Questa pagina non attesta un accreditamento.</div>
<p>Il laboratorio conserva separatamente documenti, revisioni e operazioni dello spazio. I valori dei metadati sono fittizi: non configurano l’oscuramento reale. Le risposte CART effettive restano da integrare e collaudare.</p>
<?php if (!empty($success)): ?><div class="alert alert-info" role="status"><?= esc($success) ?></div><?php endif ?>
<?php if (!empty($errors)): ?><div class="alert alert-danger" role="alert"><?= esc($errors['generic'] ?? 'Passaggio non consentito.') ?></div><?php endif ?>
<form method="post" action="<?= site_url('admin/fse2/laboratorio-toscana/azione') ?>" class="form-inline">
<?= csrf_field() ?><input type="hidden" name="command" value="new"><input type="hidden" name="revision" value="<?= (int)($lab['revision'] ?? 0) ?>">
<label for="lab-profile">Profilo sintetico</label> <select id="lab-profile" name="profile" class="form-control"><?php foreach ($profiles as $key=>$label): ?><option value="<?= esc($key) ?>"><?= esc($label) ?></option><?php endforeach ?></select>
<button class="btn btn-primary">Crea documento sintetico</button></form>
<?php if (empty($lab['documents'])): ?><p style="margin-top:16px">Nessun documento nel laboratorio. Crea il primo documento sintetico per iniziare.</p><?php endif ?>
<?php foreach (($lab['documents'] ?? []) as $doc):
    $op=$lab['operations'][$doc['pending'] ?? ''] ?? null; $available=[];
    if ($doc['state']==='draft') $available=['prepare'];
    elseif ($doc['state']==='prepared') $available=['sign'];
    elseif ($doc['state']==='signed') $available=[$doc['previous'] ? 'begin_replace' : 'begin_create'];
    elseif ($doc['state']==='published' && !$doc['open_revision']) $available=['revise','begin_metadata','begin_delete'];
    elseif ($op && $op['state']==='sent') $available=['accepted','rejected','timeout'];
    elseif ($op && $op['state']==='accepted') $available=['confirm_ok','confirm_ko'];
    if (!empty($doc['source_snapshot'])) $available=array_values(array_diff($available,['revise']));
?>
<article class="fse-lab-card" data-lab-document="<?= esc($doc['id']) ?>"><h2><?= esc($doc['id']) ?> · versione <?= (int)$doc['version'] ?></h2>
<p><?= esc($profiles[$doc['profile']] ?? (!empty($doc['source_snapshot']) ? 'Profilo congelato dello snapshot applicativo' : 'Profilo sconosciuto')) ?> — <strong><?= esc(!empty($doc['source_snapshot']) && $doc['state']==='signed' ? 'Snapshot di PDF con firma di test verificata' : ($labels[$doc['state']] ?? 'Stato sconosciuto')) ?></strong></p>
<?php if (!empty($doc['source_snapshot'])): ?><div class="alert alert-warning" data-fse-source-snapshot><strong>Snapshot, non collegamento operativo al FSE.</strong>
<p>Origine: referto sintetico #<?= (int)$doc['source_snapshot']['document_id'] ?> del laboratorio applicativo. Le operazioni sotto non aggiornano il referto sorgente, i suoi metadati o la sua firma. Il controllo degli artefatti vale al momento dell’importazione.</p>
<p class="fse-lab-id">SHA-256 PDF firmato: <?= esc($doc['source_snapshot']['signed_pdf_sha256']) ?></p>
<a href="<?= site_url('admin/fse2/documenti/modifica/'.(int)$doc['source_snapshot']['document_id']) ?>">Torna al referto del laboratorio applicativo</a>. Per una correzione prepara e firma lì la nuova versione, poi collegala al laboratorio.</div><?php endif ?>
<p>Revisione scheda: <?= (int)$doc['revision'] ?> · Versione metadati sintetici: <?= (int)$doc['metadata_version'] ?></p>
<?php if ($doc['previous']): ?><p>Revisione di <?= esc($doc['previous']) ?>. L’originale rimane conservato.</p><?php endif ?>
<?php if ($doc['open_revision']): ?><p>Revisione aperta: <?= esc($doc['open_revision']) ?>. Altre operazioni sull’originale sono bloccate.</p><?php endif ?>
<?php if ($doc['superseded_by']): ?><p>Sostituito nella simulazione da <?= esc($doc['superseded_by']) ?>. Originale non cancellato.</p><?php endif ?>
<?php if ($op): ?><p class="fse-lab-id">Operazione <?= esc($op['kind']) ?>: <?= esc($op['id']) ?><br>Workflow simulato: <?= esc($op['workflow'] ?? 'non disponibile') ?></p>
<?php if ($op['state']==='uncertain'): ?><div class="alert alert-warning">Esito incerto: nessun reinvio o sblocco disponibile. Conservare il rapporto. Per provare un altro scenario creare un nuovo documento sintetico.</div><?php elseif ($op['state']==='accepted'): ?><div class="alert alert-info">Ricezione simulata, non esito finale. La conferma deve appartenere a questa operazione.</div><?php endif ?><?php endif ?>
<div class="fse-lab-actions"><?php foreach ($available as $action): ?><form method="post" action="<?= site_url('admin/fse2/laboratorio-toscana/azione') ?>">
<?= csrf_field() ?><input type="hidden" name="command" value="<?= esc($action) ?>"><input type="hidden" name="document" value="<?= esc($doc['id']) ?>"><input type="hidden" name="revision" value="<?= (int)$doc['revision'] ?>"><input type="hidden" name="profile" value="<?= esc($doc['profile']) ?>"><input type="hidden" name="operation" value="<?= esc($op['id'] ?? '') ?>"><input type="hidden" name="workflow" value="<?= esc($op['workflow'] ?? '') ?>">
<button class="btn btn-default"><?= esc($commands[$action]) ?></button></form><?php endforeach ?></div></article>
<?php endforeach ?>
<h2>Storico dei passaggi simulati</h2><div class="table-responsive"><table class="table table-striped"><thead><tr><th>Sequenza</th><th>Documento</th><th>Passaggio</th><th>Operatore</th><th>UTC</th></tr></thead><tbody>
<?php foreach (array_slice(array_reverse($lab['events'] ?? []),0,30) as $event): ?><tr><td><?= (int)$event['sequence'] ?></td><td><?= esc($event['document']) ?></td><td><?= esc($commands[$event['command']] ?? 'Nuovo documento sintetico') ?></td><td><?= (int)$event['actor'] ?></td><td><?= esc($event['at']) ?></td></tr><?php endforeach ?>
</tbody></table></div><p>Ultimi 30 passaggi; il rapporto contiene lo storico completo del laboratorio.</p>
<a class="btn btn-info" href="<?= site_url('admin/fse2/laboratorio-toscana/report') ?>">Scarica rapporto — solo simulazione</a> <a class="btn btn-default" href="<?= site_url('admin/fse2/collaudo-offline') ?>">Scenari automatici</a> <a class="btn btn-default" href="<?= site_url('admin/fse2') ?>">Prontezza FSE</a>
</div></div></main></div></section></div></div></body></html>
