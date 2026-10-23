<?php helper(['portal','form']); ?>
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Prenotazione online | AmbulatorioFacile</title>
<link rel="stylesheet" href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>"><link rel="stylesheet" href="<?= base_url('public/dist/css/AdminLTE.css') ?>"><link rel="stylesheet" href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>"></head>
<body class="skin-blue sidebar-mini"><div class="wrapper"><?= view('partials/header',['menu_items'=>[],'portal_console_header'=>true]) ?><div class="content-wrapper"><main class="content">
<h1>Prenotazione online</h1><p>Abilita o disabilita il modulo nei singoli spazi. Ogni cliente gestisce la configurazione nel proprio spazio.</p>
<?php if($error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif ?>
<?php if($success): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif ?>
<form method="get" action="<?= esc(portal_platform_url('prenotazione-online'),'attr') ?>"><label for="pb-tenant">Spazio</label><select class="form-control" id="pb-tenant" name="tenant_id"><option value="">Seleziona uno spazio</option><?php foreach($tenants as $row): ?><option value="<?= (int)$row['id_tenant'] ?>" <?= (int)($tenant['id_tenant']??0)===(int)$row['id_tenant']?'selected':'' ?>><?= esc($row['tenant_name']) ?></option><?php endforeach ?></select><button class="btn btn-default" type="submit">Apri</button></form>
<?php if($tenant): ?>
<?php if(!$ready): ?><div class="alert alert-warning">Il modulo deve essere installato per questo spazio prima dell’attivazione.</div><?php endif ?>
<form method="post" action="<?= esc(portal_platform_url('prenotazione-online/save'),'attr') ?>"><?= csrf_field() ?><input type="hidden" name="tenant_id" value="<?= (int)$tenant['id_tenant'] ?>"><input type="hidden" name="revision" value="<?= (int)$state['revision'] ?>">
<div class="box box-primary"><div class="box-body">
<label><input type="checkbox" name="enabled" value="1" <?= $state['enabled']?'checked':'' ?> <?= !$ready?'disabled':'' ?>> Attiva prenotazione online nello spazio</label>
<p>Il responsabile dello spazio sceglie professionisti, prestazioni, sedi, recapiti, informativa privacy, regole e workflow dalla propria amministrazione.</p>
<button type="submit" class="btn btn-primary" <?= !$ready?'disabled':'' ?>>Salva abilitazione</button></div></div></form><?php endif ?>
</main></div></div><script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script><script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script><script src="<?= base_url('public/dist/js/app.min.js') ?>"></script></body></html>