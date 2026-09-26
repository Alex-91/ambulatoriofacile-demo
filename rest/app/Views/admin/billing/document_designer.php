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
<link rel="stylesheet" href="<?= base_url('public/assets/css/billing-designer.css') ?>">
</head><body class="skin-blue sidebar-mini"><div class="wrapper">
<?= view('partials/header',['menu_items'=>$menu_items??[]]) ?>
<main class="content-wrapper"><section class="bd-app">
<header class="bd-top"><div><a href="<?= site_url('admin/fatturazione') ?>">← Fatturazione</a><h1>Disegna il tuo documento</h1><p>Componi i blocchi, scegli i contenuti, controlla la stampa.</p></div><span class="bd-badge">A4 · griglia di stampa</span></header>
<?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
<?php foreach (($errors??[]) as $error): ?><div class="alert alert-danger"><?= esc(is_array($error)?implode(' ',$error):$error) ?></div><?php endforeach; ?>
<form id="bd-form" action="<?= site_url('admin/fatturazione-documento/save') ?>" method="post" data-preview-url="<?= site_url('admin/fatturazione-documento/preview') ?>">
<?= csrf_field() ?><input type="hidden" name="designer_json" id="bd-json">
<div class="bd-toolbar"><button type="button" id="bd-add">＋ Nuovo blocco</button><button type="button" id="bd-undo" disabled>Annulla modifica</button><button type="button" id="bd-redo" disabled>Ripristina</button><span class="bd-grow"></span><button type="button" id="bd-preview">Anteprima A4</button><button type="button" id="bd-pdf">PDF di prova</button><button class="bd-primary" type="submit">Salva modello</button></div>
<p class="bd-help">Trascina la maniglia dei blocchi per spostarli. Usa ½ o ⅓ per affiancarli. Puoi anche usare le frecce, da tastiera o da telefono. Le modifiche salvate si applicano alle nuove fatture.</p>
<div id="bd-status" role="status" aria-live="polite"></div>
<div class="bd-split"><div class="bd-workspace">
<aside class="bd-library"><h2>Elementi</h2><p>Seleziona un blocco, poi aggiungi qualsiasi elemento.</p><input id="bd-search" type="search" placeholder="Cerca un elemento…" aria-label="Cerca elemento"><div id="bd-catalog"></div>
<details class="bd-settings"><summary>Impostazioni del documento</summary>
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
</aside>
<section class="bd-stage"><div class="bd-stage-label"><span>DOCUMENTO · ORDINE DI STAMPA</span><span id="bd-count"></span></div><div id="bd-canvas" aria-label="Blocchi del documento"></div><button type="button" id="bd-add-bottom">＋ Aggiungi un blocco in fondo</button></section>
<aside class="bd-inspector"><h2>Proprietà del blocco</h2><div id="bd-properties"></div><div class="bd-note"><strong>Stampa protetta</strong><p>Margini A4 di 12 mm. Nessuna posizione assoluta o sovrapposizione. Tabelle e testi lunghi occupano una riga intera e continuano sulle pagine successive.</p><p>Il PDF di prova usa lo stesso motore delle fatture. Controllalo prima di salvare, soprattutto dopo modifiche a logo, testo e dimensioni.</p></div></aside>
</div><aside class="bd-live"><div class="bd-live-heading"><strong>Anteprima in tempo reale</strong><span id="bd-live-status" role="status">Preparazione…</span></div><p>Dati di esempio · formato A4</p><div id="bd-live-viewport"><div id="bd-live-paper"><iframe id="bd-live-frame" title="Anteprima in tempo reale del documento" sandbox="allow-same-origin"></iframe></div></div></aside></div></form>
<dialog id="bd-preview-dialog"><div class="bd-dialog-top"><strong>Anteprima con dati di esempio</strong><button type="button" id="bd-close-preview">Chiudi</button></div><iframe id="bd-frame" title="Anteprima A4" sandbox="allow-same-origin"></iframe></dialog>
</section></main></div>
<script id="bd-initial" type="application/json"><?= json_encode(['model'=>$initial,'catalog'=>$catalog,'csrfName'=>csrf_token()],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script><script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script>
<script src="<?= base_url('public/assets/js/billing-designer.js') ?>"></script>
</body></html>
