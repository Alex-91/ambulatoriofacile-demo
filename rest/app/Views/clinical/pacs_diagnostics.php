<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Verifica collegamenti PACS | AmbulatorioFacile</title>
<style>body{margin:0;background:#f3f6f8;color:#193345;font:16px/1.6 system-ui}main{max-width:920px;margin:auto;padding:24px}section{background:white;border:1px solid #dce5e9;border-radius:12px;padding:24px;margin:20px 0}h1{line-height:1.2}h2{font-size:21px}a{color:#086f75}button{background:#086f75;color:white;border:0;border-radius:6px;padding:12px 18px;font:inherit;cursor:pointer}button:disabled{opacity:.5;cursor:default}.ok{color:#136344}.problem{color:#953f0b}dt{font-weight:600}dd{margin:0 0 12px}button:focus-visible,a:focus-visible{outline:3px solid #e69f35;outline-offset:3px}</style></head><body><main>
<a href="<?= site_url('cartella-clinica/diagnostica') ?>">← Lista diagnostica</a><h1>Verifica collegamenti PACS</h1><p><?= esc($tenant['tenant_name']) ?></p>
<p>Controlla la preparazione dello spazio e prova la connessione al PACS configurato. La prova usa un identificativo tecnico casuale e non cerca pazienti reali.</p>
<p><a href="<?= site_url('cartella-clinica/configurazione') ?>">Prepara lo spazio e configura i collegamenti</a></p>
<section><h2>1. Preparazione dello spazio</h2><dl>
<dt>Archivio richieste e immagini</dt><dd class="<?= $status['schema'] ? 'ok':'problem' ?>"><?= $status['schema'] ? 'Pronto':'Da inizializzare: contattare l’assistenza.' ?></dd>
<dt>Cifratura dei collegamenti</dt><dd class="<?= $status['encryption'] ? 'ok':'problem' ?>"><?= $status['encryption'] ? 'Verificata':'Non disponibile: contattare l’assistenza.' ?></dd></dl></section>
<section><h2>2. Collegamenti configurati</h2>
<?php if(!$status['profiles']): ?><p>Nessun collegamento configurato. Richiedere al fornitore gli indirizzi DICOMweb e concordare gli accessi con l’assistenza.</p><?php endif ?>
<?php foreach($status['profiles'] as $p): ?><article><h3><?= esc($p['label']) ?></h3><dl>
<dt>Stato</dt><dd><?= $p['enabled'] ? 'Attivo':'Disattivo' ?></dd>
<dt>Credenziali</dt><dd><?= $p['credentials'] ? 'Presenti o non richieste':'Da configurare' ?></dd>
<dt>Download DICOM</dt><dd><?= $p['download'] ? 'Abilitato; da verificare su un esame di collaudo':'Disabilitato' ?></dd>
<dt>Visualizzatore esterno</dt><dd><?= $p['viewer'] ? 'Configurato; da verificare su un esame di collaudo':'Non configurato' ?></dd></dl>
<form method="post" action="<?= site_url('cartella-clinica/diagnostica/collegamenti') ?>"><?= csrf_field() ?><input type="hidden" name="profile_id" value="<?= esc($p['id'],'attr') ?>"><button <?= !$p['enabled'] || !$p['credentials'] ? 'disabled':'' ?>>Verifica connessione</button></form></article><?php endforeach ?>
<?php if($probe): ?><div role="status"><h3><?= esc($probe['profile']) ?></h3><p class="<?= $probe['ok'] ? 'ok':'problem' ?>"><?= esc($probe['message']) ?></p><small>Verifica del <?= esc($probe['checked_at']) ?></small></div><?php endif ?></section>
<section><h2>3. Collaudo con il servizio diagnostico</h2><p>Una connessione riuscita conferma la ricerca QIDO. Per completare il collegamento, usare un paziente sintetico e verificare richiesta, corrispondenza dell’esame, serie, download DICOM e referto.</p><p>Concordare con il fornitore l’autorità degli identificativi, il catalogo prestazioni e la destinazione della worklist. Questa verifica non certifica l’invio alle apparecchiature o l’interoperabilità completa.</p></section>
</main></body></html>
