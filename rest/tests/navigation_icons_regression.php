<?php
require __DIR__.'/../app/Services/NavigationLayoutService.php';
require __DIR__.'/../app/Services/NavigationIconService.php';
require __DIR__.'/../app/Services/UnifiedMenuService.php';
use App\Services\{NavigationIconService as Icons,NavigationLayoutService as Layout,UnifiedMenuService as Menu};
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$catalog=Icons::catalog();$counts=array_count_values(array_column($catalog,'family'));
foreach(['solid','regular','brands'] as $family){
    $files=glob(__DIR__.'/../../public/assets/fontawesome/svgs/'.$family.'/*.svg');
    check(($counts[$family]??0)===count($files),'Incomplete icon family '.$family);
}
foreach($catalog as $item)check(Icons::supported($item['id']),'Catalog includes an unsupported icon');
foreach(['fa:solid:stethoscope','fa:regular:calendar','fa:brands:github'] as $id){
    $node=['id'=>'g_test','parent'=>'','type'=>'group','label'=>'Test','icon'=>$id,'hidden'=>false];
    check(Layout::validate([$node],[])===[$node],'Icon rejected on save');
    $svg=Menu::icon($id);
    check(str_contains($svg,'fill="currentColor"')&&str_contains($svg,'af-menu-icon'),'Menu SVG is missing');
    check(str_contains($svg,'Font Awesome Free'),'Bundled license removed');
}
foreach(['fa:solid:../../../../.env','fa:solid:stethoscope.svg','fa:solid:no-such-icon','fa:duotone:user','fa:solid:user" onload="alert(1)',[],null] as $bad){
    check(!Icons::supported($bad),'Untrusted or missing icon accepted');
}
foreach(Layout::ICONS as $id)check(str_contains(Menu::icon($id),'<svg'),'Existing menu icon lost');
echo 'PASS complete installed catalog, legacy icons, all families, SVG rendering, safe IDs: '.json_encode($counts)."\n";
