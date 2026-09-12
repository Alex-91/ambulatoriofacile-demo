<?php
namespace App\Services\Pacs;

/** One requested procedure / one scheduled step. DICOM JSON + Explicit VR Little Endian file. */
final class ModalityWorklist
{
    public static function uid(string $hex): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$hex)) throw new PacsException('Identificativo richiesta non valido.');
        $decimal='0';
        foreach (str_split($hex) as $digit) {
            $carry=hexdec($digit); $result='';
            for ($i=strlen($decimal)-1;$i>=0;$i--) {
                $n=((int)$decimal[$i])*16+$carry; $result=($n%10).$result; $carry=intdiv($n,10);
            }
            $decimal=($carry ? (string)$carry : '').$result;
        }
        return '2.25.'.(ltrim($decimal,'0') ?: '0');
    }
    public static function text(string $value,int $max,bool $required=true): string
    {
        $value=trim($value);
        if (($required && $value==='') || !mb_check_encoding($value,'UTF-8') || mb_strlen($value)>$max || preg_match('/[\x00-\x1f\x7f\\\\]/u',$value)) throw new PacsException('Testo richiesta mancante, troppo lungo o non valido.');
        return $value;
    }
    public static function schedule(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D',$value) || substr($value,0,4)==='0000') throw new PacsException('Data e ora della richiesta non valide.');
        $zone=new \DateTimeZone('Europe/Rome');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$zone);
        if (!$date || $date->format('Y-m-d\TH:i')!==$value) throw new PacsException('Ora inesistente o data non valida.');
        foreach ([-3600,3600] as $offset) if ($date->setTimestamp($date->getTimestamp()+$offset)->format('Y-m-d\TH:i')===$value) throw new PacsException('Ora ambigua durante il cambio dell’ora legale: scegliere un orario non ambiguo.');
        return $date;
    }
    public static function payload(array $input,array $patient): array
    {
        foreach (['description','procedure_code','coding_scheme','modality','station_ae','scheduled_at','reason'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field])) throw new PacsException('Formato dei dati della richiesta non valido.');
        }
        $last=self::text((string)($patient['patient_last_name'] ?? ''),64);
        $first=self::text((string)($patient['patient_first_name'] ?? ''),64);
        $pn=self::text($last.'^'.$first,64);
        if (strpbrk($last.$first,'^=')!==false) throw new PacsException('Nome anagrafico non rappresentabile nel profilo DICOM.');
        $birth=trim((string)($patient['patient_birth_date'] ?? ''));
        if ($birth!=='') {
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$birth);
            if (!$d || $d->format('Y-m-d')!==$birth || substr($birth,0,4)==='0000') throw new PacsException('Correggere la data di nascita nell’anagrafica.');
            $birth=$d->format('Ymd');
        }
        $modality=strtoupper(trim((string)($input['modality'] ?? '')));
        if (!in_array($modality,['CT','MR','US','CR','DX','MG','NM','PT','XA','RF','OT','ECG','EPS','OP','OCT','SM'],true)) throw new PacsException('Modalità diagnostica non supportata da questo profilo.');
        $station=trim((string)($input['station_ae'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9 _-]{0,15}$/D',$station)) throw new PacsException('Destinazione DICOM non valida (AE Title, massimo 16 caratteri).');
        $scheduled=self::schedule((string)($input['scheduled_at'] ?? ''));
        $description=self::text((string)($input['description'] ?? ''),64);
        $code=self::text((string)($input['procedure_code'] ?? ''),16);
        $scheme=self::text((string)($input['coding_scheme'] ?? ''),16);
        $reason=self::text((string)($input['reason'] ?? ''),1000,false);
        return ['patient_name'=>$pn,'birth_date'=>$birth,'sex'=>'','description'=>$description,
            'procedure_code'=>$code,'coding_scheme'=>$scheme,'reason'=>$reason,'modality'=>$modality,
            'station_ae'=>$station,'scheduled_at'=>$scheduled->format('Y-m-d\TH:i'),
            'scheduled_utc'=>$scheduled->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'utc_offset'=>$scheduled->format('O')];
    }
    public static function dataset(array $order,array $payload,array $identity): array
    {
        $element=static fn(string $vr,string $value): array => $value==='' ? ['vr'=>$vr] : ['vr'=>$vr,'Value'=>[$value]];
        $code=['00080100'=>$element('SH',$payload['procedure_code']),'00080102'=>$element('SH',$payload['coding_scheme']),'00080104'=>$element('LO',$payload['description'])];
        $scheduled=self::schedule($payload['scheduled_at']);
        $step=[
            '00080060'=>$element('CS',$payload['modality']),
            '00400001'=>$element('AE',$payload['station_ae']),
            '00400002'=>$element('DA',$scheduled->format('Ymd')),
            '00400003'=>$element('TM',$scheduled->format('His')),
            '00400006'=>$element('PN',''),
            '00400007'=>$element('LO',$payload['description']),
            '00400008'=>['vr'=>'SQ','Value'=>[$code]],
            '00400009'=>$element('SH',$order['accession']),
            '00400010'=>$element('SH',''),
            '00400011'=>$element('SH',''),
        ];
        $data=[
            '00080005'=>$element('CS','ISO_IR 192'),'00080050'=>$element('SH',$order['accession']),
            '00080090'=>$element('PN',''),'00080201'=>$element('SH',$payload['utc_offset']),
            '00100010'=>['vr'=>'PN','Value'=>[['Alphabetic'=>$payload['patient_name']]]],
            '00100020'=>$element('LO',DicomWebClient::identifier($identity['patient_id'])),
            '00100021'=>$element('LO',DicomWebClient::identifier($identity['issuer'])),
            '00100030'=>$element('DA',$payload['birth_date']),'00100040'=>$element('CS',$payload['sex']),
            '0020000D'=>$element('UI',DicomWebClient::uid($order['study_uid'])),
            '00321060'=>$element('LO',$payload['description']),'00321064'=>['vr'=>'SQ','Value'=>[$code]],
            '00400100'=>['vr'=>'SQ','Value'=>[$step]],
            '00401001'=>$element('SH',$order['accession']),'00401003'=>$element('SH','ROUTINE'),
        ];
        ksort($data);
        return $data;
    }
    public static function file(array $data,string $instanceUid): string
    {
        $sop='1.2.840.10008.5.1.4.31';
        $meta=self::element('00020001','OB',"\0\1")
            .self::element('00020002','UI',$sop).self::element('00020003','UI',DicomWebClient::uid($instanceUid))
            .self::element('00020010','UI','1.2.840.10008.1.2.1')
            .self::element('00020012','UI','2.25.267115564795301338902383769944783562680');
        return str_repeat("\0",128).'DICM'.self::element('00020000','UL',pack('V',strlen($meta))).$meta.self::encode($data);
    }
    private static function encode(array $data): string
    {
        ksort($data); $result='';
        foreach ($data as $tag=>$e) {
            $value='';
            foreach ($e['Value'] ?? [] as $item) {
                if ($e['vr']==='SQ') {
                    $bytes=self::encode($item); $value.=pack('vvV',0xFFFE,0xE000,strlen($bytes)).$bytes;
                } else {
                    if ($value!=='') $value.='\\';
                    $value.=is_array($item) ? ($item['Alphabetic'] ?? '') : (string)$item;
                }
            }
            $result.=self::element((string)$tag,$e['vr'],$value);
        }
        return $result;
    }
    private static function element(string $tag,string $vr,string $value): string
    {
        if (strlen($value)%2) $value.=in_array($vr,['UI','OB'],true) ? "\0" : ' ';
        $tagBytes=pack('vv',hexdec(substr($tag,0,4)),hexdec(substr($tag,4,4)));
        return $tagBytes.$vr.(in_array($vr,['SQ','OB','OW','UN','UT'],true) ? "\0\0".pack('V',strlen($value)) : pack('v',strlen($value))).$value;
    }
}
