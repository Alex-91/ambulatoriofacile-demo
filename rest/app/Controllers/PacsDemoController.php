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
    private function trialService(): \App\Services\Pacs\PacsTrialService
    {
        return new \App\Services\Pacs\PacsTrialService(WRITEPATH.'pacs-trial',4,(int)(session()->get('utente_sess')->id_user??0));
    }
    public function orders()
    {
        try {
            $tenant=$this->demoContext();$service=$this->trialService();$orders=$service->listing();
            $id=(string)$this->request->getGet('order');$selected=$id!==''?$service->read($id):null;
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
            else $s->change($id,(int)$this->request->getPost('revision'),$action,(string)$this->request->getPost('report'));
            return redirect()->to(site_url('cartella-clinica/demo-pacs/richieste').'?order='.$id)->with('success','Operazione di prova salvata.')->setHeader('Cache-Control','no-store, private');
        } catch (\Throwable $e) {
            return redirect()->to(site_url('cartella-clinica/demo-pacs/richieste'))->with('error',$e instanceof PacsException?$e->getMessage():'Operazione di prova non riuscita.')->setHeader('Cache-Control','no-store, private');
        }
    }
    public function exportOrder()
    {
        try {
            $this->postOnly();$this->demoContext();$format=(string)$this->request->getPost('format');
            $bytes=$this->trialService()->export((string)$this->request->getPost('order'),(int)$this->request->getPost('revision'),$format);
            return $this->privateResponse()->setHeader('Content-Type',$format==='json'?'application/dicom+json':'application/dicom')->setHeader('Content-Disposition','attachment; filename="worklist-DEMO.'.$format.'"')->setBody($bytes);
        } catch (\Throwable $e) { return $this->failure($e); }
    }}
