<?php
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/rest/tests/_support/billing_sidebar_fixture.php';
function esc($v,$context=null){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function site_url($v='/'){return '/'.ltrim($v,'/');}
function base_url($v){return '/'.ltrim($v,'/');}
function csrf_field(){return '<input type="hidden" name="csrf_synthetic" value="synthetic">';}
function session(){return new class {public function get($key){return null;}};}
function service($name){return new class {public function getPath(){return '/admin/personale/modifica_personale';}public function getGet($key){return null;}};}
function view($name,$data=[],$options=[]){
 if($name==='partials/header')return '<header class="main-header"><a class="logo">AmbulatorioFacile</a><nav class="navbar navbar-static-top"></nav></header>';
 extract($data);ob_start();require dirname(__DIR__).'/rest/app/Views/'.$name.'.php';return ob_get_clean();
}
$menu_items=[['link'=>'personale/modifica_personale','titolo_menu'=>'Personale']];
$professionalAvailable=true;$gruppi=[['id_gruppo'=>1,'nome'=>'Sede test']];$tipi=[['id_type_doctors'=>1,'des_tipo'=>'Dottore Generale'],['id_type_doctors'=>3,'des_tipo'=>'Segreteria']];
$specialtyOptions=[['id'=>11,'name'=>'Cardiologia','active'=>true],['id'=>12,'name'=>'Medicina dello sport','active'=>true],['id'=>13,'name'=>'Dermatologia','active'=>true],['id'=>14,'name'=>'Specialità archiviata','active'=>false]];
require dirname(__DIR__).'/rest/app/Views/admin/'.(($argv[1]??'')==='create'?'personale_create':'personale_modifica').'.php';
