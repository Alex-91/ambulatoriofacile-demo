<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invio SDI · Fatture a strutture</title>
<?php foreach (['public/bootstrap/css/bootstrap.min.css', 'public/dist/css/AdminLTE.css', 'public/dist/css/skins/_all-skins.min.css', 'public/assets/css/billing-ts-ui.css', 'public/assets/css/billing-sdi.css'] as $css): ?><link rel="stylesheet" href="<?= base_url($css) ?>"><?php endforeach; ?>
</head><body class="skin-blue sidebar-mini billing-ts-ui module-fatturazione"><div class="wrapper">
<?= view('partials/header', ['menu_items' => $menu_items]) ?>
<div class="content-wrapper"><section class="content"><div class="row">
<aside class="col-md-3"><?= view('partials/sidebar_admin', ['menu_items' => $menu_items]) ?></aside>
<main class="col-md-9 sdi-workspace" id="sdi-workspace">
<a class="sdi-back" href="<?= site_url('admin/fatturazione-strutture') ?>">← Fatture a strutture</a>
<header class="sdi-heading"><div><p class="sdi-eyebrow">FATTURAZIONE ELETTRONICA</p><h1>Invio SDI</h1><p>Dalla fattura alla consegna: esplora i tre canali di trasmissione.</p></div><span class="sdi-badge">Dimostrazione interattiva</span></header>
<div class="sdi-notice"><strong>Nessun invio reale.</strong> Questa pagina utilizza esclusivamente una fattura sintetica. Gli esiti sono simulati, non sono ricevute ufficiali e non modificano l’archivio. I collegamenti PEC e API non sono ancora implementati o configurati.</div>

<section class="sdi-invoice" aria-label="Fattura di esempio"><div><span class="sdi-eyebrow">DOCUMENTO SINTETICO</span><h2>Fattura DEMO-001</h2><p>Centro medico dimostrativo → Clinica dimostrativa</p></div><div class="sdi-invoice-total"><strong>€ 122,00</strong><span>Servizi € 100,00 · IVA 22% € 22,00</span></div></section>

<h2 class="sdi-section-title">1. Scegli il canale</h2>
<div class="sdi-channels" role="group" aria-label="Canale di invio">
<button type="button" class="sdi-channel is-active" data-channel="portal" aria-pressed="true"><span class="sdi-channel-icon" aria-hidden="true">01</span><strong>XML / Portale AdE</strong><span>Prepara il file e mostra il passaggio al portale Fatture e Corrispettivi.</span><small>Trasmissione esterna manuale</small></button>
<button type="button" class="sdi-channel" data-channel="pec" aria-pressed="false"><span class="sdi-channel-icon" aria-hidden="true">02</span><strong>Invio tramite PEC</strong><span>Mostra la preparazione del messaggio e la lettura delle notifiche SDI.</span><small>Collegamento PEC da realizzare</small></button>
<button type="button" class="sdi-channel" data-channel="api" aria-pressed="false"><span class="sdi-channel-icon" aria-hidden="true">03</span><strong>Provider API</strong><span>Mostra la trasmissione tramite intermediario e il ritorno degli esiti.</span><small>Provider da scegliere e integrare</small></button>
</div>

<div class="sdi-columns"><section class="sdi-panel"><h2 class="sdi-section-title">2. Prepara e trasmetti</h2><h3 id="sdi-channel-title">XML / Portale AdE</h3><p id="sdi-channel-description"></p>
<details class="sdi-requirements"><summary>Cosa serve per attivare questo percorso</summary><ul id="sdi-requirements"></ul></details>
<label for="sdi-scenario">Esito da mostrare nella dimostrazione</label><select id="sdi-scenario" class="form-control"><option value="delivered">Consegnata</option><option value="rejected">Scartata</option><option value="unavailable">Mancata consegna</option><option value="pending">In attesa di esito</option></select>
<div class="sdi-actions"><button type="button" class="btn btn-primary" id="sdi-run">Simula percorso</button><button type="button" class="btn btn-default" id="sdi-reset">Ricomincia</button></div>
<div class="sdi-downloads"><button type="button" class="sdi-link" id="sdi-xml">Scarica XML di esempio</button><span>Solo dimostrativo · non trasmettere</span></div>
<details class="sdi-xml-preview"><summary>Mostra XML di esempio</summary><pre id="sdi-xml-preview"></pre></details>
<noscript><p class="alert alert-warning">Abilita JavaScript per eseguire la dimostrazione interattiva.</p></noscript>
</section><section class="sdi-panel"><h2 class="sdi-section-title">3. Segui gli esiti</h2><div id="sdi-result" role="status" aria-live="polite"><span class="sdi-state">Da preparare</span><p>Scegli un canale e avvia la dimostrazione.</p></div><ol class="sdi-timeline" id="sdi-timeline"></ol><button type="button" class="btn btn-default" id="sdi-report" disabled>Scarica riepilogo simulazione</button><p class="sdi-caption">Il riepilogo descrive la demo: non certifica invio, consegna o validità fiscale.</p></section></div>
<section class="sdi-panel sdi-activation"><h2>Passaggio all’operatività</h2><p>Questi tre percorsi sono dimostrativi. Per usare fatture reali occorrono generazione e validazione del relativo XML; per PEC e API vanno inoltre implementati il collegamento, la gestione delle credenziali e l’acquisizione delle notifiche. La conservazione a norma richiede una gestione dedicata.</p><a href="https://ivaservizi.agenziaentrate.gov.it/portale/" target="_blank" rel="noopener noreferrer">Apri il portale Fatture e Corrispettivi ↗</a></section>
</main></div></section></div></div>
<script src="<?= base_url('public/plugins/jQuery/jQuery-2.1.4.min.js') ?>"></script><script src="<?= base_url('public/bootstrap/js/bootstrap.min.js') ?>"></script><script src="<?= base_url('public/assets/js/billing-sdi.js') ?>"></script>
</body></html>
