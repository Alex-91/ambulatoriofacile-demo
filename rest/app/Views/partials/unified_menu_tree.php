<?php
$bestHref = ''; $bestScore = -1;
$findActive = static function(array $nodes) use (&$findActive, &$bestHref, &$bestScore, $current): void {
    foreach ($nodes as $node) {
        if (isset($node['href'])) {
            foreach ($node['area_links'] ?? [$node] as $link) {
                if (\App\Services\AdministrationNavigation::matches($link['href'], $current) && strlen($link['href']) > $bestScore) {
                    $bestHref = $link['href']; $bestScore = strlen($link['href']);
                }
            }
        } else $findActive($node['children'] ?? []);
    }
};
$findActive($tree);
$activeNode=static function(array $node) use (&$activeNode,$bestHref): bool {
    if(isset($node['href'])) {
        foreach ($node['area_links'] ?? [$node] as $link) if ($link['href'] === $bestHref) return true;
        return false;
    }
    foreach($node['children']??[] as $child)if($activeNode($child))return true;
    return false;
};
$render=static function(array $items,int $level=0) use (&$render,$activeNode): void {
    foreach($items as $node){
        $icon=\App\Services\UnifiedMenuService::icon($node['icon']);$label=esc($node['label']);$active=$activeNode($node);
        if(isset($node['href'])){
            echo '<a class="'.($level?'af-unified-child':'af-unified-entry').'" href="'.esc($node['href']).'" '.($active?'aria-current="page"':'').'>'.$icon.'<span>'.$label.'</span></a>';
        }else{
            echo '<details class="af-menu-section" '.($level===0?'name="af-navigation" ':'').($active?'open':'').'><summary>'.$icon.'<span>'.$label.'</span><svg class="af-menu-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></summary><div class="af-menu-children">';
            $render($node['children'],$level+1);echo '</div></details>';
        }
    }
};
$render($tree);
