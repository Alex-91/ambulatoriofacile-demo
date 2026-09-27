<?php
namespace App\Controllers\Admin;

use App\Services\{BillingTenantDatabaseContextService, PolyclinicAdministrationService, PolyclinicAccountingExport, PolyclinicElectronicInvoice, PolyclinicFeatureService, TenantPatientLookupService};
use DomainException;

class PolyclinicController extends BillingAdminBaseController
{
    private const URL='admin/fatturazione/gestione';
    private function legacy(): bool { return str_contains($this->request->getUri()->getPath(),'fatturazione-poliambulatori'); }
    private function capabilities(): array {
        $id=(int)$this->resolveTenantScope()['tenant_id'];
        if ($this->legacy()) { $db=(new BillingTenantDatabaseContextService())->resolveTenantContext($id)['db']; if (!\App\Services\UnifiedBillingArchive::state($db)) return array_fill_keys(array_keys(\App\Services\BillingCapabilities::FEATURES),true); }
        return \App\Services\BillingCapabilities::resolve($id);
    }

    public function __construct()
    {
        parent::__construct();
        $this->featureService = new \App\Services\BillingFeatureService();
    }

    protected function ensureAccess()
    {
        if ($this->legacy()) $this->featureService=new PolyclinicFeatureService();
        if ($guard=parent::ensureAccess()) return $guard;
        $tenantId=(int)$this->resolveTenantScope()['tenant_id'];
        $map=(new \App\Services\TenantFeatureService())->resolveEffectiveFeatureMapForTenant($tenantId);
        if (empty($map[$this->legacy()?'polyclinic_billing':'billing'])) return $this->response->setStatusCode(403)->setBody('Fatturazione non attiva per questo spazio.');
        return null;
    }

    private function context(): array
    {
        $tenantId=(int)$this->resolveTenantScope()['tenant_id'];
        $context=(new BillingTenantDatabaseContextService())->resolveTenantContext($tenantId);
        if (!$this->legacy() && !\App\Services\UnifiedBillingArchive::state($context['db'])) throw new DomainException('Archivio da unificare per questo spazio prima di usare la gestione integrata.');
        return [$tenantId,$context['db'],new PolyclinicAdministrationService($context['db'],$this->currentAdminUserId(),static fn(int $id)=>(new TenantPatientLookupService())->getPatientByIdForTenant($tenantId,$id),!empty($this->capabilities()['billing_compensation']))];
    }

