<div class="bw-filter-panel">
<div class="bw-filter-grid">
<label>Periodo<select id="bw-period"><option value="">Tutto lo storico</option><option value="month">Mese corrente</option><option value="previous">Mese precedente</option><option value="year">Anno corrente</option><option value="custom">Personalizzato</option></select></label>
<label>Dal<input type="date" id="bw-from"></label><label>Al<input type="date" id="bw-to"></label>
<label>Medico<select id="bw-doctor"><option value="">Tutti i medici</option><option value="unassigned">Senza medico associato</option><?php foreach($doctors as $id=>$name): ?><option value="<?= (int)$id ?>"><?= esc($name) ?></option><?php endforeach ?></select></label>
</div>
<div class="bw-filter-search"><label>Cerca fattura, destinatario o prestazione<input id="bw-search" type="search" placeholder="Numero, nome, codice fiscale o prestazione"></label>
<button class="bw-button" type="button" id="bw-reset">Azzera filtri</button></div>
<details class="bw-extra"><summary>Altri filtri</summary><div class="bw-filter-grid">
<label>Data di riferimento<select id="bw-date-kind"><option value="date">Emissione</option><option value="payment_date">Pagamento</option><option value="due_date">Scadenza</option></select></label>
<label>Destinatario<select id="bw-recipient" <?= $businessView?'disabled':'' ?>><option value="">Tutti</option><option value="patient">Pazienti</option><option value="business" <?= $businessView?'selected':'' ?>>Strutture e aziende</option></select></label>
<label>Pagamento<select id="bw-status"><option value="">Tutti gli stati</option><?php foreach(['Saldata','Parziale','Quota ente','Rettificata','Nota di credito','Da incassare','Bozza'] as $status): ?><option><?= $status ?></option><?php endforeach ?></select></label>
<label>Metodo pagamento<select id="bw-method"><option value="">Tutti</option><?php foreach($listing['payment_method_labels']??[] as $key=>$label): ?><option value="<?= esc($key) ?>"><?= esc($label) ?></option><?php endforeach ?></select></label>
<label>Tipo documento<select id="bw-type"><option value="">Tutti</option><?php foreach($listing['document_type_labels']??[] as $key=>$label): ?><option value="<?= esc($key) ?>"><?= esc($label) ?></option><?php endforeach ?></select></label>
<label>Sistema TS<select id="bw-ts"><option value="">Tutti</option><option value="selectable">Pronti per l’invio</option><option value="unavailable">Non inviabili</option><?php foreach($listing['ts_sync_labels']??[] as $key=>$label): ?><option value="<?= esc($key) ?>"><?= esc($label) ?></option><?php endforeach ?></select></label>
<label>Scadenze ed email<select id="bw-collection"><option value="">Tutte</option><option value="overdue">Scadute</option><option value="today">Scadono oggi</option><option value="to_send">Email da inviare</option></select></label>
</div></details></div>
<?php if($cashView): ?><div class="bw-view-tabs" aria-label="Viste incassi"><button type="button" data-cash-tab="open" aria-pressed="true">Da incassare</button><button type="button" data-cash-tab="all" aria-pressed="false">Scadenzario</button><button type="button" data-cash-tab="paid" aria-pressed="false">Fatture con incassi</button><a href="<?= site_url('admin/fatturazione-scadenzario/preesistente') ?>">Scadenzario dettagliato e solleciti</a></div><?php endif ?>
<p class="bw-scope">Filtri applicati all’intero archivio. I riepiloghi escludono le bozze. <?= $cashView?'I riquadri riepilogano i filtri, indipendentemente dalla scheda selezionata.':'' ?></p>
<form method="post" id="bw-bulk-form" action="<?= site_url('admin/sistema-ts/documenti/send-bulk-billing') ?>" data-export-url="<?= site_url('admin/fatturazione-documenti/export-selezionate') ?>">
<?= csrf_field() ?><input type="hidden" name="return_to" value="billing_archive"><div id="bw-bulk-inputs"></div>
<div class="bw-bulk"><label><input type="checkbox" id="bw-select-all"> Seleziona tutti i risultati <span id="bw-selected-count">(0)</span></label><div class="bw-bulk-actions"><label class="sr-only" for="bw-export-format">Formato esportazione</label><select id="bw-export-format"><option value="xlsx">Excel</option><option value="csv">CSV</option><option value="pdf">PDF / ZIP</option></select><button class="bw-button" id="bw-export" type="button" disabled>Esporta selezionate</button><?php if($tsEnabled): ?><button class="bw-button bw-primary" id="bw-send" type="submit" disabled>Invia a TS <span id="bw-ts-count">(0)</span></button><?php endif ?></div></div>
<p id="bw-selection-status" class="bw-scope" role="status" aria-live="polite"></p></form>
