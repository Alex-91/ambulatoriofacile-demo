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
    $subgroups=$navigation::tiles($name,$links);
  ?>
    <?php if ($name==='Personale'): ?><div class="af-menu-divider">Gestione dello studio</div><?php endif ?>
    <?php if (in_array($name,['Oggi','Agenda'],true) && count($links)===1): ?>
      <a class="af-unified-entry" href="<?= esc($links[0]['href']) ?>" <?= $active ? 'aria-current="page"' : '' ?>><?= $navigation::icon($name) ?><span><?= esc($name) ?></span></a>
    <?php else: ?>
      <details name="af-navigation" class="af-menu-section <?= $name==='Account e spazi'?'af-menu-account':'' ?>" <?= $active?'open':'' ?>>
        <summary><?= $navigation::icon($name) ?><span><?= esc($name) ?></span><svg class="af-menu-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></summary>
        <div class="af-menu-children">
        <?php foreach($subgroups as $title=>$items): ?>
          <?php if(count($subgroups)>1 && count($items)>1): ?><div class="af-menu-subtitle"><?= esc($title) ?></div><?php endif ?>
          <?php foreach($items as $link): ?><a class="af-unified-child" href="<?= esc($link['href']) ?>" <?= strtok($link['href'],'?')===$current?'aria-current="page"':'' ?>><?= $navigation::icon($name) ?><span><?= esc($link['label']) ?></span></a><?php endforeach ?>
        <?php endforeach ?>
        </div>
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
.af-unified-menu .af-menu-section>summary{display:flex!important;align-items:center;gap:0;list-style:none;font-weight:500}.af-unified-menu summary::-webkit-details-marker{display:none}.af-unified-menu .af-menu-chevron{width:14px;height:14px;margin-left:auto;flex-shrink:0;transition:transform .15s}.af-menu-section[open]>.af-menu-chevron,.af-menu-section[open]>summary>.af-menu-chevron{transform:rotate(90deg)}.af-unified-menu .af-unified-entry,.af-unified-menu .af-unified-child{display:flex!important;align-items:center;text-decoration:none}.af-unified-menu .af-unified-child{font-size:12px;line-height:1.4;padding:9px 8px 9px 12px!important;border-radius:6px}.af-unified-menu .af-unified-child:hover,.af-unified-menu summary:hover{background:#f4f7fa}.af-menu-children{margin:2px 3px 10px 18px;border-left:1px solid #dce5ec;padding-left:6px}.af-menu-subtitle{font-size:10px;letter-spacing:.4px;color:#63798a;padding:13px 10px 4px;text-transform:uppercase}.af-unified-menu .af-unified-child .af-menu-icon{opacity:.75}
.af-unified-menu{background:#fff;border:0!important;box-shadow:none!important;font:14px/1.5 system-ui,sans-serif}.af-unified-menu .box-header{display:none}.af-unified-menu .af-unified-entry{text-decoration:none;margin:2px 0;font-weight:500!important;border-radius:7px!important}.af-unified-menu .af-unified-entry:hover{background:#f4f7fa}.af-menu-divider{border-top:1px solid #dce5ec;margin:20px 10px 10px;padding-top:16px;color:#63798a;font-size:10px;text-transform:uppercase;letter-spacing:1px}.af-menu-account{border-top:1px solid #dce5ec;margin-top:18px}
.af-unified-menu .af-menu-icon{display:inline-block;width:19px;height:19px;margin-right:9px;vertical-align:middle;flex-shrink:0}.af-unified-menu .af-unified-child .af-menu-icon{width:16px;height:16px}
.af-unified-menu .box-body{padding:8px}.af-unified-menu .af-unified-entry,.af-unified-menu summary{display:block;padding:11px 10px;color:#24545b;border-radius:5px;font-weight:600;cursor:pointer}.af-unified-menu summary{display:list-item;list-style-position:inside}.af-unified-menu .af-unified-child{display:block;padding:9px 10px 9px 22px;color:#334b5b;overflow-wrap:anywhere}.af-unified-menu [aria-current=page]{background:#e5f3f4;color:#086e78}.af-unified-menu a:focus-visible,.af-unified-menu summary:focus-visible{outline:2px solid #087f8c}.af-menu-rollback{margin-top:18px;padding:12px 6px;border-top:1px solid #dce5ec}.af-menu-rollback label{font-weight:400;font-size:12px;cursor:pointer}.af-menu-rollback input{margin-right:5px}.af-menu-rollback .help-block{font-size:12px}
</style>
