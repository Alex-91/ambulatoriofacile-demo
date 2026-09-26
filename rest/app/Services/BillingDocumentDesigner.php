<?php
namespace App\Services;

/** Portable, versioned document structure shared by editor, HTML and PDF. */
class BillingDocumentDesigner
{
    public static function catalog(): array
    {
        return [
            'title'=>'Titolo documento', 'subtitle'=>'Sottotitolo', 'logo'=>'Logo',
            'issuer_name'=>'Professionista: nome', 'issuer_address'=>'Professionista: indirizzo',
            'issuer_tax_code'=>'Professionista: codice fiscale', 'issuer_vat_number'=>'Professionista: partita IVA',
            'issuer_pec'=>'Professionista: PEC', 'issuer_pension'=>'Cassa previdenziale', 'issuer_extra'=>'Righe professionista',
            'document_type'=>'Tipo documento', 'document_number'=>'Numero documento', 'issue_date'=>'Data emissione',
            'patient_name'=>'Paziente: nome', 'patient_tax_code'=>'Paziente: codice fiscale',
            'patient_address'=>'Paziente: indirizzo', 'patient_city'=>'Paziente: comune', 'patient_email'=>'Paziente: email',
            'patient_phone'=>'Paziente: telefono', 'patient_mobile'=>'Paziente: cellulare',
            'payment_method'=>'Metodo pagamento', 'payment_date'=>'Data pagamento', 'due_date'=>'Scadenza',
            'opposition'=>'Opposizione TS', 'line_items'=>'Tabella prestazioni', 'subtotal'=>'Subtotale',
            'vat'=>'IVA / natura', 'stamp'=>'Marca da bollo', 'total'=>'Totale documento',
            'notes'=>'Note fattura', 'terms'=>'Informativa', 'footer'=>'Testo footer',
            'signature'=>'Spazio firma', 'text'=>'Testo libero', 'divider'=>'Separatore', 'spacer'=>'Spazio vuoto',
        ];
    }

    public static function validate($raw): array
    {
        if (!is_array($raw) || ($raw['version'] ?? null) !== 1 || !is_array($raw['blocks'] ?? null)) {
            throw new \InvalidArgumentException('Modello visuale non valido. Ricarica la pagina.');
        }
        if (count($raw['blocks']) < 1 || count($raw['blocks']) > 40) throw new \InvalidArgumentException('Inserisci da 1 a 40 blocchi.');
        $out = ['version'=>1, 'blocks'=>[]];
        foreach ($raw['blocks'] as $i=>$block) {
            if (!is_array($block)) throw new \InvalidArgumentException('Blocco non valido.');
            $span = (int) ($block['span'] ?? 12);
            if (!in_array($span, [4,6,12], true)) throw new \InvalidArgumentException('Larghezza blocco non valida.');
            $elements = $block['elements'] ?? [];
            if (!is_array($elements) || count($elements)>12) throw new \InvalidArgumentException('Massimo 12 elementi per blocco.');
            $clean = [];
            foreach ($elements as $element) {
                $type = $element['type'] ?? '';
                if (!is_string($type) || !isset(self::catalog()[$type])) throw new \InvalidArgumentException('Elemento documento sconosciuto.');
                if (in_array($type, ['line_items','notes','terms','footer','issuer_extra'], true) && ($span!==12 || count($elements)!==1)) {
                    throw new \InvalidArgumentException('Tabelle e testi lunghi richiedono un blocco dedicato a larghezza intera.');
                }
                $text = trim((string) ($element['text'] ?? ''));
                $clean[] = ['type'=>$type,'text'=>$text,'label'=>trim((string) ($element['label'] ?? '')),'show_label'=>!empty($element['show_label'])];
            }
            $background = (string) ($block['background'] ?? '#ffffff');
            $color = (string) ($block['color'] ?? '#202b3c');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/',$background) || !preg_match('/^#[0-9a-fA-F]{6}$/',$color)) throw new \InvalidArgumentException('Colore non valido.');
            $out['blocks'][] = [
                'id'=>'b'.$i, 'name'=>trim((string) ($block['name'] ?? 'Blocco')),
                'span'=>$span,'background'=>$background,'color'=>$color,
                'align'=>in_array($block['align'] ?? '',['left','center','right'],true)?$block['align']:'left',
                'font'=>max(9,min(14,(int) ($block['font'] ?? 11))),
                'padding'=>max(0,min(6,(int) ($block['padding'] ?? 3))),
                'border'=>!empty($block['border']), 'page_break'=>!empty($block['page_break']), 'elements'=>$clean,
            ];
        }
        return $out;
    }

