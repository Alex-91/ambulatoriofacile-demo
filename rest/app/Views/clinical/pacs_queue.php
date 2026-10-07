<?php
$stages=\App\Services\Pacs\PacsOrderService::STAGES;
$url=site_url('cartella-clinica/diagnostica');
$pageUrl=static fn($page)=>$url.'?'.http_build_query(['date'=>$queue['date'],'completed'=>$queue['completed'] ? '1' : '0','page'=>$page]);
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Lista diagnostica | AmbulatorioFacile</title>
<link rel="stylesheet" href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/dist/css/AdminLTE.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/v4-shims.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/billing-workspace.css?v=20260927-icons') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/billing-sections.css?v=20260930-listini') ?>">
<style>.diagnostic-content *{box-sizing:border-box}.diagnostic-content{margin:0;background:#f3f6f8;color:#193345;font:15px/1.55 system-ui,sans-serif}.diagnostic-content header,.diagnostic-content main{max-width:1080px;margin:auto;padding:24px}.diagnostic-content header{padding-bottom:0}.diagnostic-content h1{margin:12px 0}.diagnostic-content h2{font-size:20px;margin:8px 0}.diagnostic-content a{color:#086f75}.diagnostic-content .card{background:#fff;border:1px solid #dce5e9;border-radius:12px;padding:22px;margin-bottom:16px;overflow-wrap:anywhere}.diagnostic-content .actions{display:flex;align-items:end;gap:16px;flex-wrap:wrap}.diagnostic-content label{display:block;font-weight:600}.diagnostic-content input{font:inherit;padding:10px;border:1px solid #afc2ca;border-radius:6px}.diagnostic-content input[type=date]{display:block}.diagnostic-content button,.diagnostic-content .button{display:inline-block;background:#086f75;color:white;border:0;border-radius:6px;padding:11px 16px;font:600 14px system-ui;text-decoration:none;cursor:pointer}.diagnostic-content .badge{background:#e8f2f3;padding:4px 10px;border-radius:20px;font-size:13px}.diagnostic-content small{color:#576c78}.diagnostic-content .notice{background:#e5f4ef;border-left:4px solid #086f75;padding:16px;margin-bottom:20px}.diagnostic-content .problem{background:#fff5df;padding:12px;border-left:4px solid #ad7519}.diagnostic-content a:focus-visible,.diagnostic-content button:focus-visible,.diagnostic-content input:focus-visible{outline:3px solid #e69f35;outline-offset:3px}@media(max-width:650px){.diagnostic-content header,.diagnostic-content main{padding:16px}.diagnostic-content .card{padding:18px}}
body.billing-unified-page .diagnostic-content{background:transparent;color:#344963;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.diagnostic-content>header{max-width:none;margin:0;padding:0 0 24px}
.diagnostic-content>header p{font-size:16px;color:#647087;margin:8px 0 0}
.diagnostic-content main{max-width:none;padding:0;margin:0}
.diagnostic-content .card{border:1px solid #e0e7ef;border-radius:8px;box-shadow:0 2px 4px #1d385305}
.diagnostic-content h2{color:#132440;font-weight:600}
.diagnostic-content label{font-size:13px;margin:0}
.diagnostic-content input[type=date]{height:42px;border-color:#d6e1ed;margin-top:6px;color:#344963}
.diagnostic-content input[type=checkbox]{accent-color:#008c9f;margin-right:6px}
.diagnostic-content .actions{align-items:center}
.diagnostic-content form.card.actions{align-items:flex-end}
.diagnostic-content form.card.actions label:has(input[type=checkbox]){display:flex;align-items:center;min-height:42px}
.diagnostic-content .actions form{margin:0}
.diagnostic-content :is(button,.button){display:inline-flex;align-items:center;justify-content:center;min-height:42px;background:#008c9f;border:1px solid #008c9f;border-radius:6px;padding:10px 14px;line-height:20px}
.diagnostic-content :is(button,.button):hover{background:#087485;color:#fff}
.diagnostic-content .actions>a{display:inline-flex;align-items:center;min-height:42px;padding:10px 14px;border:1px solid #d6e1ed;border-radius:6px;background:#fff;color:#344963;text-decoration:none;font-size:14px;font-weight:600}
.diagnostic-content .actions>a:hover{background:#e4f5f8}
.diagnostic-content .badge{color:#00788e;background:#e4f5f8;font-weight:600;line-height:20px}
.diagnostic-content :focus-visible{outline:3px solid #58b7d1;outline-offset:2px}
@media(max-width:650px){.diagnostic-content .card{padding:16px}.diagnostic-content form.card.actions{align-items:stretch;flex-direction:column}.diagnostic-content input[type=date]{width:100%}}
</style></head><body class="billing-unified-page skin-blue sidebar-mini"><div class="wrapper">
<?= view('partials/header',['menu_items'=>$menu_items??[]]) ?>
<div class="content-wrapper"><section class="content"><div class="row"><aside class="col-md-3">
<?= view('partials/sidebar_admin',['menu_items'=>$menu_items??[]]) ?>
</aside><div class="col-md-9"><div class="diagnostic-content">
<header><a href="<?= site_url('agenda/gestione-pazienti') ?>">← Pazienti</a><h1>Lista diagnostica</h1><p><a class="button" href="<?= site_url('cartella-clinica/diagnostica/nuova-richiesta') ?>">Nuova richiesta · scegli paziente</a></p><?php if ((int)($tenant['id_tenant']??0)===4 && function_exists('session_has_tenant_master_access') && session_has_tenant_master_access()): ?><p><a class="btn btn-primary" href="<?= site_url('cartella-clinica/demo-pacs') ?>">Archivio immagini PACS</a></p><?php endif ?><p><?= esc($tenant['tenant_name'] ?? '') ?> · Accettazione e avanzamento degli esami</p></header><main>
<?php if(session()->getFlashdata('success')): ?><div class="notice" role="status"><?= esc(session()->getFlashdata('success')) ?></div><?php endif ?>
<form class="card actions" method="get" action="<?= $url ?>"><label>Giorno<input type="date" name="date" value="<?= esc($queue['date'],'attr') ?>" required></label><label><input type="checkbox" name="completed" value="1" <?= $queue['completed'] ? 'checked' : '' ?>> Includi eseguiti</label><button>Mostra esami</button></form>
<?php if($queue['role']===4): ?><p><a href="<?= site_url('cartella-clinica/diagnostica/collegamenti') ?>">Verifica collegamenti PACS</a></p><?php endif ?>
<p><small>L’avanzamento registra le operazioni della struttura. Lo stato del referto si consulta nella richiesta.</small></p>
<?php if($queue['role']===4): ?><p class="notice">Puoi consultare le richieste dello spazio e registrare l’accettazione. Esecuzione dell’esame e refertazione restano ai professionisti abilitati.</p><?php endif ?>
<?php if(!$queue['rows']): ?><section class="card"><h2>Nessun esame in questa vista</h2><p><?= $queue['role']===4 ? 'La lista mostra le richieste confermate dei medici dello spazio.' : 'La lista mostra le richieste confermate dei medici a cui sei assegnato.' ?></p></section><?php endif ?>
<?php foreach($queue['rows'] as $item): $base=site_url('cartella-clinica/pazienti/'.$item['patient_id'].'/pacs/richieste/'.$item['id']); ?>
<article class="card"><span class="badge"><?= esc($stages[$item['stage']]) ?></span><h2><?= esc($item['patient_name']) ?></h2><p><?= esc($item['description']) ?><br><?= esc((new \DateTimeImmutable($item['scheduled_at']))->format('d/m/Y H:i')) ?> · <?= esc($item['accession']) ?><?php if($item['appointment_id']): ?> · Appuntamento #<?= (int)$item['appointment_id'] ?><?php endif ?></p>
<?php if($item['problem']): ?><p class="problem"><?= esc($item['problem']) ?></p><?php endif ?>
<div class="actions">
<?php if(!$item['problem'] && $item['stage']!=='performed' && (in_array($queue['role'],[1,2],true) || $item['stage']==='awaiting')): $next=['awaiting'=>'accepted','accepted'=>'in_progress','in_progress'=>'performed'][$item['stage']]; ?>
<form method="post" action="<?= $base.'/avanzamento' ?>"><?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)$item['revision'] ?>"><input type="hidden" name="stage" value="<?= $next ?>"><input type="hidden" name="queue_date" value="<?= esc($queue['date'],'attr') ?>"><button><?= esc(['accepted'=>'Registra accettazione','in_progress'=>'Avvia esame','performed'=>'Registra esame eseguito'][$next]) ?></button></form>
<?php endif ?><?php if($item['clinical_owner']): ?><a href="<?= $base ?>">Immagini e referto</a><?php endif ?></div></article>
<?php endforeach ?>
<nav class="actions" aria-label="Pagine della lista"><?php if($queue['page']>1): ?><a href="<?= esc($pageUrl($queue['page']-1),'attr') ?>">Precedenti</a><?php endif ?><?php if($queue['more']): ?><a href="<?= esc($pageUrl($queue['page']+1),'attr') ?>">Successive</a><?php endif ?></nav>
</main></div></div></div></section></div></div>
<script src="<?= base_url('public/assets/js/billing-workspace.js?v=20260927-icons') ?>"></script>
</body></html>
