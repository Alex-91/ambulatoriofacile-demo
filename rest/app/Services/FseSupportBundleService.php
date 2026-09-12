<?php
namespace App\Services;

/** Read-only, allowlisted diagnostics. Never decrypts patient/author data or reads artifacts. */
class FseSupportBundleService
{
    public function __construct(private ?FseTenantDatabaseContextService $contexts=null)
    {
        $this->contexts ??= new FseTenantDatabaseContextService();
    }

    public function forDocument(int $tenantId,int $documentId): array
    {
        if ($tenantId<=0 || $documentId<=0) throw new \RuntimeException('Referto non disponibile per questo spazio.');
        $context=$this->contexts->resolveTenantContext($tenantId);
        $document=$context['documents']->find($documentId);
        if (!is_array($document)) throw new \RuntimeException('Referto non disponibile per questo spazio.');
        // No auto-repair migration and no audit write on this diagnostic download.
        return self::build($document,$context['events']->where('id_fse_document',$documentId)->orderBy('id_fse_event','DESC')->findAll(20));
    }

    public static function build(array $document,array $events=[]): array
    {
        $diagnosis=(new FseReconciliationService())->inspect($document);
        $response=json_decode((string)($document['last_response_json'] ?? ''),true);
        $states=['draft','preparing','ready_to_validate','validating','validated','checking_signature','signed','publishing','published','deleting','deleted','rejected'];
        $state=in_array($document['local_state'] ?? '',$states,true) ? $document['local_state'] : 'unknown';
        $http=(int)($document['gateway_http_status'] ?? 0);
        $feedback=FseGatewayFeedback::describe(['ok'=>$http>=200 && $http<300,'http_status'=>$http,
            'error_category'=>($response['transport']['feedback_code'] ?? '')==='CDA_VOCABULARY' ? 'VOCABULARY' : null,
            'outcome_uncertain'=>!empty($response['transport']['outcome_uncertain']) || str_contains((string)($document['gateway_state'] ?? ''),'UNCERTAIN')],
            'status',$state,(string)($document['gateway_state'] ?? ''));
        $bundle=['schema_version'=>1,'kind'=>'FSE_TECHNICAL_SUPPORT_ONLY','generated_at'=>gmdate('c'),
            'official_accreditation_evidence'=>false,'read_only'=>true,'automatic_retry'=>false,
            'document'=>['local_id'=>(int)($document['id_fse_document'] ?? 0),'profile_local_id'=>(int)($document['id_fse_profile'] ?? 0),
                'version'=>(int)($document['version_number'] ?? 1),'state'=>$state,'http_status'=>$http>=100 && $http<=599 ? $http : null],
            'correlation'=>['trace_id'=>self::opaque($document['trace_id'] ?? null),'span_id'=>self::opaque($document['span_id'] ?? null),
                'workflow_id'=>self::opaque($document['workflow_instance_id'] ?? null),
                'x_cart_id'=>self::opaque($response['transport']['x_cart_id'] ?? null)],
            'artifacts'=>[],'diagnosis'=>['code'=>$diagnosis['code'],'message'=>$diagnosis['message']],
            'guidance'=>$http>0 ? $feedback['message'] : 'Nessun esito HTTP verificabile. Consultare la diagnosi e non presumere un invio riuscito.',
            'events'=>[], 'handling'=>'Contiene identificativi tecnici: condividere solo con il supporto autorizzato, non pubblicare. Nessun allegato clinico, testo libero, percorso o credenziale incluso.'];
        foreach (['cda','unsigned_pdf','signed_pdf'] as $kind) {
            $hash=$document[$kind.'_sha256'] ?? null;
            $bundle['artifacts'][$kind.'_sha256']=is_string($hash) && preg_match('/^[a-f0-9]{64}$/D',$hash) ? $hash : null;
        }
        $types=['gateway_validation','gateway_publish','gateway_status','gateway_delete','prepare','signed_pdf_accepted'];
        foreach (array_slice($events,0,20) as $event) {
            // Message/context/actor are deliberately excluded even when they look harmless.
            $bundle['events'][]=['type'=>in_array($event['event_type'] ?? '',$types,true) ? $event['event_type'] : 'other',
                'level'=>in_array($event['event_level'] ?? '',['info','warning','error'],true) ? $event['event_level'] : 'unknown',
                'at'=>is_string($event['created_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$event['created_at']) ? $event['created_at'] : null];
        }
        return $bundle;
    }

    private static function opaque($value): ?string
    {
        if (!is_string($value) || strlen($value)>500) return null;
        // Known opaque shapes only; unrecognised values are omitted, not exported as free text.
        return preg_match('/^(?:[a-f0-9]{16,64}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}|SIM\.[A-Z0-9._-]{1,100}|[0-9.]+[a-f0-9]{32,64}\.[a-f0-9]{10,64}\^{4}urn:ihe:iti:xdw:2013:workflowInstanceId)$/D',$value) ? $value : null;
    }
}
