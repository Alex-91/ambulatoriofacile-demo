<?php
namespace App\Controllers;

use App\Services\AccountantDelegationService;
use App\Services\AccountantAccessPolicy;
use App\Services\BillingFeatureService;
use App\Services\BillingReportService;
use App\Services\BillingDocumentService;
use CodeIgniter\Controller;
use Dompdf\Dompdf;
use Dompdf\Options;

/** No inherited legacy admin actions or doctor impersonation. */
class AccountantController extends Controller
{
    protected $helpers = ['url', 'form'];

    public function index()
    {
        $service = new AccountantDelegationService();
        if (!$service->ready()) { return $this->response->setStatusCode(503)->setBody('Area commercialista non ancora attivata.'); }
        $email = $this->identity();
        $grants = $email ? array_values(array_filter($service->forEmail($email), fn($grant) => $this->doctorEnabled((int) $grant['tenant_id']))) : [];
        return view('accountant/home', ['email' => $email, 'grants' => $grants]);
    }

    public function requestLogin()
    {
        $service = new AccountantDelegationService();
        if (!$service->ready()) { return $this->response->setStatusCode(503); }
        // Per-IP limit plus persistent per-mailbox cooldown; always the same public response.
        $throttler = service('throttler');
        if ($throttler->check('accountant-login-' . hash('sha256', $this->request->getIPAddress()), 5, 300)) {
            try {
                $email = strtolower(trim((string) $this->request->getPost('email')));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254) {
                    $token = $service->loginToken($email);
                    if ($token !== null) { $service->sendLink($email, site_url('commercialista/conferma') . '?accesso=' . $token, false); }
                }
            } catch (\Throwable $e) {
                log_message('error', 'Accountant login email delivery failed.');
            }
        }
        return redirect()->to(site_url('commercialista'))->with('success', 'Se hai una delega attiva, riceverai un link personale valido per 15 minuti. Controlla anche la posta indesiderata.');
    }

    public function confirm()
    {
        // Email scanners must not consume tokens. Exchange only after a CSRF-protected POST.
        $kind = $this->request->getGet('invito') !== null ? 'invito' : 'accesso';
        $token = (string) $this->request->getGet($kind);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) { return $this->response->setStatusCode(400)->setBody('Link non valido.'); }
        session()->set('accountant_pending_link', ['kind' => $kind, 'token' => $token]);
        return redirect()->to(site_url('commercialista/accetta'));
    }

    public function acceptForm()
    {
        return view('accountant/accept', ['pending' => session()->get('accountant_pending_link')]);
    }

    public function accept()
    {
        $pending = session()->get('accountant_pending_link');
        session()->remove('accountant_pending_link');
        $service = new AccountantDelegationService();
        if (!$service->ready() || !is_array($pending)) { return $this->invalidLink(); }
        $email = $pending['kind'] === 'invito' ? $service->accept($pending['token']) : $service->consumeLogin($pending['token']);
        if (!$email) { return $this->invalidLink(); }
        // Clear ALL legacy roles/IDs before establishing the restricted identity.
        foreach (array_keys(session()->get()) as $key) { session()->remove($key); }
        session()->regenerate(true);
        session()->set(['accountant_email' => $email, 'accountant_until' => time() + 7200]);
        return redirect()->to(site_url('commercialista'));
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to(site_url('commercialista'));
    }

    public function documents(int $doctor)
    {
        if (!($grant = $this->authorized($doctor))) { return $this->denied(); }
        try {
            $report = $this->report($grant);
            (new AccountantDelegationService())->audit((int) $grant['id'], 'view');
            return view('accountant/documents', ['grant' => $grant, 'report' => $report]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->to(site_url('commercialista'))->with('error', $e->getMessage());
        }
    }

    public function export(int $doctor, string $format)
    {
        if (!($grant = $this->authorized($doctor))) { return $this->denied(); }
        try {
            $report = $this->report($grant);
            if (empty($report['table_available'])) { throw new \RuntimeException('Archivio non disponibile.'); }
            $name = 'report-' . $doctor . '-' . $report['filters']['date_from'] . '-' . $report['filters']['date_to'];
            if ($format === 'csv') {
                $body = (new BillingReportService())->buildAccountantCsv($report);
                $type = 'text/csv; charset=UTF-8';
            } elseif ($format === 'xlsx') {
                $body = (new \App\Services\BillingXlsxExportService())->buildForTenant((int) $grant['tenant_id'], $report);
                $type = \App\Services\BillingXlsxExportService::MIME;
            } elseif ($format === 'pdf') {
                $body = $this->pdf(view('admin/billing/report_pdf', ['report' => $report,
                    'tenantScope' => ['tenant_name' => $grant['tenant_label']], 'generatedAt' => date('d/m/Y H:i')]), true, $doctor);
                $type = 'application/pdf';
            } elseif ($format === 'zip') {
                return $this->zip($grant, $report, $name);
            } else { return $this->response->setStatusCode(404); }
            // Recheck after generation, immediately before delivering sensitive data.
            if (!$this->grantUnchanged($grant)) { return $this->denied(); }
            (new AccountantDelegationService())->audit((int) $grant['id'], 'export_' . $format,
                $report['filters']['date_from'] . ' / ' . $report['filters']['date_to'] . ' · ' . count($report['documents']) . ' documenti');
            return $this->download($body, $name . '.' . $format, $type);
        } catch (\Throwable $e) {
            return redirect()->to(site_url('commercialista/medico/' . $doctor))->with('error', 'Esportazione non disponibile. Verifica il periodo selezionato o riprova con un intervallo più breve.');
        }
    }

    public function invoice(int $doctor, int $id)
    {
        if (!($grant = $this->authorized($doctor))) { return $this->denied(); }
        try {
            $body = $this->invoicePdf($grant, $id);
            if (!$this->grantUnchanged($grant)) { return $this->denied(); }
            (new AccountantDelegationService())->audit((int) $grant['id'], 'invoice_pdf', 'Documento #' . $id);
            return $this->download($body, 'fattura-' . $id . '.pdf', 'application/pdf');
        } catch (\Throwable $e) { return $this->response->setStatusCode(404)->setBody('Documento non disponibile.'); }
    }

    public function exportSelected(int $doctor)
    {
        $this->response->setHeader('X-CSRF-Name', csrf_token())->setHeader('X-CSRF-Hash', csrf_hash());
        if (!($grant = $this->authorized($doctor))) { return $this->denied(); }
        try {
            $rows = (new BillingReportService())->selectedDocumentsForTenant($doctor, $this->request->getPost('billing_document_ids'));
            foreach ($rows as $row) {
                $row['_export_tenant_id'] = $doctor; // Rows were read from this tenant's database.
                if (!AccountantAccessPolicy::documentAllowed($row, $doctor, $grant['from_date'])) {
                    throw new \InvalidArgumentException('Selezione non autorizzata.');
                }
            }
            $format = (string) $this->request->getPost('export_format');
            if ($format === 'pdf') {
                $file = (new \App\Services\BillingDocumentExportService())->build($doctor, $rows);
            } elseif ($format === 'xlsx') {
                $file = ['body' => (new \App\Services\BillingXlsxExportService())->buildForTenant($doctor, ['documents' => $rows]),
                    'type' => \App\Services\BillingXlsxExportService::MIME, 'name' => 'fatture-selezionate.xlsx'];
            } elseif ($format === 'csv') {
                $file = ['body' => (new BillingReportService())->buildAccountantCsv(['documents' => $rows]),
                    'type' => 'text/csv; charset=UTF-8', 'name' => 'fatture-selezionate.csv'];
            } else { throw new \InvalidArgumentException('Formato non valido.'); }
            if (!$this->grantUnchanged($grant)) { return $this->denied(); }
            (new AccountantDelegationService())->audit((int) $grant['id'], 'export_' . $format, count($rows) . ' documenti selezionati');
            return $this->download($file['body'], $file['name'], $file['type']);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(422)->setHeader('Cache-Control', 'no-store')->setJSON([
                'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Esportazione non disponibile. Seleziona al massimo 500 fatture o 50 PDF.',
            ]);
        }
    }

    private function grantUnchanged(array $grant): bool
    {
        $current = $this->authorized((int) $grant['tenant_id']);
        // A revoke/reinvite with a narrower history while rendering must not leak the old selection.
        return $current && $current['id'] === $grant['id'] && $current['from_date'] === $grant['from_date']
            && $current['accepted_at'] === $grant['accepted_at'];
    }

    private function report(array $grant): array
    {
        $filters = AccountantAccessPolicy::filters((array) $this->request->getGet(), $grant['from_date']);
        return (new BillingReportService())->buildReportForTenant((int) $grant['tenant_id'], $filters);
    }

    private function invoicePdf(array $grant, int $id): string
    {
        $preview = (new BillingDocumentService())->buildPreviewContext((int) $grant['tenant_id'], $id);
        $preview['document']['_export_tenant_id'] = (int) $grant['tenant_id'];
        if (!AccountantAccessPolicy::documentAllowed($preview['document'] ?? [], (int) $grant['tenant_id'], $grant['from_date'])) {
            throw new \RuntimeException('Documento non autorizzato.');
        }
        return $this->pdf(view('admin/billing/document_pdf', ['preview' => $preview]), false, (int) $grant['tenant_id']);
    }

    private function zip(array $grant, array $report, string $name)
    {
        if (count($report['documents']) > 100) { throw new \RuntimeException('Massimo 100 fatture per pacchetto.'); }
        $path = tempnam(WRITEPATH . 'cache', 'accountant-');
        if ($path === false) { throw new \RuntimeException('Archivio non disponibile.'); }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Archivio non disponibile.'); }
            try {
                if (!$zip->addFromString('riepilogo.csv', (new BillingReportService())->buildAccountantCsv($report))) {
                    throw new \RuntimeException('Scrittura archivio fallita.');
                }
                foreach ($report['documents'] as $row) {
                    $id = (int) $row['id_billing_document'];
                    if (!$zip->addFromString('fattura-' . $id . '.pdf', $this->invoicePdf($grant, $id))) {
                        throw new \RuntimeException('Scrittura archivio fallita.');
                    }
                }
            } finally {
                if (!$zip->close()) { throw new \RuntimeException('Chiusura archivio fallita.'); }
            }
            if (!$this->grantUnchanged($grant)) { return $this->denied(); }
            $body = file_get_contents($path);
            if ($body === false) { throw new \RuntimeException('Archivio non disponibile.'); }
            (new AccountantDelegationService())->audit((int) $grant['id'], 'export_zip',
                $report['filters']['date_from'] . ' / ' . $report['filters']['date_to'] . ' · ' . count($report['documents']) . ' documenti');
            return $this->download($body, $name . '.zip', 'application/zip');
        } finally { if (is_file($path)) { unlink($path); } }
    }

    private function pdf(string $html, bool $landscape = false, int $tenantId = 0): string
    {
        $options = (new \App\Services\BillingPdfOptionsFactory())->create($tenantId);
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $pdf->render();
        return $pdf->output();
    }

    private function download(string $body, string $name, string $type)
    {
        return $this->response->setHeader('Content-Type', $type)->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setHeader('Cache-Control', 'no-store, private')->setHeader('X-Content-Type-Options', 'nosniff')->setBody($body);
    }

    private function identity(): ?string
    {
        return (int) session()->get('accountant_until') > time() ? session()->get('accountant_email') : null;
    }

    private function authorized(int $doctor): ?array
    {
        $email = $this->identity();
        $service = new AccountantDelegationService();
        if (!$email || !$service->ready()) { return null; }
        $grant = $service->grant($email, $doctor);
        if (!$grant || !$this->doctorEnabled($doctor)) { return null; }
        // Delegated reads never install or repair tenant schemas.
        $schema = (new \App\Services\BillingTenantSchemaService())->ensureTenantSchemaReady($doctor, false);
        return !empty($schema['ready']) ? $grant : null;
    }

    private function doctorEnabled(int $doctor): bool
    {
        return (new \App\Services\AccountantTenantAccess())->enabled($doctor);
    }

    private function denied()
    {
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode(403)->setHeader('Cache-Control', 'no-store')->setJSON(['error' => 'Delega assente, revocata o sessione scaduta.']);
        }
        return $this->response->setStatusCode(403)->setBody('Delega assente, revocata o sessione scaduta. Accedi nuovamente dall’area commercialista.');
    }

    private function invalidLink()
    {
        return redirect()->to(site_url('commercialista'))->with('error', 'Link scaduto, già utilizzato o revocato. Richiedi un nuovo accesso o un nuovo invito al medico.');
    }
}
