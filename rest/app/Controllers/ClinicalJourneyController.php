<?php
namespace App\Controllers;
use App\Services\ClinicalJourneyService;

final class ClinicalJourneyController extends ClinicalRecords
{
    public function signatureSettings()
    {
        try {
            ClinicalJourneyService::assertTest();
            [$records,$tenant,$db,$userId]=$this->context();
            $actor=$records->actor();
            if (!in_array($actor['role'],[1,4],true)) throw new \RuntimeException('Impostazioni riservate al medico e al responsabile dello spazio.');
            $isDoctor=$actor['role']===1;
            $store=new \App\Services\ClinicalSignaturePreferences((int)$tenant['id_tenant'],$isDoctor?$userId:0);
            if (strtoupper($this->request->getMethod())==='POST') {
                $store->save((array)$this->request->getPost());
                return redirect()->to(site_url('cartella-clinica/impostazioni-firma'),303)->with('success','Preferenze salvate. Il servizio di firma resta da collegare.');
            }
            $preferences=$store->read();
            $spacePreferences=(new \App\Services\ClinicalSignaturePreferences((int)$tenant['id_tenant'],0))->read();
            return $this->response->setHeader('Cache-Control','no-store, private')->setBody(view('clinical/signature_settings',compact('tenant','isDoctor','preferences','spacePreferences')));
        } catch (\Throwable $e) { return $this->problem($e); }
    }
    public function queue()
    {
        try {
            ClinicalJourneyService::assertTest();
            [,$tenant,$db,$userId]=$this->context();
            $date=(string)($this->request->getGet('date')?:(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->format('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) throw new \RuntimeException('Data non valida.');
            $service=new ClinicalJourneyService($db,(int)$tenant['id_tenant'],$userId);$rows=[];
            $appointments=$db->table('dap12_agenda_appuntamenti a')->select('a.id_appuntamento')->join('dap11_agenda_slot s','s.id_slot=a.id_slot')->where('s.data_slot',$date)->where('a.stato !=','ANNULLATO')->orderBy('s.ora_inizio')->get(200)->getResultArray();
            foreach($appointments as $a) { try { $rows[]=$service->read((int)$a['id_appuntamento']); } catch (\RuntimeException) { continue; } }
            return $this->response->setHeader('Cache-Control','no-store, private')->setBody(view('clinical/journey_queue',compact('tenant','date','rows')));
        } catch (\Throwable $e) { return $this->problem($e); }
    }
    public function show(int $id)
    {
        try {
            ClinicalJourneyService::assertTest();
            [$records,$tenant,$db,$userId]=$this->context();
            $service=new ClinicalJourneyService($db,(int)$tenant['id_tenant'],$userId);
            $state=$service->read($id); $events=$service->events($id);
            return $this->response->setHeader('Cache-Control','no-store, private')->setBody(view('clinical/journey',compact('id','tenant','state','events')));
        } catch (\Throwable $e) { return $this->problem($e); }
    }
    public function update(int $id)
    {
        try {
            ClinicalJourneyService::assertTest();
            [,$tenant,$db,$userId]=$this->context();
            (new ClinicalJourneyService($db,(int)$tenant['id_tenant'],$userId))->act($id,(array)$this->request->getPost());
            return redirect()->to(site_url('cartella-clinica/esame/'.$id),303)->with('success','Passaggio salvato.');
        } catch (\Throwable $e) { return $this->problem($e); }
    }
    private function problem(\Throwable $e)
    {
        return $this->response->setHeader('Cache-Control','no-store, private')->setStatusCode(400)->setBody(view('clinical/error',['message'=>get_class($e)===\RuntimeException::class?$e->getMessage():'Operazione non completata. Riaprire l’esame.']));
    }
}
