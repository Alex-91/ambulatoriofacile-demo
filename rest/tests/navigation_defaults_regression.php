<?php
require __DIR__.'/../app/Services/NavigationLayoutService.php';
require __DIR__.'/../app/Services/NavigationIconService.php';
use App\Services\{NavigationLayoutService as Layout,NavigationIconService as Icons};
$nodes=json_decode(file_get_contents(__DIR__.'/../app/Database/Seeds/Data/navigation-default.json'),true,512,JSON_THROW_ON_ERROR);
if(Layout::validate($nodes,$nodes)!==$nodes)throw new RuntimeException('Invalid initial layout.');
if(count($nodes)!==74)throw new RuntimeException('Approved entries missing.');
foreach($nodes as $node){
    if(!Icons::supported($node['icon']))throw new RuntimeException('Icon missing.');
    if(array_diff(array_keys($node),['id','parent','type','label','icon','hidden','runtime_label']))throw new RuntimeException('Unexpected data in presentation defaults.');
    if(!empty($node['runtime_label']) && $node['label']!=='Account / spazio')throw new RuntimeException('Account-specific labels must not be exported.');
}
if(Layout::project($nodes,[])!==[])throw new RuntimeException('Defaults must not grant access.');
echo "PASS approved 74-node defaults: valid structure and icons, no URLs or account data, permissions preserved\n";
