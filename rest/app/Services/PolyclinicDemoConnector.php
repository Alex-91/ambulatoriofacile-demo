<?php
namespace App\Services;

use DomainException;

/** Offline connector contract demonstration. No network client, credentials or real protocol IDs. */
final class PolyclinicDemoConnector
{
    public function run(string $connector,string $scenario,array $payload): array
    {
        if (!in_array($connector,['accounting','sdi'],true) || !in_array($scenario,['accepted','rejected','timeout'],true)) throw new DomainException('Scenario demo non valido.');
        if ($connector==='accounting') {
            $balance=[];
            foreach ($payload['journal']??[] as $row) {
                foreach (['entry_id','account','debit','credit','document'] as $field) if (!array_key_exists($field,$row)) throw new DomainException('Export incompleto.');
                $balance[$row['entry_id']]=($balance[$row['entry_id']]??0)+PolyclinicMoney::cents($row['debit'])-PolyclinicMoney::cents($row['credit']);
            }
            if (array_filter($balance,static fn($amount)=>$amount!==0)) throw new DomainException('Prima nota non bilanciata.');
            $summary=count($balance).' registrazioni, '.count($payload['journal']??[]).' righe contabili';
            $note='Formato universale verificato localmente. La compatibilità nativa Passcom/Mexal richiede il tracciato del destinatario.';
        } else {
            $xml=(string)($payload['xml']??'');
            if (str_contains(strtoupper($xml),'<!DOCTYPE') || str_contains(strtoupper($xml),'<!ENTITY')) throw new DomainException('XML non ammesso.');
            $dom=new \DOMDocument(); $prior=libxml_use_internal_errors(true);
            try { $ok=$dom->loadXML($xml,LIBXML_NONET); } finally { libxml_clear_errors();libxml_use_internal_errors($prior); }
            if (!$ok || $dom->documentElement->localName!=='FatturaElettronica' || $dom->documentElement->getAttribute('versione')!=='FPR12') throw new DomainException('Documento XML non riconosciuto.');
            $summary='Documento azienda sintetico XML FPR12';
            $note='Simulazione del canale SdI. Nessun invio, ricevuta ufficiale o validazione fiscale/XSD.';
        }
        $labels=['accepted'=>'Accettazione simulata','rejected'=>'Scarto simulato','timeout'=>'Esito incerto simulato'];
        return ['mode'=>'demo','connector'=>$connector,'state'=>$scenario.'_simulated','label'=>$labels[$scenario],'reference'=>'DEMO-'.strtoupper(substr(hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR).$scenario),0,16)),
            'payload_sha256'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),'summary'=>$summary,'note'=>$note,'network_used'=>false];
    }

    public function sampleXml(): string
    {
        $document=['id_billing_document'=>1,'document_number'=>'DEMO-2026-1','document_type'=>'invoice','local_state'=>'issued','issue_date'=>'2026-09-21','subtotal_amount'=>'100.00','stamp_duty_amount'=>'0.00','amount_total'=>'122.00','vat_rate'=>'22.00','vat_nature'=>''];
        $recipient=['type'=>'business','name'=>'AZIENDA SINTETICA DEMO','vat_number'=>'12345678901','address'=>'Via Dimostrativa 1','postal_code'=>'00100','city'=>'Roma','province'=>'RM','recipient_code'=>'0000000'];
        $issuer=['business_name'=>'CENTRO SINTETICO DEMO','vat_number'=>'12345678901','address'=>'Via Dimostrativa 2','postal_code'=>'00100','city'=>'Roma','province'=>'RM','tax_regime'=>'RF01'];
        return (new PolyclinicElectronicInvoice())->build($document,$recipient,$issuer);
    }
}
