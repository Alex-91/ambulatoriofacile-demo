<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

/** Canonical, balanced journal. A generic CSV is never labelled a native Passepartout import. */
final class PolyclinicAccountingExport
{
    public const FIELDS=['entry_id','date','document','customer','tax_code','account','debit','credit','vat_nature','reference'];

    public function validateConfiguration(array $in): array
    {
        $out=[];
        foreach (['customer_account','revenue_account','vat_account','stamp_account','cash_account','bank_account'] as $field) {
            $value=trim((string)($in[$field]??''));
            if (!preg_match('/^[A-Za-z0-9.\/-]{1,30}$/D',$value)) throw new DomainException('Configurare tutti i conti contabili con codici validi.');
            $out[$field]=$value;
        }
        $out['columns']=array_values(array_filter(array_map('trim',explode(',',(string)($in['columns']??implode(',',self::FIELDS))))));
        if (count($out['columns'])!==count(array_unique($out['columns'])) || array_diff($out['columns'],self::FIELDS) || array_diff(['entry_id','date','document','account','debit','credit'],$out['columns'])) throw new DomainException('Colonne export non valide o mancanti.');
        $out['delimiter']=in_array($in['delimiter']??';',[';',',',':'],true)?$in['delimiter']??';':';';
        return $out;
    }

    public function journal(BaseConnection $db,string $from,string $to,array $config): array
    {
        PolyclinicAdministrationService::date($from); PolyclinicAdministrationService::date($to);
        if ($to<$from) throw new DomainException('Periodo non valido.');
        $config=$this->validateConfiguration($config+['columns'=>implode(',',self::FIELDS)]);
        $rows=[];
        $documents=$db->table('pc_documents')->where('local_state','issued')->where('issue_date >=',$from)->where('issue_date <=',$to)->orderBy('id_billing_document')->get()->getResultArray();
        foreach ($documents as $d) {
            if (!in_array($d['document_type'],['invoice','credit_note'],true)) continue;
            $total=PolyclinicMoney::cents($d['amount_total']); $subtotal=PolyclinicMoney::cents($d['subtotal_amount']); $stamp=PolyclinicMoney::cents($d['stamp_duty_amount']); $vat=$total-$subtotal-$stamp;
            if ($vat<0) throw new DomainException('Totali IVA incoerenti nel documento '.$d['document_number']);
            $sign=$d['document_type']==='credit_note'?-1:1;
            $common=['entry_id'=>'DOC-'.$d['id_billing_document'],'date'=>$d['issue_date'],'document'=>$d['document_number'],'customer'=>$d['patient_name'],'tax_code'=>$d['patient_tax_code']??'','vat_nature'=>$d['vat_nature']??'','reference'=>''];
            foreach ([[$config['customer_account'],$total*$sign],[$config['revenue_account'],-$subtotal*$sign],[$config['stamp_account'],-$stamp*$sign],[$config['vat_account'],-$vat*$sign]] as [$account,$amount]) {
                if ($amount) $rows[]=$common+['account'=>$account,'debit'=>PolyclinicMoney::decimal(max(0,$amount)),'credit'=>PolyclinicMoney::decimal(max(0,-$amount))];
            }
        }
        if ($db->tableExists('pc_payments')) {
            $payments=$db->table('pc_payments p')->select('p.*,d.document_number,d.patient_name,d.patient_tax_code')->join('pc_documents d','d.id_billing_document=p.billing_id')->where('p.payment_date >=',$from)->where('p.payment_date <=',$to)->orderBy('p.id')->get()->getResultArray();
            foreach ($payments as $p) {
                $amount=(int)$p['amount_cents'];
                $common=['entry_id'=>'PAY-'.$p['id'],'date'=>$p['payment_date'],'document'=>$p['document_number'],'customer'=>$p['patient_name'],'tax_code'=>$p['patient_tax_code']??'','vat_nature'=>'','reference'=>$p['reference']];
                foreach ([[$p['method']==='cash'?$config['cash_account']:$config['bank_account'],$amount],[$config['customer_account'],-$amount]] as [$account,$value]) $rows[]=$common+['account'=>$account,'debit'=>PolyclinicMoney::decimal(max(0,$value)),'credit'=>PolyclinicMoney::decimal(max(0,-$value))];
            }
        }
        return $rows;
    }

    public function csv(array $rows,array $columns,string $delimiter=';'): string
    {
        if (array_diff($columns,self::FIELDS) || !in_array($delimiter,[';',',',':'],true)) throw new DomainException('Formato export non valido.');
        $f=fopen('php://temp','w+'); fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,$columns,$delimiter,'"','');
        foreach ($rows as $row) {
            $values=[];
            foreach ($columns as $field) {
                $v=(string)($row[$field]??'');
                if (preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$v)) $v="'".$v;
                $values[]=$v;
            }
            fputcsv($f,$values,$delimiter,'"','');
        }
        rewind($f); $out=stream_get_contents($f); fclose($f); return $out;
    }
}
