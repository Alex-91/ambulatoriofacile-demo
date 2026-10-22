<?php
$activeArea = null; $best = -1; $selectedHref = '';
foreach ($areas as $area=>$links) foreach ($links as $link) {
    if (\App\Services\AdministrationNavigation::matches($link['href'], $current) && strlen($link['href']) > $best) {
        $best = strlen($link['href']); $activeArea = $area; $selectedHref = $link['href'];
    }
}
if ($activeArea === null) return;
?>
<template id="administration-area-tabs"><nav class="administration-area-tabs" aria-label="<?= esc($activeArea) ?>">
<span><?= esc($activeArea) ?></span><div>
<?php foreach ($areas[$activeArea] as $link): ?><a href="<?= esc($link['href']) ?>" <?= $selectedHref === $link['href'] ? 'aria-current="page"' : '' ?>><?= esc($link['label']) ?></a><?php endforeach ?>
</div></nav></template>
<style>.administration-area-tabs{margin:0 0 22px;font:14px/1.5 system-ui,sans-serif}.administration-area-tabs>span{display:block;color:#63798a;font-size:12px;margin-bottom:8px}.administration-area-tabs>div{display:flex;flex-wrap:wrap;gap:8px 18px;border-bottom:1px solid #dce5ec}.administration-area-tabs a{display:block;padding:9px 0;color:#536c79;text-decoration:none;border-bottom:2px solid transparent}.administration-area-tabs a[aria-current=page]{color:#187d86;border-bottom-color:#187d86}.administration-area-tabs a:focus-visible{outline:2px solid #187d86}</style>
<script>document.addEventListener('DOMContentLoaded',function(){var t=document.getElementById('administration-area-tabs');if(!t)return;var main=document.querySelector('#billing-workspace,main.pc,.content-wrapper .col-md-9 main,.content-wrapper .col-md-9');if(main)main.prepend(t.content.cloneNode(true));},{once:true});</script>
