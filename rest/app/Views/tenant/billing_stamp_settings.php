<?php $stamp = \App\Services\BillingStampDuty::settings($config); ?>
<section class="config-card">
  <h2>Marca da bollo</h2>
  <input type="hidden" name="stamp_settings_present" value="1">
  <div class="form-group">
    <input type="hidden" name="stamp_automatic" value="0">
    <label><input type="checkbox" name="stamp_automatic" value="1" <?= $fieldBool('stamp_automatic', (bool)$stamp['automatic']) ? 'checked' : '' ?>> Applica la marca da bollo automaticamente</label>
    <small class="help-block">Propone 2,00 € se la somma delle operazioni soggette a bollo supera 77,47 €. L’importo resta modificabile sul documento.</small>
  </div>
  <div class="form-group">
    <input type="hidden" name="stamp_charge_customer" value="0">
    <label><input type="checkbox" name="stamp_charge_customer" value="1" <?= $fieldBool('stamp_charge_customer', (bool)$stamp['charge_customer']) ? 'checked' : '' ?>> Addebita il bollo al paziente/cliente</label>
    <small class="help-block">Se disattivato, il bollo rimane visibile ma non aumenta il totale da incassare.</small>
  </div>
  <div class="form-group"><label>Trattamento predefinito delle prestazioni senza IVA</label>
    <select class="form-control" name="stamp_nature">
    <?php foreach (\App\Services\BillingStampDuty::NATURES as $key=>$label): ?>
    <option value="<?= esc($key) ?>" <?= $fieldValue('stamp_nature', $stamp['nature']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
    <?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>Modalità predefinita per le fatture al paziente</label>
    <select class="form-control" name="stamp_method">
    <?php foreach (['adhesive'=>'Contrassegno sull’originale cartaceo', 'virtual'=>'Bollo virtuale autorizzato'] as $key=>$label): ?>
    <option value="<?= $key ?>" <?= $fieldValue('stamp_method', $stamp['method']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
    <?php endforeach; ?></select>
  </div>
  <?php foreach (['number'=>'Numero autorizzazione', 'date'=>'Data autorizzazione', 'office'=>'Ufficio autorizzante'] as $key=>$label): ?>
  <div class="form-group"><label><?= esc($label) ?></label><input class="form-control" type="<?= $key==='date'?'date':'text' ?>" name="stamp_authorization_<?= $key ?>" maxlength="190" value="<?= esc($fieldValue('stamp_authorization_'.$key, $stamp['authorization_'.$key])) ?>"></div>
  <?php endforeach; ?>
  <small class="help-block">Numero e data sono necessari per il bollo virtuale autorizzato. Le scelte vengono salvate nelle nuove fatture; le fatture già emesse mantengono i propri dati del bollo.</small>
</section>
