<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

/** One instance belongs to one tenant connection, resolved by the authenticated controller. */
final class PolyclinicAdministrationService
{
    public const STATES = ['arrived'=>'Arrivato', 'waiting'=>'In attesa', 'in_care'=>'In visita', 'completed'=>'Concluso', 'cancelled'=>'Annullato'];
    public const KINDS = ['branch'=>'Branche', 'doctor'=>'Professionisti', 'service'=>'Prestazioni', 'list'=>'Listini', 'agreement'=>'Convenzioni / assicurazioni / SSN', 'rule'=>'Regole compensi'];
    private BaseConnection $db;
    private int $actor;
    private ?\Closure $patientLookup;

    public function __construct(BaseConnection $db, int $actor = 0, ?\Closure $patientLookup = null) { $this->db=$db; $this->actor=$actor; $this->patientLookup=$patientLookup; }

    public function ready(): bool
    {
        foreach (['pc_catalog','pc_tariffs','pc_encounters','pc_orders','pc_document_state','pc_credit_allocations','pc_payments','pc_installments','pc_settlements','pc_demo_runs','pc_audit','pc_settings'] as $t) {
            if (!$this->db->tableExists($t)) return false;
        }
        return $this->db->tableExists('pc_documents');
    }

    private function atomic(callable $fn)
    {
        if (!$this->ready()) throw new DomainException('Schema amministrativo non installato per questo spazio.');
        $this->db->transBegin();
        try {
            // Serialize administrative mutations per tenant, including idempotency and aggregate limits.
            $this->db->table('pc_settings')->where('name','write_lock')->set('version','version + 1',false)->update();
            if ($this->db->affectedRows()!==1) throw new DomainException('Blocco amministrativo non disponibile.');
            $result=$fn();
            if (!$this->db->transStatus()) throw new DomainException('Operazione non salvata.');
            $this->db->transCommit();
            return $result;
        } catch (\Throwable $e) { $this->db->transRollback(); throw $e; }
    }

    private function audit(string $action, int $id, array $detail=[]): void
    {
        $this->db->table('pc_audit')->insert(['actor_id'=>$this->actor,'action'=>$action,'entity_id'=>$id,'detail_json'=>self::json($detail),'created_at'=>date('Y-m-d H:i:s')]);
    }

