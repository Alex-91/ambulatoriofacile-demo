<?php
namespace App\Controllers\Admin;
use App\Services\FseToscanaLabStore;

class FseToscanaLabController extends FseAdminBaseController
{
    // Injection for isolated HTTP tests; no live-document/Gateway dependency.
    protected ?FseToscanaLabStore $store=null;
    protected function storage(): FseToscanaLabStore { return $this->store ??= new FseToscanaLabStore(); }

    public function index()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        try { $state=$this->storage()->read((int)$this->resolveTenantScope()['tenant_id']); }
        catch (\Throwable $e) { return $this->response->setStatusCode(503)->setBody('Laboratorio FSE non disponibile. Nessun invio eseguito.'); }
        return view('admin/fse/toscana_lab',['menu_items'=>$this->adminMenuItems(),'lab'=>$state,
            'success'=>session()->getFlashdata('success'),'errors'=>session()->getFlashdata('errors') ?? []]);
    }

    public function command()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        $target=site_url('admin/fse2/laboratorio-toscana');
        try {
            $input=array_intersect_key((array)$this->request->getPost(),array_flip(['command','document','revision','profile','operation','workflow']));
            foreach ($input as $value) if (!is_scalar($value) || strlen((string)$value)>150) throw new \RuntimeException('LAB_COMMAND');
            $this->storage()->command((int)$this->resolveTenantScope()['tenant_id'],$this->currentAdminUserId(),$input);
            return redirect()->to($target)->with('success','Passaggio simulato salvato. Nessuna chiamata al FSE e nessuna modifica a referti reali.');
        } catch (\Throwable $e) {
            $message=match($e->getMessage()) {
                'LAB_STALE'=>'La simulazione è stata aggiornata da un altro operatore. Ricarica la pagina.',
                'LAB_BUSY'=>'Laboratorio occupato da un altro operatore. Ricarica la pagina.',
                'LAB_CORRELATION','LAB_REPLY_ORDER'=>'Risposta simulata non correlata o fuori sequenza. Nessun avanzamento.',
                'LAB_LIMIT'=>'Limite del laboratorio raggiunto. Conservare il rapporto e richiedere assistenza.',
                default=>'Passaggio non consentito nello stato attuale del laboratorio. Nessun invio eseguito.',
            };
            return redirect()->to($target)->with('errors',['generic'=>$message]);
        }
    }

    public function report()
    {
        if ($guard=$this->ensureAccess()) return $guard;
        try { $state=$this->storage()->read((int)$this->resolveTenantScope()['tenant_id']); }
        catch (\Throwable $e) { return $this->response->setStatusCode(503)->setBody('Rapporto simulato non disponibile.'); }
        return $this->response->download('fse-toscana-SOLO-SIMULAZIONE.json',json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))->setHeader('Cache-Control','no-store');
    }
}
