<?php
namespace App\Controllers;
use App\Services\{ClinicalAccessPolicy,TenantDatabaseConnector};
use App\Services\Pacs\{PacsDiagnostics,PacsException};

class PacsDiagnosticsController extends PacsController
{
    public function index()
    {
        try {
            [, $tenant]=$this->context();
            $id=(int)$tenant['id_tenant']; $db=(new TenantDatabaseConnector())->connect($tenant);
            $user=(int)(session()->get('utente_sess')->id_user ?? 0);
            if ((new ClinicalAccessPolicy($db,$user,$id))->actor()['role']!==4) throw new PacsException('Verifica collegamenti riservata al responsabile dello spazio.');
            $service=new PacsDiagnostics(); $status=$service->overview($db,$id); $probe=null;
            if (strtoupper($this->request->getMethod())==='POST') {
                $key='pacs_diagnostics_at_'.$id;
                if (time()-(int)session()->get($key)<15) throw new PacsException('Attendere qualche secondo prima di ripetere la verifica.');
                session()->set($key,time());
                $probe=$service->probe($id,(string)$this->request->getPost('profile_id'));
            }
            return $this->privateResponse()->setBody(view('clinical/pacs_diagnostics',compact('tenant','status','probe'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
}
