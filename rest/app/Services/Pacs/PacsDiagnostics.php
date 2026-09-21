<?php
namespace App\Services\Pacs;
use App\Services\ClinicalVault;
use CodeIgniter\Database\BaseConnection;

/** Connection diagnostics never query a real patient or expose remote response bodies. */
final class PacsDiagnostics
{
    public function __construct(private ?PacsProfiles $profiles=null,private ?PacsTransport $transport=null)
    { $this->profiles ??=new PacsProfiles(); $this->transport ??=new CurlPacsTransport(); }
    public function overview(BaseConnection $db,int $tenantId): array
    {
        $encryption=false;
        try {
            $vault=new ClinicalVault($tenantId); $sample=bin2hex(random_bytes(16));
            $encryption=hash_equals($sample,$vault->open('pacs-diagnostics',$vault->seal('pacs-diagnostics',$sample)));
            PacsIntegrity::hash($sample);
        } catch (\Throwable) { $encryption=false; }
        $rows=[];
        foreach ($this->profiles->forTenant($tenantId) as $p) {
            $credentials=PacsProfiles::credentialsReady($p);
            $rows[]=['id'=>$p['id'],'label'=>$p['label'],'enabled'=>$p['enabled'],'credentials'=>$credentials,
                'download'=>$p['download_enabled'],'viewer'=>$p['viewer_url']!==''];
        }
        return ['schema'=>PacsOrderService::schemaReady($db) && $db->tableExists('pacs_patient_bindings') && $db->tableExists('pacs_study_links'),
            'encryption'=>$encryption,'profiles'=>$rows];
    }
    public function probe(int $tenantId,string $profileId): array
    {
        $p=$this->profiles->get($tenantId,$profileId);
        $result=['profile'=>$p['label'],'ok'=>false,'checked_at'=>gmdate('c'),'message'=>'Collegamento non verificato.'];
        $url=rtrim($p['qido_url'],'/').'/studies?'.http_build_query([
            'PatientID'=>'AF-CONNECTION-CHECK-'.bin2hex(random_bytes(16)),
            'IssuerOfPatientID'=>'AF-CONNECTION-CHECK','limit'=>1,
        ],'', '&',PHP_QUERY_RFC3986);
        try {
            $response=$this->transport->get($p,$url,'application/dicom+json',8192);
            if (in_array($response['status'],[401,403],true)) { $result['message']='Autenticazione rifiutata. Verificare le credenziali e i permessi del collegamento.'; return $result; }
            $empty=$response['status']===204 && $response['body']==='';
            if ($response['status']===200 && preg_match('~^application/(?:dicom\+)?json(?:\s*;|$)~i',$response['type'])) {
                $empty=trim($response['body'])==='[]';
            }
            if (!$empty || !empty($response['warning'])) {
                $result['message']='Risposta QIDO non compatibile con la verifica: controllare endpoint e filtri del PACS.';
                return $result;
            }
            $result['ok']=true;
            $result['message']='Ricerca QIDO raggiungibile via HTTPS e risposta vuota corretta per l’identificativo tecnico casuale.';
        } catch (\Throwable) {
            $result['message']='Connessione non riuscita. Verificare indirizzo pubblico, certificato TLS, credenziali e disponibilità del PACS.';
        }
        return $result;
    }
}
