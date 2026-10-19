<?php
$base = 'commercialista/medico/' . (int) $grant['tenant_id'];
$filters = $report['filters'];
$query = http_build_query($filters);
$money = static fn($value) => '€ ' . number_format((float) $value, 2, ',', '.');
$rows = $report['documents'];
$total = $paid = $due = $stamp = 0;
foreach ($rows as $row) {
    $amount = (float) $row['amount_total'];
    $total += $amount;
    $stamp += (float) $row['stamp_duty_amount'];
    if (($row['payment_status'] ?? '') === 'paid') { $paid += $amount; } else { $due += $amount; }
}
?>
<?= view('accountant/header', ['title' => 'Fatture e report']) ?>
<a href="<?= site_url('commercialista') ?>">← I tuoi medici</a><h1 style="margin-top:16px"><?= esc($grant['tenant_label']) ?></h1><p class="muted">Fatture e report · Documenti autorizzati dal <?= esc(substr($grant['from_date'], 0, 4)) ?></p>
<section class="card"><h2>Seleziona il periodo</h2>
<form method="get" class="actions" action="<?= site_url($base) ?>">
<label>Dal<input type="date" name="date_from" min="<?= esc($grant['from_date']) ?>" value="<?= esc($filters['date_from']) ?>" required></label>
<label>Al<input type="date" name="date_to" value="<?= esc($filters['date_to']) ?>" required></label>
<label>Cliente / codice fiscale<input name="patient" maxlength="120" value="<?= esc($filters['patient']) ?>"></label>
<label>Numero documento<input name="document_number" maxlength="80" value="<?= esc($filters['document_number'] ?? '') ?>"></label>
<label>Pagamento<select name="payment_status"><option value="">Tutti</option><option value="paid" <?= ($filters['payment_status'] ?? '') === 'paid' ? 'selected' : '' ?>>Pagato</option><option value="unpaid" <?= ($filters['payment_status'] ?? '') === 'unpaid' ? 'selected' : '' ?>>Non saldato</option></select></label>
<label>Documento<select name="document_type"><?php foreach ($report['filter_options']['document_types'] as $value => $label): ?><option value="<?= esc($value) ?>" <?= $filters['document_type'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach ?></select></label><button>Applica filtri</button></form>
<div class="actions" style="margin-top:16px"><?php $year = (int) date('Y'); for ($q=1;$q<=4;$q++): $start = sprintf('%d-%02d-01',$year,($q-1)*3+1); $end = date('Y-m-t', strtotime($start . ' +2 months')); ?><a class="button secondary" href="<?= site_url($base) . '?' . http_build_query(['date_from'=>$start,'date_to'=>$end]) ?>"><?= $q ?>° trimestre <?= $year ?></a><?php endfor ?></div>
</section>
<?php if (empty($report['table_available'])): ?><div class="notice error">Archivio fatture temporaneamente non disponibile.</div><?php else: ?>
<div class="grid"><article class="card">Totale documenti<strong class="amount"><?= $money($total) ?></strong></article><article class="card">Documenti segnati come pagati<strong class="amount"><?= $money($paid) ?></strong></article><article class="card">Documenti non saldati<strong class="amount"><?= $money($due) ?></strong></article><article class="card">Bolli<strong class="amount"><?= $money($stamp) ?></strong></article></div>
<p class="muted small">I totali riguardano i documenti emessi nel periodo selezionato. I pagamenti seguono lo stato registrato dal medico; non costituiscono una prima nota per data d’incasso.</p>
<section class="card"><div class="actions"><h2 style="margin-right:auto">Documenti (<?= count($rows) ?>)</h2><a class="button secondary" href="<?= site_url($base . '/export/csv') . '?' . esc($query) ?>">Report CSV</a><a class="button secondary" href="<?= site_url($base . '/export/xlsx') . '?' . esc($query) ?>">Report XLSX</a><a class="button secondary" href="<?= site_url($base . '/export/pdf') . '?' . esc($query) ?>">Report PDF</a><a class="button" href="<?= site_url($base . '/export/zip') . '?' . esc($query) ?>">Scarica pacchetto ZIP</a></div>
<p class="muted small">Il file XLSX usa le colonne e l’ordine configurati dal medico.</p>
<p class="muted small">Lo ZIP contiene il riepilogo CSV e i PDF delle fatture selezionate, fino a 100 documenti per pacchetto.</p>
<form id="accountant-selection" method="post" action="<?= site_url($base . '/export-selezionate') ?>">
<?= csrf_field() ?>
<div class="actions"><span id="accountant-selected-count" aria-live="polite">0 fatture selezionate</span>
<button type="submit" data-export="csv" disabled>CSV</button><button type="submit" data-export="xlsx" disabled>XLSX</button><button type="submit" data-export="pdf" disabled>PDF / ZIP</button></div>
<p class="muted small">Selezione: massimo 500 fatture per CSV/XLSX, 50 per PDF/ZIP.</p>
<div class="scroll"><table><thead><tr><th><input type="checkbox" id="accountant-select-all" aria-label="Seleziona tutti i documenti del report"></th><th>Numero</th><th>Data</th><th>Tipo</th><th>Cliente</th><th>Stato pagamento</th><th>Totale</th><th>Download</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><input type="checkbox" name="billing_document_ids[]" value="<?= (int) $row['id_billing_document'] ?>" aria-label="Seleziona <?= esc($row['document_number']) ?>"></td><td><?= esc($row['document_number']) ?></td><td><?= esc($row['issue_date']) ?></td><td><?= esc($row['document_type_label']) ?></td><td><?= esc($row['patient_name']) ?><br><span class="muted small"><?= esc($row['patient_tax_code']) ?></span></td><td><?= ($row['payment_status'] ?? '') === 'paid' ? 'Pagato' : 'Non saldato' ?></td><td><?= $money($row['amount_total']) ?></td><td><a href="<?= site_url($base . '/fattura/' . (int) $row['id_billing_document']) ?>">Apri / scarica PDF</a></td></tr><?php endforeach ?>
<?php if (!$rows): ?><tr><td colspan="8">Nessun documento nel periodo selezionato.</td></tr><?php endif ?></tbody></table></div></form></section>
<script src="<?= base_url('public/assets/js/billing-csv-export.js') ?>"></script>
<script>
(function () {
  var form = document.getElementById('accountant-selection');
  var checks = Array.from(form.querySelectorAll('[name="billing_document_ids[]"]'));
  var all = document.getElementById('accountant-select-all');
  function sync() {
    var n = checks.filter(function (c) { return c.checked; }).length;
    document.getElementById('accountant-selected-count').textContent = n + ' fatture selezionate';
    form.querySelectorAll('[data-export]').forEach(function (b) { b.disabled = n === 0; });
    all.checked = n > 0 && n === checks.length; all.indeterminate = n > 0 && n < checks.length;
  }
  all.addEventListener('change', function () { checks.forEach(function (c) { c.checked = all.checked; }); sync(); });
  checks.forEach(function (c) { c.addEventListener('change', sync); });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (e.submitter) window.downloadSelectedBillingCsv(form, e.submitter, form.action, sync, e.submitter.dataset.export);
  });
})();
</script>
<?php endif ?><?= view('accountant/footer') ?>
