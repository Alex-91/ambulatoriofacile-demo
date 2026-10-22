<?php $stampDefaults = \App\Services\BillingStampDuty::defaults($template ?? []); ?>
<div class="row">
  <div class="col-md-4 form-group">
    <label for="stamp-mode">Calcolo del bollo</label>
    <select id="stamp-mode" class="form-control" name="stamp_mode">
      <?php foreach (['automatic'=>'Automatico oltre 77,47 €', 'manual'=>'Importo manuale'] as $key=>$label): ?>
      <option value="<?= $key ?>" <?= $fieldValue('stamp_mode', $stampDefaults['stamp_mode']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4 form-group">
    <label for="stamp-nature">Trattamento delle prestazioni senza IVA</label>
    <select id="stamp-nature" class="form-control" name="stamp_nature">
      <?php foreach (\App\Services\BillingStampDuty::NATURES as $key=>$label): ?>
      <option value="<?= esc($key) ?>" <?= $fieldValue('stamp_nature', $stampDefaults['stamp_nature']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach; ?>
    </select>
    <small class="help-block">La soglia riguarda le operazioni soggette a bollo, prima del bollo stesso.</small>
  </div>
  <div class="col-md-4 form-group">
    <label for="stamp-charge">Addebita il bollo al paziente/cliente</label>
    <select id="stamp-charge" class="form-control" name="stamp_charge_customer">
      <option value="1" <?= $fieldValue('stamp_charge_customer', $stampDefaults['stamp_charge_customer'] ? '1' : '0') === '1' ? 'selected' : '' ?>>Sì, aggiungi al totale</option>
      <option value="0" <?= $fieldValue('stamp_charge_customer', $stampDefaults['stamp_charge_customer'] ? '1' : '0') !== '1' ? 'selected' : '' ?>>No, a carico dell’emittente</option>
    </select>
  </div>
</div>
<div class="row">
  <div class="col-md-4 form-group">
    <label for="stamp-method">Modalità di assolvimento</label>
    <select id="stamp-method" class="form-control" name="stamp_method">
      <?php foreach (['adhesive'=>'Contrassegno sull’originale cartaceo', 'virtual'=>'Bollo virtuale autorizzato', 'sdi'=>'Bollo su fattura elettronica SdI', 'unrecorded'=>'Da definire'] as $key=>$label): ?>
      <option value="<?= $key ?>" <?= $fieldValue('stamp_method', !empty($businessMode) ? 'sdi' : $stampDefaults['stamp_method']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-8 form-group" data-stamp-method="adhesive">
    <label for="stamp-identifier">Numero identificativo della marca da bollo (facoltativo)</label>
    <input id="stamp-identifier" class="form-control" name="stamp_identifier" inputmode="numeric" pattern="[0-9]{14}" maxlength="14" value="<?= esc($fieldValue('stamp_identifier')) ?>">
    <small class="help-block">Inserisci le 14 cifre solo dopo aver applicato il contrassegno sull’originale. Il numero sarà riportato nel PDF.</small>
  </div>
  <div class="col-md-8" data-stamp-method="virtual">
    <?php foreach (['number'=>'Numero autorizzazione', 'date'=>'Data autorizzazione', 'office'=>'Ufficio autorizzante'] as $key=>$label): $name='stamp_authorization_'.$key; ?>
    <div class="form-group"><label for="<?= $name ?>"><?= esc($label) ?></label>
    <input id="<?= $name ?>" class="form-control" type="<?= $key==='date'?'date':'text' ?>" name="<?= $name ?>" maxlength="190" value="<?= esc($fieldValue($name, $stampDefaults[$name])) ?>"></div>
    <?php endforeach; ?>
  </div>
  <div class="col-md-8" data-stamp-method="sdi"><p class="help-block">Questa scelta registra la modalità del bollo. Non invia la fattura allo SdI e non esegue il versamento.</p></div>
</div>
<p id="stamp-summary" class="help-block" aria-live="polite"></p>
