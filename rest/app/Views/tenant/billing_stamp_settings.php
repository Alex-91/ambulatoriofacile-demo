<?php
$stamp = \App\Services\BillingStampDuty::settings($config);
$virtualStamp = $fieldValue('stamp_method', $stamp['method']) === 'virtual';
?>
<section class="config-card config-card-full" style="order:3;">
  <div class="config-card-head">
    <div><span class="config-card-eyebrow">Imposta di bollo</span><h2>Marca da bollo</h2></div>
    <i class="fa fa-file-text-o" aria-hidden="true"></i>
  </div>
  <input type="hidden" name="stamp_settings_present" value="1">
  <div class="config-switch-list">
    <label>
      <span><strong>Applica la marca da bollo automaticamente</strong><small>Propone 2,00 € oltre 77,47 € di operazioni soggette a bollo. L’importo resta modificabile.</small></span>
      <input type="hidden" name="stamp_automatic" value="0">
      <input type="checkbox" name="stamp_automatic" value="1" <?= $fieldBool('stamp_automatic', (bool)$stamp['automatic']) ? 'checked' : '' ?>>
    </label>
    <label>
      <span><strong>Addebita il bollo al paziente/cliente</strong><small>Se disattivato, il bollo resta visibile ma non aumenta il totale da incassare.</small></span>
      <input type="hidden" name="stamp_charge_customer" value="0">
      <input type="checkbox" name="stamp_charge_customer" value="1" <?= $fieldBool('stamp_charge_customer', (bool)$stamp['charge_customer']) ? 'checked' : '' ?>>
    </label>
  </div>
  <div class="config-form-grid">
    <div class="form-group"><label for="settings-stamp-nature">Trattamento delle prestazioni senza IVA</label>
      <select id="settings-stamp-nature" class="form-control" name="stamp_nature">
      <?php foreach (\App\Services\BillingStampDuty::NATURES as $key=>$label): ?>
      <option value="<?= esc($key) ?>" <?= $fieldValue('stamp_nature', $stamp['nature']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach; ?></select>
    </div>
    <div class="form-group"><label for="settings-stamp-method">Modalità per le fatture al paziente</label>
      <select id="settings-stamp-method" class="form-control" name="stamp_method" aria-controls="settings-stamp-authorization">
      <?php foreach (['adhesive'=>'Contrassegno sull’originale cartaceo', 'virtual'=>'Bollo virtuale autorizzato'] as $key=>$label): ?>
      <option value="<?= $key ?>" <?= $fieldValue('stamp_method', $stamp['method']) === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach; ?></select>
    </div>
    <div id="settings-stamp-authorization" class="config-form-wide" style="<?= $virtualStamp ? '' : 'display:none;' ?>">
      <div class="config-form-grid" style="padding:0;">
      <?php foreach (['number'=>'Numero autorizzazione', 'date'=>'Data autorizzazione', 'office'=>'Ufficio autorizzante'] as $key=>$label): ?>
        <div class="form-group<?= $key === 'office' ? ' config-form-wide' : '' ?>">
          <label for="settings-stamp-<?= $key ?>"><?= esc($label) ?></label>
          <input id="settings-stamp-<?= $key ?>" class="form-control" type="<?= $key==='date'?'date':'text' ?>" name="stamp_authorization_<?= $key ?>" maxlength="190" value="<?= esc($fieldValue('stamp_authorization_'.$key, $stamp['authorization_'.$key])) ?>">
        </div>
      <?php endforeach; ?>
      </div>
      <small class="config-help">Numero e data sono necessari per il bollo virtuale autorizzato.</small>
    </div>
    <small class="config-help config-form-wide">Le impostazioni si applicano alle nuove fatture. I documenti già emessi conservano i propri dati del bollo.</small>
  </div>
</section>
<script>
(function () {
  var method = document.getElementById('settings-stamp-method');
  var authorization = document.getElementById('settings-stamp-authorization');
  function refresh() {
    var visible = method.value === 'virtual';
    authorization.style.display = visible ? '' : 'none';
    method.setAttribute('aria-expanded', visible ? 'true' : 'false');
  }
  method.addEventListener('change', refresh);
  refresh();
})();
</script>
