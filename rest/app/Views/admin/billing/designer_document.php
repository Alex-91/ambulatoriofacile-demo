<?php
use App\Services\BillingDocumentDesigner;
$template=$preview['template']??[];
$designer=BillingDocumentDesigner::fromConfig($template);
$branding=$template['branding']??[]; $fiscal=$template['fiscal_data']??[]; $document=$preview['document']??[];
$patient=$template['patient_details']??[];
$accent=$branding['accent_color']??'#2c8895';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) $accent='#2c8895';
$money=static fn($v)=>'EUR '.number_format((float)$v,2,',','.');
$values=$document;
foreach (['patient_address','patient_city','patient_postal_code','patient_province','patient_email','patient_phone','patient_mobile'] as $key) $values[$key]=$patient[$key]??$document[$key]??'';
$values=array_merge($values,[
 'title'=>trim($branding['header_title']??'')?:($template['document_title']??'Fattura'), 'subtitle'=>$branding['header_subtitle']??'',
 'issuer_name'=>trim($fiscal['business_name']??'')?:($preview['tenant']['tenant_name']??''),
 'issuer_address'=>implode(' ',array_filter(array_map(static fn($k)=>$fiscal[$k]??'', ['address','postal_code','city','province']))),
 'issuer_tax_code'=>$fiscal['tax_code']??'', 'issuer_vat_number'=>$fiscal['vat_number']??'', 'issuer_pec'=>$fiscal['pec']??'',
 'issuer_pension'=>!empty($template['pension_fund']['enabled'])?trim(($template['pension_fund']['name']??'').' '.($template['pension_fund']['registration_number']??'').' '.($template['pension_fund']['contribution_rate']??'').' %'):'',
 'issuer_extra'=>$branding['header_extra']??'', 'document_type'=>$preview['document_type_label']??'Documento', 'payment_method'=>$preview['payment_method_label']??'',
 'opposition'=>!empty($document['ts_opposition_flag'])?'Sì':'No', 'subtotal'=>$money($document['subtotal_amount']??0), 'total'=>$money($document['amount_total']??0),
 'vat'=>isset($template['document_stamp']) ? $money((float)$document['amount_total']-(float)$document['subtotal_amount']-\App\Services\BillingStampDuty::chargedAmount($document)) : ($document['vat_rate']??0).'% '.($document['vat_nature']??''), 'stamp'=>(float)($document['stamp_duty_amount']??0)>0?$money($document['stamp_duty_amount']):'',
 'terms'=>$branding['terms_text']??'', 'footer'=>$branding['footer_note']??'',
]);
// The old generic title is a default, not an invoice-specific heading.
if (trim((string)($branding['header_title']??''))==='' && ($values['title']??'')==='Documento fatturazione') $values['title']=$preview['document_type_label']??'Fattura';
$catalog=BillingDocumentDesigner::catalog();
// Break very long unspaced values too: Dompdf cannot wrap every Unicode token with CSS alone.
$safe=static function($value, int $wrap=35): string { $text=(string)$value; $text=preg_replace('/([^\s]{'.$wrap.'})(?=[^\s])/u', '$1' . "\u{200B}", $text); return nl2br(esc($text)); };
$renderBlock=static function(array $block) use ($values,$branding,$catalog,$preview,$safe,$money,$document): void {
 $widthPt=(186*$block['span']/12-2*$block['padding']-3)*2.8346;
 $wrap=max(5,min(35,(int)floor($widthPt/($block['font']*1.55))));
 $safeText=$safe; $safe=static fn($v, $limit=null)=>$safeText($v,$limit??$wrap);
 $style='background:'.$block['background'].';color:'.$block['color'].';text-align:'.$block['align'].';font-size:'.$block['font'].'pt;padding:'.$block['padding'].'mm;'.($block['border']?'border:0.25mm solid #d7dde5;':'');
 ?>
 <div class="db-block db-section-<?= esc($block['section']??'') ?>" style="<?= esc($style) ?>">
 <?php if (in_array($block['section']??'', ['patient','items','payment'],true)): ?><div class="db-section-heading"><?= $safe($block['name']) ?></div><?php endif; ?>
 <?php foreach ($block['elements'] as $element): $type=$element['type']; $label=$element['label']?:$catalog[$type]; ?>
 <?php if ($type==='line_items'): ?>
   <table class="db-items"><thead><tr><th style="width:40%">Prestazione</th><th style="width:23%">IVA / Esenzione</th><th style="width:7%">Qta</th><th style="width:15%">Prezzo</th><th style="width:15%">Importo</th></tr></thead><tbody>
   <?php foreach ($preview['line_items']??[] as $item): ?>
     <tr><td><?= $safe($item['description']??'',16) ?></td><td><?= $safe(\App\Services\BillingStampDuty::lineLabel($item, $document), 16) ?></td><td><?= $safe($item['quantity']??1) ?></td><td><?= esc($money($item['unit_amount']??0)) ?></td><td><?= esc($money($item['line_total']??0)) ?></td></tr>
   <?php endforeach; ?></tbody></table>
 <?php elseif ($type==='logo'): $logo=trim($branding['logo_url']??''); if ($logo!=='' && ($branding['logo_mode']??'none')!=='none'): if (!preg_match('#^https?://#i',$logo)) $logo=base_url(ltrim($logo,'/')); ?>
   <img class="db-logo" src="<?= esc($logo) ?>" alt="Logo studio">
 <?php endif; elseif ($type==='divider'): ?><hr>
 <?php elseif ($type==='spacer'): ?><div style="height:8mm"></div>
 <?php elseif ($type==='signature'): ?><div class="db-signature"><?= $safe($label) ?></div>
 <?php else:
 $value=$type==='text'?$element['text']:($values[$type]??'');
 // Earlier editors exposed only the label inside the terms block. Preserve
 // text entered there when no separate information text was supplied.
 $termsLabelContent=$type==='terms' && trim((string)$value)==='' && trim($element['label'])!=='';
 if ($termsLabelContent) $value=$element['label'];
 if (trim((string)$value)==='') continue; ?>
   <div class="db-element db-field-<?= esc($type) ?> <?= in_array($type,['title','total'],true)?'db-emphasis':'' ?>">
     <?php if ($element['show_label'] && !$termsLabelContent): ?><div class="db-label <?= $element['label']===''?'db-default-label':'' ?>"><?= $safe($label) ?></div><?php endif; ?>
     <div class="db-value"><?= $safe($value) ?></div>
   </div>
 <?php endif; endforeach; ?>
 </div>
 <?php
};
$rows=[]; $row=[]; $used=0;
foreach ($designer['blocks'] as $block) {
 // A table cell cannot split across pages in Dompdf. Promote tall columns to a
 // normal full-width flow instead of clipping unusually long real-world data.
 if ($block['span']<12) {
     $width=186*$block['span']/12-2*$block['padding']-3;
     $characters=max(8,(int)floor($width*2.8346/($block['font']*.65)));
     $height=2*$block['padding']*2.8346;
     foreach ($block['elements'] as $element) {
         $type=$element['type'];
         if ($type==='logo') { $height+=70; continue; }
         if ($type==='signature') { $height+=65+ceil(mb_strlen($element['label'])/$characters)*14; continue; }
         if ($type==='spacer') { $height+=25; continue; }
         $value=$type==='text'?$element['text']:($values[$type]??'');
         $lines=0; foreach (explode("\n",(string)$value) as $line) $lines+=max(1,ceil(mb_strlen($line)/$characters));
         $labelHeight=$element['show_label']?max(24,ceil(mb_strlen($element['label'])/$characters)*14):0;
         $height+=($lines*1.5*$block['font']+$labelHeight+8)*(in_array($type,['title','total'],true)?1.4:1);
     }
     if ($height>600) $block['span']=12;
 }
 if ($row!==[] && ($used+$block['span']>12 || $block['page_break'])) { $rows[]=$row; $row=[]; $used=0; }
 $row[]=$block; $used+=$block['span'];
 if ($used===12) { $rows[]=$row; $row=[]; $used=0; }
}
if ($row!==[]) $rows[]=$row;
?>
<!doctype html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Anteprima documento</title>
<style>
@page { size:A4 portrait; margin:12mm; }
* { box-sizing:border-box; }
body { margin:0; font-family:DejaVu Sans,Arial,sans-serif; font-size:11pt; color:#202b3c; line-height:1.4; }
.db-sheet { width:100%; }
.db-row { width:100%; table-layout:fixed; border-collapse:collapse; margin-bottom:3mm; page-break-inside:avoid; }
.db-cell { vertical-align:top; padding:0 1.5mm; }
.db-cell:first-child { padding-left:0; } .db-cell:last-child { padding-right:0; }
.db-full { margin-bottom:3mm; }
.db-block { overflow-wrap:break-word; word-wrap:break-word; }
.db-element { margin-bottom:2mm; } .db-element:last-child { margin-bottom:0; }
.db-label { font-size:8pt; opacity:.8; margin-bottom:0.7mm; }
.db-emphasis { font-weight:bold; font-size:1.4em; }
.db-logo { max-width:100%; width:auto; height:auto; max-height:22mm; }
.db-items { width:100%; table-layout:fixed; border-collapse:collapse; font-size:10pt; }
.db-items th,.db-items td { padding:2mm; border-bottom:0.25mm solid #d7dde5; vertical-align:top; text-align:left; overflow-wrap:break-word; word-wrap:break-word; }
.db-items thead { display:table-header-group; } .db-items tr { page-break-inside:avoid; }
.db-signature { padding-top:14mm; border-bottom:0.25mm solid #788494; }
hr { border:0; border-top:0.25mm solid #d7dde5; }
.db-items th { background:#edf6f7; color:<?= esc($accent) ?>; font-size:8pt; text-transform:uppercase; letter-spacing:.4pt; }
.db-items td { padding:3mm 2mm; font-size:9pt; }
.db-items td:nth-child(3), .db-items th:nth-child(3) { text-align:center; }
.db-items td:nth-child(4), .db-items td:nth-child(5), .db-items th:nth-child(4), .db-items th:nth-child(5) { text-align:right; }
.db-field-title { font-size:2em; letter-spacing:1pt; }
.db-field-issuer_name, .db-field-patient_name { font-weight:bold; }
.db-field-total { background:#edf6f7; color:<?= esc($accent) ?>; padding:4mm; margin-top:3mm; }
.db-field-notes > .db-label, .db-field-terms > .db-label { border-bottom:.25mm solid <?= esc($accent) ?>; padding-bottom:2mm; margin-bottom:3mm; color:<?= esc($accent) ?>; text-transform:uppercase; letter-spacing:1pt; }
.db-field-footer { border-top:.25mm solid <?= esc($accent) ?>; padding-top:3mm; font-size:8pt; color:#607278; }
.db-section-heading { color:#176b77; border-bottom:.25mm solid #71aeb6; padding-bottom:2mm; margin:3mm 0 3mm; text-transform:uppercase; letter-spacing:1.2pt; font-size:9pt; font-weight:bold; }
.db-section-issuer .db-field-issuer_name { color:#176b77; font-size:1.5em; }
.db-section-issuer .db-field-issuer_name > .db-default-label, .db-section-issuer .db-field-issuer_address > .db-default-label { display:none; }
.db-section-document .db-label, .db-section-document .db-value { display:inline; }
.db-section-document .db-label { margin-right:2mm; }
.db-section-document .db-field-title { color:#176b77; text-transform:uppercase; }
.db-section-totals .db-element { margin-left:45%; }
.db-section-totals .db-label { display:inline; font-size:9pt; }
.db-section-totals .db-value { display:inline; margin-left:5mm; }
.db-section-totals .db-field-total { margin-top:4mm; padding:4mm; }
.db-section-patient .db-label, .db-section-payment .db-label { display:inline; margin-right:3mm; }
.db-section-patient .db-value, .db-section-payment .db-value { display:inline; }
.db-section-patient .db-field-patient_name > .db-default-label { display:none; }
.db-section-patient .db-field-patient_name { font-size:1.2em; }
<?php if (empty($pdfMode)): ?>
@media screen { body { background:#e8ecf2; padding:12mm; } .db-sheet { width:186mm; min-height:273mm; padding:0; margin:auto; background:white; box-shadow:0 0 0 12mm white; } }
<?php endif; ?>
.db-toolbar { margin:0 auto 16mm; padding:12px; background:#233047; color:white; }
.db-toolbar a { color:white; margin-right:16px; }
@media print { .db-sheet { width:100%; } .db-toolbar { display:none; } }
</style></head><body>
<?php if (!empty($showToolbar)): ?><nav class="db-toolbar"><a target="_blank" rel="noopener" href="<?= site_url('admin/fatturazione-documenti/modifica/'.(int)($document['id_billing_document']??0)) ?>">Torna al documento</a><a target="_blank" rel="noopener" href="<?= site_url('admin/fatturazione-documenti/pdf/'.(int)($document['id_billing_document']??0)) ?>">Apri PDF</a><a href="#" onclick="window.print();return false;">Stampa</a></nav><?php endif; ?>
<main class="db-sheet">
<?php foreach ($rows as $row): $break=$row[0]['page_break']; ?>
<?php if (count($row)===1 && $row[0]['span']===12): ?>
 <div class="db-full" style="<?= $break?'page-break-before:always;':'' ?>"><?php $renderBlock($row[0]); ?></div>
<?php else: ?>
 <table class="db-row" style="<?= $break?'page-break-before:always;':'' ?>"><tbody><tr>
 <?php $used=0; foreach ($row as $block): $used+=$block['span']; ?><td class="db-cell" style="width:<?= $block['span']/12*100 ?>%;"><?php $renderBlock($block); ?></td><?php endforeach; ?>
 <?php if ($used<12): ?><td style="width:<?= (12-$used)/12*100 ?>%"></td><?php endif; ?>
 </tr></tbody></table>
<?php endif; endforeach; ?>
<?= view('admin/billing/stamp_notice', ['document'=>$document]) ?>
</main></body></html>
