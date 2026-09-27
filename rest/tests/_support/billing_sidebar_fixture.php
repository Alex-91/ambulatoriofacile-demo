<?php
// Standalone fixture: render the real sidebar without app bootstrap or database access.
namespace App\Services {
    class MenuResolverService {
        public function resolveAdminSidebar(array $items=[]): array {
            return ['tenant_id'=>0,'tenant_name'=>'Spazio sintetico','menu_items'=>[
                ['link'=>'dashboard','titolo_menu'=>'Dashboard'],['link'=>'agenda/gestione-sedi','titolo_menu'=>'Gestione sedi'],['link'=>'agenda','titolo_menu'=>'Agenda'],
                ['link'=>'fatturazione','titolo_menu'=>'Fatturazione'],['link'=>'fatturazione-documenti','titolo_menu'=>'Lista fatture'],
                ['link'=>'fatturazione-documento','titolo_menu'=>'Documento fatturazione'],['link'=>'sistema-ts','titolo_menu'=>'Sistema TS'],
            ],'context_actions'=>[['href'=>'http://localhost/spazio/fatturazione','label'=>'Configura fatturazione'],['href'=>'http://localhost/spazio/sistema-ts','label'=>'Configura Sistema TS']]];
        }
    }
}
namespace {
    function helper($name) {}
    function admin_menu_pretty_title($title,$link) {return $title;}
    function admin_menu_resolve_icon($icon,$title,$link) {return $icon?:'fa-home';}
    function admin_menu_resolve_href($link) {return site_url($link);}
}
