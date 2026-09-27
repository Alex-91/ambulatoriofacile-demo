<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Read-only presentation data; all writes continue through the existing guarded endpoints. */
final class BillingWorkspacePresenter
{
    public function enrich(BaseConnection $db, array $documents, array $capabilities): array
    {
        if (!$documents) return [];
        $ids=array_map('intval',array_column($documents,'id_billing_document'));
        $stored=array_column($db->table('billing_documents')->select('id_billing_document,line_items_json')->whereIn('id_billing_document',$ids)->get()->getResultArray(),null,'id_billing_document');
        $unified=(bool)UnifiedBillingArchive::state($db);
        $managed=$unified?array_column($db->table('pc_document_state')->whereIn('billing_id',$ids)->get()->getResultArray(),null,'billing_id'):[];
        $orders=$unified?$db->table('pc_orders')->whereIn('billing_id',$ids)->get()->getResultArray():[];
        $byDocument=[];
        foreach($orders as $order) $byDocument[(int)$order['billing_id']][]=$order;
        $clinic=$unified?new PolyclinicAdministrationService($db):null;
        foreach($documents as &$row) {
            $id=(int)$row['id_billing_document'];
            $row['managed']=isset($managed[$id]);
            $total=PolyclinicMoney::cents($row['amount_total']);
            $credit=$row['document_type']==='credit_note';
            $issued=$row['local_state']==='issued';
            $paid=$row['payment_status']==='paid';
            $row['balance']=['net_cents'=>$total,'paid_cents'=>$paid?$total:0,'due_cents'=>$paid?0:$total,'credit_cents'=>0,'patient_net_cents'=>$total,'patient_paid_cents'=>$paid?$total:0,'organization_net_cents'=>0,'organization_paid_cents'=>0];
            if($row['managed']) $row['balance']=$clinic->balance($id);
            $row['services']=array_values(array_filter(array_map(static fn($item)=>(string)($item['description']??''),json_decode($stored[$id]['line_items_json']??'[]',true)?:[])));
            $row['doctors']=[]; $row['agreements']=[]; $row['earned_cents']=0; $row['fee_due_cents']=0;
            foreach($byDocument[$id]??[] as $order) {
                $snapshot=json_decode($order['snapshot_json'],true)?:[];
                $row['doctors'][(int)$order['doctor_id']]=$snapshot['doctor']??'Professionista';
                if(!empty($capabilities['billing_agreements']) && !empty($snapshot['agreement'])) $row['agreements'][]=$snapshot['agreement'];
            }
            $row['agreements']=array_values(array_unique($row['agreements']));
            if($row['managed'] && !$credit && !empty($capabilities['billing_compensation'])) {
                foreach($clinic->compensation($id) as $fee) { $row['earned_cents']+=(int)$fee['earned_cents']; $row['fee_due_cents']+=(int)$fee['due_cents']; }
            }
            $row['signed_total_cents']=$credit?-$total:$total;
            $row['revenue_cents']=$issued?$row['signed_total_cents']:0;
            // Managed refunds are already included in the original invoice's payment ledger.
            $row['cash_cents']=!$issued?0:($row['managed']?($credit?0:$row['balance']['paid_cents']):($paid?$row['signed_total_cents']:0));
            $row['outstanding_cents']=$issued&&!$credit?$row['balance']['due_cents']:0;
            [$row['status_label'],$row['status_tone']]=!$issued?['Bozza','muted']:($credit?['Nota di credito','purple']:($row['balance']['credit_cents']>0?['Rettificata','muted']:($row['balance']['due_cents']===0?['Saldata','green']:($row['balance']['organization_net_cents']>$row['balance']['organization_paid_cents']?['Quota ente','amber']:($row['balance']['paid_cents']>0?['Parziale','amber']:['Da incassare','blue'])))));
        }
        unset($row);
        return $documents;
    }
}
