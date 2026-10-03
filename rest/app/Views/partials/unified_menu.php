<?php
helper(['session_auth', 'form']);
$navigation = new \App\Services\UnifiedMenuService();
$groups = $navigation->groups();
$current = current_url();
?>
<nav class="af-unified-menu box box-solid" aria-label="Menu principale">
  <div class="box-header"><strong>Menu</strong></div>
  <div class="box-body">
  <?php foreach ($groups as $name => $links):
    $sectionHref=site_url('navigazione/'.$navigation::SECTION_SLUGS[$name]);
    $active = $current===$sectionHref || (bool) array_filter($links, static fn($link) => strtok($link['href'], '?') === $current);
    $href=$navigation::sectionUrl($name,$links);
  ?>
    <?php if ($name==='Personale'): ?><div class="af-menu-divider">Gestione dello studio</div><?php endif ?>
    <?php if ($name==='Account e spazi'): ?>
      <details class="af-menu-account"><summary><?= $navigation::icon($name) ?><?= esc($name) ?></summary>
      <?php foreach($links as $link): ?><a class="af-unified-child" href="<?= esc($link['href']) ?>"><?= esc($link['label']) ?></a><?php endforeach ?>
      </details>
    <?php else: ?>
      <a class="af-unified-entry" href="<?= esc($href) ?>" <?= $active ? 'aria-current="page"' : '' ?>><?= $navigation::icon($name) ?><?= esc($name) ?></a>
      <?php if ($name==='Agenda' && $active && count($links)>1): ?><a class="af-unified-child" href="<?= esc($sectionHref) ?>">Strumenti agenda</a><?php endif ?>
    <?php endif ?>
  <?php endforeach ?>
  <?php if (session_has_tenant_master_access()): ?>
    <form method="post" action="<?= site_url('preferenze-navigazione/menu') ?>" class="af-menu-rollback">
      <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= esc(current_url()) ?>">
      <label><input type="checkbox" name="legacy" value="1" onchange="this.form.requestSubmit()"> Non ti piace? Clicca qui per tornare al menu precedente.</label>
      <p class="help-block">La scelta si applica a tutto lo spazio.</p>
      <noscript><button type="submit" class="btn btn-default">Applica</button></noscript>
    </form>
  <?php endif ?>
  </div>
</nav>
<style>
.af-unified-menu{background:#fff;border:0!important;box-shadow:none!important;font:14px/1.5 system-ui,sans-serif}.af-unified-menu .box-header{display:none}.af-unified-menu .af-unified-entry{text-decoration:none;margin:2px 0;font-weight:500!important;border-radius:7px!important}.af-unified-menu .af-unified-entry:hover{background:#f4f7fa}.af-menu-divider{border-top:1px solid #dce5ec;margin:20px 10px 10px;padding-top:16px;color:#63798a;font-size:10px;text-transform:uppercase;letter-spacing:1px}.af-menu-account{border-top:1px solid #dce5ec;margin-top:18px}
.af-unified-menu .af-menu-icon{display:inline-block;width:19px;height:19px;margin-right:9px;vertical-align:middle;flex-shrink:0}.af-unified-menu .af-unified-child .af-menu-icon{width:16px;height:16px}
.af-unified-menu .box-body{padding:8px}.af-unified-menu .af-unified-entry,.af-unified-menu summary{display:block;padding:11px 10px;color:#24545b;border-radius:5px;font-weight:600;cursor:pointer}.af-unified-menu summary{display:list-item;list-style-position:inside}.af-unified-menu .af-unified-child{display:block;padding:9px 10px 9px 22px;color:#334b5b;overflow-wrap:anywhere}.af-unified-menu [aria-current=page]{background:#e5f3f4;color:#086e78}.af-unified-menu a:focus-visible,.af-unified-menu summary:focus-visible{outline:2px solid #087f8c}.af-menu-rollback{margin-top:18px;padding:12px 6px;border-top:1px solid #dce5ec}.af-menu-rollback label{font-weight:400;font-size:12px;cursor:pointer}.af-menu-rollback input{margin-right:5px}.af-menu-rollback .help-block{font-size:12px}
</style>
