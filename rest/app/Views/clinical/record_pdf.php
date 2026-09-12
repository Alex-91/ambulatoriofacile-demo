<!doctype html><html lang="it"><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10pt;line-height:1.5;color:#172c3b}h1{font-size:19pt}h2{font-size:12pt;margin-top:20px}.meta{color:#455968;border-bottom:1px solid #ccc;padding-bottom:14px}p{white-space:pre-wrap}footer{font-size:8pt;margin-top:30px}</style><body>
<h1><?= esc($entry['content']['title']) ?></h1>
<div class="meta">Paziente: <?= esc($patient['patient_name'] ?? '') ?><br>Codice fiscale: <?= esc($patient['patient_tax_code'] ?? '') ?><br>
Prestazione: <?= esc($entry['occurred_at']) ?><br>Autore: <?= esc($authorIdentity) ?><br>Documento <?= (int)$entry['id'] ?> · Revisione <?= (int)$entry['revision'] ?>
<?php if ($entry['previous_entry_id']): ?><br>Correzione del documento <?= (int)$entry['previous_entry_id'] ?><?php endif ?></div>
<p><?= esc($entry['content']['body']) ?></p>
<?php foreach (['anamnesis'=>'Anamnesi','findings'=>'Esame / riscontri','diagnosis'=>'Diagnosi','therapy'=>'Terapia','follow_up'=>'Indicazioni e controlli'] as $key=>$label): if (empty($entry['content'][$key])) continue; ?>
<h2><?= esc($label) ?></h2><p><?= esc($entry['content'][$key]) ?></p><?php endforeach ?>
<footer>AmbulatorioFacile · Spazio <?= (int)$tenantId ?> · Documento definitivo. L’eventuale firma digitale è verificabile nel file sottoscritto.</footer>
</body></html>
