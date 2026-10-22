<?php
helper(['form','portal']);
$config=$settings['config']??[]; $branding=$config['branding']??[];
$initial=\App\Services\BillingDocumentDesigner::fromConfig($config);
$posted=old('designer_json');
if (is_string($posted) && $posted!=='') { try { $initial=\App\Services\BillingDocumentDesigner::validate(json_decode($posted,true)); } catch (\Throwable $e) {} }
$value=static fn($name,$fallback='')=>esc((string)(old($name)??$fallback));
$catalog=\App\Services\BillingDocumentDesigner::catalog();
?>
<!doctype html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AmbulatorioFacile | Editor documento</title>
<link rel="stylesheet" href="<?= base_url('public/bootstrap/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/dist/css/AdminLTE.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/dist/css/skins/_all-skins.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/all.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/fontawesome/css/v4-shims.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/billing-designer.css?v=20261022-sections') ?>">
<link rel="stylesheet" href="<?= base_url('public/assets/css/billing-sections.css?v=20260927-group') ?>">
</head><body class="billing-unified-page skin-blue sidebar-mini"><div class="wrapper">
<?= view('partials/header',['menu_items'=>$menu_items??[]]) ?>
<main class="content-wrapper"><section class="content"><div class="row"><aside class="col-md-3"><?= view('partials/sidebar_admin',['menu_items'=>$menu_items??[]]) ?></aside><div class="col-md-9"><section class="bd-app">
<header class="bd-top"><div><a href="<?= site_url('admin/fatturazione') ?>">← Fatturazione / Documento fatturazione</a><h1>Modello documento</h1><p>Personalizza ogni sezione e controlla il risultato nell’anteprima accanto.</p></div><button class="bd-primary" type="submit" form="bd-form" id="bd-save">Salva modello</button></header>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
<?php foreach (($errors??[]) as $error): ?><div class="alert alert-danger"><?= esc(is_array($error)?implode(' ',$error):$error) ?></div><?php endforeach; ?>
<form id="bd-form" action="<?= site_url('admin/fatturazione-documento/save') ?>" method="post" data-preview-url="<?= site_url('admin/fatturazione-documento/preview') ?>">
<?= csrf_field() ?><input type="hidden" name="designer_json" id="bd-json">
<div id="bd-sections" class="bd-sections" aria-label="Sezioni del documento"></div>
<div class="bd-toolbar"><button type="button" id="bd-show-library" aria-expanded="false" aria-controls="bd-library">＋ Aggiungi elemento</button><button type="button" id="bd-undo" disabled aria-label="Annulla modifica"><i class="fa fa-undo" aria-hidden="true"></i> Annulla</button><button type="button" id="bd-redo" disabled aria-label="Ripristina modifica"><i class="fa fa-repeat" aria-hidden="true"></i> Ripristina</button><span class="bd-grow"></span><button type="button" id="bd-show-settings" aria-expanded="false" aria-controls="bd-general">Impostazioni</button><button type="button" id="bd-preview">Anteprima A4</button><button type="button" id="bd-pdf">PDF di prova</button></div>
<div id="bd-status" role="status" aria-live="polite"></div>
<section class="bd-library" id="bd-library" hidden><div class="bd-panel-heading"><h2>Elementi disponibili</h2><button type="button" id="bd-close-library" aria-label="Chiudi elementi">×</button></div><p>Scegli un elemento da aggiungere alla sezione selezionata.</p><input id="bd-search" type="search" placeholder="Cerca tra <?= count($catalog) ?> elementi…" aria-label="Cerca elemento"><div id="bd-catalog"></div>
</section>
<section id="bd-general" class="bd-general" hidden><div class="bd-panel-heading"><h2>Impostazioni del documento</h2><button type="button" id="bd-close-settings" aria-label="Chiudi impostazioni">×</button></div><details class="bd-settings"><summary>Impostazioni del documento</summary>
<label>Titolo documento<input name="document_title" value="<?= $value('document_title',$config['document_title']??'Fattura') ?>"></label>
<label>Prefisso numerazione<input name="document_code_prefix" maxlength="12" value="<?= $value('document_code_prefix',$config['document_code_prefix']??'FT') ?>"></label>
<label>Titolo intestazione<input name="branding_header_title" value="<?= $value('branding_header_title',$branding['header_title']??'') ?>"></label>
<label>Sottotitolo<input name="branding_header_subtitle" value="<?= $value('branding_header_subtitle',$branding['header_subtitle']??'') ?>"></label>
<label><input type="checkbox" name="branding_logo_enabled" value="1" <?= ($branding['logo_mode']??'none')!=='none'?'checked':'' ?>> Abilita logo</label>
<label>URL o percorso logo<input name="branding_logo_url" maxlength="255" value="<?= $value('branding_logo_url',$branding['logo_url']??'') ?>" placeholder="https://… oppure /upload/…"></label>
<?php foreach (['header_extra'=>['Righe professionista',1500],'terms_text'=>['Informativa',3000],'footer_note'=>['Footer',255]] as $key=>[$label,$max]): ?>
<label><?= esc($label) ?><textarea name="branding_<?= esc($key) ?>" rows="4"><?= $value('branding_'.$key,$branding[$key]??'') ?></textarea></label>
<?php endforeach; ?>
<p>I dati fiscali del professionista si modificano in <a href="<?= portal_tenant_space_url('fatturazione') ?>">Configura fatturazione</a>.</p>
</details><details class="bd-settings"><summary>Integrazione Sistema TS</summary>
<?php foreach (['enabled_when_available'=>'Abilita integrazione','show_ts_reference'=>'Mostra riferimento TS','require_expense_type'=>'Richiedi tipo spesa','require_opposition_flag'=>'Richiedi scelta opposizione'] as $key=>$label): ?>
<label><input type="checkbox" name="ts_<?= esc($key) ?>" value="1" <?= !empty($config['integration_ts'][$key])?'checked':'' ?>> <?= esc($label) ?></label>
<?php endforeach; ?></details>
<button type="button" id="bd-default">Ripristina modello essenziale</button></section>

