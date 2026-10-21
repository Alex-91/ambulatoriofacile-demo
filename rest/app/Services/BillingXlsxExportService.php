<?php
namespace App\Services;

/** Small, dependency-free XLSX export of invoice values (never executable formulas). */
class BillingXlsxExportService
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function fields(): array
    {
        return [
            'document_number' => ['Numero fattura', 'text', 24],
            'issue_date' => ['Data', 'date', 14],
            'patient_name' => ['Destinatario', 'text', 34],
            'amount_total' => ['Importo', 'money', 17],
            'subtotal_amount' => ['Imponibile', 'money', 17],
            'stamp_duty_amount' => ['Marca da bollo', 'money', 19],
            'issuer_name' => ['Entità di fatturazione', 'text', 34],
            'patient_tax_code' => ['C. Fiscale/ P.IVA', 'text', 24],
            'services' => ['Prestazione', 'text', 48],
            'vat_rate' => ['IVA %', 'number', 12],
            'vat_nature' => ['Natura Esenzione', 'text', 20],
            'payment_status' => ['Stato pagamento', 'text', 22],
            'payment_method' => ['Metodo di pagamento', 'text', 25],
            'payment_traceable' => ['Pagamento tracciabile', 'text', 26],
            'payment_date' => ['Data pagamento', 'date', 19],
            'ts_opposition_flag' => ['Rifiuta invio al sistema TS', 'text', 29],
            'patient_address' => ['Indirizzo del destinatario', 'text', 42],
            'issuer_address' => ["Indirizzo dell'emittente", 'text', 42],
            'document_type' => ['Tipo documento', 'text', 22],
            'local_state' => ['Stato documento', 'text', 22],
            'due_date' => ['Scadenza', 'date', 14],
            'ts_expense_type_code' => ['Tipo spesa TS', 'text', 18],
            'ts_sync_state' => ['Stato TS', 'text', 24],
            'notes' => ['Note', 'text', 48],
            'business_vat_number' => ['Partita IVA destinatario', 'text', 24],
            'business_postal_code' => ['CAP destinatario', 'text', 16],
            'business_province' => ['Provincia destinatario', 'text', 18],
            'business_country' => ['Paese destinatario', 'text', 18],
            'business_recipient_code' => ['Codice destinatario', 'text', 22],
            'business_pec' => ['PEC destinatario', 'text', 36],
        ];
    }

    public static function defaultColumns(): array
    {
        return array_slice(array_keys(self::fields()), 0, 6);
    }

    public static function validateColumns($columns): array
    {
        if (!is_array($columns) || !$columns || count($columns) > count(self::fields())) {
            throw new \InvalidArgumentException('Seleziona almeno un campo per l’esportazione Excel.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !isset(self::fields()[$column])) {
                throw new \InvalidArgumentException('Campo Excel non valido.');
            }
        }
        if (count(array_unique($columns)) !== count($columns)) {
            throw new \InvalidArgumentException('I campi Excel non possono essere duplicati.');
        }
        return array_values($columns);
    }

    public function buildForTenant(int $doctorId, array $report): string
    {
        if ($doctorId <= 0) { throw new \InvalidArgumentException('Dottore non valido.'); }
        $columns = (new BillingDocumentSettingsService())->resolveXlsxColumns($doctorId);
        return $this->build((array) ($report['documents'] ?? []), $columns);
    }

    public function build(array $documents, array $columns): string
    {
        $columns = self::validateColumns($columns);
        if (count($documents) > 100000) { throw new \RuntimeException('Riduci il periodo: massimo 100.000 documenti per file Excel.'); }
        $fields = self::fields();
        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($columns as $index => $key) {
            $n = $index + 1;
            $sheet .= '<col min="'.$n.'" max="'.$n.'" width="'.$fields[$key][2].'" customWidth="1"/>';
        }
        $sheet .= '</cols><sheetData><row r="1" ht="32" customHeight="1">';
        foreach ($columns as $index => $key) { $sheet .= $this->textCell($this->columnName($index).'1', $fields[$key][0], 1); }
        $sheet .= '</row>';
        $rowNumber = 1;
        foreach ($documents as $document) {
            $rowNumber++;
            $sheet .= '<row r="'.$rowNumber.'">';
            $snapshot = json_decode((string) ($document['template_snapshot_json'] ?? ''), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            foreach ($columns as $index => $key) {
                $ref = $this->columnName($index).$rowNumber;
                $value = $this->value($key, $document, $snapshot);
                $type = $fields[$key][1];
                if ($type === 'money' || $type === 'number') {
                    if (!is_numeric($value) || !is_finite((float) $value)) { throw new \RuntimeException('Importo non valido nel documento esportato.'); }
                    $sheet .= '<c r="'.$ref.'" s="2"><v>'.number_format((float) $value, 2, '.', '').'</v></c>';
                } elseif ($type === 'date' && $value !== '' && $value !== null) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value, new \DateTimeZone('UTC'));
                    if ($date && $date->format('Y-m-d') === $value && $value >= '1900-03-01') {
                        $days = (int) (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->diff($date)->format('%a');
                        $sheet .= '<c r="'.$ref.'" s="3"><v>'.$days.'</v></c>';
                    } else { $sheet .= $this->textCell($ref, (string) $value); }
                } else { $sheet .= $this->textCell($ref, (string) $value); }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData><autoFilter ref="A1:'.$this->columnName(count($columns)-1).$rowNumber.'"/></worksheet>';
        $parts = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Fatture emesse" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF176875"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $path = tempnam(sys_get_temp_dir(), 'billing-xlsx-');
        if ($path === false) { throw new \RuntimeException('Impossibile creare il file Excel.'); }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Impossibile creare il file Excel.'); }
            try {
                foreach ($parts as $name => $xml) {
                    if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml)) { throw new \RuntimeException('Scrittura Excel non riuscita.'); }
                }
            } finally { if (!$zip->close()) { throw new \RuntimeException('Chiusura Excel non riuscita.'); } }
            $body = file_get_contents($path);
            if ($body === false) { throw new \RuntimeException('Lettura Excel non riuscita.'); }
            return $body;
        } finally { if (is_file($path)) { unlink($path); } }
    }

    private function value(string $key, array $row, array $snapshot)
    {
        if (str_starts_with($key, 'business_')) { return $snapshot['patient_details'][$key] ?? ''; }
        switch ($key) {
            case 'patient_tax_code': return ($row['patient_tax_code'] ?? '') ?: ($snapshot['patient_details']['business_vat_number'] ?? '');
            case 'patient_name': return BillingExportFormat::patientName($row, $snapshot);
            case 'services':
                $items = json_decode((string) ($row['line_items_json'] ?? ''), true);
                return implode(' | ', (array) ($row['service_descriptions'] ?? array_column(is_array($items) ? $items : [], 'description')));
            case 'patient_address':
                $details = (array) ($snapshot['patient_details'] ?? []);
                return implode(' ', array_filter(array_map(static fn($key) => trim((string) ($details[$key] ?? '')), ['patient_address', 'patient_postal_code', 'patient_city', 'patient_province'])));
            case 'issuer_name': return $snapshot['fiscal_data']['business_name'] ?? '';
            case 'issuer_address':
                $fiscal = (array) ($snapshot['fiscal_data'] ?? []);
                return implode(', ', array_filter(array_map(static fn($key) => trim((string) ($fiscal[$key] ?? '')), ['address', 'postal_code', 'city', 'province'])));
            case 'payment_status': return $row['payment_status_label'] ?? (['paid' => 'Pagato', 'unpaid' => 'Non saldato'][$row['payment_status'] ?? ''] ?? '');
            case 'payment_method': return $row['payment_method_label'] ?? (['cash' => 'Contanti', 'pos' => 'POS', 'bank_transfer' => 'Bonifico', 'mixed' => 'Misto', 'other' => 'Altro'][$row['payment_method'] ?? ''] ?? '');
            case 'payment_traceable': return ['cash' => 'No', 'pos' => 'Sì', 'bank_transfer' => 'Sì'][$row['payment_method'] ?? ''] ?? 'Non determinabile';
            case 'ts_opposition_flag': return !empty($row[$key]) ? 'Sì' : 'No';
            case 'document_type': case 'local_state': case 'ts_sync_state': return $row[$key.'_label'] ?? $row[$key] ?? '';
            default: return $row[$key] ?? (in_array($key, ['amount_total', 'subtotal_amount', 'stamp_duty_amount', 'vat_rate'], true) ? 0 : '');
        }
    }

    private function textCell(string $ref, string $value, int $style = 0): string
    {
        $value = mb_substr($value, 0, 32767, 'UTF-8');
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        do { $name = chr(65 + $index % 26).$name; $index = intdiv($index, 26) - 1; } while ($index >= 0);
        return $name;
    }
}
