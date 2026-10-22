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
        if($board==='A02-richieste' && empty($boot['previewMode'])) throw new PatientBookingException('gestione_richieste_non_disponibile',503);
        $root=dirname(rtrim(ROOTPATH,DIRECTORY_SEPARATOR));
        $html=file_get_contents($root.'/public/assets/patient-booking/design/tavole/html/'.$board.'.html');
        $assets=base_url('public/assets/patient-booking/design/');
        $html=str_replace(['../../font/','../../asset/','../../css/'],[$assets.'font/',$assets.'asset/',$assets.'css/'],$html);
        if(empty($boot['previewMode'])) $html=str_replace('Poliambulatorio Santa Chiara',esc($boot['tenant']),$html);
        foreach(self::BOARDS as $name) $html=str_replace('href="'.$name.'.html"','href="'.esc($boot['adminBase'].'/'.$name,'attr').'"',$html);
        $html=preg_replace('/href="(?:M\d\d|D\d\d|G\d\d)[^"]*\.html"/','href="'.esc($boot['patientUrl'],'attr').'" target="_blank" rel="noopener"',$html);
        $boot['board']=$board;
        $html=str_replace('</body>','<script>window.bookingAdmin='.json_encode($boot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';</script><script src="'.base_url('public/assets/patient-booking/original-admin.js').'"></script></body>',$html);
        return $html;
    }
}
