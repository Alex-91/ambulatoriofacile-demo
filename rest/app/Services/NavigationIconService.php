<?php
namespace App\Services;

/** Only the Font Awesome SVGs shipped with this application can be selected. */
final class NavigationIconService
{
    private static function directory(): string
    {
        return dirname(__DIR__,3).'/public/assets/fontawesome/svgs';
    }

    private static function path(string $id): ?string
    {
        if(!preg_match('/^fa:(solid|regular|brands):([a-z0-9]+(?:-[a-z0-9]+)*)$/D',$id,$parts))return null;
        $path=self::directory().'/'.$parts[1].'/'.$parts[2].'.svg';
        return is_file($path)?$path:null;
    }

    public static function supported($id): bool
    {
        return is_string($id) && (in_array($id,NavigationLayoutService::ICONS,true)||self::path($id)!==null);
    }

    public static function catalog(): array
    {
        $result=[];
        foreach(NavigationLayoutService::ICONS as $name)$result[]=['id'=>$name,'label'=>$name,'family'=>'menu'];
        foreach(['solid','regular','brands'] as $family){
            foreach(glob(self::directory().'/'.$family.'/*.svg')?:[] as $path){
                $name=basename($path,'.svg');
                $result[]=['id'=>'fa:'.$family.':'.$name,'label'=>str_replace('-',' ',$name),'family'=>$family,'asset'=>$family.'/'.$name.'.svg'];
            }
        }
        return $result;
    }

    public static function svg(string $id): string
    {
        static $cache=[];
        if(isset($cache[$id]))return $cache[$id];
        $path=self::path($id);
        if($path===null)return '';
        $svg=file_get_contents($path);
        if($svg===false)return '';
        // Preserve the bundled SVG and its license; inherit the menu's text color.
        return $cache[$id]=preg_replace('/<svg\b/','<svg class="af-menu-icon" aria-hidden="true" focusable="false" fill="currentColor"',$svg,1);
    }
}
