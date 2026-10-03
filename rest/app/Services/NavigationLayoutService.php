<?php
namespace App\Services;

/** Presentation only: a saved leaf never supplies a URL or an authorization. */
final class NavigationLayoutService
{
    public const ICONS=['Oggi','Agenda','Pazienti','Esami','Amministrazione','Comunicazioni','Personale','Report','Impostazioni','Account e spazi'];
    private $db;
    public function __construct() { $this->db=\Config\Database::connect('platform'); }
    public static function linkId(string $href): string
    {
        $path=preg_replace('~^(?:app|demo)/~','',ltrim((string)parse_url($href,PHP_URL_PATH),'/'));
        return 'l_'.hash('sha256',$path.'?'.(string)parse_url($href,PHP_URL_QUERY));
    }
    private function row(int $scope): ?array
    {
        return $this->db->table('platform_navigation_layouts')->where('scope_id',$scope)->get()->getRowArray();
    }
    private function globalRow(): array
    {
        $this->db->query('INSERT IGNORE INTO platform_navigation_layouts (scope_id,version,nodes_json,updated_at) VALUES (0,1,?,?)',['[]',date('Y-m-d H:i:s')]);
        return $this->row(0);
    }
    /** Import newly available entries once; subsequent renders use saved parents/order/labels. */
    public function discover(array $groups): void
    {
        $row=$this->globalRow();$nodes=json_decode($row['nodes_json'],true,512,JSON_THROW_ON_ERROR);
        $known=array_column($nodes,null,'id');$append=[];
        foreach($groups as $group=>$links){
            $parent='g_'.substr(hash('sha256',$group),0,16);
            if(!isset($known[$parent])){$append[]=$known[$parent]=['id'=>$parent,'parent'=>'','label'=>$group,'icon'=>$group,'type'=>'group','hidden'=>false];}
            foreach($links as $link){
                $id=self::linkId($link['href']);
                if(isset($known[$id]))continue;
                // Account names and session-specific URLs are never persisted in the shared catalog.
                $append[]=$known[$id]=['id'=>$id,'parent'=>$parent,'label'=>$group==='Account e spazi'?'Account / spazio':$link['label'],'icon'=>$group,'type'=>'link','hidden'=>false,'runtime_label'=>$group==='Account e spazi'];
            }
        }
        if(!$append)return;
        $this->db->transBegin();
        try{
            $locked=$this->db->query('SELECT * FROM platform_navigation_layouts WHERE scope_id=0 FOR UPDATE')->getRowArray();
            $nodes=json_decode($locked['nodes_json'],true,512,JSON_THROW_ON_ERROR);$known=array_column($nodes,null,'id');
            foreach($append as $node)if(!isset($known[$node['id']])){$nodes[]=$node;$known[$node['id']]=$node;}
            $this->db->table('platform_navigation_layouts')->where('scope_id',0)->update(['nodes_json'=>json_encode($nodes,JSON_THROW_ON_ERROR),'version'=>(int)$locked['version']+1,'updated_at'=>date('Y-m-d H:i:s')]);
            if(!$this->db->transStatus())throw new \RuntimeException('Catalogo menu non aggiornato.');
            $this->db->transCommit();
        }catch(\Throwable $e){$this->db->transRollback();throw $e;}
    }
    public function read(int $scope): array
    {
        $global=$this->globalRow();$own=$scope>0?$this->row($scope):$global;
        $nodes=json_decode(($own??$global)['nodes_json'],true,512,JSON_THROW_ON_ERROR);
        // New product entries remain available in tenant overrides without overwriting customizations.
        $known=array_column($nodes,null,'id');
        foreach(json_decode($global['nodes_json'],true) as $node)if(!isset($known[$node['id']]))$nodes[]=$node;
        return ['scope'=>$scope,'version'=>(int)($own['version']??0),'globalVersion'=>(int)$global['version'],'inherited'=>$own===null,'canRestore'=>!empty($own['previous_json']),'nodes'=>$nodes];
    }
    public static function validate(array $nodes,array $catalog): array
    {
        if(count($nodes)>1000)throw new \InvalidArgumentException('Troppe voci.');
        $known=array_column($catalog,null,'id');$map=[];$clean=[];
        foreach($nodes as $node){
            if(!is_array($node))throw new \InvalidArgumentException('Voce non valida.');
            $id=(string)($node['id']??'');$type=$node['type']??'';$label=trim((string)($node['label']??''));
            if(!preg_match('/^[gl]_[a-zA-Z0-9_-]{1,80}$/D',$id)||isset($map[$id])||!in_array($type,['group','link'],true)||!preg_match('/^.{1,90}$/usD',$label)||!NavigationIconService::supported($node['icon']??null))throw new \InvalidArgumentException('Identificativo, nome o icona non validi.');
            if($type==='link' && (!isset($known[$id])||$known[$id]['type']!=='link'))throw new \InvalidArgumentException('Pagina non presente nel catalogo.');
            if(isset($known[$id]) && $known[$id]['type']!==$type)throw new \InvalidArgumentException('Tipo voce non modificabile.');
            $item=['id'=>$id,'parent'=>(string)($node['parent']??''),'type'=>$type,'label'=>$label,'icon'=>$node['icon'],'hidden'=>!empty($node['hidden'])];
            if(!empty($known[$id]['runtime_label']))$item['runtime_label']=true;
            $map[$id]=$item;$clean[]=$item;
        }
        foreach($known as $id=>$node)if(!isset($map[$id]))throw new \InvalidArgumentException('Usa Nascondi per rimuovere una voce dal menu.');
        foreach($map as $id=>$node){
            $seen=[$id=>true];$parent=$node['parent'];$depth=0;
            while($parent!==''){
                if(isset($seen[$parent])||!isset($map[$parent])||$map[$parent]['type']!=='group'||++$depth>3)throw new \InvalidArgumentException('Gerarchia non valida: massimo tre livelli di sottomenu, senza cicli.');
                $seen[$parent]=true;$parent=$map[$parent]['parent'];
            }
        }
        return $clean;
    }
    /** Project the saved tree onto the current user's permitted links, pruning empty groups. */
    public static function project(array $nodes,array $allowed,string $parent=''): array
    {
        $result=[];
        foreach($nodes as $node){
            if($node['parent']!==$parent||!empty($node['hidden']))continue;
            if($node['type']==='group'){
                $node['children']=self::project($nodes,$allowed,$node['id']);
                if(!$node['children'])continue;
            }else{
                if(!isset($allowed[$node['id']]))continue;
                $node['href']=$allowed[$node['id']]['href'];
                if(!empty($node['runtime_label']))$node['label']=$allowed[$node['id']]['label'];
            }
            $result[]=$node;
        }
        return $result;
    }
    public function tree(int $tenant,array $groups): array
    {
        $this->discover($groups);$allowed=[];
        foreach($groups as $links)foreach($links as $link)$allowed[self::linkId($link['href'])]=$link;
        return self::project($this->read($tenant)['nodes'],$allowed);
    }
    public function save(int $scope,int $version,int $globalVersion,array $nodes,int $actor,string $action='save'): void
    {
        if($scope<0||($scope>0&&!$this->db->table('platform_tenants')->where('id_tenant',$scope)->countAllResults()))throw new \InvalidArgumentException('Spazio inesistente.');
        if(!in_array($action,['save','restore','inherit'],true)||($scope===0&&$action==='inherit'))throw new \InvalidArgumentException('Azione non valida.');
        $this->globalRow();$this->db->transBegin();
        try{
            $global=$this->db->query('SELECT * FROM platform_navigation_layouts WHERE scope_id=0 FOR UPDATE')->getRowArray();
            $own=$scope===0?$global:$this->db->query('SELECT * FROM platform_navigation_layouts WHERE scope_id=? FOR UPDATE',[$scope])->getRowArray();
            if((int)($own['version']??0)!==$version||(int)$global['version']!==$globalVersion)throw new \DomainException('Il menu è stato aggiornato. Ricarica prima di salvare.');
            $catalog=json_decode($global['nodes_json'],true);$previous=$own['nodes_json']??null;
            if($action==='inherit'){
                $this->db->table('platform_navigation_layouts')->where('scope_id',$scope)->delete();
            }else{
                if($action==='restore'){
                    if(empty($own['previous_json']))throw new \InvalidArgumentException('Nessuna disposizione precedente.');
                    $nodes=json_decode($own['previous_json'],true);
                    $ids=array_column($nodes,'id');foreach($catalog as $node)if(!in_array($node['id'],$ids,true))$nodes[]=$node;
                }
                $nodes=self::validate($nodes,$catalog);
                $data=['version'=>$version+1,'nodes_json'=>json_encode($nodes,JSON_THROW_ON_ERROR),'previous_json'=>$previous,'updated_by'=>$actor,'updated_at'=>date('Y-m-d H:i:s')];
                if($own)$this->db->table('platform_navigation_layouts')->where('scope_id',$scope)->update($data);
                else $this->db->table('platform_navigation_layouts')->insert(['scope_id'=>$scope]+$data);
            }
            if(!$this->db->transStatus())throw new \RuntimeException('Salvataggio non riuscito.');
            $this->db->transCommit();
        }catch(\Throwable $e){$this->db->transRollback();throw $e;}
    }
}
