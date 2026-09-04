<?php

namespace App\Services;

class FsePdfEnvelopeService
{
    /** @param array<string, mixed> $data */
    public function build(string $cdaXml, array $data): string
    {
        if ($cdaXml === '') {
            throw new \InvalidArgumentException('CDA vuoto: impossibile generare il PDF FSE.');
        }

        $lines = $this->reportLines($data);
        $pages = array_chunk($lines, 46);
        if ($pages === []) {
            $pages = [['Referto di Specialistica Ambulatoriale']];
        }

        $objects = [];
        $pageRefs = [];
        foreach ($pages as $index => $pageLines) {
            $pageObject = 6 + ($index * 2);
            $contentObject = $pageObject + 1;
            $pageRefs[] = $pageObject . ' 0 R';
            $stream = $this->contentStream($pageLines, $index + 1, count($pages));
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
            $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [(cda.xml) 4 0 R] >> >> /AF [4 0 R] >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Filespec /F (cda.xml) /UF (cda.xml) /Desc (HL7 CDA R2) /AFRelationship /Data /EF << /F 5 0 R /UF 5 0 R >> >>';
        $objects[5] = '<< /Type /EmbeddedFile /Subtype /text#2Fxml /Params << /Size ' . strlen($cdaXml) . ' /CheckSum <' . md5($cdaXml) . ">> /Length " . strlen($cdaXml) . " >>\nstream\n" . $cdaXml . "\nendstream";
        ksort($objects);

        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($number = 1; $number <= $max; $number++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number]) . "\n";
        }
        $pdf .= 'trailer << /Size ' . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function reportLines(array $data): array
    {
        $lines = [
            (string) ($data['document_title'] ?? 'Referto di Specialistica Ambulatoriale'),
            '',
            'Paziente: ' . trim((string) ($data['patient_last_name'] ?? '') . ' ' . (string) ($data['patient_first_name'] ?? '')),
            'Codice fiscale: ' . strtoupper(trim((string) ($data['patient_cf'] ?? ''))),
            'Data prestazione: ' . trim((string) ($data['service_start'] ?? '')),
            'Medico: ' . trim((string) ($data['author_last_name'] ?? '') . ' ' . (string) ($data['author_first_name'] ?? '')),
            '',
        ];

        foreach ([
            'Motivo della visita' => 'reason_text',
            'Anamnesi' => 'history_text',
            'Reperti' => 'findings_text',
            'Referto' => 'report_text',
            'Diagnosi' => 'diagnosis_text',
            'Conclusioni' => 'conclusions_text',
        ] as $label => $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $lines[] = strtoupper($label);
            foreach (preg_split('/\R/u', $value) ?: [$value] as $paragraph) {
                foreach ($this->wrap((string) $paragraph, 92) as $line) {
                    $lines[] = $line;
                }
            }
            $lines[] = '';
        }

        return $lines;
    }

    /** @return list<string> */
    private function wrap(string $text, int $width): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return [''];
        }

        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($candidate) > $width && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param list<string> $lines */
    private function contentStream(array $lines, int $page, int $totalPages): string
    {
        $commands = ["BT", "/F1 10 Tf", "50 790 Td", "14 TL"];
        foreach ($lines as $line) {
            $commands[] = '(' . $this->pdfString($line) . ') Tj';
            $commands[] = 'T*';
        }
        $commands[] = 'ET';
        $commands[] = 'BT /F1 8 Tf 50 28 Td (Documento FSE 2.0 - pagina ' . $page . ' di ' . $totalPages . ') Tj ET';

        return implode("\n", $commands);
    }

    private function pdfString(string $value): string
    {
        $converted = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) : $value;
        $converted = is_string($converted) ? $converted : $value;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $converted);
    }
}