    public static function fromConfig(array $config): array
    {
        if (!empty($config['designer'])) return self::validate($config['designer']);
        $blocks=[]; $b=$config['branding']??[]; $f=$config['fields']??[]; $l=$config['layout']??[];
        $add = static function(string $name,array $types,int $span=12,array $style=[]) use (&$blocks): void {
            if ($types===[]) return;
            $blocks[]=array_merge(['name'=>$name,'span'=>$span,'elements'=>array_map(static fn($t)=>['type'=>$t,'show_label'=>!in_array($t,['title','subtitle','logo','text'],true)],$types)],$style);
        };
        if ($l['show_header']??true) {
            $types=['title']; if (!empty($b['header_subtitle'])) $types[]='subtitle';
            if (($l['show_logo']??true) && ($b['logo_mode']??'none')!=='none') $types[]='logo';
            foreach (['issuer_name','issuer_address','issuer_tax_code','issuer_vat_number','issuer_pec','issuer_pension'] as $key) if ($b['show_'.$key]??true) $types[]=$key;
            $add('Intestazione',$types,12,($b['header_style']??'')==='plain'?[]:['background'=>$b['accent_color']??'#2c8895','color'=>'#ffffff']);
            $types=[]; foreach (['document_number','issue_date','payment_method','payment_date','patient_name','patient_tax_code','patient_address','patient_city','patient_email','patient_phone','patient_mobile'] as $key) if (!empty($b['header_'.$key])) $types[]=$key;
            if (!empty($b['show_header_opposition'])) $types[]='opposition';
            $add('Dati in alto',$types);
            if (($b['show_issuer_extra']??true) && !empty($b['header_extra'])) $add('Professionista — testo',['issuer_extra']);
        }
        $types=[]; foreach (['issuer_name','issuer_address','issuer_tax_code','issuer_vat_number','issuer_pec','issuer_pension'] as $key) if (!empty($f['show_'.$key])) $types[]=$key;
        $add('Professionista',$types);
        if (!empty($f['show_issuer_extra']) && !empty($b['header_extra'])) $add('Righe professionista',['issuer_extra']);
        if ($l['show_document_box']??true) { $types=['document_type']; foreach (['document_number','issue_date'] as $key) if ($f['show_'.$key]??true) $types[]=$key; $add('Documento',$types,6); }
        if ($l['show_patient_box']??true) { $types=[]; foreach (['patient_name','patient_tax_code','patient_address','patient_city','patient_email','patient_phone','patient_mobile'] as $key) if (!empty($f['show_'.$key])) $types[]=$key; $add($config['labels']['patient_section_title']??'Paziente',$types,6); }
        if ($f['show_line_items']??true) $add('Prestazioni',['line_items']);
        if ($l['show_payment_box']??true) { $types=['due_date']; foreach (['payment_method','payment_date'] as $key) if ($f['show_'.$key]??true) $types[]=$key; $add($config['labels']['payment_section_title']??'Pagamento',$types,6); }
        $types=['subtotal']; if ($f['show_vat_summary']??true) $types[]='vat'; if ($f['show_stamp_duty']??true) $types[]='stamp'; $types[]='total'; $add('Totali',$types,6);
        foreach (['notes'=>($f['show_notes']??true),'terms'=>!empty($l['show_terms_box']),'signature'=>!empty($l['show_signature_box']),'footer'=>!empty($l['show_footer'])] as $type=>$enabled) {
            if (!$enabled) continue;
            $label = trim((string) ($config['labels'][$type.'_label'] ?? ''));
            $add($label ?: self::catalog()[$type], [$type]);
            if ($label !== '' && ($type !== 'terms' || $label !== 'Informativa')) {
                $blocks[array_key_last($blocks)]['elements'][0]['label'] = $label;
            }
        }
        return self::validate(['version'=>1,'blocks'=>$blocks]);
    }

    public static function sample(array $config): array
    {
        return ['template'=>$config,'tenant'=>['tenant_name'=>'Studio medico di esempio'], 'document_type_label'=>'Fattura', 'payment_method_label'=>'Carta / POS', 'generated_at'=>date('d/m/Y H:i'),
            'document'=>['document_number'=>'FT-2026-0017','issue_date'=>'2026-09-25','payment_date'=>'2026-09-25','due_date'=>'2026-10-25', 'patient_name'=>'Giulia Bianchi','patient_tax_code'=>'BNCGLI90L41H501R','patient_address'=>'Via Roma 10','patient_city'=>'Firenze','patient_email'=>'paziente@example.test','patient_phone'=>'055 000000','patient_mobile'=>'333 0000000','subtotal_amount'=>130,'stamp_duty_amount'=>2,'amount_total'=>132,'vat_rate'=>0,'vat_nature'=>'Esente art. 10','notes'=>'Prestazioni sanitarie di esempio.'],
            'line_items'=>[['description'=>'Visita specialistica con controllo e referto','quantity'=>1,'unit_amount'=>130,'line_total'=>130]]];
    }
}
