<?php
namespace App\Services;

final class PatientBookingLogo
{
    public static function validate(?string $url): ?string
    {
        if(!$url)return null;
        if(!preg_match('#^data:image/(png|jpeg|svg\+xml);base64,([A-Za-z0-9+/=]+)$#D',$url,$m))throw new \InvalidArgumentException('Il logo deve essere PNG, JPEG o SVG.');
        $bytes=base64_decode($m[2],true);
        if($bytes===false || strlen($bytes)>1048576)throw new \InvalidArgumentException('Il file supera 1 MB. Caricane uno più leggero.');
        if($m[1]!=='svg+xml'){
            $info=@getimagesizefromstring($bytes);
            if(!$info || $info[0]>4096 || $info[1]>4096 || $info['mime']!=='image/'.$m[1])throw new \InvalidArgumentException('Logo non valido: massimo 4096 pixel.');
            return $url;
        }
        if(preg_match('/<!DOCTYPE|<!ENTITY/i',$bytes))throw new \InvalidArgumentException('SVG non valido.');
        $dom=new \DOMDocument();$old=libxml_use_internal_errors(true);$valid=$dom->loadXML($bytes,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
        if(!$valid || $dom->documentElement->localName!=='svg')throw new \InvalidArgumentException('SVG non valido.');
        $tags=['svg','g','path','rect','circle','ellipse','line','polyline','polygon','defs','linearGradient','radialGradient','stop','clipPath','mask','title','desc','use'];
        foreach($dom->getElementsByTagName('*') as $node){
            if(!in_array($node->localName,$tags,true))throw new \InvalidArgumentException('Il logo SVG contiene elementi non consentiti.');
            foreach($node->attributes as $attr){
                $value=$attr->value;
                if(str_starts_with(strtolower($attr->localName),'on') || strtolower($attr->localName)==='style' || ($attr->localName==='href'&&!str_starts_with($value,'#')) || preg_match('/(?:javascript:|data:|https?:|url\(\s*["\x27]?(?!#))/i',$value)&&!str_starts_with($attr->nodeName,'xmlns'))throw new \InvalidArgumentException('Il logo SVG contiene riferimenti non consentiti.');
            }
        }
        return 'data:image/svg+xml;base64,'.base64_encode($dom->saveXML($dom->documentElement));
    }
}
