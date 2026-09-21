<?php
namespace App\Controllers\Admin;

use App\Services\{BillingTenantDatabaseContextService, PolyclinicAdministrationService, PolyclinicAccountingExport, PolyclinicElectronicInvoice, PolyclinicFeatureService, TenantPatientLookupService};
use DomainException;

class PolyclinicController extends BillingAdminBaseController
{
    private const URL='admin/fatturazione-poliambulatori';

    public function __construct()
    {
        parent::__construct();
        $this->featureService = new PolyclinicFeatureService();
    }

    private function context(): array
    {
        $tenantId=(int)$this->resolveTenantScope()['tenant_id'];
        $context=(new BillingTenantDatabaseContextService())->resolveTenantContext($tenantId);
        return [$tenantId,$context['db'],new PolyclinicAdministrationService($context['db'],$this->currentAdminUserId(),static fn(int $id)=>(new TenantPatientLookupService())->getPatientByIdForTenant($tenantId,$id))];
    }

    public function index()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        $this->response->setHeader('Cache-Control','no-store, max-age=0');
        $data=['menu_items'=>$this->adminMenuItems(),'tenantScope'=>$this->resolveTenantScope(),'ready'=>false,'tab'=>'accettazione','date'=>date('Y-m-d'),'error'=>null,'patients'=>[]];
        try {
            [$tenantId,$db,$service]=$this->context();
            $tab=(string)($this->request->getGet('tab')??'accettazione');
            if (!in_array($tab,['accettazione','catalogo','documenti','report','integrazioni','requisiti'],true)) $tab='accettazione';
            $data['tab']=$tab;
            $data['date']=PolyclinicAdministrationService::date((string)($this->request->getGet('date')??date('Y-m-d')));
            $data['ready']=$service->ready();
            if ($data['ready']) {
                $data+=$service->snapshot($data['date']);
                $data['report']=$service->report((array)$this->request->getGet());
                $data['accounting']=$service->settings('accounting'); $data['einvoice']=$service->settings('einvoice');
                $data['documents']=$db->table('pc_documents d')->select('d.*,s.original_id')->join('pc_document_state s','s.billing_id=d.id_billing_document')->orderBy('d.id_billing_document','DESC')->get(100)->getResultArray();
                foreach ($data['documents'] as &$d) $d['balance']=$service->balance((int)$d['id_billing_document']); unset($d);
                $documentId=(int)($this->request->getGet('document')??0);
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
        $in=(array)$this->request->getPost(); $target=self::URL;
        $tab=(string)($in['tab']??'accettazione'); if (in_array($tab,['accettazione','catalogo','documenti','integrazioni'],true)) $target.='?tab='.$tab;
        if (!empty($in['return_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$in['return_date'])) $target.='&date='.$in['return_date'];
        if ($tab==='catalogo' && isset(PolyclinicAdministrationService::KINDS[$in['kind']??''])) $target.='&kind='.urlencode($in['kind']);
        if (($in['action']??'')==='tariff') $target.='&kind=list';
        if ((int)($in['billing_id']??0)>0) $target=self::URL.'?tab=documenti&document='.(int)$in['billing_id'];
        try {
            [$tenantId,$db,$service]=$this->context();
            if (!$service->ready()) throw new DomainException('Modulo da installare sullo spazio selezionato.');
            $documentId=(int)($in['billing_id']??0);
            switch ($in['action']??'') {
                case 'catalog': $service->saveCatalog($in); break;
                case 'tariff': $service->saveTariff($in); break;
                case 'arrival': $service->arrive($in); break;
                case 'transition': $service->transition($in); break;
                case 'order': $service->addOrder($in); break;
                case 'remove_order': $service->removeOrder((int)($in['id']??0)); break;
                case 'invoice':
                    $template=['document_title'=>'Fattura poliambulatorio','fields'=>['show_stamp_duty'=>true],'fiscal_data'=>$service->settings('einvoice')['data']];
                    $documentId=$service->issueInvoice($in,$template); break;
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
            if ($documentId>0) $target=self::URL.'?tab=documenti&document='.$documentId;
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
            $xml=$service->prepareElectronicInvoice($id);
            return $this->response->setHeader('Cache-Control','no-store, max-age=0')->setHeader('Content-Type','application/xml; charset=UTF-8')->setHeader('Content-Disposition','attachment; filename="fattura-PC'.$id.'.xml"')->setBody($xml);
        } catch (DomainException $e) { return redirect()->to(site_url(self::URL.'?tab=documenti&document='.$id))->with('pc_error',$e->getMessage()); }
    }
}
