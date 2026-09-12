<?php
namespace App\Services\Pacs;

class DicomWebClient
{
    public const PAGE_SIZE = 25;
    public const MAX_DOWNLOAD = 33554432;
    public function __construct(private array $profile, private ?PacsTransport $transport = null)
    { $this->transport ??= new CurlPacsTransport(); }
    public static function uid(string $value): string
    {
        if (strlen($value)>64 || !preg_match('/^(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*))+$/D', $value)) throw new PacsException('Identificativo DICOM non valido.');
        return $value;
    }
    public static function identifier(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value)>64 || preg_match('/[\x00-\x1f\x7f*?\\\\]/u', $value)) throw new PacsException('Identificativo paziente o autorità PACS non valido.');
        return $value;
    }
    public static function value(array $data, string $tag): string
    {
        $value = $data[$tag]['Value'][0] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
    public static function study(array $data): array
    {
        $name = $data['00100010']['Value'][0] ?? '';
        return [
            'uid'=>self::uid(self::value($data,'0020000D')),
            'patient_id'=>self::value($data,'00100020'), 'issuer'=>self::value($data,'00100021'),
            'patient_name'=>is_array($name) ? (string)($name['Alphabetic'] ?? '') : (string)$name,
            'birth_date'=>self::value($data,'00100030'), 'date'=>self::value($data,'00080020'),
            'description'=>self::value($data,'00081030'), 'accession'=>self::value($data,'00080050'),
            'modalities'=>implode(', ', array_filter($data['00080061']['Value'] ?? [], 'is_string')),
        ];
    }
    public function studies(string $patientId, string $issuer, int $page = 1): array
    {
        $patientId=self::identifier($patientId); $issuer=self::identifier($issuer);
        if ($page<1 || $page>400) throw new PacsException('Pagina PACS non valida.');
        $result=$this->json('qido_url','/studies',[
            'PatientID'=>$patientId,'IssuerOfPatientID'=>$issuer,
            'includefield'=>'00100021,00100030,00081030,00080061',
            'limit'=>self::PAGE_SIZE,'offset'=>($page-1)*self::PAGE_SIZE,
        ]);
        $studies=[];
        foreach ($result['rows'] as $row) {
            $study=self::study($row);
            $this->assertIdentity($study,$patientId,$issuer);
            $studies[]=$study;
        }
        return ['studies'=>$studies,'page'=>$page,'more'=>$result['warning'] || count($studies)>=self::PAGE_SIZE];
    }
    public function verifiedStudy(string $uid, string $patientId, string $issuer): array
    {
        self::uid($uid);
        $result=$this->json('qido_url','/studies',[
            'StudyInstanceUID'=>$uid,'PatientID'=>self::identifier($patientId),'IssuerOfPatientID'=>self::identifier($issuer),
            'includefield'=>'00100021,00100030,00081030,00080061','limit'=>2,
        ]);
        if (count($result['rows'])!==1 || $result['warning']) throw new PacsException('Studio PACS non trovato o non univoco.');
        $study=self::study($result['rows'][0]);
        if ($study['uid']!==$uid) throw new PacsException('Il PACS ha restituito uno studio diverso da quello richiesto.');
        $this->assertIdentity($study,$patientId,$issuer);
        return $study;
    }
    public function series(string $study): array
    {
        $result=$this->json('qido_url','/studies/'.self::uid($study).'/series',['limit'=>100]);
        $rows=[];
        foreach ($result['rows'] as $row) {
            $parent=self::value($row,'0020000D');
            if ($parent!=='' && $parent!==$study) throw new PacsException('Serie PACS non coerente con lo studio.');
            $rows[]=['uid'=>self::uid(self::value($row,'0020000E')),'description'=>self::value($row,'0008103E'),'modality'=>self::value($row,'00080060'),'number'=>self::value($row,'00200011')];
        }
        return ['rows'=>$rows,'more'=>$result['warning'] || count($rows)>=100];
    }
    public function instances(string $study,string $series,int $page=1): array
    {
        if ($page<1 || $page>400) throw new PacsException('Pagina PACS non valida.');
        $result=$this->json('qido_url','/studies/'.self::uid($study).'/series/'.self::uid($series).'/instances',['limit'=>25,'offset'=>($page-1)*25]);
        $rows=[];
        foreach ($result['rows'] as $row) {
            foreach (['0020000D'=>$study,'0020000E'=>$series] as $tag=>$expected) {
                if (self::value($row,$tag)!=='' && self::value($row,$tag)!==$expected) throw new PacsException('Oggetto PACS non coerente con lo studio.');
            }
            $rows[]=['uid'=>self::uid(self::value($row,'00080018')),'class'=>self::value($row,'00080016'),'number'=>self::value($row,'00200013')];
        }
        return ['rows'=>$rows,'more'=>$result['warning'] || count($rows)>=25,'page'=>$page];
    }
    public function download(string $study,string $series,string $instance,string $patientId,string $issuer): array
    {
        if (empty($this->profile['download_enabled'])) throw new PacsException('Download DICOM non abilitato per questo collegamento.');
        $path='/studies/'.self::uid($study).'/series/'.self::uid($series).'/instances/'.self::uid($instance);
        $metadata=$this->json('wado_url',$path.'/metadata',[]);
        if (count($metadata['rows'])!==1) throw new PacsException('Oggetto DICOM non univoco.');
        $this->assertIdentity(self::study($metadata['rows'][0]),self::identifier($patientId),self::identifier($issuer));
        foreach (['0020000D'=>$study,'0020000E'=>$series,'00080018'=>$instance] as $tag=>$expected) {
            if (self::value($metadata['rows'][0],$tag)!==$expected) throw new PacsException('Oggetto DICOM non appartenente allo studio richiesto.');
        }
        $r=$this->transport->get($this->profile,rtrim($this->profile['wado_url'],'/').$path,'multipart/related; type="application/dicom"; transfer-syntax=*',self::MAX_DOWNLOAD);
        if ($r['status']!==200 || $r['body']==='' || strlen($r['body'])>self::MAX_DOWNLOAD) throw new PacsException('Oggetto DICOM non disponibile o oltre il limite di 32 MB.');
        if (!preg_match('/^multipart\/related\s*;.*boundary=(?:"([^"\r\n]{1,70})"|([a-zA-Z0-9_-]{1,70}))/i',$r['type'],$m)) throw new PacsException('Il PACS non ha restituito un contenitore DICOM supportato.');
        $boundary=$m[1] ?: ($m[2] ?? '');
        $parts=explode('--'.$boundary,$r['body']);
        if (count($parts)!==3 || trim($parts[0])!=='' || trim($parts[2])!=='--') throw new PacsException('Il PACS deve restituire un solo oggetto DICOM.');
        $part=$parts[1];
        if (!str_starts_with($part,"\r\n") || !str_ends_with($part,"\r\n")) throw new PacsException('Contenitore DICOM non valido.');
        $split=strpos($part,"\r\n\r\n");
        if ($split===false || $split>8192 || !preg_match('/(?:^|\r\n)Content-Type:\s*application\/dicom\s*(?:;[^\r\n]*)?\r\n/i',substr($part,0,$split+2))) throw new PacsException('Tipo oggetto DICOM non valido.');
        $bytes=substr($part,$split+4,-2);
        if (strlen($bytes)<132 || substr($bytes,128,4)!=='DICM') throw new PacsException('File DICOM non valido.');
        return ['bytes'=>$bytes,'mime'=>'application/dicom','name'=>'oggetto.dcm'];
    }
    public function viewer(string $study): string
    {
        $template=(string)($this->profile['viewer_url'] ?? '');
        if ($template==='') throw new PacsException('Visualizzatore PACS non configurato.');
        return str_replace('{study}',rawurlencode(self::uid($study)),$template);
    }
    private function assertIdentity(array $study,string $patient,string $issuer): void
    {
        if ($study['patient_id']!==$patient || $study['issuer']!==$issuer) throw new PacsException('Identità PACS non verificata. Controllare identificativo e autorità con il fornitore.');
    }
    private function json(string $base,string $path,array $query): array
    {
        $url=rtrim($this->profile[$base],'/').$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $r=$this->transport->get($this->profile,$url,'application/dicom+json',2097152);
        if ($r['status']===204) return ['rows'=>[],'warning'=>false];
        if ($r['status']!==200 || strtolower(trim(explode(';',$r['type'])[0]))!=='application/dicom+json' || strlen($r['body'])>2097152) throw new PacsException('Risposta DICOMweb non valida o collegamento non autorizzato.');
        try { $rows=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new PacsException('Risposta DICOMweb non leggibile.'); }
        if (!str_starts_with(ltrim($r['body']),'[') || !is_array($rows) || !array_is_list($rows) || count($rows)>100 || array_filter($rows,static fn($r)=>!is_array($r))) throw new PacsException('Risposta DICOMweb oltre i limiti o non valida.');
        return ['rows'=>$rows,'warning'=>$r['warning']];
    }
}
