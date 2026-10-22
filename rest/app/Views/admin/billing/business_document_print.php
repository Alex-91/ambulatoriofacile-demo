<?php
$document = $preview['document'];
$template = $preview['template'];
$recipient = $template['patient_details'] ?? [];
$issuer = $template['fiscal_data'] ?? [];
$branding = $template['branding'] ?? [];
$money = static fn($value): string => 'EUR ' . number_format((float) $value, 2, ',', '.');
$vat = isset($template['document_stamp']) ? round((float)$document['amount_total']-(float)$document['subtotal_amount']-\App\Services\BillingStampDuty::chargedAmount($document),2) : round((float) $document['subtotal_amount'] * (float) $document['vat_rate'] / 100, 2);
?>
<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fattura a struttura</title>
<style>
@page { size:A4; margin:16mm; } body { font-family:DejaVu Sans,Arial,sans-serif; font-size:10pt; color:#233347; line-height:1.5; }
h1 { color:#237c87; margin-bottom:4mm; } h2 { font-size:12pt; } .identity { padding:4mm 0; border-bottom:1px solid #d7e1e5; overflow-wrap:break-word; }
table { width:100%; border-collapse:collapse; margin-top:6mm; table-layout:fixed; } th,td { padding:3mm; text-align:left; border-bottom:1px solid #d7e1e5; overflow-wrap:break-word; } th { background:#edf5f6; } td { vertical-align:top; } tr { page-break-inside:avoid; } thead { display:table-header-group; } .number { text-align:right; } .totals { margin:5mm 0; text-align:right; } .notes { white-space:pre-wrap; overflow-wrap:break-word; } .toolbar { margin-bottom:15px; } @media print { .toolbar { display:none; } }
</style></head><body>
<?php if (!empty($showToolbar)): ?><div class="toolbar"><a target="_blank" rel="noopener" href="<?= site_url('admin/fatturazione-strutture/modifica/'.(int) $document['id_billing_document']) ?>">Torna alla fattura</a> · <a target="_blank" rel="noopener" href="<?= \App\Services\BillingExportFormat::pdfUrl((int) $document['id_billing_document']) ?>">Scarica PDF</a></div><?php endif; ?>
<h1><?= esc($preview['document_type_label'] ?? 'Fattura') ?> <?= esc($document['document_number'] ?: 'BOZZA') ?></h1>
<p>Data: <?= esc($document['issue_date']) ?><?php if (($document['local_state'] ?? '') === 'draft'): ?> — BOZZA<?php endif; ?></p>
<div class="identity"><h2>Emittente</h2>
<?php foreach (['' => ($issuer['business_name'] ?? '') ?: ($branding['header_title'] ?? ''), 'Indirizzo' => $issuer['address'] ?? '', 'Comune' => trim(($issuer['postal_code'] ?? '').' '.($issuer['city'] ?? '').' '.($issuer['province'] ?? '')), 'Partita IVA' => $issuer['vat_number'] ?? '', 'Codice fiscale' => $issuer['tax_code'] ?? '', 'PEC' => $issuer['pec'] ?? ''] as $label => $value): if (trim((string) $value) === '') continue; ?>
<div><?= $label !== '' ? esc($label).': ' : '' ?><?= esc($value) ?></div><?php endforeach; ?></div>
<div class="identity"><h2>Destinatario</h2><strong><?= esc($document['patient_name']) ?></strong>
<?php foreach (['Partita IVA'=>$recipient['business_vat_number'] ?? '', 'Codice fiscale'=>$document['patient_tax_code'] ?? '', 'Indirizzo'=>$recipient['patient_address'] ?? '', 'Comune'=>trim(($recipient['business_postal_code'] ?? '').' '.($recipient['patient_city'] ?? '').' '.($recipient['business_province'] ?? '')), 'Paese'=>$recipient['business_country'] ?? '', 'Email'=>$document['patient_email'] ?? '', 'Codice destinatario'=>$recipient['business_recipient_code'] ?? '', 'PEC'=>$recipient['business_pec'] ?? ''] as $label=>$value): if (trim((string) $value) === '') continue; ?>
<div><?= esc($label) ?>: <?= esc($value) ?></div><?php endforeach; ?></div>
<table><thead><tr><th style="width:49%">Descrizione prestazioni</th><th style="width:9%">Qtà</th><th style="width:21%">Importo unitario</th><th style="width:21%">Importo</th></tr></thead><tbody>
<?php foreach ($preview['line_items'] as $item): ?><tr><td><?= nl2br(esc($item['description'])) ?><br><small><?= esc(\App\Services\BillingStampDuty::lineLabel($item, $document)) ?></small></td><td><?= esc((string) $item['quantity']) ?></td><td class="number"><?= $money($item['unit_amount']) ?></td><td class="number"><?= $money($item['line_total']) ?></td></tr><?php endforeach; ?></tbody></table>
<div class="totals">Totale prestazioni: <?= $money($document['subtotal_amount']) ?><br>
<?php if ($vat > 0): ?>IVA: <?= $money($vat) ?><br><?php elseif (!empty($document['vat_nature'])): ?>IVA: <?= esc($document['vat_nature']) ?><br><?php endif; ?>
<?php if ((float) $document['stamp_duty_amount'] > 0): ?>Bollo<?= \App\Services\BillingStampDuty::document($document)['stamp_charge_customer'] ? '' : ' (a carico emittente)' ?>: <?= $money($document['stamp_duty_amount']) ?><br><?php endif; ?>
<strong>Totale documento: <?= $money($document['amount_total']) ?></strong></div>
<p>Pagamento: <?= esc($preview['payment_method_label'] ?? '') ?><?php if (!empty($document['due_date'])): ?><br>Scadenza: <?= esc($document['due_date']) ?><?php endif; ?></p>
<?php if (!empty($issuer['iban'])): ?><p>IBAN: <?= esc($issuer['iban']) ?></p><?php endif; ?>
<?php if (!empty($document['notes'])): ?><div class="notes"><?= esc($document['notes']) ?></div><?php endif; ?>
<?php if (!empty($branding['footer_note'])): ?><p><?= nl2br(esc($branding['footer_note'])) ?></p><?php endif; ?>
<?= view('admin/billing/stamp_notice', ['document'=>$document]) ?>
</body></html>
