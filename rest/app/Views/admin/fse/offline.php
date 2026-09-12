<?php $report = is_array($report ?? null) ? $report : []; ?>
<!doctype html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>FSE | Collaudo simulato</title>
<link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet"><link href="<?= base_url('public/dist/css/AdminLTE.css') ?>" rel="stylesheet"><link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet"></head>
<body class="skin-blue sidebar-mini"><div class="wrapper"><?= view('partials/header', ['menu_items'=>$menu_items ?? []]) ?><div class="content-wrapper"><section class="content"><div class="row"><aside class="col-md-3"><?= view('partials/sidebar_admin', ['menu_items'=>$menu_items ?? []]) ?></aside><main class="col-md-9"><div class="box box-info"><div class="box-body">
<h1>Collaudo FSE simulato</h1><div class="alert alert-warning"><strong>Solo simulazione offline.</strong> Nessuna chiamata alla Regione, nessun paziente reale, nessun accreditamento ottenuto con queste prove.</div>
<p><a class="btn btn-primary" href="<?= site_url('admin/fse2/laboratorio-toscana') ?>">Apri il laboratorio interattivo Toscana</a></p>
<p>Il laboratorio prova il modello locale del ciclo Toscana. Gli esiti finali sono eventi fittizi: l’interpretazione delle risposte CART reali resta da collaudare.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Scenario</th><th>Prova locale</th><th>Esito</th></tr></thead><tbody>
<?php foreach (($report['scenarios'] ?? []) as $row): ?><tr><td><?= esc($row['id']) ?></td><td><?= esc($row['title']) ?></td><td><?= $row['passed'] ? 'Superata (simulata)' : 'Non superata' ?></td></tr><?php endforeach ?>
</tbody></table></div><p>La verifica crittografica di firme e certificati è coperta da una suite separata; in questi scenari la firma è simulata.</p>
<a class="btn btn-info" href="<?= site_url('admin/fse2/collaudo-offline/report') ?>">Scarica rapporto JSON della simulazione</a> <a class="btn btn-default" href="<?= site_url('admin/fse2') ?>">Torna alla prontezza FSE</a>
</div></div></main></div></section></div></div></body></html>
