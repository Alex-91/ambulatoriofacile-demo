<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Models\PlatformFeaturesModel;
use App\Services\PlatformAdminAccessService;
use App\Services\TenantFeatureService;

class PlatformFeaturesController extends BaseController
{
    private PlatformAdminAccessService $platformAdminAccess;
    private \CodeIgniter\Database\BaseConnection $platformDb;

    public function __construct()
    {
        helper('portal');
        $this->platformAdminAccess = new PlatformAdminAccessService();
        $this->platformDb = \Config\Database::connect('platform');
    }

    public function index()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        if (!portal_current_path_matches('login/piattaforma/funzioni')) {
            return redirect()->to(portal_platform_url('funzioni'));
        }

        $selectedFeatureId = (int) ($this->request->getGet('id_feature') ?? 0);
        $features = (new TenantFeatureService())->listPlatformFeatures();
        $selectedFeature = $selectedFeatureId > 0
            ? (new PlatformFeaturesModel())->find($selectedFeatureId)
            : null;

        return view('admin/platform_features', [
            'menu_items' => [],
            'features' => $features,
            'selectedFeatureId' => $selectedFeatureId,
            'selectedFeature' => $selectedFeature ?: null,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'warnings' => session()->getFlashdata('warnings') ?? [],
            'platformUser' => $this->platformAdminAccess->currentPlatformUser(),
            'platformMasterEmails' => $this->platformAdminAccess->configuredMasterEmails(),
            'legacyBootstrapMode' => $this->legacyBootstrapMode(),
            'platformBootstrapWarnings' => $this->platformBootstrapWarnings($this->legacyBootstrapMode()),
        ]);
    }

    public function save()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $model = new PlatformFeaturesModel();
        $featureId = (int) ($this->request->getPost('id_feature') ?? 0);
        $existing = $featureId > 0 ? $model->find($featureId) : null;

        try {
            if ($featureId > 0 && !$existing) {
                throw new \RuntimeException('Funzione non trovata.');
            }

            $featureKey = $existing
                ? (string) ($existing['feature_key'] ?? '')
                : $this->normalizeFeatureKey((string) $this->request->getPost('feature_key'));

            $featureName = trim((string) $this->request->getPost('feature_name'));
            if ($featureKey === '') {
                throw new \InvalidArgumentException('Chiave funzione obbligatoria.');
            }
            if ($featureName === '') {
                throw new \InvalidArgumentException('Nome funzione obbligatorio.');
            }

            $duplicate = $model->findByKey($featureKey);
            if ($duplicate && (int) ($duplicate['id_feature'] ?? 0) !== $featureId) {
                throw new \RuntimeException('Esiste gia una funzione con chiave ' . $featureKey . '.');
            }

            $payload = [
                'feature_key' => $featureKey,
                'feature_name' => $featureName,
                'feature_scope' => trim((string) $this->request->getPost('feature_scope')) ?: 'module',
                'description' => trim((string) $this->request->getPost('description')) ?: null,
                'default_enabled' => (int) ($this->request->getPost('default_enabled') ?? 0) === 1 ? 1 : 0,
                'icon_class' => trim((string) $this->request->getPost('icon_class')) ?: null,
                'is_tenant_managed' => (int) ($this->request->getPost('is_tenant_managed') ?? 0) === 1 ? 1 : 0,
                'tenant_default_enabled' => (int) ($this->request->getPost('tenant_default_enabled') ?? 0) === 1 ? 1 : 0,
                'sort_order' => (int) ($this->request->getPost('sort_order') ?? 0),
            ];

            if ($existing) {
                $model->update($featureId, $payload);
            } else {
                $featureId = (int) $model->insert($payload);
                if ($featureId <= 0) {
                    throw new \RuntimeException('Salvataggio funzione non riuscito.');
                }
            }

            return redirect()
                ->to(portal_platform_url('funzioni') . '?id_feature=' . $featureId)
                ->with('success', $existing ? 'Funzione aggiornata con successo.' : 'Funzione creata con successo.');
        } catch (\Throwable $e) {
            log_message('error', 'PlatformFeaturesController::save failed: ' . $e->getMessage());

            $redirectUrl = portal_platform_url('funzioni');
            if ($featureId > 0) {
                $redirectUrl .= '?id_feature=' . $featureId;
            }

            return redirect()
                ->to($redirectUrl)
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    private function normalizeFeatureKey(string $featureKey): string
    {
        $featureKey = strtolower(trim($featureKey));
        $featureKey = preg_replace('/[^a-z0-9_\-]/', '_', $featureKey) ?? '';
        $featureKey = preg_replace('/_+/', '_', $featureKey) ?? '';
        $featureKey = preg_replace('/\-+/', '-', $featureKey) ?? '';
        return trim($featureKey, '_-');
    }

    private function ensurePlatformAdminPage()
    {
        if ((bool) (session()->get('isLoggedInConfirmed') ?? false) !== true) {
            return redirect()->to(portal_public_access_url('login'));
        }

        if ($this->legacyBootstrapMode()) {
            return null;
        }

        if (!$this->platformAdminAccess->canAccessPlatformConsole()) {
            return redirect()->to(portal_public_access_url('login'))->with('login_error', 'Area piattaforma riservata agli account master.');
        }

        return null;
    }

    private function legacyBootstrapMode(): bool
    {
        if ($this->platformAdminAccess->canAccessPlatformConsole()) {
            return false;
        }

        if (!$this->isLegacyAdminAuthorized()) {
            return false;
        }

        return !$this->platformAdminAccess->hasPersistentPlatformAdmins();
    }

    private function isLegacyAdminAuthorized(): bool
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return false;
        }

        if (session()->get(\App\Services\TenantContextService::SESSION_KEY)) {
            return false;
        }

        return session()->get('is_admin') === true
            || (int) (session()->get('admin') ?? 0) === 1
            || (int) ($me->tipo ?? 0) === 1;
    }

    private function platformUsersCount(): int
    {
        try {
            return (int) $this->platformDb->table('platform_users')->countAllResults();
        } catch (\Throwable $e) {
            log_message('error', 'PlatformFeaturesController::platformUsersCount failed: ' . $e->getMessage());
            return PHP_INT_MAX;
        }
    }

    /**
     * @return array<int, string>
     */
    private function platformBootstrapWarnings(bool $legacyBootstrapMode): array
    {
        if (!$legacyBootstrapMode) {
            return [];
        }

        $warnings = [
            'Accesso bootstrap attivo: stai entrando con un account admin legacy per inizializzare la nuova console sotto /login.',
        ];

        if (!$this->platformAdminAccess->hasPersistentPlatformAdmins()) {
            $warnings[] = 'Non esiste ancora un account master piattaforma persistente. Creane almeno uno dal pannello degli spazi cliente prima di uscire dalla modalita bootstrap.';
        }

        if ($this->platformAdminAccess->configuredMasterEmails() === []) {
            $warnings[] = 'PLATFORM_MASTER_EMAILS non e configurata in Coolify. Va bene: da ora i master possono essere gestiti dal pannello. Usa la env solo se vuoi tenere una scorciatoia bootstrap tecnica.';
        }

        return $warnings;
    }
}
