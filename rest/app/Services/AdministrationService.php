<?php
namespace App\Services;
use CodeIgniter\Database\BaseConnection;

/** Tenant-local administration. No insurer/SSN submission, bank payment or fiscal issuance. */
class AdministrationService
{
    use AdministrationCompensation;
    public const TABLES=['catalog','quotes','cases','receipts','compensation','audit'];
    public function __construct(protected BaseConnection $db,protected int $tenantId,protected int $actor,
        protected ?AdministrationFeatureService $features=null)
    { $this->features ??=new AdministrationFeatureService(); }
    public function modules(): array { return $this->features->available($this->tenantId); }
    public function assertAccess(?string $module=null): void
    {
        if ($module!==null) $this->features->assertEnabled($this->tenantId,$module);
        elseif (!$this->modules()) throw new AdministrationException('Nessun modulo amministrativo abilitato.');
        if ((new ClinicalAccessPolicy($this->db,$this->actor,$this->tenantId))->actor()['role']!==4) throw new AdministrationException('Gestione amministrativa riservata al responsabile dello spazio.');
    }
    public function ready(): bool
    {
        foreach (self::TABLES as $table) if (!$this->db->tableExists('administration_'.$table)) return false;
        foreach (['catalog'=>['kind','active'],'quotes'=>['request_token','patient_id','state','total_cents','expires_on'],'cases'=>['quote_id','regime','received_cents','payer_cents','patient_cents'],'receipts'=>['case_id','party','amount_cents','active'],'compensation'=>['billing_id','staff_id','amount_cents','state']] as $table=>$columns) {
            foreach (array_merge(['tenant_id','id','revision','payload_enc','created_by','updated_at'],$columns) as $column) if (!$this->db->fieldExists($column,'administration_'.$table)) return false;
        }
        return true;
    }
    public function initialize(): void
    {
        $this->assertAccess();
        $vault=new ClinicalVault($this->tenantId); $vault->open('administration-check',$vault->seal('administration-check','synthetic'));
        require_once APPPATH.'Database/Migrations/2026-09-14-140001_CreateAdministrationModules.php';
        (new \App\Database\Migrations\CreateAdministrationModules(\Config\Database::forge($this->db)))->up();
        $this->audit('prepared','',1);
    }
    protected function guard(string $module): void
    { $this->assertAccess($module); if (!$this->ready()) throw new AdministrationException('Preparare prima gli archivi amministrativi.'); }
    public static function cents($value): int
    {
        $v=str_replace(',','.',trim((string)$value));
        if (!preg_match('/^(0|[1-9][0-9]{0,7})(?:\.([0-9]{1,2}))?$/D',$v,$m)) throw new AdministrationException('Importo non valido: usare al massimo due decimali, senza separatori delle migliaia.');
        return (int)$m[1]*100+(int)str_pad($m[2] ?? '',2,'0');
    }
    public static function money(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
    protected static function percent($value): int
    { $p=self::cents($value); if ($p>10000) throw new AdministrationException('Percentuale oltre il 100%.'); return $p; }
    protected static function text($value,int $max=250,bool $required=false): string
    {
        $v=trim((string)$value);
        if (($required && $v==='') || mb_strlen($v)>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$v)) throw new AdministrationException('Campo mancante o troppo lungo.');
        return $v;
    }
    protected static function date($value,bool $optional=false): string
    {
        $v=(string)$value; if ($optional && $v==='') return '';
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!$d || $d->format('Y-m-d')!==$v) throw new AdministrationException('Data non valida.'); return $v;
    }
    protected function patient(int $id): void
    { if ($id<=0 || !$this->db->table('dap02_clients')->where('id_client',$id)->countAllResults()) throw new AdministrationException('Paziente non disponibile nello spazio.'); }
    protected function raw(string $table,string $id): array
    {
        if (!in_array($table,['catalog','quotes','cases','receipts','compensation'],true)) throw new AdministrationException('Archivio non valido.');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new AdministrationException('Identificativo non valido.');
        $row=$this->db->table('administration_'.$table)->where('tenant_id',$this->tenantId)->where('id',$id)->get()->getRowArray();
        if (!$row) throw new AdministrationException('Elemento non disponibile nello spazio.');
        return $this->decode($table,$row);
    }
    protected function decode(string $table,array $row): array
    {
        $row['data']=json_decode((new ClinicalVault($this->tenantId))->open('administration:'.$table.':'.$row['id'],$row['payload_enc']),true,32,JSON_THROW_ON_ERROR);
        unset($row['payload_enc']); return $row;
    }
    protected function revision(array $row,int $revision): void
    { if ($revision!==(int)$row['revision']) throw new AdministrationException('Dati aggiornati da un’altra operazione. Ricaricare la pagina.'); }
    public function list(string $table,int $page=1): array
    {
        $module=['catalog'=>'admin_quotes','quotes'=>'admin_quotes','cases'=>'admin_payers','compensation'=>'admin_compensation'][$table] ?? '';
        if ($table==='cases' && !$this->features->enabled($this->tenantId,'admin_payers')) $module='admin_ssn';
        if ($table==='catalog') { $this->assertAccess(); if (!$this->ready()) return []; } else $this->guard($module);
        $query=$this->db->table('administration_'.$table)->where('tenant_id',$this->tenantId);
        if ($table==='catalog') {
            $kinds=array_values(array_filter(['service','payer','rule'],fn($k)=>$this->features->enabled($this->tenantId,$this->catalogModule($k))));
            if (!$kinds) return []; $query->whereIn('kind',$kinds);
        }
        if ($table==='cases') {
            if (!$this->features->enabled($this->tenantId,'admin_payers')) $query->where('regime','ssn');
            if (!$this->features->enabled($this->tenantId,'admin_ssn')) $query->where('regime !=','ssn');
        }
        $rows=$query->orderBy('updated_at','DESC')->orderBy('id','DESC')->get(51,max(0,$page-1)*50)->getResultArray();
        $result=[];
        foreach ($rows as $row) {
            if ($table==='catalog' && !$this->features->enabled($this->tenantId,$this->catalogModule($row['kind']))) continue;
            $result[]=$this->decode($table,$row);
        }
        return $result;
    }
    protected function catalogModule(string $kind): string
    { return ['service'=>'admin_quotes','payer'=>'admin_payers','rule'=>'admin_compensation'][$kind] ?? throw new AdministrationException('Tipo non valido.'); }
    public function catalog(string $kind,bool $activeOnly=false): array
    {
        $this->guard($this->catalogModule($kind));
        $query=$this->db->table('administration_catalog')->where('tenant_id',$this->tenantId)->where('kind',$kind);
        if ($activeOnly) $query->where('active',1);
        $rows=$query->orderBy('updated_at','DESC')->get(501)->getResultArray();
        if (count($rows)>500) throw new AdministrationException('Catalogo oltre 500 voci: archiviare le voci obsolete o contattare l’assistenza.');
        return array_map(fn($row)=>$this->decode('catalog',$row),$rows);
    }
    public function saveCatalog(string $kind,array $input): string
    {
        $this->guard($this->catalogModule($kind));
        $id=(string)($input['id'] ?? ''); $old=$id!=='' ? $this->raw('catalog',$id):null;
        if ($old) $this->revision($old,(int)($input['revision'] ?? 0));
        if ($old && $old['kind']!==$kind) throw new AdministrationException('Categoria non modificabile.');
        $data=['name'=>self::text($input['name'] ?? '',150,true),'from'=>self::date($input['from'] ?? ''),'until'=>self::date($input['until'] ?? '',true),'notes'=>self::text($input['notes'] ?? '',2000)];
        if ($data['until']!=='' && $data['until']<$data['from']) throw new AdministrationException('Scadenza precedente alla decorrenza.');
        if ($kind==='service') $data += ['code'=>self::text($input['code'] ?? '',40,true),'list'=>self::text($input['list'] ?? '',100,true),'price_cents'=>self::cents($input['price'] ?? ''),'tax_note'=>self::text($input['tax_note'] ?? '',150)];
        if ($kind==='payer') {
            $type=(string)($input['type'] ?? '');
            if (!in_array($type,['convention','insurance','fund'],true)) throw new AdministrationException('Tipo ente non valido.');
            $data += ['type'=>$type,'coverage_bps'=>self::percent($input['coverage'] ?? ''),'deductible_cents'=>self::cents($input['deductible'] ?? '0'),'cap_cents'=>self::cents($input['cap'] ?? '0'),'reference'=>self::text($input['reference'] ?? '',100)];
        }
        if ($kind==='rule') {
            $staff=(int)($input['staff_id'] ?? 0);
            if (!$this->db->table('dap03_personale')->where('id_personale',$staff)->whereIn('tipo',[1,2])->countAllResults()) throw new AdministrationException('Professionista non valido nello spazio.');
            $basis=(string)($input['basis'] ?? ''); if (!in_array($basis,['issued','paid'],true)) throw new AdministrationException('Base di maturazione non valida.');
            $data += ['staff_id'=>$staff,'basis'=>$basis,'percent_bps'=>self::percent($input['percent'] ?? '0'),'fixed_cents'=>self::cents($input['fixed'] ?? '0')];
        }
        return $this->write('catalog',$id,(int)($input['revision'] ?? 0),$data,['kind'=>$kind,'active'=>($input['active'] ?? '')==='1' ? 1:0],'catalog_saved');
    }
    protected function validCatalog(string $id,string $kind,string $date): array
    {
        $row=$this->raw('catalog',$id); $d=$row['data'];
        if ($row['kind']!==$kind || !(int)$row['active'] || $d['from']>$date || ($d['until']!=='' && $d['until']<$date)) throw new AdministrationException('Voce non attiva o fuori validità.');
        return $row;
    }
    public function createQuote(array $input): string
    {
        $this->guard('admin_quotes'); $patient=(int)($input['patient_id'] ?? 0); $this->patient($patient);
        $date=self::date($input['date'] ?? ''); $expires=self::date($input['expires'] ?? '');
        if ($expires<$date) throw new AdministrationException('Scadenza precedente al preventivo.');
        $ids=(array)($input['service_id'] ?? []); $quantities=(array)($input['quantity'] ?? []);
        if (!$ids || count($ids)>50) throw new AdministrationException('Inserire da 1 a 50 prestazioni.');
        $lines=[]; $gross=0;
        foreach ($ids as $i=>$id) {
            if ($id==='') continue;
            $row=$this->validCatalog((string)$id,'service',$date); $qty=(string)($quantities[$i] ?? '');
            if (!preg_match('/^[1-9][0-9]{0,2}$/D',$qty)) throw new AdministrationException('Quantità da 1 a 999.');
            $amount=$row['data']['price_cents']*(int)$qty; $gross+=$amount;
            $lines[]=['service_id'=>$row['id'],'service_revision'=>(int)$row['revision'],'description'=>$row['data']['name'],'code'=>$row['data']['code'],'list'=>$row['data']['list'],'tax_note'=>$row['data']['tax_note'],'quantity'=>(int)$qty,'unit_cents'=>$row['data']['price_cents'],'total_cents'=>$amount];
        }
        if (!$lines || $gross>999999999) throw new AdministrationException('Totale o righe non validi.');
        $discount=self::percent($input['discount'] ?? '0'); $total=$gross-intdiv($gross*$discount+5000,10000);
        if ($total<=0) throw new AdministrationException('Il preventivo deve avere un importo positivo.');
        $data=['date'=>$date,'lines'=>$lines,'gross_cents'=>$gross,'discount_bps'=>$discount,'discount_cents'=>$gross-$total,'notes'=>self::text($input['notes'] ?? '',2000)];
        $token=self::text($input['request_token'] ?? '',64,true);
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new AdministrationException('Riaprire il modulo preventivo.');
        $data['history']=[['state'=>'draft','at'=>gmdate('c'),'actor'=>$this->actor]];
        return $this->write('quotes','',0,$data,['request_token'=>$token,'patient_id'=>$patient,'state'=>'draft','total_cents'=>$total,'expires_on'=>$expires],'quote_created');
    }
    public function quoteState(string $id,int $revision,string $target,string $reference): void
    {
        $this->guard('admin_quotes'); $row=$this->raw('quotes',$id);
        $this->revision($row,$revision);
        $allowed=['draft'=>['offered','cancelled'],'offered'=>['accepted','rejected','cancelled'],'accepted'=>['cancelled']];
        if (!in_array($target,$allowed[$row['state']] ?? [],true)) throw new AdministrationException('Passaggio di stato non consentito.');
        if ($target==='accepted' && $row['expires_on']<date('Y-m-d')) throw new AdministrationException('Preventivo scaduto: crearne uno aggiornato.');
        if ($target==='cancelled' && $this->db->table('administration_cases')->where('tenant_id',$this->tenantId)->where('quote_id',$id)->countAllResults()) throw new AdministrationException('Preventivo collegato a una pratica: gestire prima la pratica e conservare lo storico.');
        $data=$row['data']; $data['state_reference']=self::text($reference,250,true); $data['state_date']=gmdate('c');
        $data['history'][]=['state'=>$target,'reference'=>$data['state_reference'],'at'=>$data['state_date'],'actor'=>$this->actor];
        $this->write('quotes',$id,$revision,$data,['state'=>$target],'quote_'.$target);
    }
    public function createCase(array $input): string
    {
        $regime=(string)($input['regime'] ?? ''); $this->guard($regime==='ssn' ? 'admin_ssn':'admin_payers');
        if (!in_array($regime,['payer','ssn'],true)) throw new AdministrationException('Regime non valido.');
        $quote=$this->raw('quotes',(string)($input['quote_id'] ?? ''));
        if ($quote['state']!=='accepted') throw new AdministrationException('La pratica richiede un preventivo accettato.');
        $total=(int)$quote['total_cents']; $data=['quote_snapshot'=>$quote['data'],'reference'=>self::text($input['reference'] ?? '',100,true),'due_on'=>self::date($input['due_on'] ?? ''),'notes'=>self::text($input['notes'] ?? '',2000)];
        if ($regime==='payer') {
            $payer=$this->validCatalog((string)($input['payer_id'] ?? ''),'payer',date('Y-m-d'));
            $p=$payer['data']; $covered=max(0,intdiv($total*$p['coverage_bps']+5000,10000)-$p['deductible_cents']);
            if ($p['cap_cents']>0) $covered=min($covered,$p['cap_cents']); $covered=min($covered,$total);
            $data += ['payer_id'=>$payer['id'],'payer_revision'=>(int)$payer['revision'],'payer_snapshot'=>$p];
        } else {
            $patient=self::cents($input['ticket'] ?? ''); if ($patient>$total) throw new AdministrationException('Quota paziente superiore al totale.');
            $covered=$total-$patient;
            $data += ['prescription'=>self::text($input['prescription'] ?? '',64,true),'exemption'=>self::text($input['exemption'] ?? '',64),'payer_snapshot'=>['name'=>self::text($input['ssn_entity'] ?? '',150,true)]];
        }
        return $this->transaction(function() use ($quote,$data,$regime,$total,$covered) {
            // CAS locks the accepted quote until the case and audit commit together.
            $this->write('quotes',$quote['id'],(int)$quote['revision'],$quote['data'],[],'case_linked');
            return $this->write('cases','',0,$data,['quote_id'=>$quote['id'],'patient_id'=>$quote['patient_id'],'regime'=>$regime,'state'=>'draft','total_cents'=>$total,'payer_cents'=>$covered,'patient_cents'=>$total-$covered,'received_cents'=>0],'case_created');
        });
    }
    protected function caseRow(string $id): array
    { $this->assertAccess(); $row=$this->raw('cases',$id); $this->guard($row['regime']==='ssn' ? 'admin_ssn':'admin_payers'); return $row; }
    public function caseState(string $id,int $revision,string $target,string $reference): void
    {
        $row=$this->caseRow($id); $allowed=['draft'=>['authorized','rejected','cancelled'],'authorized'=>['submitted','cancelled'],'submitted'=>['reconciled','rejected'],'rejected'=>['draft']];
        $this->revision($row,$revision);
        if (!in_array($target,$allowed[$row['state']] ?? [],true)) throw new AdministrationException('Passaggio pratica non consentito.');
        if ($target==='cancelled' && (int)$row['received_cents']>0) throw new AdministrationException('Stornare le registrazioni di incasso prima di annullare.');
        if ($target==='reconciled' && (int)$row['received_cents']!==(int)$row['total_cents']) throw new AdministrationException('Gli incassi non coprono il totale della pratica.');
        $data=$row['data']; $data['state_reference']=self::text($reference,250,true); $data['state_date']=gmdate('c');
        $data['history'][]=['state'=>$target,'reference'=>$data['state_reference'],'at'=>$data['state_date'],'actor'=>$this->actor];
        $this->write('cases',$id,$revision,$data,['state'=>$target],'case_'.$target);
    }
    public function receipt(string $id,int $revision,array $input): string
    {
        $row=$this->caseRow($id); if (!in_array($row['state'],['authorized','submitted'],true)) throw new AdministrationException('Pratica non disponibile per registrare incassi.');
        $this->revision($row,$revision);
        $party=(string)($input['party'] ?? ''); if (!in_array($party,['patient','payer'],true)) throw new AdministrationException('Soggetto pagante non valido.');
        $amount=self::cents($input['amount'] ?? ''); if ($amount<=0) throw new AdministrationException('Importo incasso non valido.');
        $sum=(int)($this->db->table('administration_receipts')->selectSum('amount_cents','total')->where('tenant_id',$this->tenantId)->where('case_id',$id)->where('party',$party)->where('active',1)->get()->getRowArray()['total'] ?? 0);
        if ($sum+$amount>(int)$row[$party.'_cents']) throw new AdministrationException('Incasso superiore alla quota residua del soggetto.');
        $date=self::date($input['paid_on'] ?? ''); if ($date>date('Y-m-d')) throw new AdministrationException('Data incasso futura.');
        $data=['reference'=>self::text($input['reference'] ?? '',150,true),'method'=>self::text($input['method'] ?? '',40,true)];
        return $this->transaction(function() use ($row,$id,$revision,$data,$amount,$party,$date) {
            $this->write('cases',$id,$revision,$row['data'],['received_cents'=>(int)$row['received_cents']+$amount],'receipt_added');
            return $this->write('receipts','',0,$data,['case_id'=>$id,'amount_cents'=>$amount,'party'=>$party,'paid_on'=>$date,'active'=>1],'receipt_recorded');
        });
    }
    public function voidReceipt(string $caseId,int $caseRevision,string $receiptId,string $reason): void
    {
        $case=$this->caseRow($caseId); $receipt=$this->raw('receipts',$receiptId);
        $this->revision($case,$caseRevision);
        if ($receipt['case_id']!==$caseId || !(int)$receipt['active']) throw new AdministrationException('Incasso non stornabile.');
        $data=$receipt['data']; $data['void_reason']=self::text($reason,250,true);
        $this->transaction(function() use ($case,$caseId,$caseRevision,$receipt,$receiptId,$data) {
            $this->write('cases',$caseId,$caseRevision,$case['data'],['state'=>$case['state']==='reconciled' ? 'submitted':$case['state'],'received_cents'=>(int)$case['received_cents']-(int)$receipt['amount_cents']],'receipt_voided');
            $this->write('receipts',$receiptId,(int)$receipt['revision'],$data,['active'=>0],'receipt_voided');
        });
    }
    public function detail(string $table,string $id): array
    {
        if (!in_array($table,['quotes','cases','compensation'],true)) throw new AdministrationException('Archivio non valido.');
        if ($table==='cases') { $this->assertAccess(); $row=$this->caseRow($id); $row['receipts']=array_map(fn($r)=>$this->decode('receipts',$r),$this->db->table('administration_receipts')->where('tenant_id',$this->tenantId)->where('case_id',$id)->orderBy('paid_on')->get()->getResultArray()); return $row; }
        $this->guard($table==='compensation' ? 'admin_compensation':'admin_quotes'); return $this->raw($table,$id);
    }
    protected function write(string $table,string $id,int $revision,array $data,array $columns,string $event): string
    {
        $new=$id===''; if ($new) { if ($revision!==0) throw new AdministrationException('Revisione non valida.'); $id=bin2hex(random_bytes(16)); }
        $cipher=(new ClinicalVault($this->tenantId))->seal('administration:'.$table.':'.$id,json_encode($data,JSON_THROW_ON_ERROR));
        return $this->transaction(function() use ($table,$id,$revision,$cipher,$columns,$event,$new) {
            $values=$columns+['payload_enc'=>$cipher,'revision'=>$revision+1,'updated_at'=>gmdate('Y-m-d H:i:s')];
            $builder=$this->db->table('administration_'.$table);
            $ok=$new ? $builder->insert($values+['id'=>$id,'tenant_id'=>$this->tenantId,'created_by'=>$this->actor]) : $builder->where('tenant_id',$this->tenantId)->where('id',$id)->where('revision',$revision)->update($values);
            if (!$ok || $this->db->affectedRows()!==1) throw new AdministrationException('Salvataggio non eseguito: ricaricare la pagina e verificare eventuali duplicati.');
            $this->audit($event,$id,$revision+1); return $id;
        });
    }
    protected function transaction(callable $call)
    {
        $owned=$this->db->transDepth===0;
        if ($owned) $this->db->resetTransStatus();
        $this->db->transBegin();
        try { $result=$call(); if (!$this->db->transStatus()) throw new AdministrationException('Operazione amministrativa non salvata.'); $this->db->transCommit(); return $result; }
        catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }
    protected function audit(string $event,string $id,int $revision): void
    {
        if (!$this->db->table('administration_audit')->insert(['tenant_id'=>$this->tenantId,'actor_id'=>$this->actor,'entity_id'=>$id,'event'=>$event,'revision'=>$revision,'created_at'=>gmdate('Y-m-d H:i:s')])) throw new AdministrationException('Storico amministrativo non salvato.');
    }
}
