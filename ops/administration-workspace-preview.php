<?php
// Read-only synthetic rendering. Does not bootstrap the app or load environment credentials.
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/rest/tests/_support/billing_sidebar_fixture.php';
require dirname(__DIR__).'/rest/app/Services/BillingExportFormat.php';
require dirname(__DIR__).'/rest/app/Services/AdministrationNavigation.php';
require dirname(__DIR__).'/rest/app/Services/UnifiedMenuService.php';
function service($name) {return new class {public function getPath(){return match($GLOBALS['argv'][1]??''){ 'branches','services'=>'/admin/fatturazione/gestione','locations'=>'/agenda/gestione-sedi','outside'=>'/agenda','schedule'=>'/admin/fatturazione-scadenzario','reports'=>'/admin/fatturazione-statistiche','ts'=>'/admin/sistema-ts/documenti',default=>'/admin/fatturazione-documenti'};}public function getGet($key){$mode=$GLOBALS['argv'][1]??'';if(in_array($mode,['branches','services'],true))return match($key){'tab'=>'catalogo','kind'=>$mode==='branches'?'branch':'service',default=>null};return null;}};}
function esc($v,$context=null){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function site_url($v='/'){return '/'.ltrim($v,'/');}
function base_url($v){return '/'.ltrim($v,'/');}
function csrf_field(){return '<input type="hidden" name="csrf_synthetic" value="synthetic">';}
function view($name,$data=[]){
 if($name==='partials/header') return '<header class="main-header"><a class="logo" href="/">AmbulatorioFacile</a><nav class="navbar navbar-static-top">Menu applicazione</nav></header>';
 if($name==='partials/sidebar_admin') {
 $paths=['admin/fatturazione-documenti','admin/fatturazione-strutture','admin/fatturazione-scadenzario','admin/fatturazione/gestione?tab=documenti','admin/fatturazione/gestione?tab=catalogo&kind=service','admin/fatturazione/gestione?tab=catalogo&kind=list','admin/fatturazione/gestione?tab=catalogo&kind=agreement','admin/fatturazione/gestione?tab=catalogo&kind=rule','admin/sistema-ts/documenti','admin/sistema-ts','admin/fatturazione-statistiche'];
 $tree=[['label'=>'Amministrazione','icon'=>'Amministrazione','children'=>array_map(fn($path)=>['id'=>md5($path),'label'=>'Source','icon'=>'Amministrazione','href'=>'/'.$path],$paths)]];
 $organized=\App\Services\AdministrationNavigation::organize($tree);
 $current=($GLOBALS['argv'][1]??'')==='cash'?'/admin/fatturazione-scadenzario':'/admin/fatturazione-documenti';
 return '<nav class="af-unified-menu" aria-label="Menu principale">'.view('partials/unified_menu_tree',['tree'=>$organized['tree'],'current'=>$current]).'</nav>'.view('admin/billing/area_tabs',['areas'=>$organized['areas'],'current'=>$current]).'<style>.af-unified-menu a{display:block;padding:10px}.af-menu-icon{width:18px;height:18px;margin-right:8px}.af-menu-chevron{width:14px;height:14px}</style>';
 }
 extract($data);$navigation=['capabilities'=>$GLOBALS['capabilities'],'unified'=>true,'ts_enabled'=>true];
 ob_start();require dirname(__DIR__).'/rest/app/Views/'.$name.'.php';return ob_get_clean();
}
$capabilities=array_fill_keys(['billing_services','billing_agreements','billing_compensation'],($argv[1]??'advanced')!=='basic');
$tenantScope=['tenant_name'=>'Ambiente di test'];$workspaceUnified=true;$tsEnabled=true;$menu_items=[];
$names=['Marco DEMO Bianchi','Elena DEMO Verdi','Paolo DEMO Blu','Sara DEMO Viola','Sara DEMO Viola','Luca DEMO Gialli'];
$amounts=[10000,12000,11000,10000,2000,11000];$cash=[10000,4000,3300,8000,0,0];$net=[10000,12000,11000,8000,2000,11000];$states=['Saldata','Parziale','Quota ente','Rettificata','Nota di credito','Da incassare'];$tones=['green','amber','amber','muted','purple','blue'];
$documents=[];
foreach($names as $i=>$name){$credit=$i===4;$managed=$i!==0;$payer=$i===2?7700:0;
$documents[]=['id_billing_document'=>$i+1,'document_number'=>$credit?'NC-001':'FT-00'.($i<4?$i+1:5),'document_type'=>$credit?'credit_note':'invoice','local_state'=>'issued','patient_name'=>$name,'patient_tax_code'=>'SYNTHETIC','issue_date'=>'2026-09-21','payment_status'=>$i===0?'paid':($i===1?'partial':'unpaid'),'amount_total'=>$amounts[$i]/100,'signed_total_cents'=>$credit?-$amounts[$i]:$amounts[$i],'revenue_cents'=>$credit?-$amounts[$i]:$amounts[$i],'cash_cents'=>$cash[$i],'outstanding_cents'=>$credit?0:$net[$i]-$cash[$i],'status_label'=>$states[$i],'status_tone'=>$tones[$i],'managed'=>$managed,'services'=>['Visita cardiologica'],'doctors'=>$managed?[1=>'Dott.ssa Alice DEMO']:[],'agreements'=>$i===2?['Assicurazione DEMO · copertura 70%']:[],'earned_cents'=>$i===2?1980:2000,'fee_due_cents'=>$capabilities['billing_compensation']?($i===2?1980:2040):0,'ts_sync_enabled'=>$i===0?1:0,'ts_sync_state'=>$i===0?'pending':'disabled','ts_blocking_reason'=>$managed?'Verificare quote e incassi':'','can_edit'=>true,'can_delete'=>!$managed,'balance'=>['net_cents'=>$net[$i],'paid_cents'=>$cash[$i],'due_cents'=>$credit?0:$net[$i]-$cash[$i],'credit_cents'=>$i===3?2000:0,'patient_net_cents'=>$net[$i]-$payer,'patient_paid_cents'=>$cash[$i],'organization_net_cents'=>$payer,'organization_paid_cents'=>0]];
}
$listing=['table_available'=>true,'documents'=>($argv[1]??'')==='empty'?[]:$documents,'document_type_labels'=>['invoice'=>'Fattura','credit_note'=>'Nota di credito'],'local_state_labels'=>['issued'=>'Definitivo'],'ts_sync_labels'=>['pending'=>'Da inviare','disabled'=>'Non richiesto']];
$workspaceMode=match($argv[1]??'') {'cash'=>'cash','business'=>'business',default=>'documents'};
foreach($listing['documents'] as &$document) {$document['recipient_type']=$document['id_billing_document']===6?'business':'patient';$document['payment_date']='2026-09-22';$document['due_date']=$document['id_billing_document']===6?date('Y-m-d'):'2026-09-30';$document['payment_method']='card';} unset($document);
if(($argv[1]??'')==='many') { $base=$listing['documents'][0];$listing['documents']=[];for($id=1;$id<=56;$id++)$listing['documents'][]=array_replace($base,['id_billing_document'=>$id,'document_number'=>'FT-'.$id]); }
$listing['payment_method_labels']=['card'=>'Carta','cash'=>'Contanti'];
$previewView=match($argv[1]??'') {'schedule'=>'billing/schedule','reports'=>'billing/reports','ts'=>'ts/documents',default=>'billing/workspace'};
if ($previewView!=='billing/workspace') {$listing=['table_available'=>true];$schedule=['table_available'=>true];$report=['table_available'=>true];}
require dirname(__DIR__).'/rest/app/Views/admin/'.$previewView.'.php';