    public function index()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        $this->response->setHeader('Cache-Control','no-store, max-age=0');
        $data=['menu_items'=>$this->adminMenuItems(),'tenantScope'=>$this->resolveTenantScope(),'ready'=>false,'tab'=>'accettazione','date'=>date('Y-m-d'),'error'=>null,'patients'=>[]];
        try {
            [$tenantId,$db,$service]=$this->context();
            $tab=(string)($this->request->getGet('tab')??'documenti');
            if ($this->legacy() && !$this->request->getGet('document') && \App\Services\UnifiedBillingArchive::state($db)) return redirect()->to(site_url(self::URL.'?'.http_build_query((array)$this->request->getGet())));
            if (!in_array($tab,['accettazione','catalogo','documenti','report','integrazioni','requisiti'],true)) $tab='accettazione';
            $data['tab']=$tab;
            $data['capabilities']=$this->capabilities();
            $data['base']=$this->legacy()?'admin/fatturazione-poliambulatori':self::URL;
            if ($tab==='accettazione' && !array_filter($data['capabilities'])) throw new DomainException('Prestazioni non attive per questo spazio.');
            $data['date']=PolyclinicAdministrationService::date((string)($this->request->getGet('date')??date('Y-m-d')));
            $data['unified']=(bool)\App\Services\UnifiedBillingArchive::state($db);
            $data['ready']=$service->ready();
            if ($tab==='catalogo') \App\Services\BillingCapabilities::assertCommand($data['capabilities'],['action'=>'catalog','kind'=>(string)($this->request->getGet('kind')??'branch')]);
            if ($data['ready']) {
                $data+=$service->snapshot($data['date']);
                $data['report']=$service->report((array)$this->request->getGet());
                $data['accounting']=$service->settings('accounting'); $data['einvoice']=$service->settings('einvoice');
                $data['documents']=$db->table(\App\Services\UnifiedBillingArchive::table($db).' d')->select('d.*,s.original_id')->join('pc_document_state s','s.billing_id=d.id_billing_document')->orderBy('d.id_billing_document','DESC')->get(100)->getResultArray();
                foreach ($data['documents'] as &$d) $d['balance']=$service->balance((int)$d['id_billing_document']); unset($d);
                $documentId=(int)($this->request->getGet('document')??0);
                if ($this->legacy() && $documentId && ($archive=\App\Services\UnifiedBillingArchive::state($db))) {
                    $mapped=$archive['mapping'][$documentId]??null;
                    if (!$mapped) throw new DomainException('Documento precedente non trovato.');
                    return redirect()->to(site_url(self::URL.'?tab=documenti&document='.$mapped));
                }
                if ($documentId) $data['detail']=$service->detail($documentId);
                $query=trim((string)($this->request->getGet('q')??''));
                if (mb_strlen($query)>=2) $data['patients']=(new TenantPatientLookupService())->searchPatientsForTenant($tenantId,$query,20);
                $data['audit']=$db->table('pc_audit')->orderBy('id','DESC')->get(30)->getResultArray();
                $data['demoRuns']=$db->table('pc_demo_runs')->orderBy('id','DESC')->get(10)->getResultArray();
            }
        } catch (DomainException $e) { $data['error']=$e->getMessage(); }
        catch (\Throwable $e) { log_message('error','Polyclinic index failed: '.$e->getMessage()); $data['error']='Impossibile caricare il modulo amministrativo.'; }
        return view('admin/polyclinic/index',$data);
    }

    public function command()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        if (strtolower($this->request->getMethod())!=='post') return $this->response->setStatusCode(405);
        $baseUrl=$this->legacy()?'admin/fatturazione-poliambulatori':self::URL;
        $in=(array)$this->request->getPost(); $target=$baseUrl;
        $tab=(string)($in['tab']??'accettazione'); if (in_array($tab,['accettazione','catalogo','documenti','integrazioni'],true)) $target.='?tab='.$tab;
        if (!empty($in['return_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$in['return_date'])) $target.='&date='.$in['return_date'];
        if ($tab==='catalogo' && isset(PolyclinicAdministrationService::KINDS[$in['kind']??''])) $target.='&kind='.urlencode($in['kind']);
        if (($in['action']??'')==='tariff') $target.='&kind=list';
        if ((int)($in['billing_id']??0)>0) $target=$baseUrl.'?tab=documenti&document='.(int)$in['billing_id'];
        try {
            [$tenantId,$db,$service]=$this->context();
            if (!$service->ready()) throw new DomainException('Modulo da installare sullo spazio selezionato.');
            if ($this->legacy() && \App\Services\UnifiedBillingArchive::state($db)) throw new DomainException('Pagina precedente alla migrazione: riaprire Fatturazione prima di salvare.');
            \App\Services\BillingCapabilities::assertCommand($this->capabilities(),$in);
            $documentId=(int)($in['billing_id']??0);
            switch ($in['action']??'') {
                case 'catalog': $service->saveCatalog($in); break;
                case 'tariff': $service->saveTariff($in); break;
                case 'arrival': $service->arrive($in); break;
                case 'transition': $service->transition($in); break;
                case 'order': $service->addOrder($in); break;
                case 'remove_order': $service->removeOrder((int)($in['id']??0)); break;
                case 'invoice':
                    $template=['document_title'=>'Fattura','fields'=>['show_stamp_duty'=>true],'fiscal_data'=>$service->settings('einvoice')['data']];
                    $documentId=$service->issueInvoice($in,$template); break;
                case 'ts_settings': $service->configureTs($in); break;
                case 'payment': $service->payment($in); break;
                case 'credit': $documentId=$service->credit($in); break;
                case 'installments': $service->installments($in); break;
                case 'settle': $service->settle($in); break;
                case 'accounting':
                    $settings=(new PolyclinicAccountingExport())->validateConfiguration($in);
                    $service->saveSettings('accounting',$settings,(int)($in['version']??-1)); break;
                case 'einvoice_settings':
                    $settings=[];
                    foreach (['business_name','vat_number','address','postal_code','city','province','tax_regime'] as $f) { $settings[$f]=trim((string)($in[$f]??'')); if ($settings[$f]==='' || mb_strlen($settings[$f])>190) throw new DomainException('Completare i dati dell’emittente.'); }
                    $service->saveSettings('einvoice',$settings,(int)($in['version']??-1)); break;
                case 'electronic_outcome':
                    if (($in['state']??'')==='exported') throw new DomainException('Generare prima il file XML.');
                    $service->recordElectronicOutcome($in); break;
                case 'simulate_connector': $service->simulateConnector($in); break;
                default: throw new DomainException('Operazione non riconosciuta.');
            }
            if ($documentId>0) $target=$baseUrl.'?tab=documenti&document='.$documentId;
            return redirect()->to(site_url($target))->with('pc_success','Operazione registrata.');
        } catch (DomainException|\InvalidArgumentException $e) {
            return redirect()->to(site_url($target))->withInput()->with('pc_error',$e->getMessage());
        } catch (\Throwable $e) {
            log_message('error','Polyclinic command failed: '.$e->getMessage());
            return redirect()->to(site_url($target))->withInput()->with('pc_error','Operazione non salvata. Verificare eventuali codici duplicati e ricaricare.');
        }
    }

    public function export()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        try {
            [, $db,$service]=$this->context(); if (!$service->ready()) throw new DomainException('Schema non pronto.');
            $config=$service->settings('accounting')['data'];
            if (!$config) throw new DomainException('Configurare prima i conti per l’esportazione.');
            $exporter=new PolyclinicAccountingExport();
            $config['columns']=implode(',',(array)$config['columns']);
            $config=$exporter->validateConfiguration($config);
            $from=(string)($this->request->getGet('from')??date('Y-m-01')); $to=(string)($this->request->getGet('to')??date('Y-m-d'));
            $journalConfig=$config; $journalConfig['columns']=implode(',',$config['columns']);
            $rows=$exporter->journal($db,$from,$to,$journalConfig);
            return $this->response->setHeader('Cache-Control','no-store, max-age=0')->setHeader('Content-Type','text/csv; charset=UTF-8')->setHeader('Content-Disposition','attachment; filename="prima-nota-'.$from.'-'.$to.'.csv"')->setBody($exporter->csv($rows,$config['columns'],$config['delimiter']));
        } catch (DomainException $e) { return redirect()->to(site_url(self::URL.'?tab=integrazioni'))->with('pc_error',$e->getMessage()); }
    }

    public function pdf(int $id)
    {
        if ($guard=$this->ensureAccess()) return $guard;
        if (!class_exists(\Dompdf\Dompdf::class)) {
            return $this->response->setStatusCode(503)->setBody('Generazione PDF non disponibile.');
        }
        try {
            [$tenantId,,$service]=$this->context();
            if ($this->legacy()) {
                [,$archiveDb]=$this->context(); $state=\App\Services\UnifiedBillingArchive::state($archiveDb);
                if ($state) { $id=(int)($state['mapping'][$id]??0); if (!$id) throw new DomainException('Documento precedente non trovato.'); }
            }
            $document=$service->document($id);
            $template=json_decode($document['template_snapshot_json']??'{}',true)??[];
            $preview=['document_type_label'=>$document['document_type']==='credit_note'?'Nota di credito':'Fattura', 'tenant'=>['tenant_name'=>$this->resolveTenantScope()['tenant_name']], 'document'=>$document,
                'template'=>$template,'line_items'=>json_decode($document['line_items_json']??'[]',true)??[]];
            $options=(new \App\Services\BillingPdfOptionsFactory())->create($tenantId);
            $options->setIsRemoteEnabled(false);
            $pdf=new \Dompdf\Dompdf($options);
            $pdf->loadHtml(view('admin/polyclinic/document_pdf',['preview'=>$preview]),'UTF-8');
            $pdf->setPaper('A4','portrait'); $pdf->render();
            return $this->response->setHeader('Cache-Control','no-store, max-age=0')->setHeader('Content-Type','application/pdf')
                ->setHeader('Content-Disposition','inline; filename="poliambulatorio-'.$id.'.pdf"')->setBody($pdf->output());
        } catch (DomainException $e) {
            return $this->response->setStatusCode(404)->setBody('Documento del poliambulatorio non disponibile.');
        }
    }

    public function xml(int $id)
    {
        if ($guard=$this->ensureAccess()) return $guard;
        if (strtolower($this->request->getMethod())!=='post') return $this->response->setStatusCode(405);
        try {
            [,,$service]=$this->context();
            if ($this->legacy()) { [,$archiveDb]=$this->context(); if (\App\Services\UnifiedBillingArchive::state($archiveDb)) throw new DomainException('Riaprire il documento dalla Fatturazione unificata.'); }
            $xml=$service->prepareElectronicInvoice($id);
            return $this->response->setHeader('Cache-Control','no-store, max-age=0')->setHeader('Content-Type','application/xml; charset=UTF-8')->setHeader('Content-Disposition','attachment; filename="fattura-PC'.$id.'.xml"')->setBody($xml);
        } catch (DomainException $e) { return redirect()->to(site_url(self::URL.'?tab=documenti&document='.$id))->with('pc_error',$e->getMessage()); }
    }
}
