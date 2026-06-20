<?php

namespace App\Controllers\Login;

use App\Controllers\BaseController;
use App\Models\PlatformFeaturesModel;
use App\Models\PlatformPackagesModel;
use App\Models\PlatformTenantsModel;
use App\Services\PlatformAccessService;
use App\Services\PlatformAdminAccessService;
use App\Services\PlatformMasterAccountService;
use App\Services\TenantCatalogService;
use App\Services\TenantFeatureService;
use App\Services\TenantInfrastructureProvisioningService;
use App\Services\TenantProvisioningService;

class PlatformTenantSpacesController extends BaseController
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

        $canonicalPath = trim(service('uri')->getPath(), '/');
        if ($canonicalPath !== 'login/piattaforma/spazi-clienti') {
            $canonicalUrl = portal_platform_url('spazi-clienti');
            $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
            if ($queryString !== '') {
                $canonicalUrl .= '?' . $queryString;
            }

            return redirect()->to($canonicalUrl);
        }

        $packages = (new PlatformPackagesModel())
            ->where('is_active', 1)
            ->orderBy('package_name', 'ASC')
            ->findAll();
        $features = (new TenantFeatureService())->listPlatformFeatures();

        $filters = [
            'q' => trim((string) ($this->request->getGet('q') ?? '')),
            'status' => trim((string) ($this->request->getGet('status') ?? '')),
            'package' => trim((string) ($this->request->getGet('package') ?? '')),
        ];

        $selectedTenantId = (int) ($this->request->getGet('id_tenant') ?? 0);
        $provisioning = new TenantProvisioningService();
        $tenantRows = $this->queryTenantRows($filters['q'], $filters['status'], $filters['package']);
        $selectedTenant = $selectedTenantId > 0
            ? $this->loadTenantDetail($selectedTenantId)
            : null;
        $legacyBootstrapMode = $this->legacyBootstrapMode();
        $masterAccounts = (new PlatformMasterAccountService())->listConfiguredMasterAccounts();

        return view('admin/tenant_spaces', [
            'menu_items' => [],
            'packages' => $packages,
            'features' => $features,
            'filters' => $filters,
            'tenantRows' => $tenantRows,
            'selectedTenantId' => $selectedTenantId,
            'selectedTenant' => $selectedTenant,
            'tenantMembers' => $selectedTenantId > 0 ? $provisioning->listTenantMembers($selectedTenantId) : [],
            'tenantCapacity' => $selectedTenantId > 0 ? $provisioning->getTenantUserCapacity($selectedTenantId) : [],
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'tempPassword' => session()->getFlashdata('tenant_master_temp_password'),
            'memberSuccess' => session()->getFlashdata('member_success'),
            'memberErrors' => session()->getFlashdata('member_errors') ?? [],
            'memberTempPassword' => session()->getFlashdata('tenant_member_temp_password'),
            'warnings' => session()->getFlashdata('warnings') ?? [],
            'memberWarnings' => session()->getFlashdata('member_warnings') ?? [],
            'masterAccountWarnings' => session()->getFlashdata('master_account_warnings') ?? [],
            'masterTempPasswords' => session()->getFlashdata('platform_master_temp_passwords') ?? [],
            'platformUser' => $this->platformAdminAccess->currentPlatformUser(),
            'platformMasterEmails' => $this->platformAdminAccess->configuredMasterEmails(),
            'platformMasterAccounts' => $masterAccounts,
            'legacyBootstrapMode' => $legacyBootstrapMode,
            'platformBootstrapWarnings' => $this->platformBootstrapWarnings($legacyBootstrapMode),
        ]);
    }

    public function syncMasterAccounts()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $sendAccess = (int) ($this->request->getPost('send_access') ?? 0) === 1;

        try {
            $result = (new PlatformMasterAccountService())->syncConfiguredMasterAccounts($sendAccess);

            $messages = [];
            if ((int) ($result['created_count'] ?? 0) > 0) {
                $messages[] = 'Account master creati: ' . (int) ($result['created_count'] ?? 0) . '.';
            } else {
                $messages[] = 'Gli account master configurati erano gia pronti.';
            }

            if ($sendAccess) {
                $messages[] = 'Email di accesso inviate: ' . (int) ($result['emailed_count'] ?? 0) . '.';
            }

            $redirect = redirect()
                ->to($this->tenantSpacesUrl())
                ->with('success', implode(' ', $messages));

            $tempPasswords = is_array($result['temp_passwords'] ?? null) ? $result['temp_passwords'] : [];
            if ($tempPasswords !== []) {
                $redirect = $redirect->with('platform_master_temp_passwords', $tempPasswords);
            }

            $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
            if ($warnings !== []) {
                $redirect = $redirect->with('master_account_warnings', $warnings);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'PlatformTenantSpacesController::syncMasterAccounts failed: ' . $e->getMessage());

            return redirect()
                ->to($this->tenantSpacesUrl())
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function sendMasterAccess()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $email = (string) ($this->request->getPost('email') ?? '');

        try {
            $result = (new PlatformMasterAccountService())->sendConfiguredMasterAccess($email);
            $messages = ['Email di accesso inviata a ' . (string) (($result['account']['email'] ?? $email)) . '.'];

            $redirect = redirect()
                ->to($this->tenantSpacesUrl())
                ->with('success', implode(' ', $messages));

            $tempPassword = trim((string) ($result['temporary_password'] ?? ''));
            if ($tempPassword !== '') {
                $redirect = $redirect->with('platform_master_temp_passwords', [
                    (string) (($result['account']['email'] ?? $email)) => $tempPassword,
                ]);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'PlatformTenantSpacesController::sendMasterAccess failed: ' . $e->getMessage());

            return redirect()
                ->to($this->tenantSpacesUrl())
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function save()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = (int) ($this->request->getPost('id_tenant') ?? 0);
        $featureKeys = $this->allFeatureKeys();
        $enabledFeatures = array_values(array_filter(array_map(
            static fn($value): string => trim(strtolower((string) $value)),
            (array) $this->request->getPost('enabled_features')
        )));
        $enabledFeatures = array_values(array_unique(array_intersect($enabledFeatures, $featureKeys)));
        $disabledFeatures = array_values(array_diff($featureKeys, $enabledFeatures));

        $payload = [
            'tenant_key' => (string) $this->request->getPost('tenant_key'),
            'tenant_name' => (string) $this->request->getPost('tenant_name'),
            'legal_name' => (string) $this->request->getPost('legal_name'),
            'package_code' => (string) $this->request->getPost('package_code'),
            'status' => (string) $this->request->getPost('status'),
            'onboarding_status' => (string) $this->request->getPost('onboarding_status'),
            'login_hint' => (string) $this->request->getPost('login_hint'),
            'feature_profile' => (string) $this->request->getPost('feature_profile'),
            'storage_key' => (string) $this->request->getPost('storage_key'),
            'db_host' => (string) $this->request->getPost('db_host'),
            'db_port' => (int) ($this->request->getPost('db_port') ?? 3306),
            'db_name' => (string) $this->request->getPost('db_name'),
            'db_username' => (string) $this->request->getPost('db_username'),
            'db_password_ref' => (string) $this->request->getPost('db_password_ref'),
            'db_driver' => (string) $this->request->getPost('db_driver'),
            'db_prefix' => (string) $this->request->getPost('db_prefix'),
            'master_email' => (string) $this->request->getPost('master_email'),
            'master_first_name' => (string) $this->request->getPost('master_first_name'),
            'master_last_name' => (string) $this->request->getPost('master_last_name'),
            'master_password' => (string) $this->request->getPost('master_password'),
            'app_user_id' => (int) ($this->request->getPost('master_app_user_id') ?? 0),
            'enabled_features' => $enabledFeatures,
            'disabled_features' => $disabledFeatures,
            'is_active' => (int) ($this->request->getPost('is_active') ?? 0) === 1 ? 1 : 0,
        ];

        $service = new TenantProvisioningService();

        try {
            $result = $tenantId > 0
                ? $service->updateTenant($tenantId, $payload)
                : $service->createTenant($payload);

            $savedTenant = (array) ($result['tenant'] ?? []);
            $savedTenantId = (int) ($savedTenant['id_tenant'] ?? 0);

            if ((int) ($this->request->getPost('prepare_local_dirs') ?? 0) === 1 && $savedTenantId > 0) {
                $service->prepareLocalDirectories(
                    (string) ($savedTenant['tenant_key'] ?? ''),
                    (string) ($savedTenant['storage_key'] ?? '')
                );
            }

            $messages = [$tenantId > 0
                ? 'Spazio cliente aggiornato con successo.'
                : 'Spazio cliente creato con successo.'];
            $warnings = [];
            $tenantAppSync = (array) ($result['tenant_app_sync'] ?? []);

            $tempPassword = (string) (($result['platform_user']['temporary_password'] ?? '') ?: '');
            if ($tempPassword !== '') {
                session()->setFlashdata('tenant_master_temp_password', $tempPassword);
            }

            if (in_array((string) ($tenantAppSync['status'] ?? ''), ['skipped', 'error'], true)) {
                $warnings[] = 'Spazio cliente salvato, ma il profilo applicativo del tenant master non e ancora pronto: ' . (string) ($tenantAppSync['message'] ?? 'sincronizzazione rimandata');
            }

            if ((int) ($this->request->getPost('send_master_access_email') ?? 0) === 1) {
                try {
                    (new PlatformAccessService())->sendMembershipAccessEmail((int) ($result['membership']['id_platform_user_tenant'] ?? 0), 'platform_console_master_save');
                    $messages[] = 'Email di accesso inviata al tenant master.';
                } catch (\Throwable $e) {
                    $warnings[] = 'Salvataggio completato, ma l invio accesso master non e riuscito: ' . $e->getMessage();
                }
            }

            if ((int) ($this->request->getPost('provision_after_save') ?? 0) === 1 && $savedTenantId > 0) {
                try {
                    $provision = (new TenantInfrastructureProvisioningService())->provisionTenantInfrastructure($savedTenantId);
                    $messages[] = 'Provisioning tecnico completato (' . (string) ($provision['template_mode'] ?? 'ok') . ').';
                } catch (\Throwable $e) {
                    $warnings[] = 'Salvataggio completato, ma il provisioning tecnico non e riuscito: ' . $e->getMessage();
                }
            }

            $redirect = redirect()
                ->to($this->tenantSpacesUrl($savedTenantId))
                ->with('success', implode(' ', $messages));

            if ($warnings !== []) {
                $redirect = $redirect->with('warnings', $warnings);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'PlatformTenantSpacesController::save failed: ' . $e->getMessage());

            return redirect()
                ->to($this->tenantSpacesUrl($tenantId))
                ->withInput()
                ->with('errors', ['generic' => $e->getMessage()]);
        }
    }

    public function saveMember()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = (int) ($this->request->getPost('id_tenant') ?? 0);
        $payload = [
            'id_platform_user_tenant' => (int) ($this->request->getPost('member_id_platform_user_tenant') ?? 0),
            'email' => (string) $this->request->getPost('member_email'),
            'first_name' => (string) $this->request->getPost('member_first_name'),
            'last_name' => (string) $this->request->getPost('member_last_name'),
            'tenant_role' => (string) $this->request->getPost('member_tenant_role'),
            'app_user_id' => (int) ($this->request->getPost('member_app_user_id') ?? 0),
            'password' => (string) $this->request->getPost('member_password'),
            'is_default' => (int) ($this->request->getPost('member_is_default') ?? 0) === 1 ? 1 : 0,
        ];

        $service = new TenantProvisioningService();

        try {
            $result = $service->saveTenantMember($tenantId, $payload);
            $messages = [];
            $warnings = [];
            $tenantAppSync = (array) ($result['tenant_app_sync'] ?? []);
            $tempPassword = (string) (($result['platform_user']['temporary_password'] ?? '') ?: '');
            if ($tempPassword !== '') {
                session()->setFlashdata('tenant_member_temp_password', $tempPassword);
            }

            $messages[] = (($result['mode'] ?? 'created') === 'updated')
                ? 'Utente dello spazio aggiornato con successo.'
                : 'Utente dello spazio aggiunto con successo.';

            if (in_array((string) ($tenantAppSync['status'] ?? ''), ['skipped', 'error'], true)) {
                $warnings[] = 'Utente salvato, ma il profilo applicativo del tenant non e ancora pronto: ' . (string) ($tenantAppSync['message'] ?? 'sincronizzazione rimandata');
            }

            if ((int) ($this->request->getPost('member_send_access_email') ?? 0) === 1) {
                try {
                    (new PlatformAccessService())->sendMembershipAccessEmail((int) ($result['membership']['id_platform_user_tenant'] ?? 0), 'platform_console_member_save');
                    $messages[] = 'Email di accesso inviata all utente dello spazio.';
                } catch (\Throwable $e) {
                    $warnings[] = 'Utente salvato, ma l invio accesso non e riuscito: ' . $e->getMessage();
                }
            }

            $redirect = redirect()
                ->to($this->tenantSpacesUrl($tenantId, '#tenant-members'))
                ->with('member_success', implode(' ', $messages));

            if ($warnings !== []) {
                $redirect = $redirect->with('member_warnings', $warnings);
            }

            return $redirect;
        } catch (\Throwable $e) {
            log_message('error', 'PlatformTenantSpacesController::saveMember failed: ' . $e->getMessage());

            return redirect()
                ->to($this->tenantSpacesUrl($tenantId, '#tenant-members'))
                ->withInput()
                ->with('member_errors', ['generic' => $e->getMessage()]);
        }
    }

    public function sendMemberAccess()
    {
        if ($guard = $this->ensurePlatformAdminPage()) {
            return $guard;
        }

        $tenantId = (int) ($this->request->getPost('id_tenant') ?? 0);
        $membershipId = (int) ($this->request->getPost('member_id_platform_user_tenant') ?? 0);

        try {
            (new PlatformAccessService())->sendMembershipAccessEmail($membershipId, 'platform_console_member_action');

            return redirect()
                ->to($this->tenantSpacesUrl($tenantId, '#tenant-members'))
                ->with('member_success', 'Email di accesso inviata con successo.');
        } catch (\Throwable $e) {
            log_message('error', 'PlatformTenantSpacesController::sendMemberAccess failed: ' . $e->getMessage());

            return redirect()
                ->to($this->tenantSpacesUrl($tenantId, '#tenant-members'))
                ->with('member_errors', ['generic' => $e->getMessage()]);
        }
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

        if ($this->platformUsersCount() === 0) {
            return true;
        }

        return $this->platformAdminAccess->configuredMasterEmails() === [];
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
            log_message('error', 'PlatformTenantSpacesController::platformUsersCount failed: ' . $e->getMessage());
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

        if ($this->platformUsersCount() === 0) {
            $warnings[] = 'Il catalogo piattaforma e ancora vuoto. Crea il primo spazio usando la tua email o quella del tuo socio come tenant master per generare il primo account piattaforma.';
        }

        if ($this->platformAdminAccess->configuredMasterEmails() === []) {
            $warnings[] = 'In Coolify manca ancora PLATFORM_MASTER_EMAILS. Finche non viene configurata, la console resta in modalita bootstrap.';
        }

        return $warnings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryTenantRows(string $query = '', string $status = '', string $packageCode = '', int $limit = 80): array
    {
        $builder = $this->platformDb->table('platform_tenants t');
        $builder->select("
            t.id_tenant,
            t.tenant_key,
            t.tenant_name,
            t.legal_name,
            t.status,
            t.onboarding_status,
            t.storage_key,
            t.feature_profile,
            t.is_active,
            p.package_code,
            p.package_name,
            MAX(CASE WHEN put.is_owner = 1 THEN u.email ELSE '' END) AS owner_email
        ", false);
        $builder->join('platform_packages p', 'p.id_package = t.id_package', 'left');
        $builder->join('platform_user_tenants put', 'put.id_tenant = t.id_tenant', 'left');
        $builder->join('platform_users u', 'u.id_platform_user = put.id_platform_user', 'left');

        if ($query !== '') {
            $builder->groupStart()
                ->like('t.tenant_name', $query)
                ->orLike('t.tenant_key', $query)
                ->orLike('t.legal_name', $query)
                ->orLike('u.email', $query)
                ->groupEnd();
        }

        if ($status !== '') {
            $builder->where('t.status', $status);
        }

        if ($packageCode !== '') {
            $builder->where('p.package_code', $packageCode);
        }

        return $builder
            ->groupBy('t.id_tenant, t.tenant_key, t.tenant_name, t.legal_name, t.status, t.onboarding_status, t.storage_key, t.feature_profile, t.is_active, p.package_code, p.package_name')
            ->orderBy('t.updated_at', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTenantDetail(int $tenantId): ?array
    {
        $tenant = (new PlatformTenantsModel())->find($tenantId);
        if (!$tenant) {
            return null;
        }

        $package = null;
        if ((int) ($tenant['id_package'] ?? 0) > 0) {
            $package = (new PlatformPackagesModel())->find((int) $tenant['id_package']);
        }

        $owner = $this->platformDb->table('platform_user_tenants put')
            ->select('put.*, u.email, u.first_name, u.last_name')
            ->join('platform_users u', 'u.id_platform_user = put.id_platform_user')
            ->where('put.id_tenant', $tenantId)
            ->where('put.is_owner', 1)
            ->orderBy('put.is_default', 'DESC')
            ->get(1)
            ->getRowArray();

        $featureMap = (new TenantCatalogService())->resolveFeatureMapForTenant($tenantId);
        $explicitOverrides = $this->platformDb->table('platform_tenant_features tf')
            ->select('f.feature_key, tf.is_enabled')
            ->join('platform_features f', 'f.id_feature = tf.id_feature')
            ->where('tf.id_tenant', $tenantId)
            ->get()
            ->getResultArray();

        $overrideMap = [];
        foreach ($explicitOverrides as $row) {
            $overrideMap[(string) ($row['feature_key'] ?? '')] = (int) ($row['is_enabled'] ?? 0) === 1;
        }

        return [
            'tenant' => $tenant,
            'package' => $package,
            'owner' => $owner ?: null,
            'feature_map' => $featureMap,
            'override_map' => $overrideMap,
            'runtime' => (new TenantProvisioningService())->buildRuntimeBlueprint($tenant),
            'metadata' => $this->decodeMetadata((string) ($tenant['metadata_json'] ?? '')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allFeatureKeys(): array
    {
        $rows = (new TenantFeatureService())->listPlatformFeatures();

        $keys = [];
        foreach ($rows as $row) {
            $featureKey = trim((string) ($row['feature_key'] ?? ''));
            if ($featureKey !== '') {
                $keys[] = $featureKey;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tenantSpacesUrl(int $tenantId = 0, string $suffix = ''): string
    {
        $url = portal_platform_url('spazi-clienti');
        if ($tenantId > 0) {
            $url .= '?id_tenant=' . $tenantId;
        }

        return $url . $suffix;
    }
}
