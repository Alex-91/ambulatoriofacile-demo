<?php $selectedSpecialties=array_map('intval',is_array($selectedSpecialties??null)?$selectedSpecialties:[]); ?>
<div class="ps-select" id="personnel-specialty-select" data-options-url="<?= site_url('admin/personale/specialita/opzioni') ?>">
  <div class="ps-label-row"><label id="ps-label" for="professional_specialty_ids">Specialità <span>(facoltativo)</span></label>
    <a class="ps-manage" href="<?= site_url('admin/fatturazione/gestione?tab=catalogo&kind=branch&context=personale') ?>" target="_blank" rel="noopener">Gestisci specialità <span class="sr-only">(si apre in una nuova scheda)</span></a>
  </div>
  <input type="hidden" name="professional_specialties_catalog" value="1">
  <select multiple name="professional_specialty_ids[]" id="professional_specialty_ids" class="form-control" aria-describedby="ps-help">
    <?php foreach (($specialtyOptions??[]) as $option): $selected=in_array((int)$option['id'],$selectedSpecialties,true); ?>
    <option value="<?= (int)$option['id'] ?>" data-active="<?= !empty($option['active'])?'1':'0' ?>" <?= $selected?'selected':'' ?> <?= empty($option['active'])&&!$selected?'disabled':'' ?>><?= esc($option['name']) ?></option>
    <?php endforeach ?>
  </select>
  <div class="ps-enhanced" hidden>
    <button type="button" class="ps-trigger" aria-haspopup="dialog" aria-expanded="false" aria-controls="ps-panel" aria-labelledby="ps-label ps-summary"><span id="ps-summary">Seleziona una o più specialità</span><span class="ps-chevron" aria-hidden="true"></span></button>
    <div class="ps-chips" aria-label="Specialità selezionate"></div>
    <div class="ps-panel" id="ps-panel" role="dialog" aria-modal="false" aria-labelledby="ps-label" hidden>
      <div class="ps-search-wrap"><label class="sr-only" for="ps-search">Cerca una specialità</label><input type="search" id="ps-search" autocomplete="off" placeholder="Cerca una specialità…" aria-controls="ps-options"></div>
      <div class="ps-options" id="ps-options" role="group" aria-label="Specialità disponibili"></div>
      <p class="ps-empty" hidden></p>
      <div class="ps-footer"><button type="button" class="ps-refresh">Aggiorna elenco</button><button type="button" class="ps-done">Fatto</button></div>
    </div>
  </div>
  <p class="ps-help" id="ps-help">Scegli dall’elenco le specialità di questa persona. Puoi selezionarne più di una.</p>
  <p class="ps-status" role="status" aria-live="polite"></p>
</div>
