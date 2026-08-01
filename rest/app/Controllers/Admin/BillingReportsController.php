<?php

namespace App\Controllers\Admin;

use App\Services\BillingReportService;
use Dompdf\Dompdf;
use Dompdf\Options;

class BillingReportsController extends BillingAdminBaseController
{
    private BillingReportService $reports;

    public function __construct()
    {
        parent::__construct();
        $this->reports = new BillingReportService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $report = $this->reports->buildReportForTenant($tenantId, (array) $this->request->getGet());

        return view('admin/billing/reports', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'report' => $report,
            'errors' => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function export()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $report = $this->reports->buildReportForTenant($tenantId, (array) $this->request->getGet());

        if (empty($report['table_available'])) {
            return redirect()->to(site_url('admin/fatturazione-statistiche'))->with('errors', [
                'generic' => trim((string) ($report['schema_message'] ?? '')) ?: 'Archivio fatture non disponibile.',
            ]);
        }

        try {
            $csv = $this->reports->buildAccountantCsv($report);
        } catch (\Throwable $e) {
            log_message('error', 'BillingReportsController::export failed: ' . $e->getMessage());

            return redirect()->to(site_url('admin/fatturazione-statistiche'))->with('errors', [
                'generic' => 'Impossibile generare l’export per il commercialista.',
            ]);
        }

        $filename = 'fatturato-commercialista-' . $this->periodLabelForFilename($report) . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setBody($csv);
    }

    public function exportPdf()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        if (!class_exists(Dompdf::class)) {
            return redirect()->to(site_url('admin/fatturazione-statistiche'))->with('errors', [
                'generic' => 'Generatore PDF non disponibile.',
            ]);
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $report = $this->reports->buildReportForTenant($tenantId, (array) $this->request->getGet());

        if (empty($report['table_available'])) {
            return redirect()->to(site_url('admin/fatturazione-statistiche'))->with('errors', [
                'generic' => trim((string) ($report['schema_message'] ?? '')) ?: 'Archivio fatture non disponibile.',
            ]);
        }

        try {
            $html = view('admin/billing/report_pdf', [
                'tenantScope' => $tenantScope,
                'report' => $report,
                'generatedAt' => date('d/m/Y H:i'),
            ]);

            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            $filename = 'fatturato-commercialista-' . $this->periodLabelForFilename($report) . '.pdf';

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setHeader('Cache-Control', 'no-store, max-age=0')
                ->setBody($dompdf->output());
        } catch (\Throwable $e) {
            log_message('error', 'BillingReportsController::exportPdf failed: ' . $e->getMessage());

            return redirect()->to(site_url('admin/fatturazione-statistiche'))->with('errors', [
                'generic' => 'Impossibile generare il PDF del fatturato.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function periodLabelForFilename(array $report): string
    {
        $filters = is_array($report['filters'] ?? null) ? $report['filters'] : [];
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($from === '') {
            return 'storico-completo';
        }

        return str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
    }
}
