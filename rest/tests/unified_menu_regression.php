<?php
require __DIR__ . '/../app/Services/UnifiedMenuService.php';
require __DIR__ . '/../app/Libraries/TenantFeatureRegistry.php';
use App\Services\UnifiedMenuService;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$links = UnifiedMenuService::extractLinks('<a href="#">Gruppo</a><a href="javascript:alert(1)">Bad</a><li class="disabled"><a href="/admin/fatture">Hidden</a></li><a href="/agenda">Vai all’agenda</a><a href="/agenda">Agenda</a><a href="/pacs" aria-disabled="true">No</a><a href="/pazienti">Pazienti</a><a href="/cartella-clinica/diagnostica">Diagnostica</a><a href="/login/spazio/pacs">Configurazione PACS</a>');
check(count($links)===4,'Disabled, placeholder and duplicate entries must be excluded');
$groups=UnifiedMenuService::groupLinks($links);
check(isset($groups['Agenda'],$groups['Pazienti'],$groups['Esami'],$groups['Impostazioni']),'Clinical and configuration routes must remain distinct');
check(!isset($groups['Amministrazione']),'Grouping must not introduce unauthorized modules');
check(array_sum(array_map('count',$groups))===count($links),'Every allowed link must remain reachable');
check(UnifiedMenuService::extractLinks('<a href="/x">Disponibilità &amp; orari</a>')[0]['label']==='Disponibilità & orari','Labels preserve Unicode');
echo "PASS unified menu: visibility, grouping, reachability, Unicode\n";
$routes=['/agenda','/admin/fatturazione-documenti','/admin/sistema-ts/diagnostica','/cartella-clinica/diagnostica','/cartella-clinica/configurazione','/admin/fse2','/agenda/importa-pazienti-excel','/agenda/gestione-tipi-visita','/login/spazio/utenti','/preferenze-navigazione'];
$items=array_map(static fn($href)=>['href'=>'https://test.invalid/app'.$href,'label'=>$href],$routes);
$minimal=UnifiedMenuService::filterEnabledLinks($items,['agenda'=>true]);
check(array_column($minimal,'label')===['/agenda','/preferenze-navigazione'],'Disabled tenant modules must be absent, including injected links');
$ts=UnifiedMenuService::filterEnabledLinks($items,['ts_billing'=>true]);
check(array_column($ts,'label')===['/admin/sistema-ts/diagnostica','/preferenze-navigazione'],'TS diagnostics must not require PACS and must remain independent of billing');
$pacs=UnifiedMenuService::filterEnabledLinks($items,['pacs_dicom'=>true]);
check(count($pacs)===1,'PACS requires clinical records too');
$billing=[['href'=>'/admin/fatturazione/gestione?tab=catalogo&kind=agreement','label'=>'Convenzioni']];
check(UnifiedMenuService::filterEnabledLinks($billing,['billing'=>true])===[],'Optional billing capability must be enabled');
check(count(UnifiedMenuService::filterEnabledLinks($billing,['billing'=>true,'billing_agreements'=>true]))===1,'Enabled billing capability must remain visible');
check(str_contains(UnifiedMenuService::icon('Agenda'),'<svg'),'Icons must not depend on an external font');
$locations=[['href'=>'/agenda/gestione-sedi','label'=>'Sedi']];
check(UnifiedMenuService::filterEnabledLinks($locations,['agenda'=>true])===[],'Multi-location links require the tenant module');
check(count(UnifiedMenuService::filterEnabledLinks($locations,['agenda'=>true,'multi_location'=>true]))===1,'Enabled multi-location stays visible');
echo "PASS tenant module isolation, PACS dependencies, billing capabilities, SVG icons\n";
