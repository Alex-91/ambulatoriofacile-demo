<?php
require __DIR__ . '/../app/Services/UnifiedMenuService.php';
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
