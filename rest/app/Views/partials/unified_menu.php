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
    $active = (bool) array_filter($links, static fn($link) => strtok($link['href'], '?') === $current);
  ?>
    <?php if (count($links) === 1): $link = $links[0]; ?>
      <a class="af-unified-entry" href="<?= esc($link['href']) ?>" <?= $active ? 'aria-current="page"' : '' ?>><?= $navigation::icon($name) ?><?= esc($name) ?></a>
    <?php else: ?>
      <details <?= $active ? 'open' : '' ?>><summary><?= $navigation::icon($name) ?><?= esc($name) ?></summary>
        <?php foreach ($links as $link): ?><a class="af-unified-child" href="<?= esc($link['href']) ?>" <?= strtok($link['href'], '?') === $current ? 'aria-current="page"' : '' ?>><?= $navigation::icon($name) ?><?= esc($link['label']) ?></a><?php endforeach ?>
      </details>
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
.af-unified-menu .af-menu-icon{display:inline-block;width:19px;height:19px;margin-right:9px;vertical-align:middle;flex-shrink:0}.af-unified-menu .af-unified-child .af-menu-icon{width:16px;height:16px}
.af-unified-menu .box-body{padding:8px}.af-unified-menu .af-unified-entry,.af-unified-menu summary{display:block;padding:11px 10px;color:#24545b;border-radius:5px;font-weight:600;cursor:pointer}.af-unified-menu summary{display:list-item;list-style-position:inside}.af-unified-menu .af-unified-child{display:block;padding:9px 10px 9px 22px;color:#334b5b;overflow-wrap:anywhere}.af-unified-menu [aria-current=page]{background:#e5f3f4;color:#086e78}.af-unified-menu a:focus-visible,.af-unified-menu summary:focus-visible{outline:2px solid #087f8c}.af-menu-rollback{margin-top:18px;padding:12px 6px;border-top:1px solid #dce5ec}.af-menu-rollback label{font-weight:400;font-size:12px;cursor:pointer}.af-menu-rollback input{margin-right:5px}.af-menu-rollback .help-block{font-size:12px}
</style>
