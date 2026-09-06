<?php
$fontSettings = is_array($agendaFontSettings ?? null) ? $agendaFontSettings : [];
$fontRows = is_array($fontSettings['rows'] ?? null) ? $fontSettings['rows'] : [];
$fontDefaults = is_array($fontSettings['defaults'] ?? null) ? $fontSettings['defaults'] : [];
$fontDefaultsJson = json_encode($fontDefaults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($fontDefaultsJson) || $fontDefaultsJson === '') {
    $fontDefaultsJson = '{}';
}
?>

<form method="post" action="<?= base_url('profilo/preferenze-agenda') ?>" id="agenda-font-preferences">
  <?= csrf_field() ?>

  <div
    class="box box-primary agenda-font-panel"
    id="agendaFontPreferencesPanel"
    data-defaults="<?= esc($fontDefaultsJson, 'attr') ?>"
  >
    <div class="box-header with-border">
      <h3 class="box-title">
        <i class="fa fa-font" aria-hidden="true"></i>
        Dimensioni testi agenda
      </h3>
      <span class="agenda-font-panel-badge">Impostazione personale</span>
    </div>

    <div class="box-body">
      <p class="agenda-font-panel-intro">
        Scegli quanto devono essere grandi i diversi testi della tua agenda. La configurazione vale solo per il tuo profilo;
        lasciando <strong>Attuale</strong> l’agenda rimane identica a oggi. Non serve digitare alcun valore.
      </p>

      <div class="agenda-font-presets" aria-label="Scelte rapide dimensione testi">
        <span class="agenda-font-presets-label">Scelte rapide</span>
        <button type="button" class="btn btn-default agenda-font-preset" data-agenda-font-preset="current">
          Attuale
        </button>
        <button type="button" class="btn btn-info agenda-font-preset" data-agenda-font-preset="comfortable">
          Più leggibile
        </button>
        <button type="button" class="btn btn-primary agenda-font-preset" data-agenda-font-preset="large">
          Grande
        </button>
      </div>

      <div class="agenda-font-grid">
        <?php foreach ($fontRows as $fontRow): ?>
          <?php
          $fontKey = trim((string) ($fontRow['key'] ?? ''));
          if ($fontKey === '') {
              continue;
          }
          $fontValue = (int) ($fontRow['value'] ?? 0);
          $fontDefault = (int) ($fontRow['default'] ?? 0);
          ?>
          <div class="agenda-font-setting">
            <span class="agenda-font-setting-group"><?= esc((string) ($fontRow['group'] ?? 'Agenda')) ?></span>
            <label for="agenda-font-<?= esc($fontKey, 'attr') ?>">
              <?= esc((string) ($fontRow['label'] ?? $fontKey)) ?>
            </label>
            <p><?= esc((string) ($fontRow['description'] ?? '')) ?></p>
            <div class="agenda-font-select-wrap">
              <select
                class="form-control"
                id="agenda-font-<?= esc($fontKey, 'attr') ?>"
                name="agenda_font_sizes[<?= esc($fontKey, 'attr') ?>]"
                data-agenda-font-setting="<?= esc($fontKey, 'attr') ?>"
              >
                <?php foreach ((array) ($fontRow['options'] ?? []) as $fontOption): ?>
                  <?php $fontOption = (int) $fontOption; ?>
                  <option value="<?= $fontOption ?>" <?= $fontOption === $fontValue ? 'selected' : '' ?>>
                    <?= $fontOption ?> px<?= $fontOption === $fontDefault ? ' — attuale' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="agenda-font-preview" aria-label="Anteprima dimensioni agenda">
        <div class="agenda-font-preview-heading">
          <strong>Anteprima immediata</strong>
          <span>Le modifiche qui sotto si vedono prima del salvataggio</span>
        </div>
        <div class="agenda-font-preview-calendar">
          <div class="agenda-font-preview-cell"></div>
          <div class="agenda-font-preview-cell agenda-font-preview-day">Lunedì 7</div>
          <div class="agenda-font-preview-cell agenda-font-preview-day">Martedì 8</div>

          <div class="agenda-font-preview-cell agenda-font-preview-axis">09:00</div>
          <div class="agenda-font-preview-cell">
            <span class="agenda-font-preview-professional">Dott.ssa Bianchi</span>
          </div>
          <div class="agenda-font-preview-cell">
            <div class="agenda-font-preview-slot">
              <span class="agenda-font-preview-time">09:00 – 09:30</span>
              <span class="agenda-font-preview-title">Mario Rossi</span>
              <span class="agenda-font-preview-detail">Prima visita · Ambulatorio 1</span>
            </div>
          </div>
        </div>
        <div class="agenda-font-preview-tools">
          <span class="agenda-font-preview-controls"><i class="fa fa-filter"></i> Filtri e comandi</span>
          <span class="agenda-font-preview-mini-calendar"><i class="fa fa-calendar"></i> Mini calendario</span>
          <span class="agenda-font-preview-notes"><i class="fa fa-sticky-note"></i> Note e memo</span>
        </div>
      </div>
    </div>

    <div class="box-footer">
      <span class="agenda-font-save-note">
        Le preferenze ti seguiranno in tutti gli accessi al tuo spazio.
      </span>
      <div>
        <button type="button" class="btn btn-default" data-agenda-font-reset>
          <i class="fa fa-undo"></i> Ripristina valori attuali
        </button>
        <button type="submit" class="btn btn-primary">
          <i class="fa fa-check"></i> Salva dimensioni
        </button>
      </div>
    </div>
  </div>
</form>
