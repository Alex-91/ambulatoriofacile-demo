<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Explicit, per-tenant archive switch. The old table remains a read-only backup. */
final class UnifiedBillingArchive
{
    public static function state(BaseConnection $db): array
    {
        if (!$db->tableExists('pc_settings')) return [];
        $row=$db->table('pc_settings')->where('name','unified_archive')->get()->getRowArray();
        return $row ? json_decode($row['data_json'],true,512,JSON_THROW_ON_ERROR) : [];
    }

    public static function table(BaseConnection $db): string
    {
        return self::state($db) ? 'billing_documents' : 'pc_documents';
    }

    public static function manages(BaseConnection $db,int $id): bool
    {
        return $id>0 && self::state($db) && $db->table('pc_document_state')->where('billing_id',$id)->countAllResults()>0;
    }

    public static function assertOrdinaryMutation(BaseConnection $db,int $id): void
    {
        if (self::manages($db,$id)) throw new \DomainException('Usare Incassi e compensi per questo documento. Le rettifiche si registrano con nota di credito.');
    }

    public static function collectionSummary(BaseConnection $db,array $summary): array
    {
        if (!self::state($db)) return $summary;
        $clinic=new PolyclinicAdministrationService($db);
        $summary['month_revenue']=0; $summary['outstanding_amount']=0;
        $summary['paid_count']=0; $summary['unpaid_count']=0; $summary['overdue_count']=0;
        foreach ($db->table('billing_documents')->where('local_state','issued')->get()->getResultArray() as $d) {
            $id=(int)$d['id_billing_document']; $managed=self::manages($db,$id);
            if ($d['document_type']==='credit_note') {
                if (!$managed && $d['payment_status']==='paid' && substr((string)$d['payment_date'],0,7)===date('Y-m')) $summary['month_revenue']-=abs((float)$d['amount_total']);
                continue;
            }
            $due=$managed?$clinic->balance($id)['due_cents']:($d['payment_status']==='paid'?0:PolyclinicMoney::cents($d['amount_total']));
            $summary[$due>0?'unpaid_count':'paid_count']++;
            $summary['outstanding_amount']+=$due/100;
            if ($due>0 && !empty($d['due_date']) && $d['due_date']<date('Y-m-d')) $summary['overdue_count']++;
            if (!$managed && $d['payment_status']==='paid' && substr((string)$d['payment_date'],0,7)===date('Y-m')) $summary['month_revenue']+=(float)$d['amount_total'];
        }
        $cash=$db->table('pc_payments')->selectSum('amount_cents','amount')->where('payment_date >=',date('Y-m-01'))->where('payment_date <',date('Y-m-01',strtotime('+1 month')))->get()->getRowArray();
        $summary['month_revenue']+=((int)($cash['amount']??0))/100;
        return $summary;
    }

    public function migrate(BaseConnection $db,bool $apply=false): array
    {
        if (!$db->tableExists('billing_documents') || !$db->tableExists('pc_documents')) throw new \RuntimeException('Installare prima gli schemi Fatturazione e gestione prestazioni.');
        if ($state=self::state($db)) return ['already_unified'=>true,'mapping'=>$state['mapping']??[]];
        $db->transBegin();
        try {
            $db->table('pc_settings')->where('name','write_lock')->set('version','version + 1',false)->update();
            if ($db->affectedRows()!==1) throw new \RuntimeException('Blocco archivio non disponibile.');
            // Recheck after acquiring the same lock used by every administrative mutation.
            if ($state=self::state($db)) { $db->transRollback(); return ['already_unified'=>true,'mapping'=>$state['mapping']??[]]; }
            $rows=$db->table('pc_documents')->orderBy('id_billing_document')->get()->getResultArray();
            $states=$db->table('pc_document_state')->get()->getResultArray();
            if (count($rows)!==count($states)) throw new \RuntimeException('Documenti senza stato: migrazione interrotta.');
            $oldIds=array_map('intval',array_column($rows,'id_billing_document'));
            foreach ($states as $state) {
                if (!in_array((int)$state['billing_id'],$oldIds,true) || (!empty($state['original_id']) && !in_array((int)$state['original_id'],$oldIds,true))) throw new \RuntimeException('Riferimenti documenti incoerenti.');
            }
            foreach ($rows as $row) {
                if ($db->table('billing_documents')->where('document_number',$row['document_number'])->where('issue_date',$row['issue_date'])->countAllResults()) throw new \RuntimeException('Numero e data duplicati: '.$row['document_number'].'. Nessun documento modificato.');
            }
            if (!$apply) { $db->transRollback(); return ['already_unified'=>false,'documents'=>count($rows),'ready'=>true]; }
            $fields=array_flip($db->getFieldNames('billing_documents')); $mapping=[];
            $nextId=max($oldIds ? max($oldIds) : 0,(int)($db->table('billing_documents')->selectMax('id_billing_document','n')->get()->getRowArray()['n']??0))+1;
            foreach ($rows as $row) {
                $old=(int)$row['id_billing_document']; unset($row['id_billing_document']);
                // Never enqueue historical documents or invent a TS expense classification.
                $row['id_billing_document']=$nextId++; $row['ts_sync_enabled']=0; $row['ts_sync_state']='disabled';
                $db->table('billing_documents')->insert(array_intersect_key($row,$fields));
                $mapping[$old]=(int)$db->insertID();
            }
            // CASE remapping avoids collisions when old and new numeric identifiers overlap.
            foreach (['pc_document_state'=>['billing_id','original_id'],'pc_orders'=>['billing_id'],'pc_credit_allocations'=>['credit_id'],'pc_payments'=>['billing_id'],'pc_installments'=>['billing_id'],'pc_settlements'=>['billing_id']] as $table=>$columns) {
                foreach ($columns as $column) if ($mapping) {
                    $case='CASE '.$column; foreach ($mapping as $old=>$new) $case.=' WHEN '.(int)$old.' THEN '.(int)$new;
                    $case.=' ELSE '.$column.' END';
                    $db->table($table)->set($column,$case,false)->whereIn($column,array_keys($mapping))->update();
                }
            }
            $db->table('pc_settings')->insert(['name'=>'unified_archive','version'=>1,'data_json'=>json_encode(['mapping'=>$mapping,'migrated_at'=>date('c')],JSON_THROW_ON_ERROR)]);
            if (!$db->transStatus()) throw new \RuntimeException('Migrazione annullata per errore database.');
            $db->transCommit(); return ['already_unified'=>false,'documents'=>count($mapping),'mapping'=>$mapping];
        } catch (\Throwable $e) { $db->transRollback(); throw $e; }
    }
}
