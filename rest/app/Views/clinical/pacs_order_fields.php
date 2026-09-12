<?php $v=$v ?? []; ?>
<div class="grid">
<label class="full">Prestazione richiesta<input name="description" maxlength="64" required value="<?= esc($v['description'] ?? '','attr') ?>"></label>
<label>Codice prestazione<input name="procedure_code" maxlength="16" required value="<?= esc($v['procedure_code'] ?? '','attr') ?>"></label>
<label>Catalogo di codifica<input name="coding_scheme" maxlength="16" required value="<?= esc($v['coding_scheme'] ?? '','attr') ?>"><small>Codice del catalogo concordato con il servizio diagnostico.</small></label>
<label>Modalità<select name="modality" required><option value="">Seleziona</option><?php foreach(['CT'=>'TC','MR'=>'Risonanza magnetica','US'=>'Ecografia','CR'=>'Radiografia CR','DX'=>'Radiografia digitale','MG'=>'Mammografia','NM'=>'Medicina nucleare','PT'=>'PET','XA'=>'Angiografia','RF'=>'Radiofluoroscopia','OT'=>'Altro','ECG'=>'Elettrocardiografia','EPS'=>'Elettrofisiologia','OP'=>'Oftalmologia','OCT'=>'Tomografia ottica','SM'=>'Microscopia'] as $code=>$label): ?><option value="<?= $code ?>" <?= ($v['modality'] ?? '')===$code ? 'selected' : '' ?>><?= esc($label.' ('.$code.')') ?></option><?php endforeach ?></select></label>
<label>Destinazione esame<input name="station_ae" maxlength="16" required value="<?= esc($v['station_ae'] ?? '','attr') ?>"><small>AE Title della stazione, fornito dal servizio diagnostico.</small></label>
<label>Data e ora previste<input type="datetime-local" name="scheduled_at" required value="<?= esc($v['scheduled_at'] ?? '','attr') ?>"><small>Fuso orario Italia.</small></label>
<label class="full">Note interne<textarea name="reason" maxlength="1000" rows="3"><?= esc($v['reason'] ?? '') ?></textarea><small>Conservate nella richiesta; non incluse nella worklist.</small></label>
</div>
