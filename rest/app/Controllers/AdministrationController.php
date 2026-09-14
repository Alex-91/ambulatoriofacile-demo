<?php
namespace App\Controllers;
use App\Services\{AdministrationService,TenantCatalogService,TenantContextService,TenantDatabaseConnector,TenantPatientLookupService,BillingPdfOptionsFactory};

class AdministrationController extends BaseController
{
    protected function postOnly(): void
    { if (strtoupper($this->request->getMethod())!=='POST') throw new \RuntimeException('Metodo non consentito.'); }
    protected function privateResponse()
    { return $this->response->setHeader('Cache-Control','no-store, private')->setHeader('Pragma','no-cache')->setHeader('Referrer-Policy','no-referrer')->setHeader('X-Content-Type-Options','nosniff')->setHeader('X-Frame-Options','DENY'); }
    protected function adminContext(): array
    {
        helper(['session_auth','form','url']);
        if (!session_access_is_confirmed()) throw new \RuntimeException('Accesso non confermato.');
        $catalog=new TenantCatalogService(); $context=(new TenantContextService($catalog))->getCurrentTenant();
        $tenant=$context ? $catalog->getTenantById($context->tenantId):$catalog->resolveCurrentRuntimeTenant();
        if (!$tenant || empty($tenant['is_active'])) throw new \RuntimeException('Spazio non disponibile.');
        $me=session()->get('utente_sess'); $actor=is_object($me) ? (int)($me->id_user ?? 0):(int)session()->get('id_user');
        if ($context && $context->appUserId>0 && $context->appUserId!==$actor) throw new \RuntimeException('Identità non coerente.');
        $db=(new TenantDatabaseConnector())->connect($tenant);
        $service=new AdministrationService($db,(int)$tenant['id_tenant'],$actor); $service->assertAccess();
        return [$service,$tenant,$db];
    }
    public function index()
    {
        try {
            [$service,$tenant,$db]=$this->adminContext(); $modules=$service->modules(); $ready=$service->ready();
            $section=(string)($this->request->getGet('section') ?: 'catalog');
            if (!in_array($section,['catalog','quotes','cases','compensation'],true)) $section='catalog';
            $page=max(1,min(20000,(int)$this->request->getGet('page'))); $rows=$ready ? $service->list($section,$page):[];
            $more=count($rows)>50; $rows=array_slice($rows,0,50); $catalog=[]; $patients=[]; $staff=[];
            if ($ready) {
                foreach (['service'=>'admin_quotes','payer'=>'admin_payers','rule'=>'admin_compensation'] as $kind=>$module) if (isset($modules[$module])) $catalog[$kind]=$service->catalog($kind,true);
                if (isset($modules['admin_quotes']) && $this->request->getGet('q')) $patients=(new TenantPatientLookupService())->searchPatientsForTenant((int)$tenant['id_tenant'],mb_substr((string)$this->request->getGet('q'),0,100),20);
                if (isset($modules['admin_compensation'])) {
                    (new \App\Libraries\DatabaseConfig())->setEncryptionConfig($db);
                    $crypto=new \App\Libraries\Crypto_helper();
                    $staff=$db->table('dap03_personale p')->select('p.id_personale,'.$crypto->decryptSenzaAlias('p.nome').' AS nome,'.$crypto->decryptSenzaAlias('p.cognome').' AS cognome',false)->whereIn('p.tipo',[1,2])->get(500)->getResultArray();
                }
            }
            return $this->privateResponse()->setBody(view('administration/index',compact('tenant','modules','ready','section','page','rows','more','catalog','patients','staff'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function change()
    {
        try {
            $this->postOnly(); [$service]=$this->adminContext(); $p=(array)$this->request->getPost(); $op=(string)($p['op'] ?? '');
            $id=(string)($p['id'] ?? ''); $rev=(int)($p['revision'] ?? 0); $ref=(string)($p['reference'] ?? ''); $target=(string)($p['target'] ?? ''); $section='catalog';
            switch ($op) {
                case 'prepare': $service->initialize(); break;
                case 'catalog': $service->saveCatalog((string)($p['kind'] ?? ''),$p); break;
                case 'quote': $id=$service->createQuote($p); $section='quotes'; break;
                case 'quote_state': $service->quoteState($id,$rev,$target,$ref); $section='quotes'; break;
                case 'case': $id=$service->createCase($p); $section='cases'; break;
                case 'case_state': $service->caseState($id,$rev,$target,$ref); $section='cases'; break;
                case 'receipt': $service->receipt($id,$rev,$p); $section='cases'; break;
                case 'void_receipt': $service->voidReceipt($id,$rev,(string)($p['receipt_id'] ?? ''),$ref); $section='cases'; break;
                case 'accrue': $id=$service->accrue($p); $section='compensation'; break;
                case 'compensation_state': $service->compensationState($id,$rev,$target,$ref); $section='compensation'; break;
                default: throw new \RuntimeException('Operazione non disponibile.');
            }
            $url='admin/amministrazione'.($section==='catalog' ? '?section=catalog':'/dettaglio/'.$section.'/'.$id);
            return redirect()->to(site_url($url))->with('success','Operazione registrata.')->setHeader('Cache-Control','no-store, private');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function detail(string $section,string $id)
    {
        try {
            [$service,$tenant]=$this->adminContext(); $row=$service->detail($section,$id); $modules=$service->modules();
            $patient=isset($row['patient_id']) ? (new TenantPatientLookupService())->getPatientByIdForTenant((int)$tenant['id_tenant'],(int)$row['patient_id']):[];
            $payers=isset($modules['admin_payers']) ? $service->catalog('payer',true):[]; $audit=$service->auditTrail($id);
            return $this->privateResponse()->setBody(view('administration/detail',compact('tenant','modules','section','row','patient','payers','audit'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function pdf(string $id)
    {
        try {
            [$service,$tenant]=$this->adminContext(); $row=$service->detail('quotes',$id);
            $patient=(new TenantPatientLookupService())->getPatientByIdForTenant((int)$tenant['id_tenant'],(int)$row['patient_id']);
            $pdf=new \Dompdf\Dompdf((new BillingPdfOptionsFactory())->create((int)$tenant['id_tenant']));
            $pdf->loadHtml(view('administration/quote_pdf',compact('tenant','row','patient'),['saveData'=>false])); $pdf->setPaper('A4'); $pdf->render();
            return $this->privateResponse()->setHeader('Content-Type','application/pdf')->setHeader('Content-Disposition','attachment; filename="preventivo-'.substr($id,0,12).'.pdf"')->setBody($pdf->output());
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function invoice(string $id)
    {
        try {
            $this->postOnly(); [$service,$tenant]=$this->adminContext(); $row=$service->detail('quotes',$id);
            if ($row['state']!=='accepted') throw new \App\Services\AdministrationException('La bozza fattura richiede un preventivo accettato.');
            $patient=(new TenantPatientLookupService())->getPatientByIdForTenant((int)$tenant['id_tenant'],(int)$row['patient_id']);
            $lines=[]; foreach ($row['data']['lines'] as $line) $lines[]=['description'=>$line['description'],'quantity'=>$line['quantity'],'unit_amount'=>AdministrationService::money($line['unit_cents'])];
            if ($row['data']['discount_cents']>0) {
                // Preserve the exact negotiated total without relying on negative invoice rows.
                $lines=[['description'=>'Prestazioni preventivo '.substr($id,0,12).' (sconto incluso)','quantity'=>1,'unit_amount'=>AdministrationService::money((int)$row['total_cents'])]];
            }
            session()->setFlashdata('billing_document_prefill',['administration_tenant_id'=>(int)$tenant['id_tenant'],'document'=>array_merge($patient,['id_client'=>(int)$row['patient_id'],'notes'=>'Da preventivo '.$id.'. Verificare trattamento fiscale e documenti già emessi.']),'line_items'=>$lines]);
            return redirect()->to(site_url('admin/fatturazione-documenti/nuovo'))->setHeader('Cache-Control','no-store, private');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function export(string $section)
    {
        try {
            [$service]=$this->adminContext();
            if (!in_array($section,['quotes','cases','compensation'],true)) throw new \RuntimeException('Archivio non valido.');
            $out=[['Identificativo','Paziente/professionista ID','Stato','Totale EUR','Ente EUR','Paziente EUR','Incassi EUR','Aggiornato UTC']];
            for ($page=1;$page<=200;$page++) {
                $rows=$service->list($section,$page);
                foreach (array_slice($rows,0,50) as $r) $out[]=[$r['id'],$r['patient_id'] ?? $r['staff_id'],$r['state'],AdministrationService::money((int)($r['total_cents'] ?? $r['amount_cents'])),AdministrationService::money((int)($r['payer_cents'] ?? 0)),AdministrationService::money((int)($r['patient_cents'] ?? 0)),AdministrationService::money((int)($r['received_cents'] ?? 0)),$r['updated_at']];
                if (count($rows)<=50) break;
                if ($page===200) throw new \App\Services\AdministrationException('Esportazione oltre 10.000 righe: richiedere un export dedicato.');
            }
            return $this->privateResponse()->setHeader('Content-Type','text/csv; charset=UTF-8')->setHeader('Content-Disposition','attachment; filename="registro-'.$section.'.csv"')->setBody(AdministrationService::csv($out));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    protected function failure(\Throwable $e)
    {
        $message=$e instanceof \App\Services\AdministrationException ? $e->getMessage():'Operazione non disponibile. Verificare abilitazioni, dati inseriti ed eventuali duplicati, oppure contattare il responsabile.';
        return $this->privateResponse()->setStatusCode(400)->setBody(view('clinical/error',['message'=>$message]));
    }
}
