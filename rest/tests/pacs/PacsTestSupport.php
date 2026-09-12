<?php
namespace Tests\Pacs;
use App\Services\Pacs\{PacsTransport,PacsFeatureService};

class MemoryPacsTransport implements PacsTransport
{
    public array $calls=[];
    public array $replies=[];
    public $onGet=null;
    public function get(array $profile,string $url,string $accept,int $maxBytes): array
    {
        $this->calls[]=compact('url','accept','maxBytes');
        if ($this->onGet) ($this->onGet)();
        return array_shift($this->replies) ?? self::json([self::study()]);
    }
    public static function json(array $rows): array
    { return ['status'=>200,'type'=>'application/dicom+json','body'=>json_encode($rows,JSON_THROW_ON_ERROR),'warning'=>false]; }
    public static function study(string $patient='P-100',string $issuer='TEST-HOSPITAL',string $uid='1.2.826.0.1.100'): array
    {
        $row=[];
        foreach (['0020000D'=>['UI',$uid],'00100020'=>['LO',$patient],'00100021'=>['LO',$issuer],'00100030'=>['DA','19800101'],'00080020'=>['DA','20260913'],'00081030'=>['LO','Esame sintetico'],'00080050'=>['SH','ACCESS-1'],'00080061'=>['CS','OT']] as $tag=>[$vr,$v]) $row[$tag]=['vr'=>$vr,'Value'=>[$v]];
        $row['00100010']=['vr'=>'PN','Value'=>[['Alphabetic'=>'SINTETICO^PAZIENTE']]];
        return $row;
    }
    public static function profile(): array
    { return ['id'=>'cloud','label'=>'PACS sintetico','enabled'=>true,'qido_url'=>'https://pacs.example.test/dicom-web','wado_url'=>'https://pacs.example.test/dicom-web','auth'=>'none','viewer_url'=>'https://viewer.example.test/view?study={study}','download_enabled'=>true]; }
}
class MutablePacsGate extends PacsFeatureService
{
    public bool $enabled=true;
    public function isEnabledForTenant(int $id): bool { return $id>0 && $this->enabled; }
}
