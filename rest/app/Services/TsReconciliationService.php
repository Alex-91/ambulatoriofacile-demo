<?php
namespace App\Services;

/** Human reconciliation is explicitly recorded as such, never as a SOAP success. */
class TsReconciliationService
{
    public function __construct(private ?TsTenantDatabaseContextService $contexts=null) { $this->contexts ??= new TsTenantDatabaseContextService(); }
    public static function fingerprint(array $record): string
    { return hash('sha256',json_encode(array_intersect_key($record,array_flip(['id_ts_document','local_state','last_error_code','updated_at','request_payload_json','response_payload_json'])),JSON_THROW_ON_ERROR)); }
    public function reconcile(int $tenantId,int $documentId,int $userId,array $input,string $proof): void
    {
        $decision=(string)($input['decision'] ?? '');$protocol=trim((string)($input['protocol'] ?? ''));$note=trim((string)($input['note'] ?? ''));
        if($userId<=0 || !in_array($decision,['accepted','not_acquired'],true) || ($input['verified'] ?? '')!=='1'
            || mb_strlen($note)<10 || mb_strlen($note)>2000 || strlen($proof)>ClinicalVault::MAX_BYTES
            || !str_starts_with($proof,'%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($proof)!=='application/pdf') throw new \InvalidArgumentException('Indicare la verifica eseguita sul Sistema TS e allegare il documento PDF di riscontro.');
        if(($decision==='accepted' && !preg_match('/^[A-Za-z0-9._\/-]{1,100}$/D',$protocol)) || ($decision==='not_acquired' && $protocol!=='')) throw new \InvalidArgumentException('Il protocollo è richiesto solo per l’acquisizione confermata.');
        $context=$this->contexts->resolveTenantContext($tenantId);$db=$context['db'];$documents=$context['documents'];
        $vault=new ClinicalVault($tenantId,null,WRITEPATH.'ts-reconciliation-private');
        $db->transBegin();
        try {
            $lock=$db->DBDriver==='MySQLi' ? ' FOR UPDATE' : '';
            $record=$db->query('SELECT * FROM ts_documents WHERE id_ts_document=?'.$lock,[$documentId])->getRowArray();
            if(!$record || $record['local_state']!=='sending' || $record['last_error_code']!=='TS_OUTCOME_UNKNOWN'
                || !hash_equals(self::fingerprint($record),(string)($input['fingerprint'] ?? ''))) throw new \RuntimeException('L’esito non è più in attesa di questa verifica. Riaprire il documento.');
            $object=bin2hex(random_bytes(16));$sha=$vault->put($object,$proof);
            $evidence=['mode'=>'operator_verified','decision'=>$decision,'protocol'=>$protocol,'actor'=>$userId,'recorded_at'=>gmdate('c'),
                'proof_object'=>$object,'proof_sha256'=>$sha,'note_enc'=>$vault->seal('ts-reconciliation:'.$documentId,$note),'prior_response_sha256'=>hash('sha256',(string)$record['response_payload_json'])];
            $response=json_decode((string)$record['response_payload_json'],true) ?: [];
            $response['reconciliations'][]=$evidence;
            $sent=$decision==='accepted';
            $changes=['local_state'=>$sent ? 'sent' : 'ready','ts_state'=>$sent ? 'accepted' : 'not_sent','ts_protocol'=>$sent ? $protocol : null,
                'ts_sent_at'=>$record['ts_sent_at'] ?? null,'response_payload_json'=>json_encode($response,JSON_THROW_ON_ERROR),
                'last_error_code'=>null,'last_error_message'=>null,'updated_by'=>$userId];
            if(!$documents->update($documentId,$changes)) throw new \RuntimeException('Riconciliazione non salvata.');
            if($sent && in_array($record['source_type'],['ts_variation','ts_cancellation'],true)) {
                $parent=(int)$record['source_ref_id'];
                if(!$documents->find($parent) || !$documents->update($parent,['ts_state'=>$record['source_type']==='ts_cancellation' ? 'cancelled' : 'varied','updated_by'=>$userId])) throw new \RuntimeException('Documento TS di origine non aggiornabile.');
            }
            if(!$context['audit']->record($documentId,'outcome_reconciled','Esito riconciliato da un operatore dopo verifica sul Sistema TS.','warning',$evidence,$userId)) throw new \RuntimeException('Audit della riconciliazione non salvato.');
            if(!$db->transStatus() || !$db->transCommit()) throw new \RuntimeException('Riconciliazione non completata.');
        }catch(\Throwable $e){$db->transRollback();throw $e;}
    }
    public function proof(int $tenantId,int $documentId): string
    {
        $context=$this->contexts->resolveTenantContext($tenantId);
        $event=$context['events']->where('id_ts_document',$documentId)->where('event_type','outcome_reconciled')->orderBy('id_ts_event','DESC')->first();
        $evidence=json_decode((string)($event['context_json'] ?? ''),true);
        if(!$evidence) throw new \RuntimeException('Riscontro non disponibile.');
        return (new ClinicalVault($tenantId,null,WRITEPATH.'ts-reconciliation-private'))->get($evidence['proof_object'],$evidence['proof_sha256']);
    }
}
