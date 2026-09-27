<?php
// Read-only synthetic rendering. Does not bootstrap the app or load environment credentials.
if(PHP_SAPI!=='cli') exit(1);
function esc($v,$context=null){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function site_url($v='/'){return '/'.ltrim($v,'/');}
function base_url($v){return '/'.ltrim($v,'/');}
function csrf_field(){return '<input type="hidden" name="csrf_synthetic" value="synthetic">';}
function view($name,$data=[]){
 if($name==='partials/header') return '<header class="main-header"><a class="logo" href="/">AmbulatorioFacile</a><nav class="navbar navbar-static-top">Menu applicazione</nav></header>';
 if($name==='partials/sidebar_admin') return '<div class="box box-solid admin-sidebar-menu"><div class="box-header with-border"><h3 class="box-title">Menu</h3></div><ul class="nav nav-pills nav-stacked"><li><a href="/">⌂　Dashboard</a></li><li><a href="/agenda">▦　Agenda</a></li><li><a href="/pazienti">♙　Pazienti</a></li><li><a href="/cartella">▤　Cartella clinica</a></li><li class="active"><a href="/admin/fatturazione">▣　Fatturazione</a></li><li><a href="/settings">⚙　Impostazioni</a></li></ul></div>';
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
require dirname(__DIR__).'/rest/app/Views/admin/billing/workspace.php';
