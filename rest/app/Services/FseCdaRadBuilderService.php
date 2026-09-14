<?php
namespace App\Services;

/** CDA RAD preparation. Publication requires a separate authorized RAD integration profile. */
class FseCdaRadBuilderService
{
    public function build(array $data): string
    {
        foreach (['patient_local_id','patient_local_oid','order_id','order_oid','exam_code','exam_code_system'] as $field) {
            if (trim((string)($data[$field] ?? ''))==='' || mb_strlen((string)$data[$field])>128) throw new \InvalidArgumentException('Campo RAD obbligatorio: '.$field);
        }
        foreach (['patient_local_oid','order_oid','exam_code_system'] as $field) if (!preg_match('/^[0-2](?:\.[0-9]+)+$/D',(string)$data[$field])) throw new \InvalidArgumentException('OID RAD non valido: '.$field);
        if (!in_array($data['exam_code_system'],['2.16.840.1.113883.6.1','2.16.840.1.113883.6.103'],true)) throw new \InvalidArgumentException('Codifica esame RAD: LOINC o ICD-9-CM.');
        if ($data['patient_local_oid']===\App\Config\Fse2::CF_OID) throw new \InvalidArgumentException('L’identificativo locale richiede un dominio distinto dal codice fiscale.');
        $xml=new \DOMDocument('1.0','UTF-8');
        $header=(new FseCdaRsaBuilderService())->build(array_replace($data,['loinc_code'=>'68604-8','loinc_display_name'=>'Referto Radiologico','document_title'=>'Referto Radiologico']));
        if (!$xml->loadXML($header,LIBXML_NONET)) throw new \RuntimeException('CDA non generato.');
        $xp=new \DOMXPath($xml); $xp->registerNamespace('h','urn:hl7-org:v3');
        $xp->query('/h:ClinicalDocument/h:templateId')->item(0)->setAttribute('root','2.16.840.1.113883.2.9.10.1.7.1');
        $node=static function(string $name,array $attrs=[],?string $text=null) use($xml): \DOMElement {
            $e=$xml->createElementNS('urn:hl7-org:v3',$name); foreach($attrs as $k=>$v) $e->setAttribute($k,(string)$v); if($text!==null) $e->appendChild($xml->createTextNode($text)); return $e;
        };
        $patient=$xp->query('/h:ClinicalDocument/h:recordTarget/h:patientRole')->item(0);
        $patient->insertBefore($node('id',['root'=>$data['patient_local_oid'],'extension'=>$data['patient_local_id']]),$patient->firstChild);
        $fulfillment=$node('inFulfillmentOf'); $order=$node('order'); $order->appendChild($node('id',['root'=>$data['order_oid'],'extension'=>$data['order_id']])); $fulfillment->appendChild($order);
        $root=$xml->documentElement; $next=$xp->query('/h:ClinicalDocument/h:relatedDocument | /h:ClinicalDocument/h:componentOf | /h:ClinicalDocument/h:component')->item(0); $root->insertBefore($fulfillment,$next);
        $body=$xp->query('/h:ClinicalDocument/h:component/h:structuredBody')->item(0); while($body->firstChild) $body->removeChild($body->firstChild);
        $time=$xp->query('/h:ClinicalDocument/h:effectiveTime')->item(0)->getAttribute('value');
        foreach ([['55111-9','Esame eseguito',$data['service_description']],['18782-3','Referto radiologico',$data['report_text']]] as [$code,$title,$text]) {
            $component=$node('component'); $section=$node('section'); $section->appendChild($node('code',['code'=>$code,'codeSystem'=>\App\Config\Fse2::LOINC_OID])); $section->appendChild($node('title',[],$title));
            $narrative=$node('text'); $narrative->appendChild($node('paragraph',[],$text));
            if($code==='18782-3' && trim((string)($data['conclusions_text'] ?? ''))!=='') $narrative->appendChild($node('paragraph',[],'Conclusioni: '.$data['conclusions_text']));
            $section->appendChild($narrative);
            if($code==='55111-9') {
                $entry=$node('entry'); $act=$node('act',['classCode'=>'ACT','moodCode'=>'EVN']);
                $act->appendChild($node('code',['code'=>$data['exam_code'],'codeSystem'=>$data['exam_code_system']]));
                $act->appendChild($node('statusCode',['code'=>'completed'])); $act->appendChild($node('effectiveTime',['value'=>$time]));
                $entry->appendChild($act); $section->appendChild($entry);
            }
            $component->appendChild($section); $body->appendChild($component);
        }
        return $xml->saveXML();
    }
}
