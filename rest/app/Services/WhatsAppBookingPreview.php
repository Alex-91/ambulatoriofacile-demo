<?php
namespace App\Services;

/** Deliberately has no database or gateway dependency. */
final class WhatsAppBookingPreview
{
    public function __construct(private array $catalog,private bool $patientFound=true){}
    public function choices(array $c,array $s,string $step): array
    {
        if($step==='visit')return array_values(array_filter($this->catalog['visits'],static fn($v)=>in_array((int)$v['id'],$c['visit_ids'],true)));
        if($step==='day'){
            $day=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Rome')))->modify('+1 day');
            return array_map(static function($offset)use($day){$date=$day->modify('+'.$offset.' days');return ['id'=>$date->format('Y-m-d'),'label'=>$date->format('d/m/Y')];},range(0,11));
        }
        return [['id'=>1,'label'=>'09:00'],['id'=>2,'label'=>'10:30'],['id'=>3,'label'=>'15:00']];
    }
    public function patient(string $cf): ?array {return $this->patientFound?['id_client'=>1,'nome'=>'Andrea','cognome'=>'Esempio']:null;}
    public function summary(array $c,array $s): string
    {
        $visits=array_column($this->catalog['visits'],null,'id');$doctors=array_column($this->catalog['doctors'],null,'id');
        return "Riepilogo dimostrativo\n".($c['single_visit']?'Appuntamento · 30 minuti':($visits[$s['visit_id']??0]['label']??'Prestazione'))."\n".($doctors[$c['doctor_id']]['label']??'Medico')."\n".'Sede dell’agenda (dimostrativa)'."\n".$s['day'].' alle '.$s['slot']['label']."\n".trim(($s['patient']['nome']??'').' '.($s['patient']['cognome']??''));
    }
    public function cancellations(array $c,string $cf): array {return $this->patientFound?[['id'=>1,'label'=>date('d/m/Y',strtotime('+2 days')).' 09:00 · Sede dimostrativa']]:[];}
    public function cancel(array $c,array $s): void {}
    public function book(array $c,array $s): int {return 0;}
}
