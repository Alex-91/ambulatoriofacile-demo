<?php
namespace App\Services;

use DomainException;
use DOMDocument;
use DOMElement;

/** FPR12 file preparation. Transport and delivery are deliberately distinct from XML generation. */
final class PolyclinicElectronicInvoice
{
    private DOMDocument $xml;

    public function build(array $d,array $recipient,array $issuer): string
    {
        if (($recipient['type']??'')!=='business') throw new DomainException('Export SdI non disponibile per fatture sanitarie a persone fisiche.');
        if (!in_array($d['document_type']??'',['invoice','credit_note'],true) || ($d['local_state']??'')!=='issued') throw new DomainException('È necessario un documento definitivo.');
        foreach (['business_name','vat_number','address','postal_code','city','province','tax_regime'] as $field) if (trim((string)($issuer[$field]??''))==='') throw new DomainException('Dati emittente incompleti: '.$field);
        foreach (['name','vat_number','address','postal_code','city','province','recipient_code'] as $field) if (trim((string)($recipient[$field]??''))==='') throw new DomainException('Dati destinatario incompleti: '.$field);
        foreach ([$issuer,$recipient] as $party) {
            if (!preg_match('/^\d{11}$/D',$party['vat_number']) || !preg_match('/^\d{5}$/D',$party['postal_code']) || !preg_match('/^[A-Z]{2}$/D',$party['province'])) throw new DomainException('Partita IVA, CAP o provincia non validi.');
        }
        if (!preg_match('/^[A-Z0-9]{7}$/D',$recipient['recipient_code']) || !preg_match('/^RF\d{2}$/D',$issuer['tax_regime'])) throw new DomainException('Codice destinatario o regime fiscale non validi.');
        $this->xml=new DOMDocument('1.0','UTF-8'); $this->xml->formatOutput=true;
        $root=$this->xml->createElementNS('http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2','p:FatturaElettronica'); $root->setAttribute('versione','FPR12'); $this->xml->appendChild($root);
        $header=$this->node($root,'FatturaElettronicaHeader'); $trans=$this->node($header,'DatiTrasmissione');
        $sender=$this->node($trans,'IdTrasmittente'); $this->node($sender,'IdPaese','IT'); $this->node($sender,'IdCodice',$issuer['vat_number']);
        $this->node($trans,'ProgressivoInvio','PC'.$d['id_billing_document']); $this->node($trans,'FormatoTrasmissione','FPR12'); $this->node($trans,'CodiceDestinatario',$recipient['recipient_code']);
        $seller=$this->node($header,'CedentePrestatore'); $identity=$this->node($seller,'DatiAnagrafici'); $vat=$this->node($identity,'IdFiscaleIVA');
        $this->node($vat,'IdPaese','IT'); $this->node($vat,'IdCodice',$issuer['vat_number']); $name=$this->node($identity,'Anagrafica'); $this->node($name,'Denominazione',$issuer['business_name']); $this->node($identity,'RegimeFiscale',$issuer['tax_regime']); $this->address($seller,$issuer);
        $buyer=$this->node($header,'CessionarioCommittente'); $identity=$this->node($buyer,'DatiAnagrafici'); $vat=$this->node($identity,'IdFiscaleIVA');
        $this->node($vat,'IdPaese','IT'); $this->node($vat,'IdCodice',$recipient['vat_number']); $name=$this->node($identity,'Anagrafica'); $this->node($name,'Denominazione',$recipient['name']); $this->address($buyer,$recipient);
        $body=$this->node($root,'FatturaElettronicaBody'); $general=$this->node($body,'DatiGenerali'); $gd=$this->node($general,'DatiGeneraliDocumento');
        $this->node($gd,'TipoDocumento',$d['document_type']==='credit_note'?'TD04':'TD01'); $this->node($gd,'Divisa','EUR'); $this->node($gd,'Data',$d['issue_date']); $this->node($gd,'Numero',$d['document_number']);
        $stamp=PolyclinicMoney::cents($d['stamp_duty_amount']); $subtotal=PolyclinicMoney::cents($d['subtotal_amount']); $total=PolyclinicMoney::cents($d['amount_total']); $tax=$total-$subtotal-$stamp;
        if ($stamp>0) { $bollo=$this->node($gd,'DatiBollo'); $this->node($bollo,'BolloVirtuale','SI'); $this->node($bollo,'ImportoBollo',PolyclinicMoney::decimal($stamp)); }
        $this->node($gd,'ImportoTotaleDocumento',PolyclinicMoney::decimal($total));
        if ($d['document_type']==='credit_note' && !empty($d['original_number'])) { $ref=$this->node($general,'DatiFattureCollegate'); $this->node($ref,'IdDocumento',$d['original_number']); $this->node($ref,'Data',$d['original_date']); }
        $goods=$this->node($body,'DatiBeniServizi');
        // No patient identifiers or clinical descriptions are exported to a business recipient.
        $line=$this->node($goods,'DettaglioLinee'); $this->node($line,'NumeroLinea','1'); $this->node($line,'Descrizione',$d['document_type']==='credit_note'?'Storno servizi alla struttura':'Servizi alla struttura'); $this->node($line,'Quantita','1.00'); $this->node($line,'PrezzoUnitario',PolyclinicMoney::decimal($subtotal)); $this->node($line,'PrezzoTotale',PolyclinicMoney::decimal($subtotal));
        $rate=PolyclinicMoney::cents($d['vat_rate']); $this->node($line,'AliquotaIVA',PolyclinicMoney::decimal($rate));
        if (!$rate) { if (empty($d['vat_nature'])) throw new DomainException('Natura IVA obbligatoria.'); $this->node($line,'Natura',$d['vat_nature']); }
        $summary=$this->node($goods,'DatiRiepilogo'); $this->node($summary,'AliquotaIVA',PolyclinicMoney::decimal($rate)); if (!$rate) $this->node($summary,'Natura',$d['vat_nature']);
        $this->node($summary,'ImponibileImporto',PolyclinicMoney::decimal($subtotal)); $this->node($summary,'Imposta',PolyclinicMoney::decimal($tax));
        if ($rate) $this->node($summary,'EsigibilitaIVA','I');
        return $this->xml->saveXML();
    }

    private function address(DOMElement $parent,array $data): void
    {
        $s=$this->node($parent,'Sede'); foreach (['Indirizzo'=>'address','CAP'=>'postal_code','Comune'=>'city','Provincia'=>'province'] as $tag=>$field) $this->node($s,$tag,$data[$field]); $this->node($s,'Nazione','IT');
    }
    private function node(DOMElement $parent,string $name,?string $value=null): DOMElement
    {
        $n=$this->xml->createElement($name); if ($value!==null) $n->appendChild($this->xml->createTextNode($value)); $parent->appendChild($n); return $n;
    }
}
