<?php
$activeNode=static function(array $node) use (&$activeNode,$current): bool {
    if(isset($node['href']))return strtok($node['href'],'?')===$current;
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