    private static function json(array $data): string { return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    public static function data(array $row): array { return json_decode((string)($row['data_json']??'{}'),true,512,JSON_THROW_ON_ERROR) ?: []; }
    private static function text($value, int $max=190): string
    {
        $v=trim((string)$value);
        if (mb_strlen($v)>$max || preg_match('/[\x00-\x1f]/',$v)) throw new DomainException('Testo troppo lungo o non valido.');
        return $v;
    }
    public static function date(string $v): string
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!$d || $d->format('Y-m-d')!==$v) throw new DomainException('Data non valida.');
        return $v;
    }
    private static function enum($v,array $allowed): string
    {
        if (!in_array($v,$allowed,true)) throw new DomainException('Opzione non valida.');
        return $v;
    }
    private static function positive($value): int
    {
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',(string)$value)) throw new DomainException('Identificativo o quantità non validi.');
        return (int)$value;
    }
    private static function key(array $input): string
    {
        $key=(string)($input['request_key']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key)) throw new DomainException('Chiave operazione mancante: ricaricare la pagina.');
        return $key;
    }
    private static function requestHash(array $in): string
    {
        // Ignore transport fields and retain the complete business command.
        foreach (array_keys($in) as $field) if (str_contains(strtolower($field),'csrf') || in_array($field,['request_key','tab','return_date'],true)) unset($in[$field]);
        ksort($in);
        return hash('sha256',self::json($in));
    }
    private function replay(string $table,array $in): ?array
    {
        $old=$this->db->table($table)->where('request_key',self::key($in))->get()->getRowArray();
        if ($old && !hash_equals((string)$old['request_hash'],self::requestHash($in))) throw new DomainException('Chiave già utilizzata per dati diversi: ricaricare prima di una nuova operazione.');
        return $old;
    }
    private function row(string $table,int $id): array
    {
        $r=$this->db->table($table)->where('id',$id)->get()->getRowArray();
        if (!$r) throw new DomainException('Elemento non trovato in questo spazio.');
        return $r;
    }
    private function catalogItem(int $id,string $kind, bool $active=true): array
    {
        $r=$this->row('pc_catalog',$id);
        if ($r['kind']!==$kind || ($active && !(int)$r['active'])) throw new DomainException('Voce di catalogo non disponibile.');
        return $r;
    }
    public function catalog(): array
    {
        $out=array_fill_keys(array_keys(self::KINDS),[]);
        foreach ($this->db->table('pc_catalog')->orderBy('name')->get()->getResultArray() as $r) { $r['data']=self::data($r); $out[$r['kind']][]=$r; }
        return $out;
    }

    public function saveCatalog(array $in): int
    {
        return $this->atomic(function() use($in) {
            $kind=self::enum($in['kind']??'',array_keys(self::KINDS));
            $code=self::text($in['code']??'',40); $name=self::text($in['name']??'');
            if ($code==='' || $name==='') throw new DomainException('Codice e nome sono obbligatori.');
            $data=[];
            if ($kind==='doctor') $data=['agenda_id'=>max(0,(int)($in['agenda_id']??0))];
            if ($kind==='service') {
                $branch=$this->catalogItem((int)($in['branch_id']??0),'branch');
                $price=PolyclinicMoney::cents($in['price']??'0');
                if ($price<0) throw new DomainException('Prezzo negativo non ammesso.');
                $data=['branch_id'=>(int)$branch['id'],'price_cents'=>$price];
            }
            if ($kind==='agreement') {
                $list=$this->catalogItem((int)($in['list_id']??0),'list');
                $coverage=PolyclinicMoney::cents($in['coverage']??'0');
                if ($coverage<0 || $coverage>10000) throw new DomainException('Copertura compresa tra 0 e 100%.');
                $data=['list_id'=>(int)$list['id'],'kind'=>self::enum($in['agreement_kind']??'private',['private','insurance','ssn']), 'coverage_bps'=>$coverage,'payer'=>self::text($in['payer']??''),'authorization_required'=>!empty($in['authorization_required'])];
                if ($coverage>0 && $data['payer']==='') throw new DomainException('Indicare il soggetto pagatore.');
            }
            if ($kind==='rule') {
                $doctor=$this->catalogItem((int)($in['doctor_id']??0),'doctor');
                $service=$this->catalogItem((int)($in['service_id']??0),'service');
                $mode=self::enum($in['mode']??'percent',['percent','fixed']);
                $value=PolyclinicMoney::cents($in['value']??'0');
                if ($value<0 || ($mode==='percent' && $value>10000)) throw new DomainException('Compenso non valido.');
                $data=['doctor_id'=>(int)$doctor['id'],'service_id'=>(int)$service['id'],'mode'=>$mode,'value'=>$value,'basis'=>self::enum($in['basis']??'collected',['billed','collected'])];
                foreach ($this->catalog()['rule'] as $rule) {
                    if ((int)$rule['id']!==(int)($in['id']??0) && $rule['active'] && $rule['data']['doctor_id']===$data['doctor_id'] && $rule['data']['service_id']===$data['service_id'] && !empty($in['active'])) throw new DomainException('Esiste già una regola attiva per medico e prestazione.');
                }
            }
            $id=(int)($in['id']??0);
            $record=['kind'=>$kind,'code'=>$code,'name'=>$name,'data_json'=>self::json($data),'active'=>!empty($in['active'])?1:0];
            if ($id>0) {
                $old=$this->catalogItem($id,$kind,false);
                if ((int)$old['version']!==(int)($in['version']??-1)) throw new DomainException('Voce modificata da un altro operatore: ricaricare.');
                $record['version']=(int)$old['version']+1;
                $this->db->table('pc_catalog')->where('id',$id)->update($record);
            } else {
                $this->db->table('pc_catalog')->insert($record); $id=(int)$this->db->insertID();
            }
            $this->audit('catalog_saved',$id,['kind'=>$kind]);
            return $id;
        });
    }

    public function saveTariff(array $in): void
    {
        $this->atomic(function() use($in) {
            $list=$this->catalogItem((int)($in['list_id']??0),'list');
            $service=$this->catalogItem((int)($in['service_id']??0),'service');
            $amount=PolyclinicMoney::cents($in['price']??'');
            if ($amount<0) throw new DomainException('Tariffa negativa non ammessa.');
            $q=$this->db->table('pc_tariffs')->where('list_id',$list['id'])->where('service_id',$service['id']);
            $old=$q->get()->getRowArray();
            if ($old) {
                if ((int)$old['version']!==(int)($in['version']??-1)) throw new DomainException('Tariffa modificata: ricaricare.');
                $this->db->table('pc_tariffs')->where('id',$old['id'])->update(['amount_cents'=>$amount,'version'=>(int)$old['version']+1]);
            } else $this->db->table('pc_tariffs')->insert(['list_id'=>$list['id'],'service_id'=>$service['id'],'amount_cents'=>$amount]);
            $this->audit('tariff_saved',(int)$service['id'],['list_id'=>$list['id'],'amount_cents'=>$amount]);
        });
    }

    public function arrive(array $in): int
    {
        return $this->atomic(function() use($in) {
            $key=self::key($in);
            if ($old=$this->replay('pc_encounters',$in)) return (int)$old['id'];
            $appointmentId=(int)($in['appointment_id']??0);
            $patientId=self::positive($in['patient_id']??0);
            $doctor=$this->catalogItem((int)($in['doctor_id']??0),'doctor');
            $date=self::date((string)($in['visit_date']??''));
            if (!$this->patientLookup) throw new DomainException('Ricerca pazienti non configurata.');
            $patient=($this->patientLookup)($patientId);
            if (!$patient || (int)($patient['id_client']??0)!==$patientId) throw new DomainException('Paziente non trovato nello spazio.');
            if ($appointmentId>0) {
                $appointment=$this->db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',$appointmentId)->get()->getRowArray();
                if (!$appointment || (int)$appointment['id_client']!==$patientId || strtoupper((string)($appointment['stato']??''))==='ANNULLATO') throw new DomainException('Appuntamento non disponibile per questo paziente.');
                $agendaId=(int)(self::data($doctor)['agenda_id']??0);
                if (!$agendaId || (int)$appointment['id_dot']!==$agendaId) throw new DomainException('Collegare il professionista al corretto medico agenda.');
                $existing=$this->db->table('pc_encounters')->where('appointment_id',$appointmentId)->get()->getRowArray();
                if ($existing) return (int)$existing['id'];
            }
            $name=trim(($patient['patient_last_name']??'').' '.($patient['patient_first_name']??''));
            if ($name==='') throw new DomainException('Completare nome e cognome nell’anagrafica paziente.');
            $this->db->table('pc_encounters')->insert(['request_key'=>$key,'request_hash'=>self::requestHash($in),'appointment_id'=>$appointmentId?:null,'patient_id'=>$patientId,'patient_name'=>$name,'patient_tax_code'=>$patient['patient_tax_code']??'','doctor_id'=>$doctor['id'],'visit_date'=>$date,'state'=>'arrived','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            $id=(int)$this->db->insertID(); $this->audit('arrival',$id); return $id;
        });
    }

    public function transition(array $in): void
    {
        $this->atomic(function() use($in) {
            $e=$this->row('pc_encounters',(int)($in['id']??0));
            $next=self::enum($in['state']??'',array_keys(self::STATES));
            $allowed=['arrived'=>['waiting','cancelled'],'waiting'=>['in_care','cancelled'],'in_care'=>['completed'],'completed'=>[],'cancelled'=>[]];
            if ((int)$e['version']!==(int)($in['version']??-1) || !in_array($next,$allowed[$e['state']],true)) throw new DomainException('Passaggio di stato non valido o scheda modificata: ricaricare.');
            $this->db->table('pc_encounters')->where('id',$e['id'])->update(['state'=>$next,'version'=>(int)$e['version']+1,'updated_at'=>date('Y-m-d H:i:s')]);
            $this->audit('encounter_state',(int)$e['id'],['from'=>$e['state'],'to'=>$next]);
        });
    }

    public function addOrder(array $in): int
    {
        return $this->atomic(function() use($in) {
            $key=self::key($in);
            if ($old=$this->replay('pc_orders',$in)) return (int)$old['id'];
            $e=$this->row('pc_encounters',(int)($in['encounter_id']??0));
            if (in_array($e['state'],['cancelled','completed'],true)) throw new DomainException('Prestazioni modificabili prima della chiusura della visita.');
            $s=$this->catalogItem((int)($in['service_id']??0),'service'); $sd=self::data($s);
            $doctor=$this->catalogItem((int)($in['doctor_id']??$e['doctor_id']),'doctor');
            $branch=$this->catalogItem((int)$sd['branch_id'],'branch');
            $qty=self::positive($in['quantity']??1); if ($qty>1000) throw new DomainException('Quantità massima 1000.');
            $agreementId=(int)($in['agreement_id']??0); $listId=(int)($in['list_id']??0); $coverage=0; $agreement=[]; $ad=[];
            if ($agreementId) {
                $agreement=$this->catalogItem($agreementId,'agreement'); $ad=self::data($agreement); $listId=(int)$ad['list_id']; $coverage=(int)$ad['coverage_bps'];
                if ($ad['authorization_required'] && trim((string)($in['authorization']??''))==='') throw new DomainException('Numero autorizzazione/impegnativa obbligatorio.');
            }
            $unit=(int)$sd['price_cents'];
            if ($listId) {
                $this->catalogItem($listId,'list');
                $tariff=$this->db->table('pc_tariffs')->where('list_id',$listId)->where('service_id',$s['id'])->get()->getRowArray();
                if (!$tariff) throw new DomainException('Prestazione senza tariffa nel listino selezionato.');
                $unit=(int)$tariff['amount_cents'];
            }
            $total=$unit*$qty; if ($total>999999999) throw new DomainException('Importo oltre il limite.');
            $rule=null;
            foreach ($this->catalog()['rule'] as $r) if ($r['active'] && (int)$r['data']['doctor_id']===(int)$doctor['id'] && (int)$r['data']['service_id']===(int)$s['id']) $rule=$r['data'];
            if (!$rule) throw new DomainException('Configurare il compenso per medico e prestazione (anche zero).');
            $earned=$rule['mode']==='fixed' ? $rule['value']*$qty : PolyclinicMoney::proportion($total,$rule['value'],10000);
            if ($earned>$total) throw new DomainException('Compenso superiore al valore della prestazione.');
            $snapshot=['service'=>$s['name'],'code'=>$s['code'],'branch'=>$branch['name'],'branch_id'=>(int)$branch['id'],'doctor'=>$doctor['name'],'agreement'=>$agreement['name']??'Privato','agreement_kind'=>$ad['kind']??'private','payer'=>$ad['payer']??'','authorization'=>self::text($in['authorization']??''),'rule'=>$rule,'fee_cents'=>$earned];
            $this->db->table('pc_orders')->insert(['request_key'=>$key,'request_hash'=>self::requestHash($in),'encounter_id'=>$e['id'],'service_id'=>$s['id'],'doctor_id'=>$doctor['id'],'agreement_id'=>$agreementId,'quantity'=>$qty,'unit_cents'=>$unit,'total_cents'=>$total,'payer_cents'=>PolyclinicMoney::proportion($total,$coverage,10000),'snapshot_json'=>self::json($snapshot),'created_at'=>date('Y-m-d H:i:s')]);
            $id=(int)$this->db->insertID(); $this->audit('order_added',$id); return $id;
        });
    }

    public function removeOrder(int $id): void
    {
        $this->atomic(function() use($id) {
            $order=$this->row('pc_orders',$id); $e=$this->row('pc_encounters',(int)$order['encounter_id']);
            if ($order['billing_id'] || in_array($e['state'],['completed','cancelled'],true)) throw new DomainException('Prestazione già chiusa o fatturata.');
            $this->db->table('pc_orders')->where('id',$id)->delete(); $this->audit('order_removed',$id);
        });
    }

    public function issueInvoice(array $in,array $template): int
    {
        return $this->atomic(function() use($in,$template) {
            $key=self::key($in);
            if ($old=$this->replay('pc_document_state',$in)) return (int)$old['billing_id'];
            $e=$this->row('pc_encounters',(int)($in['encounter_id']??0));
            if ($e['state']!=='completed') throw new DomainException('Concludere la visita prima della fatturazione.');
            $orders=$this->db->table('pc_orders')->where('encounter_id',$e['id'])->where('billing_id',null)->get()->getResultArray();
            if (!$orders) throw new DomainException('Nessuna prestazione da fatturare.');
            $lines=[]; $sum=0;
            foreach ($orders as $o) {
                $snapshot=json_decode($o['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                $sum+=(int)$o['total_cents'];
                $lines[]=['description'=>$snapshot['service'],'quantity'=>(int)$o['quantity'],'unit_amount'=>PolyclinicMoney::decimal((int)$o['unit_cents']),'line_total'=>PolyclinicMoney::decimal((int)$o['total_cents'])];
            }
            $stamp=PolyclinicMoney::cents($in['stamp']??'0');
            if ($stamp<0 || $stamp>10000 || $sum+$stamp>999999999) throw new DomainException('Totale o bollo non valido.');
            $date=self::date((string)($in['issue_date']??''));
            $number=$this->documentNumber('FT',$date);
            $rate=PolyclinicMoney::cents($in['vat_rate']??'0');
            if ($rate<0 || $rate>10000) throw new DomainException('Aliquota IVA non valida.');
            $nature=$rate>0 ? '' : self::enum($in['vat_nature']??'',['N1','N2.1','N2.2','N3.1','N3.2','N3.3','N3.4','N3.5','N3.6','N4','N5','N6.1','N6.2','N6.3','N6.4','N6.5','N6.6','N6.7','N6.8','N6.9','N7']);
            $tax=PolyclinicMoney::proportion($sum,$rate,10000);
            $recipient=['type'=>self::enum($in['recipient_type']??'patient',['patient','business'])];
            if ($recipient['type']==='business') {
                foreach (['name','vat_number','address','postal_code','city','province','recipient_code'] as $field) {
                    $recipient[$field]=self::text($in['recipient_'.$field]??'');
                    if ($recipient[$field]==='') throw new DomainException('Completare i dati del destinatario azienda.');
                }
            }
            $template['polyclinic_recipient']=$recipient;
            $template['polyclinic_issuer']=$this->settings('einvoice')['data'];
            $record=['id_client'=>$e['patient_id'],'document_number'=>$number,'document_type'=>'invoice','issue_date'=>$date,'due_date'=>self::date((string)($in['due_date']??$date)),'patient_name'=>$e['patient_name'],'patient_tax_code'=>$e['patient_tax_code'],'payment_method'=>'bank_transfer','payment_status'=>'unpaid','line_items_json'=>self::json($lines),'subtotal_amount'=>PolyclinicMoney::decimal($sum),'stamp_duty_amount'=>PolyclinicMoney::decimal($stamp),'vat_rate'=>'0.00','vat_nature'=>$nature,'amount_total'=>PolyclinicMoney::decimal($sum+$stamp),'template_snapshot_json'=>self::json($template),'ts_sync_enabled'=>0,'ts_sync_state'=>'disabled','local_state'=>'issued','notes'=>'Prestazioni visita #'.$e['id'],'created_by'=>$this->actor?:null,'updated_by'=>$this->actor?:null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')];
            $record['vat_rate']=PolyclinicMoney::decimal($rate); $record['vat_nature']=$nature;
            $record['amount_total']=PolyclinicMoney::decimal($sum+$stamp+$tax);
            if ($sum+$stamp+$tax>999999999) throw new DomainException('Totale oltre il limite.');
            if ($recipient['type']==='business') {
                $record['patient_name']=$recipient['name']; $record['patient_tax_code']=$recipient['vat_number'];
                // Keep the patient relation on the encounter, avoiding patient-email fallback for a company invoice.
                $record['id_client']=null;
                (new PolyclinicElectronicInvoice())->build($record+['id_billing_document'=>0],$recipient,$template['polyclinic_issuer']);
            }
            $this->db->table('pc_documents')->insert($record); $id=(int)$this->db->insertID();
            $this->db->table('pc_document_state')->insert(['billing_id'=>$id,'request_key'=>$key,'request_hash'=>self::requestHash($in),'einvoice_state'=>'not_prepared','created_at'=>date('Y-m-d H:i:s')]);
            $this->db->table('pc_orders')->whereIn('id',array_column($orders,'id'))->update(['billing_id'=>$id]);
            $this->audit('invoice_issued',$id,['encounter_id'=>$e['id']]); return $id;
        });
    }

    private function documentNumber(string $prefix,string $date): string
    {
        $base=$prefix.'-'.substr($date,0,4).'-PC-';
        $n=1;
        $rows=$this->db->table('pc_documents')->select('document_number')->like('document_number',$base,'after')->get()->getResultArray();
        foreach ($rows as $r) $n=max($n,(int)substr($r['document_number'],strlen($base))+1);
        return $base.str_pad((string)$n,6,'0',STR_PAD_LEFT);
    }

    public function document(int $id): array
    {
        $d=$this->db->table('pc_documents')->where('id_billing_document',$id)->get()->getRowArray();
        if (!$d || $d['local_state']!=='issued') throw new DomainException('Documento emesso non trovato nello spazio.');
        return $d;
    }
    private function state(int $id): array
    {
        $s=$this->db->table('pc_document_state')->where('billing_id',$id)->get()->getRowArray();
        if (!$s) throw new DomainException('Documento non gestito dal registro amministrativo.');
        return $s;
    }
    public function balance(int $id): array
    {
        $d=$this->document($id); $total=PolyclinicMoney::cents($d['amount_total']);
        $credit=0;
        foreach ($this->db->table('pc_document_state')->where('original_id',$id)->get()->getResultArray() as $s) $credit+=PolyclinicMoney::cents($this->document((int)$s['billing_id'])['amount_total']);
        $paid=(int)($this->db->table('pc_payments')->selectSum('amount_cents','amount')->where('billing_id',$id)->get()->getRowArray()['amount']??0);
        $payerBase=(int)($this->db->table('pc_orders')->selectSum('payer_cents','amount')->where('billing_id',$id)->get()->getRowArray()['amount']??0);
        $payerTotal=$payerBase+PolyclinicMoney::proportion($payerBase,PolyclinicMoney::cents($d['vat_rate']),10000);
        $template=json_decode($d['template_snapshot_json']??'{}',true,512,JSON_THROW_ON_ERROR);
        if (($template['polyclinic_recipient']['type']??'')==='business') $payerTotal=$total;
        $payerNet=$total>0 ? PolyclinicMoney::proportion($payerTotal,$total-$credit,$total) : 0;
        $payerPaid=(int)($this->db->table('pc_payments')->selectSum('amount_cents','amount')->where('billing_id',$id)->where('payer','organization')->get()->getRowArray()['amount']??0);
        return ['total_cents'=>$total,'credit_cents'=>$credit,'net_cents'=>$total-$credit,'paid_cents'=>$paid,'due_cents'=>max(0,$total-$credit-$paid),'refund_due_cents'=>max(0,$paid-($total-$credit)),
            'organization_net_cents'=>$payerNet,'organization_paid_cents'=>$payerPaid,'patient_net_cents'=>$total-$credit-$payerNet,'patient_paid_cents'=>$paid-$payerPaid];
    }

    public function payment(array $in): int
    {
        return $this->atomic(function() use($in) {
            $key=self::key($in);
            if ($old=$this->replay('pc_payments',$in)) return (int)$old['id'];
            $id=self::positive($in['billing_id']??0); $this->state($id); $d=$this->document($id);
            if ($d['document_type']==='credit_note') throw new DomainException('Registrare il rimborso sulla fattura originaria.');
            $amount=PolyclinicMoney::cents($in['amount']??''); $b=$this->balance($id);
            if ($amount===0 || $amount>$b['due_cents'] || -$amount>$b['paid_cents']) throw new DomainException('Importo oltre il saldo incassabile/rimborsabile.');
            $payer=self::enum($in['payer']??'patient',['patient','organization']);
            if ($amount>max(0,$b[$payer.'_net_cents']-$b[$payer.'_paid_cents']) || -$amount>$b[$payer.'_paid_cents']) throw new DomainException('Importo oltre la quota del pagatore selezionato.');
            $reference=self::text($in['reference']??'');
            if ($amount<0 && $reference==='') throw new DomainException('Indicare la causale del rimborso/storno.');
            $date=self::date((string)($in['payment_date']??''));
            if ($date<$d['issue_date']) throw new DomainException('Pagamento precedente alla fattura.');
            $this->db->table('pc_payments')->insert(['billing_id'=>$id,'amount_cents'=>$amount,'method'=>self::enum($in['method']??'',['cash','card','pos','bank_transfer','other']),'payer'=>self::enum($in['payer']??'patient',['patient','organization']),'payment_date'=>$date,'reference'=>$reference,'request_key'=>$key,'request_hash'=>self::requestHash($in),'created_by'=>$this->actor,'created_at'=>date('Y-m-d H:i:s')]);
            $paymentId=(int)$this->db->insertID(); $this->syncBalance($id); $this->audit('payment',$paymentId,['billing_id'=>$id,'amount_cents'=>$amount]); return $paymentId;
        });
    }
    private function syncBalance(int $id): void
    {
        $b=$this->balance($id);
        $last=$this->db->table('pc_payments')->where('billing_id',$id)->orderBy('payment_date','DESC')->orderBy('id','DESC')->get()->getRowArray();
        $status=$b['due_cents']===0 ? 'paid' : ($b['paid_cents']>0 ? 'partial' : 'unpaid');
        $this->db->table('pc_documents')->where('id_billing_document',$id)->update(['payment_status'=>$status,'payment_date'=>$status==='paid' && $last ? $last['payment_date'] : null,'paid_at'=>$status==='paid'?date('Y-m-d H:i:s'):null,'payment_method'=>$last['method']??'bank_transfer']);
        $this->db->table('pc_document_state')->where('billing_id',$id)->set('version','version + 1',false)->update();
    }

    public function credit(array $in): int
    {
        return $this->atomic(function() use($in) {
            $key=self::key($in);
            if ($old=$this->replay('pc_document_state',$in)) return (int)$old['billing_id'];
            $id=self::positive($in['billing_id']??0); $this->state($id); $original=$this->document($id);
            if ($original['document_type']!=='invoice') throw new DomainException('Nota di credito ammessa solo su fattura.');
            $amount=PolyclinicMoney::cents($in['amount']??''); $b=$this->balance($id);
            if ($amount<=0 || $amount>$b['net_cents']) throw new DomainException('Credito superiore al residuo stornabile.');
            $reason=self::text($in['reason']??''); if ($reason==='') throw new DomainException('Causale obbligatoria.');
            $date=self::date((string)($in['issue_date']??'')); if ($date<$original['issue_date']) throw new DomainException('Data antecedente alla fattura.');
            $credit=$original; unset($credit['id_billing_document']);
            $previousSubtotal=0; $previousStamp=0;
            foreach ($this->db->table('pc_document_state')->where('original_id',$id)->get()->getResultArray() as $prior) {
                $priorDoc=$this->document((int)$prior['billing_id']); $previousSubtotal+=PolyclinicMoney::cents($priorDoc['subtotal_amount']); $previousStamp+=PolyclinicMoney::cents($priorDoc['stamp_duty_amount']);
            }
            $remainingSubtotal=PolyclinicMoney::cents($original['subtotal_amount'])-$previousSubtotal;
            $remainingStamp=PolyclinicMoney::cents($original['stamp_duty_amount'])-$previousStamp;
            $components=PolyclinicMoney::allocate($amount,[$remainingSubtotal,$remainingStamp,$b['net_cents']-$remainingSubtotal-$remainingStamp]);
            [$creditSubtotal,$creditStamp]=$components;
            foreach (['payment_date','paid_at','pdf_generated_at','invoice_email_sent_at','last_reminder_sent_at','email_last_recipient','email_last_error','linked_ts_document_id'] as $field) if (array_key_exists($field,$credit)) $credit[$field]=null;
            $credit=array_replace($credit,['document_number'=>$this->documentNumber('NC',$date),'document_type'=>'credit_note','issue_date'=>$date,'due_date'=>null,'payment_status'=>'paid','amount_total'=>PolyclinicMoney::decimal($amount),'subtotal_amount'=>PolyclinicMoney::decimal($amount),'stamp_duty_amount'=>'0.00','line_items_json'=>self::json([['description'=>'Storno proporzionale fattura '.$original['document_number'].': '.$reason,'quantity'=>1,'unit_amount'=>PolyclinicMoney::decimal($amount),'line_total'=>PolyclinicMoney::decimal($amount)]]),'notes'=>'Riferimento fattura '.$original['document_number'].' del '.$original['issue_date'].'. '.$reason,'ts_sync_enabled'=>0,'ts_sync_state'=>'disabled','reminder_count'=>0,'created_by'=>$this->actor?:null,'updated_by'=>$this->actor?:null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            $credit['subtotal_amount']=PolyclinicMoney::decimal($creditSubtotal); $credit['stamp_duty_amount']=PolyclinicMoney::decimal($creditStamp);
            $creditLines=json_decode($credit['line_items_json'],true,512,JSON_THROW_ON_ERROR); $creditLines[0]['unit_amount']=$creditLines[0]['line_total']=PolyclinicMoney::decimal($creditSubtotal); $credit['line_items_json']=self::json($creditLines);
            $this->db->table('pc_documents')->insert($credit); $creditId=(int)$this->db->insertID();
            $weights=[];
            foreach ($this->db->table('pc_orders')->where('billing_id',$id)->get()->getResultArray() as $order) {
                $credited=(int)($this->db->table('pc_credit_allocations')->selectSum('amount_cents','amount')->where('order_id',$order['id'])->get()->getRowArray()['amount']??0);
                $weights[(int)$order['id']]=(int)$order['total_cents']-$credited;
            }
            foreach (PolyclinicMoney::allocate($creditSubtotal,$weights) as $orderId=>$allocated) $this->db->table('pc_credit_allocations')->insert(['credit_id'=>$creditId,'order_id'=>$orderId,'amount_cents'=>$allocated]);
            $this->db->table('pc_document_state')->insert(['billing_id'=>$creditId,'original_id'=>$id,'request_key'=>$key,'request_hash'=>self::requestHash($in),'einvoice_state'=>'not_prepared','created_at'=>date('Y-m-d H:i:s')]);
            $this->syncBalance($id); $this->audit('credit_issued',$creditId,['original_id'=>$id,'amount_cents'=>$amount]); return $creditId;
        });
    }

    public function installments(array $in): void
    {
        $this->atomic(function() use($in) {
            $id=self::positive($in['billing_id']??0); $state=$this->state($id); $d=$this->document($id);
            if ($d['document_type']!=='invoice' || (int)$state['version']!==(int)($in['version']??-1)) throw new DomainException('Documento modificato: ricaricare.');
            $dates=(array)($in['dates']??[]); $amounts=(array)($in['amounts']??[]);
            if (!$dates || count($dates)!==count($amounts) || count($dates)>60) throw new DomainException('Piano rate non valido.');
            $rows=[]; $sum=0;
            foreach ($dates as $i=>$date) {
                $amount=PolyclinicMoney::cents($amounts[$i]); if ($amount<=0) throw new DomainException('Ogni rata deve essere positiva.');
                $date=self::date($date); if ($date<$d['issue_date']) throw new DomainException('Rata precedente alla fattura.');
                $sum+=$amount; $rows[]=['billing_id'=>$id,'due_date'=>$date,'amount_cents'=>$amount];
            }
            if ($sum!==$this->balance($id)['net_cents']) throw new DomainException('La somma delle rate deve coincidere con il totale netto delle note di credito.');
            $this->db->table('pc_installments')->where('billing_id',$id)->delete();
            foreach ($rows as $row) $this->db->table('pc_installments')->insert($row);
            $this->db->table('pc_document_state')->where('billing_id',$id)->set('version','version + 1',false)->update();
            $this->audit('installments',$id,['count'=>count($rows)]);
        });
    }

    public function compensation(int $id): array
    {
        $b=$this->balance($id); $totals=[];
        foreach ($this->db->table('pc_orders')->where('billing_id',$id)->get()->getResultArray() as $o) {
            $s=json_decode($o['snapshot_json'],true,512,JSON_THROW_ON_ERROR); $doctor=(int)$o['doctor_id'];
            $credited=(int)($this->db->table('pc_credit_allocations')->selectSum('amount_cents','amount')->where('order_id',$o['id'])->get()->getRowArray()['amount']??0);
            $fee=(int)$o['total_cents']>0 ? PolyclinicMoney::proportion((int)$s['fee_cents'],(int)$o['total_cents']-$credited,(int)$o['total_cents']) : 0;
            if ($s['rule']['basis']==='collected') $fee=$b['net_cents']>0 ? PolyclinicMoney::proportion($fee,min($b['paid_cents'],$b['net_cents']),$b['net_cents']) : 0;
            $totals[$doctor]??=['doctor_id'=>$doctor,'doctor'=>$s['doctor'],'earned_cents'=>0,'settled_cents'=>0,'due_cents'=>0];
            $totals[$doctor]['earned_cents']+=$fee;
        }
        foreach ($this->db->table('pc_settlements')->where('billing_id',$id)->get()->getResultArray() as $p) {
            $doctor=(int)$p['doctor_id']; if (isset($totals[$doctor])) $totals[$doctor]['settled_cents']+=(int)$p['amount_cents'];
        }
        foreach ($totals as &$row) $row['due_cents']=$row['earned_cents']-$row['settled_cents'];
        unset($row); return array_values($totals);
    }
    public function settle(array $in): int
    {
        return $this->atomic(function() use($in) {
            $key=self::key($in);
            if ($old=$this->replay('pc_settlements',$in)) return (int)$old['id'];
            $id=self::positive($in['billing_id']??0); $this->state($id); $doctor=self::positive($in['doctor_id']??0);
            $fees=array_column($this->compensation($id),null,'doctor_id'); $fee=$fees[$doctor]??null;
            $amount=PolyclinicMoney::cents($in['amount']??'');
            if (!$fee || !$amount || ($amount>0 && $amount>$fee['due_cents']) || ($amount<0 && ($fee['due_cents']>=0 || $amount<$fee['due_cents']))) throw new DomainException('Liquidazione/recupero oltre il compenso disponibile.');
            $reference=self::text($in['reference']??''); if ($reference==='') throw new DomainException('Riferimento liquidazione obbligatorio.');
            $this->db->table('pc_settlements')->insert(['billing_id'=>$id,'doctor_id'=>$doctor,'amount_cents'=>$amount,'payment_date'=>self::date((string)($in['payment_date']??'')),'reference'=>$reference,'request_key'=>$key,'request_hash'=>self::requestHash($in),'created_by'=>$this->actor,'created_at'=>date('Y-m-d H:i:s')]);
            $newId=(int)$this->db->insertID(); $this->audit('compensation_paid',$newId,['billing_id'=>$id]); return $newId;
        });
    }

    public function snapshot(string $date): array
    {
        self::date($date);
        $encounters=$this->db->table('pc_encounters')->where('visit_date',$date)->orderBy('id','DESC')->get()->getResultArray();
        foreach ($encounters as &$e) $e['orders']=$this->db->table('pc_orders')->where('encounter_id',$e['id'])->get()->getResultArray();
        unset($e);
        return ['catalog'=>$this->catalog(),'tariffs'=>$this->db->table('pc_tariffs')->get()->getResultArray(),'encounters'=>$encounters];
    }
    public function detail(int $id): array
    {
        $d=$this->document($id); $state=$this->state($id); $b=$this->balance($id);
        $installments=$this->db->table('pc_installments')->where('billing_id',$id)->orderBy('due_date')->orderBy('id')->get()->getResultArray();
        $remainingPaid=$b['paid_cents']; $planTotal=0;
        foreach ($installments as &$r) { $planTotal+=(int)$r['amount_cents']; $allocated=min($remainingPaid,(int)$r['amount_cents']); $r['due_cents']=(int)$r['amount_cents']-$allocated; $remainingPaid-=$allocated; }
        unset($r);
        return ['document'=>$d,'state'=>$state,'balance'=>$b,'payments'=>$this->db->table('pc_payments')->where('billing_id',$id)->orderBy('id')->get()->getResultArray(),'installments'=>$installments,'plan_needs_update'=>$installments && $planTotal!==$b['net_cents'],'compensation'=>$d['document_type']==='invoice'?$this->compensation($id):[],'settlements'=>$this->db->table('pc_settlements')->where('billing_id',$id)->get()->getResultArray()];
    }

    public function report(array $in): array
    {
        $from=self::date((string)($in['from']??date('Y-m-01'))); $to=self::date((string)($in['to']??date('Y-m-d')));
        if ($to<$from) throw new DomainException('Periodo non valido.');
        $group=self::enum($in['group']??'doctor',['doctor','branch','service','agreement']);
        $rows=[]; $documents=$this->db->table('pc_documents d')->select('d.*, s.original_id')->join('pc_document_state s','s.billing_id=d.id_billing_document')->where('d.issue_date >=',$from)->where('d.issue_date <=',$to)->where('d.local_state','issued')->orderBy('d.issue_date')->get()->getResultArray();
        foreach ($documents as $d) {
            $credit=$d['document_type']==='credit_note'; $base=$credit?(int)$d['original_id']:(int)$d['id_billing_document']; $original=$this->document($base); $den=PolyclinicMoney::cents($original['amount_total']);
            $amount=PolyclinicMoney::cents($d['amount_total']);
            foreach ($this->db->table('pc_orders')->where('billing_id',$base)->get()->getResultArray() as $o) {
                $s=json_decode($o['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                if (!empty($in['doctor_id']) && (int)$in['doctor_id']!==(int)$o['doctor_id']) continue;
                if (!empty($in['branch_id']) && (int)$in['branch_id']!==(int)$s['branch_id']) continue;
                if (!empty($in['service_id']) && (int)$in['service_id']!==(int)$o['service_id']) continue;
                $label=$s[$group]; $key=$group==='doctor'?$o['doctor_id']:($group==='branch'?$s['branch_id']:($group==='service'?$o['service_id']:$o['agreement_id']));
                $rows[$key]??=['label'=>$label,'gross_cents'=>0,'credit_cents'=>0,'net_cents'=>0,'cash_cents'=>0,'quantity'=>0];
                $line=(int)$o['total_cents'];
                if ($credit) $line=(int)($this->db->table('pc_credit_allocations')->where('credit_id',$d['id_billing_document'])->where('order_id',$o['id'])->get()->getRowArray()['amount_cents']??0);
                $rows[$key][$credit?'credit_cents':'gross_cents']+=$line;
                if (!$credit) $rows[$key]['quantity']+=(int)$o['quantity'];
            }
        }
        foreach ($rows as &$r) $r['net_cents']=$r['gross_cents']-$r['credit_cents']; unset($r);
        $payments=$this->db->table('pc_payments')->where('payment_date >=',$from)->where('payment_date <=',$to)->get()->getResultArray();
        foreach ($payments as $p) {
            $doc=$this->document((int)$p['billing_id']);
            $orders=$this->db->table('pc_orders')->where('billing_id',$p['billing_id'])->get()->getResultArray();
            $weights=[]; foreach ($orders as $o) $weights[(int)$o['id']]=(int)$o['total_cents'];
            $weights['tax_stamp']=PolyclinicMoney::cents($doc['amount_total'])-array_sum($weights);
            $previousPaid=(int)($this->db->table('pc_payments')->selectSum('amount_cents','amount')->where('billing_id',$p['billing_id'])->where('id <',$p['id'])->get()->getRowArray()['amount']??0);
            $before=PolyclinicMoney::allocate($previousPaid,$weights);
            $after=PolyclinicMoney::allocate($previousPaid+(int)$p['amount_cents'],$weights);
            foreach ($orders as $o) {
                $s=json_decode($o['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                if (!empty($in['doctor_id']) && (int)$in['doctor_id']!==(int)$o['doctor_id']) continue;
                if (!empty($in['branch_id']) && (int)$in['branch_id']!==(int)$s['branch_id']) continue;
                if (!empty($in['service_id']) && (int)$in['service_id']!==(int)$o['service_id']) continue;
                $key=$group==='doctor'?$o['doctor_id']:($group==='branch'?$s['branch_id']:($group==='service'?$o['service_id']:$o['agreement_id']));
                $rows[$key]??=['label'=>$s[$group],'gross_cents'=>0,'credit_cents'=>0,'net_cents'=>0,'cash_cents'=>0,'quantity'=>0];
                $rows[$key]['cash_cents']+=$after[(int)$o['id']]-$before[(int)$o['id']];
            }
        }
        $fees=[];
        foreach ($documents as $d) {
            if ($d['document_type']!=='invoice') continue;
            foreach ($this->compensation((int)$d['id_billing_document']) as $fee) {
                if (!empty($in['doctor_id']) && (int)$in['doctor_id']!==$fee['doctor_id']) continue;
                $key=$fee['doctor_id']; $fees[$key]??=['doctor'=>$fee['doctor'],'earned_cents'=>0,'settled_cents'=>0,'due_cents'=>0];
                foreach (['earned_cents','settled_cents','due_cents'] as $field) $fees[$key][$field]+=$fee[$field];
            }
        }
        return ['from'=>$from,'to'=>$to,'group'=>$group,'rows'=>array_values($rows),'documents'=>$documents,'compensation'=>array_values($fees)];
    }

    public function settings(string $name): array
    {
        $row=$this->db->table('pc_settings')->where('name',$name)->get()->getRowArray();
        return $row ? ['version'=>(int)$row['version'],'data'=>self::data($row)] : ['version'=>-1,'data'=>[]];
    }
    public function saveSettings(string $name,array $data,int $version): void
    {
        if (!in_array($name,['accounting','einvoice'],true)) throw new DomainException('Configurazione non valida.');
        $this->atomic(function() use($name,$data,$version) {
            $old=$this->settings($name);
            if ($old['version']!==$version) throw new DomainException('Configurazione modificata: ricaricare.');
            $record=['data_json'=>self::json($data),'version'=>$version+1];
            if ($version<0) $this->db->table('pc_settings')->insert(['name'=>$name]+$record);
            else $this->db->table('pc_settings')->where('name',$name)->update($record);
            $this->audit('settings_saved',0,['name'=>$name]);
        });
    }

    public function recordElectronicOutcome(array $in): void
    {
        $this->atomic(function() use($in) {
            $id=self::positive($in['billing_id']??0); $state=$this->state($id); $this->document($id);
            $next=self::enum($in['state']??'',['submitted','delivered','rejected','undeliverable']);
            $allowed=['not_prepared'=>[],'exported'=>['submitted'],'submitted'=>['delivered','rejected','undeliverable'],'rejected'=>['submitted'],'delivered'=>[],'undeliverable'=>[]];
            if ((int)$state['version']!==(int)($in['version']??-1) || !in_array($next,$allowed[$state['einvoice_state']]??[],true)) throw new DomainException('Stato non coerente o documento modificato.');
            $reference=self::text($in['reference']??''); if ($reference==='') throw new DomainException('Indicare il riferimento del canale esterno o della ricevuta.');
            $this->db->table('pc_document_state')->where('id',$state['id'])->update(['einvoice_state'=>$next,'einvoice_reference'=>$reference,'version'=>(int)$state['version']+1]);
            $this->audit('einvoice_manual_outcome',$id,['state'=>$next,'reference'=>$reference]);
        });
    }

    public function prepareElectronicInvoice(int $id): string
    {
        return $this->atomic(function() use($id) {
            $state=$this->state($id); $d=$this->document($id);
            if (!empty($state['einvoice_xml'])) return $state['einvoice_xml'];
            $snapshot=json_decode($d['template_snapshot_json']??'{}',true,512,JSON_THROW_ON_ERROR);
            if ($state['original_id']) { $original=$this->document((int)$state['original_id']); $d['original_number']=$original['document_number']; $d['original_date']=$original['issue_date']; }
            $xml=(new PolyclinicElectronicInvoice())->build($d,$snapshot['polyclinic_recipient']??[],$snapshot['polyclinic_issuer']??[]);
            $this->db->table('pc_document_state')->where('id',$state['id'])->update(['einvoice_state'=>'exported','einvoice_reference'=>'SHA256 '.hash('sha256',$xml),'einvoice_xml'=>$xml,'version'=>(int)$state['version']+1]);
            $this->audit('einvoice_prepared',$id,['sha256'=>hash('sha256',$xml)]);
            return $xml;
        });
    }

    public function simulateConnector(array $in): array
    {
        return $this->atomic(function() use($in) {
            if ($old=$this->replay('pc_demo_runs',$in)) return json_decode($old['result_json'],true,512,JSON_THROW_ON_ERROR);
            $connector=self::enum($in['connector']??'',['accounting','sdi']);
            $scenario=self::enum($in['scenario']??'',['accepted','rejected','timeout']);
            $demo=new PolyclinicDemoConnector();
            if ($connector==='sdi') $payload=['xml'=>$demo->sampleXml()];
            else {
                $config=$this->settings('accounting')['data'];
                if (!$config) $config=['customer_account'=>'DEMO.CLIENTI','revenue_account'=>'DEMO.RICAVI','vat_account'=>'DEMO.IVA','stamp_account'=>'DEMO.BOLLI','cash_account'=>'DEMO.CASSA','bank_account'=>'DEMO.BANCA'];
                if (isset($config['columns']) && is_array($config['columns'])) $config['columns']=implode(',',$config['columns']);
                $payload=['journal'=>(new PolyclinicAccountingExport())->journal($this->db,self::date((string)($in['from']??date('Y-m-01'))),self::date((string)($in['to']??date('Y-m-d'))),$config)];
                if (!$payload['journal']) throw new DomainException('Emettere almeno una fattura nel periodo della demo.');
            }
            $result=$demo->run($connector,$scenario,$payload);
            $this->db->table('pc_demo_runs')->insert(['connector'=>$connector,'scenario'=>$scenario,'result_json'=>self::json($result),'request_key'=>self::key($in),'request_hash'=>self::requestHash($in),'created_by'=>$this->actor,'created_at'=>date('Y-m-d H:i:s')]);
            $this->audit('connector_demo',(int)$this->db->insertID(),['connector'=>$connector,'scenario'=>$scenario]);
            return $result;
        });
    }
}