<div class="bd-workspace">
<aside class="bd-inspector"><div class="bd-panel-label">Sezione del documento</div><h2 id="bd-selected-name">Sezione selezionata</h2><div class="bd-property-tabs" aria-label="Proprietà della sezione"><button type="button" data-property-tab="content" aria-pressed="true">Contenuto</button><button type="button" data-property-tab="style" aria-pressed="false">Aspetto</button></div><div id="bd-properties"></div><div class="bd-section-actions"><button type="button" id="bd-section-up" aria-label="Sposta sezione su">↑</button><button type="button" id="bd-section-down" aria-label="Sposta sezione giù">↓</button><button type="button" id="bd-add">＋ Nuova sezione</button></div></aside>
<section class="bd-stage"><aside class="bd-live"><div class="bd-live-heading"><strong>Anteprima in tempo reale</strong><span id="bd-live-status" role="status">Preparazione…</span></div><p>Dati di esempio · formato A4</p><div id="bd-live-viewport"><div id="bd-live-paper"><iframe id="bd-live-frame" title="Anteprima in tempo reale del documento" sandbox="allow-same-origin"></iframe></div></div></aside><details class="bd-composition"><summary>Organizza blocchi e impaginazione <span id="bd-count"></span></summary><p class="bd-help">Trascina blocchi e campi oppure usa le frecce per riordinarli. Le larghezze ½ e ⅓ affiancano i blocchi sul foglio.</p><div id="bd-canvas" aria-label="Blocchi del documento"></div><button type="button" id="bd-add-bottom">＋ Aggiungi una sezione</button></details></section>
</div><details class="bd-document-info"><summary>Come si applicano le modifiche</summary><p>Il modello e le diciture si applicano anche alle fatture già emesse. Numeri, date, riferimenti, importi e pagamenti restano invariati. Margini A4 di 12 mm; testi lunghi e tabelle continuano sulle pagine successive. Verifica il PDF di prova prima di salvare.</p></details></form>
<dialog id="bd-preview-dialog"><div class="bd-dialog-top"><strong>Anteprima con dati di esempio</strong><button type="button" id="bd-close-preview">Chiudi</button></div><iframe id="bd-frame" title="Anteprima A4" sandbox="allow-same-origin"></iframe></dialog>
</section></div></div></section></main></div>
<script id="bd-initial" type="application/json"><?= json_encode(['model'=>$initial,'catalog'=>$catalog,'csrfName'=>csrf_token()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script><script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/assets/js/billing-designer.js?v=20261022-sections') ?>"></script>
</body></html>
