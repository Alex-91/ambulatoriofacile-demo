<?php
namespace App\Controllers;

use App\Services\{ClinicalAccessPolicy,TenantDatabaseConnector};
use App\Services\Pacs\{PacsException,PacsProfiles,DicomWebClient};

/** Master presentation restricted to immutable, generated fixtures in test tenant 4. */
class PacsDemoController extends PacsController
{
    private function demoContext(): array
    {
        [, $tenant]=$this->context();
        if ((int)$tenant['id_tenant']!==4) throw new PacsException('Demo disponibile solo nello spazio test.');
        $db=(new TenantDatabaseConnector())->connect($tenant);
        $user=(int)(session()->get('utente_sess')->id_user ?? 0);
        if ((new ClinicalAccessPolicy($db,$user,4))->actor()['role']!==4) throw new PacsException('Demo riservata al master dello spazio test.');
        return $tenant;
    }
    private function manifest(): array
    { return json_decode(file_get_contents(APPPATH.'Resources/pacs-demo/manifest.json'),true,32,JSON_THROW_ON_ERROR); }
    private function client(): DicomWebClient
    {
        $profile=(new PacsProfiles())->get(4,'synthetic-cloud');
        if (($profile['qido_url']??'')!=='https://orthanc-j3le41pb3ym1f79lc52y271b.178.104.113.107.sslip.io/dicom-web') throw new PacsException('Archivio demo non disponibile.');
        return new DicomWebClient($profile);
    }
    public function index()
    {
        try {
            $tenant=$this->demoContext(); $manifest=$this->manifest(); $connected=false;
            try {
                $this->client()->verifiedStudy($manifest['study'],$manifest['patient'],$manifest['issuer']);
                $connected=true;
            } catch (\Throwable) {}
            return $this->privateResponse()->setBody(view('clinical/pacs_demo',compact('tenant','manifest','connected'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function image(int $number)
    {
        try {
            $this->demoContext(); $m=$this->manifest();
            if ($number<1 || $number>count($m['instances'])) throw new PacsException('Immagine demo non disponibile.');
            // Only the exact generated instance can be downloaded, never an arbitrary patient/study.
            $file=$this->client()->download($m['study'],$m['series'],$m['instances'][$number-1],$m['patient'],$m['issuer']);
            if (!hash_equals($m['hashes'][$number-1],hash('sha256',$file['bytes']))) throw new PacsException('Immagine demo modificata: verifica necessaria.');
            $this->demoContext();
            if ($this->request->getGet('download')==='1') return $this->privateResponse()->setHeader('Content-Type','application/dicom')->setHeader('Content-Disposition','attachment; filename="demo-'.sprintf('%02d',$number).'.dcm"')->setBody($file['bytes']);
            return $this->privateResponse()->setHeader('Content-Type','image/png')->setBody(file_get_contents(APPPATH.'Resources/pacs-demo/'.sprintf('%02d',$number).'.png'));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function verifiedCase(): array
    {
        $case=json_decode(file_get_contents(APPPATH.'Resources/pacs-demo/verified-case.json'),true,64,JSON_THROW_ON_ERROR);
        if (($case['marker']??'')!=='AF_VERIFIED_SYNTHETIC_CHART_V1'
            || $case['images']['patient']!=='AF-CASE-20261002' || $case['images']['issuer']!=='AF-DEMO'
            || $case['images']['study']!==$case['order']['study_uid']
            || $case['images']['accession']!==$case['order']['accession']
            || (int)$case['report']['id']!==(int)$case['order']['report_entry_id']) throw new PacsException('Caso sintetico non coerente.');
        return $case;
    }
    public function chart()
    {
        try {
            $tenant=$this->demoContext();$case=$this->verifiedCase();$connected=false;
            try {
                $study=$this->client()->verifiedStudy($case['images']['study'],$case['images']['patient'],$case['images']['issuer']);
                $connected=hash_equals($case['order']['accession'],(string)$study['accession']);
            } catch (\Throwable) {}
            return $this->privateResponse()->setBody(view('clinical/pacs_demo_chart',compact('tenant','case','connected'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function chartImage(int $number)
    {
        try {
            $this->demoContext();$case=$this->verifiedCase();$m=$case['images'];
            if ($number<1 || $number>count($m['instances'])) throw new PacsException('Immagine del caso non disponibile.');
            $client=$this->client();$study=$client->verifiedStudy($m['study'],$m['patient'],$m['issuer']);
            if (!hash_equals($m['accession'],(string)$study['accession'])) throw new PacsException('Immagini non coerenti con la richiesta.');
            $file=$client->download($m['study'],$m['series'],$m['instances'][$number-1],$m['patient'],$m['issuer']);
            if (!hash_equals($m['hashes'][$number-1],hash('sha256',$file['bytes']))) throw new PacsException('Immagine del caso modificata.');
            $this->demoContext();
            if ($this->request->getGet('download')==='1') return $this->privateResponse()->setHeader('Content-Type','application/dicom')->setHeader('Content-Disposition','attachment; filename="caso-sintetico-'.sprintf('%02d',$number).'.dcm"')->setBody($file['bytes']);
            // Same generated pixel phantom, verified against this case's exact DICOM above.
            return $this->privateResponse()->setHeader('Content-Type','image/png')->setBody(file_get_contents(APPPATH.'Resources/pacs-demo/'.sprintf('%02d',$number).'.png'));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function patientDemoContext(int $patientId): array
    {
        $tenant=$this->demoContext();
        $db=(new TenantDatabaseConnector())->connect($tenant);
        $user=(int)(session()->get('utente_sess')->id_user??0);
        (new ClinicalAccessPolicy($db,$user,4))->assertPatient($patientId,false);
        return $tenant;
    }
    public function savePatientOrder(int $patientId)
    {
        $return=site_url('cartella-clinica/pazienti/'.$patientId);
        try {
            $this->postOnly();$this->patientDemoContext($patientId);$service=$this->trialService();
            $action=(string)$this->request->getPost('action');$id=(string)$this->request->getPost('order');
            if ($action==='create') $id=$service->create((array)$this->request->getPost(),(string)$this->request->getPost('request_key'),$patientId);
            else $service->changeForPatient($patientId,$id,(int)$this->request->getPost('revision'),$action,(string)$this->request->getPost('report'));
            return redirect()->to($return.'?demo_order='.$id.'#pacs-demo')->with('success','Esame di prova salvato nella sezione demo di questa cartella.')->setHeader('Cache-Control','no-store, private');
        } catch (\Throwable $e) {
            return redirect()->to($return.'#pacs-demo')->with('error',$e instanceof PacsException?$e->getMessage():'Operazione demo non disponibile.')->setHeader('Cache-Control','no-store, private');
        }
    }
    public function exportPatientOrder(int $patientId)
    {
        try {
            $this->postOnly();$this->patientDemoContext($patientId);$format=(string)$this->request->getPost('format');
            $bytes=$this->trialService()->exportForPatient($patientId,(string)$this->request->getPost('order'),(int)$this->request->getPost('revision'),$format);
            return $this->privateResponse()->setHeader('Content-Type',$format==='json'?'application/dicom+json':'application/dicom')->setHeader('Content-Disposition','attachment; filename="worklist-DEMO.'.$format.'"')->setBody($bytes);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function trialService(): \App\Services\Pacs\PacsTrialService
    {
        return new \App\Services\Pacs\PacsTrialService(WRITEPATH.'pacs-trial',4,(int)(session()->get('utente_sess')->id_user??0));
    }
    public function orders()
    {
        try {
            $tenant=$this->demoContext();$service=$this->trialService();$orders=array_values(array_filter($service->listing(),static fn($row)=>empty($row['source_patient_id'])));
            $id=(string)$this->request->getGet('order');$selected=$id!==''?$service->read($id):null;
            if (!empty($selected['source_patient_id'])) { $pid=(int)$selected['source_patient_id'];$this->patientDemoContext($pid);return redirect()->to(site_url('cartella-clinica/pazienti/'.$pid).'?demo_order='.$id.'#pacs-demo'); }
            $catalog=\App\Services\Pacs\PacsTrialService::catalog();$stations=\App\Services\Pacs\PacsTrialService::stations();
            $requestKey=bin2hex(random_bytes(16));
            return $this->privateResponse()->setBody(view('clinical/pacs_trial',compact('tenant','orders','selected','catalog','stations','requestKey'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function saveOrder()
    {
        try {
            $this->postOnly();$this->demoContext();$s=$this->trialService();
            $action=(string)$this->request->getPost('action');$id=(string)$this->request->getPost('order');
            if ($action==='create') $id=$s->create((array)$this->request->getPost(),(string)$this->request->getPost('request_key'));
            else { if (!empty($s->read($id)['source_patient_id'])) throw new PacsException('Aprire questa prova dalla cartella del paziente.');$s->change($id,(int)$this->request->getPost('revision'),$action,(string)$this->request->getPost('report')); }
            return redirect()->to(site_url('cartella-clinica/demo-pacs/richieste').'?order='.$id)->with('success','Operazione di prova salvata.')->setHeader('Cache-Control','no-store, private');
        } catch (\Throwable $e) {
            return redirect()->to(site_url('cartella-clinica/demo-pacs/richieste'))->with('error',$e instanceof PacsException?$e->getMessage():'Operazione di prova non riuscita.')->setHeader('Cache-Control','no-store, private');
        }
    }
    public function exportOrder()
    {
        try {
            $this->postOnly();$this->demoContext();$format=(string)$this->request->getPost('format');
            if (!empty($this->trialService()->read((string)$this->request->getPost('order'))['source_patient_id'])) throw new PacsException('Esportare questa prova dalla cartella del paziente.');
            $bytes=$this->trialService()->export((string)$this->request->getPost('order'),(int)$this->request->getPost('revision'),$format);
            return $this->privateResponse()->setHeader('Content-Type',$format==='json'?'application/dicom+json':'application/dicom')->setHeader('Content-Disposition','attachment; filename="worklist-DEMO.'.$format.'"')->setBody($bytes);
        } catch (\Throwable $e) { return $this->failure($e); }
    }}
