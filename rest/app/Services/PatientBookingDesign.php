<?php
namespace App\Services;

/** Uses the exported HTML verbatim; only asset/routes and runtime data are injected. */
final class PatientBookingDesign
{
    public const BOARDS=['A01-regole','A02-richieste','A03-link-aspetto','A04-configurazione-flusso','A05-configurazione-verifica'];
    public static function render(string $board,array $boot): string
    {
        if(!in_array($board,self::BOARDS,true)) throw new PatientBookingException('non_trovata',404);
        // This board contains example patient records. Never expose it as real operational data.
        $root=dirname(rtrim(ROOTPATH,DIRECTORY_SEPARATOR));
        $html=file_get_contents($root.'/public/assets/patient-booking/design/tavole/html/'.$board.'.html');
        if($board==='A02-richieste')$html=self::requests($html,$boot);
        $assets=base_url('public/assets/patient-booking/design/');
        $html=str_replace(['../../font/','../../asset/','../../css/'],[$assets.'font/',$assets.'asset/',$assets.'css/'],$html);
        $html=str_replace(['Poliambulatorio Santa Chiara','Empoli · Vinci','Marta Sereni','>MS<','>SC<'],[esc($boot['tenant']),esc(implode(' · ',array_column($boot['catalog']['sedi'],'nome'))),esc($boot['operator']??'Amministrazione'),'>'.esc(mb_substr($boot['operator']??'AM',0,2)).'<','>'.esc(mb_substr($boot['tenant'],0,2)).'<'],$html);
        foreach(self::BOARDS as $name) $html=str_replace('href="'.$name.'.html"','href="'.esc($boot['adminBase'].'/'.$name,'attr').'"',$html);
        $html=preg_replace('/href="(?:M\d\d|D\d\d|G\d\d)[^"]*\.html"/','href="'.esc($boot['patientUrl'],'attr').'" target="_blank" rel="noopener"',$html);
        $boot['board']=$board;
        $html=str_replace('</body>','<script>window.bookingAdmin='.json_encode($boot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';</script><script src="'.base_url('public/assets/patient-booking/original-admin.js').'"></script></body>',$html);
        return $html;
    }

    private static function requests(string $html,array $boot): string
    {
        $dom=new \DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML($html);libxml_clear_errors();libxml_use_internal_errors($old);
        $xp=new \DOMXPath($dom);$list=$xp->query('//section[@aria-labelledby="titolo-richieste"]/ul')->item(0);
        if(!$list)return $html;
        $template=$xp->query('./li[2]',$list)->item(0)->cloneNode(true);
        while($list->firstChild)$list->removeChild($list->firstChild);
        $cat=$boot['catalog'];$visits=array_column($cat['prestazioni'],null,'id');$doctors=array_column($cat['professionisti'],null,'id');$sites=array_column($cat['sedi'],null,'id');
        foreach($boot['requests']??[] as $p) {
            $row=$template->cloneNode(true);$row->setAttribute('data-booking-ref',$p['ref']);
            $cols=$xp->query('./div[1]/div',$row);$doctor=$doctors[$p['professionista']]??[];$visit=$visits[$p['prestazione']]??[];
            $texts=[[$p['paziente']['nome'].' '.$p['paziente']['cognome'],implode(' · ',array_filter([$p['cellulare'],$p['email']]))],[$visit['nome']??'',trim(implode(' ',[$doctor['titolo']??'',$doctor['nome']??'',$doctor['cognome']??''])).' · '.($sites[$p['sede']]['nome']??'')],[(new \DateTimeImmutable($p['data']))->format('d/m/Y'),$p['ora'].' · '.($visit['durata']??'').' min']];
            foreach($texts as $i=>$values)foreach($values as $j=>$value)$xp->query('./div',$cols->item($i))->item($j)->textContent=$value;
            $expiry=$xp->query('./span',$cols->item(2))->item(0);$expiry->textContent='Scade tra '.max(0,(int)ceil((strtotime(($p['scade_il']??'').' UTC')-time())/60)).' minuti';
            $badges=$xp->query('./div[2]/div[1]',$row)->item(0);while($badges->childNodes->length>1)$badges->removeChild($badges->lastChild);$badges->firstChild->textContent='Non verificato';
            foreach($xp->query('.//button',$row) as $button)$button->setAttribute('data-request-action',trim($button->textContent)==='Conferma'?'conferma':'rifiuta');
            $list->appendChild($row);
        }
        if(!$list->childNodes->length){$row=$template->cloneNode(false);$row->textContent='Nessuna richiesta da confermare.';$list->appendChild($row);}
        $count=count($boot['requests']??[]);
        $badge=$xp->query('//*[@id="titolo-richieste"]/span')->item(0);if($badge)$badge->textContent=(string)$count;
        foreach($xp->query('//button[starts-with(normalize-space(.),"Mostra le altre")]') as $node)$node->parentNode->removeChild($node);
        return $dom->saveHTML();
    }
}
