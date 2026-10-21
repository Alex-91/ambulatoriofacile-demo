<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class BillingDocumentExportService
{
    public function __construct(private ?BillingDocumentService $documents = null) {}

    public function build(int $tenantId, array $rows): array
    {
        if (!$rows || count($rows) > 50) {
            throw new \InvalidArgumentException('Seleziona da 1 a 50 fatture per il download PDF/ZIP.');
        }
        if (!class_exists(Dompdf::class) || (count($rows) > 1 && !class_exists(\ZipArchive::class))) {
            throw new \RuntimeException('Generatore PDF/ZIP non disponibile.');
        }
        $path = null;
        $zip = null;
        try {
            if (count($rows) > 1) {
                $path = tempnam(sys_get_temp_dir(), 'billing_export_');
                $zip = new \ZipArchive();
                if ($path === false || $zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Impossibile creare l’archivio ZIP.');
                }
            }
            $documents = $this->documents ?? new BillingDocumentService();
            $usedNames = [];
            foreach ($rows as $row) {
                $id = (int) $row['id_billing_document'];
                $preview = $documents->buildPreviewContext($tenantId, $id);
                $options = (new BillingPdfOptionsFactory())->create($tenantId);
                $pdf = new Dompdf($options);
                $pdf->loadHtml(view('admin/billing/document_pdf', ['preview' => $preview]), 'UTF-8');
                $pdf->setPaper('A4', 'portrait');
                $pdf->render();
                $body = $pdf->output();
                $name = BillingExportFormat::filename($preview['document'], $usedNames);
                if ($zip === null) {
                    return ['body' => $body, 'type' => 'application/pdf', 'name' => $name];
                }
                if (!$zip->addFromString($name, $body)) {
                    throw new \RuntimeException('Impossibile aggiungere una fattura allo ZIP.');
                }
                unset($pdf, $body);
            }
            $closed = $zip->close();
            $zip = null;
            if (!$closed) {
                throw new \RuntimeException('Impossibile completare l’archivio ZIP.');
            }
            $body = file_get_contents($path);
            if ($body === false) {
                throw new \RuntimeException('Impossibile leggere l’archivio ZIP.');
            }
            return ['body' => $body, 'type' => 'application/zip', 'name' => 'fatture-selezionate.zip'];
        } finally {
            if ($zip !== null) {
                try { $zip->close(); } catch (\Throwable $ignored) {}
            }
            if (is_string($path) && is_file($path)) { unlink($path); }
        }
    }
}
