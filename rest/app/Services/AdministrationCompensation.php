<?php
namespace App\Services;

trait AdministrationCompensation
{
    protected function billingSnapshot(int $id,string $basis): array
    {
        if (!$this->db->tableExists('billing_documents')) throw new AdministrationException('Archivio fatture non disponibile.');
        $row=$this->db->table('billing_documents')->where('id_billing_document',$id)->get()->getRowArray();
        if (!$row || ($row['local_state'] ?? '')!=='issued' || ($row['document_type'] ?? '')!=='invoice') throw new AdministrationException('Selezionare una fattura emessa, non una nota di credito.');
        if ($basis==='paid' && ($row['payment_status'] ?? '')!=='paid') throw new AdministrationException('La regola richiede una fattura saldata.');
        $total=self::cents(number_format((float)$row['amount_total'],2,'.',''));
        if ($total<=0) throw new AdministrationException('Fattura senza importo positivo.');
        return ['id'=>$id,'number'=>(string)$row['document_number'],'date'=>self::date($row['issue_date']),'total_cents'=>$total,
            'fingerprint'=>hash('sha256',json_encode([$row['amount_total'],$row['document_number'],$row['issue_date'],$row['local_state'],$row['payment_status'],$row['document_type'],$row['line_items_json'] ?? ''],JSON_THROW_ON_ERROR))];
    }
    public function accrue(array $input): string
    {
        $this->guard('admin_compensation');
        $rule=$this->raw('catalog',(string)($input['rule_id'] ?? ''));
        if ($rule['kind']!=='rule') throw new AdministrationException('Regola non valida.');
        $bill=$this->billingSnapshot((int)($input['billing_id'] ?? 0),$rule['data']['basis']);
        $rule=$this->validCatalog($rule['id'],'rule',$bill['date']); $r=$rule['data'];
        $amount=intdiv($bill['total_cents']*$r['percent_bps']+5000,10000)+$r['fixed_cents'];
        if ($amount<=0 || $amount>$bill['total_cents']) throw new AdministrationException('Spettanza non positiva o superiore alla fattura.');
        $data=['rule_id'=>$rule['id'],'rule_revision'=>(int)$rule['revision'],'rule_snapshot'=>$r,'billing_snapshot'=>$bill,
            'reference'=>self::text($input['reference'] ?? '',250,true)];
        $existing=$this->db->table('administration_compensation')->where('tenant_id',$this->tenantId)->where('billing_id',$bill['id'])->where('staff_id',$r['staff_id'])->get()->getRowArray();
        if ($existing) {
            $old=$this->decode('compensation',$existing);
            if ($old['state']!=='void') throw new AdministrationException('Spettanza già presente per questa fattura e professionista.');
            $data['previous_calculations']=$old['data']['previous_calculations'] ?? [];
            $snapshot=$old['data']; unset($snapshot['previous_calculations']);
            $data['previous_calculations'][]=['revision'=>(int)$old['revision'],'amount_cents'=>(int)$old['amount_cents'],'data'=>$snapshot];
            if (count($data['previous_calculations'])>100) throw new AdministrationException('Numero massimo di ricalcoli raggiunto.');
            return $this->write('compensation',$old['id'],(int)$old['revision'],$data,['state'=>'draft','amount_cents'=>$amount],'compensation_recalculated');
        }
        return $this->write('compensation','',0,$data,['billing_id'=>$bill['id'],'staff_id'=>$r['staff_id'],'state'=>'draft','amount_cents'=>$amount],'compensation_created');
    }
    public function compensationState(string $id,int $revision,string $target,string $reference): void
    {
        $this->guard('admin_compensation'); $row=$this->raw('compensation',$id);
        $this->revision($row,$revision);
        $allowed=['draft'=>['approved','void'],'approved'=>['settled','void'],'settled'=>['void']];
        if (!in_array($target,$allowed[$row['state']] ?? [],true)) throw new AdministrationException('Passaggio spettanza non consentito.');
        $data=$row['data'];
        if ($target!=='void') {
            $bill=$this->billingSnapshot((int)$row['billing_id'],$data['rule_snapshot']['basis']);
            if (!hash_equals($data['billing_snapshot']['fingerprint'],$bill['fingerprint'])) throw new AdministrationException('La fattura è cambiata: verificare e stornare la spettanza prima di ricalcolarla.');
        }
        $data['history'][]=['state'=>$target,'reference'=>self::text($reference,250,true),'actor'=>$this->actor,'at'=>gmdate('c')];
        $this->write('compensation',$id,$revision,$data,['state'=>$target],'compensation_'.$target);
    }
    public function auditTrail(string $id): array
    {
        $this->assertAccess();
        return $this->db->table('administration_audit')->where('tenant_id',$this->tenantId)->where('entity_id',$id)->orderBy('id')->get(1000)->getResultArray();
    }
    public static function csv(array $rows): string
    {
        $stream=fopen('php://temp','w+'); fwrite($stream,"\xEF\xBB\xBF");
        foreach ($rows as $row) {
            $safe=array_map(static function($v) { $v=(string)$v; return preg_match('/^[\s]*[=+@-]/u',$v) ? "'".$v:$v; },$row);
            fputcsv($stream,$safe,';', '"', '');
        }
        rewind($stream); $bytes=stream_get_contents($stream); fclose($stream); return $bytes;
    }
}
