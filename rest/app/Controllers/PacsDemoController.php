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
}
