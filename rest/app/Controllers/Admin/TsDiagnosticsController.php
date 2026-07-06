<?php

namespace App\Controllers\Admin;

use App\Services\TsDiagnosticsService;

class TsDiagnosticsController extends TsAdminBaseController
{
    private TsDiagnosticsService $diagnostics;

    public function __construct()
    {
        parent::__construct();
        $this->diagnostics = new TsDiagnosticsService();
    }

    public function index()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $filters = $this->buildFiltersFromRequest();
        $listing = $this->diagnostics->searchTraces($tenantId, $filters, 80);

        $selectedTraceId = trim((string) ($this->request->getGet('trace') ?? ''));
        if ($selectedTraceId === '' && count((array) ($listing['results'] ?? [])) === 1) {
            $selectedTraceId = trim((string) (($listing['results'][0]['trace_id'] ?? '')));
        }

        $selectedTrace = null;
        $selectedTraceMissing = false;
        if ($selectedTraceId !== '') {
            $selectedTrace = $this->diagnostics->getTraceDetail($tenantId, $selectedTraceId);
            $selectedTraceMissing = $selectedTrace === null;
        }

        return view('admin/ts/diagnostics', [
            'menu_items' => $this->adminMenuItems(),
            'tenantScope' => $tenantScope,
            'listing' => $listing,
            'selectedTrace' => $selectedTrace,
            'selectedTraceId' => $selectedTraceId,
            'selectedTraceMissing' => $selectedTraceMissing,
            'availableOperations' => [
                'document_dispatch' => 'Invio documento',
                'receipt_fetch' => 'Recupero ricevuta',
                'tenant_healthcheck' => 'Healthcheck spazio',
            ],
            'availableStatuses' => [
                'success' => 'Successo',
                'blocked' => 'Bloccato',
                'error' => 'Errore',
            ],
            'rawPreviewLimit' => 40000,
        ]);
    }

    public function download()
    {
        if ($guard = $this->ensureAccess()) {
            return $guard;
        }

        $tenantScope = $this->resolveTenantScope();
        $tenantId = (int) ($tenantScope['tenant_id'] ?? 0);
        $traceId = trim((string) ($this->request->getGet('trace') ?? ''));
        if ($traceId === '') {
            return redirect()->to(site_url('admin/fatturazione-ts/diagnostica'))->with('error', 'Trace TS non specificato.');
        }

        $download = $this->diagnostics->resolveTraceDownload($tenantId, $traceId);
        if (!is_array($download) || trim((string) ($download['path'] ?? '')) === '') {
            return redirect()->to(site_url('admin/fatturazione-ts/diagnostica'))->with('error', 'Trace TS non trovato.');
        }

        return $this->response
            ->download((string) $download['path'], null)
            ->setFileName((string) ($download['file_name'] ?? ('ts-trace-' . $traceId . '.json')));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFiltersFromRequest(): array
    {
        return [
            'trace_id' => $this->request->getGet('trace_id'),
            'document_id' => $this->request->getGet('document_id'),
            'document_number' => $this->request->getGet('document_number'),
            'protocol' => $this->request->getGet('protocol'),
            'operation' => $this->request->getGet('operation'),
            'status' => $this->request->getGet('status'),
            'date_from' => $this->request->getGet('date_from'),
            'date_to' => $this->request->getGet('date_to'),
        ];
    }
}
