<?php
namespace App\Controllers;
use App\Services\Pacs\PacsOrderService;

class PacsOrdersController extends PacsController
{
    protected function ordersContext(): array
    {
        [$pacs,$tenant]=$this->context();
        return [$pacs->orders(),$pacs,$tenant];
    }
    public function index(int $patientId)
    {
        try {
            [$orders,$pacs,$tenant]=$this->ordersContext();
            $listing=$orders->listing($patientId,(int)($this->request->getGet('page') ?: 1));
            $overview=$pacs->overview($patientId);
            $patient=$this->patientIdentity((int)$tenant['id_tenant'],$patientId);
            return $this->privateResponse()->setBody(view('clinical/pacs_orders',compact('listing','overview','patient','tenant','patientId'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function detail(int $patientId,string $id)
    {
        try {
            [$orders,,$tenant]=$this->ordersContext();
            $order=$orders->read($patientId,$id);
            $listing=$orders->listing($patientId);
            $patient=$this->patientIdentity((int)$tenant['id_tenant'],$patientId);
            return $this->privateResponse()->setBody(view('clinical/pacs_orders',compact('order','listing','patient','tenant','patientId'),['saveData'=>false]));
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    public function create(int $patientId)
    {
        return $this->action($patientId,function ($s) use ($patientId) {
            return $s->create($patientId,(string)$this->request->getPost('binding_id'),(array)$this->request->getPost(),(string)$this->request->getPost('request_key'));
        },'Bozza salvata. Verifica i dati prima di confermarla.');
    }
    public function update(int $patientId,string $id)
    {
        return $this->action($patientId,function ($s) use ($patientId,$id) {
            $s->update($patientId,$id,(array)$this->request->getPost(),(int)$this->request->getPost('revision')); return $id;
        },'Bozza aggiornata.');
    }
    public function approve(int $patientId,string $id)
    {
        return $this->action($patientId,function ($s) use ($patientId,$id) {
            $s->approve($patientId,$id,(int)$this->request->getPost('revision'),$this->request->getPost('confirmed')==='1'); return $id;
        },'Richiesta confermata e disponibile per l’esportazione.');
    }
    public function cancel(int $patientId,string $id)
    {
        return $this->action($patientId,function ($s) use ($patientId,$id) {
            $s->cancel($patientId,$id,(int)$this->request->getPost('revision')); return $id;
        },'Richiesta annullata. Le copie già esportate devono essere ritirate anche dal sistema destinatario.');
    }
    public function export(int $patientId,string $id)
    {
        try {
            $this->postOnly(); [$orders]=$this->ordersContext();
            $file=$orders->export($patientId,$id,(int)$this->request->getPost('revision'),(string)$this->request->getPost('format'));
            return $this->privateResponse()->setHeader('Content-Type',$file['mime'])
                ->setHeader('Content-Disposition','attachment; filename="'.$file['name'].'"')->setBody($file['bytes']);
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function action(int $patientId,callable $callback,string $message)
    {
        try {
            $this->postOnly(); [$orders]=$this->ordersContext(); $id=$callback($orders);
            return redirect()->to(site_url('cartella-clinica/pazienti/'.$patientId.'/pacs/richieste/'.$id))->with('success',$message)
                ->setHeader('Cache-Control','no-store, private')->setHeader('Referrer-Policy','no-referrer');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
}
