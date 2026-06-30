<?php

namespace App\Services;

use App\Models\PlatformUserAccessTokensModel;
use App\Models\PlatformUsersModel;
use App\Models\PlatformUserTenantsModel;
use Config\Services;

class PlatformAccessService
{
    public const SESSION_KEY_PENDING_PASSWORD_SETUP = 'platform_pending_password_setup';
    public const SESSION_TTL_SECONDS = 1800;
    public const TOKEN_TYPE_PASSWORD_SETUP = 'password_setup';
    public const TOKEN_TYPE_PASSWORD_RESET = 'password_reset';

    private PlatformUsersModel $usersModel;
    private PlatformUserTenantsModel $membershipsModel;
    private PlatformUserAccessTokensModel $tokensModel;
    private TenantCatalogService $catalog;
    private PlatformAuthService $auth;

    public function __construct()
    {
        $this->usersModel = new PlatformUsersModel();
        $this->membershipsModel = new PlatformUserTenantsModel();
        $this->tokensModel = new PlatformUserAccessTokensModel();
        $this->catalog = new TenantCatalogService();
        $this->auth = new PlatformAuthService();
    }

    /**
     * @param array<int, array<string, mixed>> $selectableTenants
     */
    public function storePendingPasswordSetup(array $platformUser, array $selectableTenants): void
    {
        session()->set(self::SESSION_KEY_PENDING_PASSWORD_SETUP, [
            'platform_user_id' => (int) ($platformUser['id_platform_user'] ?? 0),
            'email' => (string) ($platformUser['email'] ?? ''),
            'tenant_ids' => array_values(array_map(static fn(array $tenant): int => (int) ($tenant['id_tenant'] ?? 0), $selectableTenants)),
            'created_at' => time(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPendingPasswordSetup(): ?array
    {
        $pending = session()->get(self::SESSION_KEY_PENDING_PASSWORD_SETUP);
        if (!is_array($pending)) {
            return null;
        }

        $createdAt = (int) ($pending['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::SESSION_TTL_SECONDS) {
            $this->clearPendingPasswordSetup();
            return null;
        }

        $platformUserId = (int) ($pending['platform_user_id'] ?? 0);
        if ($platformUserId <= 0) {
            $this->clearPendingPasswordSetup();
            return null;
        }

        $platformUser = $this->usersModel->find($platformUserId);
        if (!$platformUser) {
            $this->clearPendingPasswordSetup();
            return null;
        }

        $memberships = $this->catalog->listTenantsForPlatformUser($platformUserId);
        $selectableTenants = $this->auth->buildSelectableTenants($memberships);

        return [
            'platform_user' => $platformUser,
            'selectable_tenants' => $selectableTenants,
            'tenant_ids' => array_values(array_map(static fn(array $tenant): int => (int) ($tenant['id_tenant'] ?? 0), $selectableTenants)),
            'created_at' => $createdAt,
        ];
    }

    public function clearPendingPasswordSetup(): void
    {
        session()->remove(self::SESSION_KEY_PENDING_PASSWORD_SETUP);
    }

    /**
     * @return array<string, mixed>
     */
    public function completePendingPasswordSetup(string $password): array
    {
        $pending = $this->getPendingPasswordSetup();
        if ($pending === null) {
            throw new \RuntimeException('La sessione di impostazione password e scaduta. Effettua di nuovo il login.');
        }

        $platformUser = (array) ($pending['platform_user'] ?? []);
        $platformUserId = (int) ($platformUser['id_platform_user'] ?? 0);
        if ($platformUserId <= 0) {
            throw new \RuntimeException('Utente piattaforma non valido.');
        }

        $updatedUser = $this->updatePlatformPassword($platformUserId, $password);
        $this->clearPendingPasswordSetup();

        return [
            'platform_user' => $updatedUser,
            'selectable_tenants' => (array) ($pending['selectable_tenants'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveToken(string $rawToken, ?string $expectedType = null): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return null;
        }

        $token = $this->tokensModel->findValidByHash(hash('sha256', $rawToken), $expectedType);
        if (!$token) {
            return null;
        }

        $platformUser = $this->usersModel->find((int) ($token['id_platform_user'] ?? 0));
        if (!$platformUser) {
            return null;
        }

        $tenant = null;
        $tenantId = (int) ($token['id_tenant'] ?? 0);
        if ($tenantId > 0) {
            $tenant = $this->catalog->getTenantById($tenantId);
        }

        return [
            'token' => $token,
            'platform_user' => $platformUser,
            'tenant' => $tenant,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function completePasswordSetupByToken(string $rawToken, string $password): array
    {
        $resolved = $this->resolveToken($rawToken);
        if ($resolved === null) {
            throw new \RuntimeException('Link non valido o scaduto.');
        }

        $token = (array) ($resolved['token'] ?? []);
        $platformUserId = (int) ($token['id_platform_user'] ?? 0);
        if ($platformUserId <= 0) {
            throw new \RuntimeException('Token accesso non valido.');
        }

        $updatedUser = $this->updatePlatformPassword($platformUserId, $password);
        $this->tokensModel->update((int) ($token['id_platform_user_access_token'] ?? 0), [
            'used_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'platform_user' => $updatedUser,
            'tenant' => $resolved['tenant'] ?? null,
            'token_type' => (string) ($token['token_type'] ?? ''),
        ];
    }

    public function requestPasswordResetByEmail(string $email): bool
    {
        $platformUser = $this->usersModel->findByEmailInsensitive($email);
        if (!$platformUser) {
            return false;
        }

        $selectableTenants = $this->auth->buildSelectableTenants(
            $this->catalog->listTenantsForPlatformUser((int) ($platformUser['id_platform_user'] ?? 0))
        );
        $isPlatformAdmin = (new PlatformAdminAccessService())->isPlatformAdmin($platformUser);

        if ($selectableTenants === [] && !$isPlatformAdmin) {
            return false;
        }

        $defaultTenant = $this->resolvePreferredTenant($selectableTenants);

        $this->sendAccessEmailForUser(
            (int) ($platformUser['id_platform_user'] ?? 0),
            (int) ($defaultTenant['id_tenant'] ?? 0),
            self::TOKEN_TYPE_PASSWORD_RESET,
            'self_service'
        );

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMembershipAccessEmail(int $membershipId, string $triggeredBy = 'admin_panel'): array
    {
        $membership = $this->membershipsModel->find($membershipId);
        if (!$membership) {
            throw new \RuntimeException('Utente dello spazio non trovato.');
        }

        $platformUserId = (int) ($membership['id_platform_user'] ?? 0);
        $tenantId = (int) ($membership['id_tenant'] ?? 0);
        if ($platformUserId <= 0 || $tenantId <= 0) {
            throw new \RuntimeException('Membership piattaforma non valida.');
        }

        return $this->sendAccessEmailForUser($platformUserId, $tenantId, null, $triggeredBy);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendAccessEmailForUser(
        int $platformUserId,
        int $tenantId = 0,
        ?string $forcedTokenType = null,
        string $triggeredBy = 'admin_panel'
    ): array {
        $platformUser = $this->usersModel->find($platformUserId);
        if (!$platformUser) {
            throw new \RuntimeException('Account piattaforma non trovato.');
        }

        $email = strtolower(trim((string) ($platformUser['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('L account piattaforma non ha una email valida.');
        }

        $isPlatformAdmin = (new PlatformAdminAccessService())->isPlatformAdmin($platformUser);
        $selectableTenants = $this->auth->buildSelectableTenants(
            $this->catalog->listTenantsForPlatformUser($platformUserId)
        );
        if ($selectableTenants === [] && !$isPlatformAdmin) {
            throw new \RuntimeException('Questo account non ha spazi cliente attivi associati.');
        }

        $tenant = $tenantId > 0 ? $this->catalog->getTenantById($tenantId) : null;
        if ($tenantId > 0 && !$tenant) {
            throw new \RuntimeException('Spazio cliente non trovato per l invio accesso.');
        }

        $tokenType = $forcedTokenType ?: $this->resolveTokenTypeForUser($platformUser);
        $ttlSeconds = $tokenType === self::TOKEN_TYPE_PASSWORD_RESET ? 7200 : 259200;
        $token = $this->createAccessToken($platformUserId, $tenantId > 0 ? $tenantId : null, $tokenType, $email, $ttlSeconds, [
            'triggered_by' => $triggeredBy,
        ]);

        helper('portal');
        $setupUrl = portal_public_access_url('login/password-imposta') . '?token=' . rawurlencode($token);
        $loginUrl = portal_public_access_url('login');
        if ($tokenType === self::TOKEN_TYPE_PASSWORD_RESET) {
            $subject = $isPlatformAdmin && $selectableTenants === []
                ? 'Reimposta la password della console AmbulatorioFacile'
                : 'Reimposta la password di accesso ad AmbulatorioFacile';
        } else {
            $subject = $isPlatformAdmin && $selectableTenants === []
                ? 'Completa l accesso alla console AmbulatorioFacile'
                : 'Completa l accesso al tuo spazio su AmbulatorioFacile';
        }

        $body = $this->buildEmailBody($platformUser, $tenant, $selectableTenants, $tokenType, $setupUrl, $loginUrl, $isPlatformAdmin);
        $this->sendEmail($email, $subject, $body);

        if ($tenantId > 0) {
            $membership = $this->membershipsModel->findMembership($platformUserId, $tenantId);
            if ($membership && (string) ($membership['invitation_status'] ?? '') !== 'accepted') {
                $this->membershipsModel->update((int) ($membership['id_platform_user_tenant'] ?? 0), [
                    'invitation_status' => 'pending',
                    'invited_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return [
            'email' => $email,
            'token_type' => $tokenType,
            'tenant' => $tenant,
            'setup_url' => $setupUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePlatformPassword(int $platformUserId, string $password): array
    {
        $platformUser = $this->usersModel->find($platformUserId);
        if (!$platformUser) {
            throw new \RuntimeException('Account piattaforma non trovato.');
        }

        $password = trim($password);
        $rules = $this->validatePasswordRules($password);
        if (!$rules['valid']) {
            throw new \InvalidArgumentException($rules['message']);
        }

        $this->usersModel->update($platformUserId, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'must_reset_password' => 0,
            'status' => 'active',
            'email_verified_at' => (string) ($platformUser['email_verified_at'] ?? '') !== ''
                ? $platformUser['email_verified_at']
                : date('Y-m-d H:i:s'),
        ]);

        return $this->usersModel->find($platformUserId) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatePasswordRules(string $password): array
    {
        $hasLen = strlen($password) >= 8;
        $hasUpper = (bool) preg_match('/[A-Z]/', $password);
        $hasLower = (bool) preg_match('/[a-z]/', $password);
        $hasSpecial = (bool) preg_match('/[^A-Za-z0-9]/', $password);
        $valid = $hasLen && $hasUpper && $hasLower && $hasSpecial;

        return [
            'valid' => $valid,
            'length' => $hasLen,
            'uppercase' => $hasUpper,
            'lowercase' => $hasLower,
            'special' => $hasSpecial,
            'message' => $valid
                ? ''
                : 'La password deve avere almeno 8 caratteri, una maiuscola, una minuscola e un carattere speciale.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $selectableTenants
     * @return array<string, mixed>|null
     */
    private function resolvePreferredTenant(array $selectableTenants): ?array
    {
        if ($selectableTenants === []) {
            return null;
        }

        foreach ($selectableTenants as $tenant) {
            if (!empty($tenant['is_default'])) {
                return $tenant;
            }
        }

        return $selectableTenants[0];
    }

    /**
     * @param array<string, mixed> $platformUser
     */
    private function resolveTokenTypeForUser(array $platformUser): string
    {
        $mustReset = (int) ($platformUser['must_reset_password'] ?? 0) === 1;
        $status = strtolower(trim((string) ($platformUser['status'] ?? 'active')));

        if ($mustReset || in_array($status, ['invited', 'pending'], true)) {
            return self::TOKEN_TYPE_PASSWORD_SETUP;
        }

        return self::TOKEN_TYPE_PASSWORD_RESET;
    }

    /**
     * @param array<string, mixed> $platformUser
     * @param array<string, mixed>|null $tenant
     * @param array<int, array<string, mixed>> $selectableTenants
     */
    private function buildEmailBody(
        array $platformUser,
        ?array $tenant,
        array $selectableTenants,
        string $tokenType,
        string $setupUrl,
        string $loginUrl,
        bool $isPlatformAdmin
    ): string {
        $fullName = trim((string) ($platformUser['first_name'] ?? '') . ' ' . (string) ($platformUser['last_name'] ?? ''));
        $greeting = $fullName !== '' ? 'Ciao ' . $fullName . ',' : 'Ciao,';
        $tenantName = trim((string) ($tenant['tenant_name'] ?? ''));
        if ($tenantName !== '') {
            $tenantLine = 'Spazio cliente: ' . $tenantName;
        } elseif ($selectableTenants !== []) {
            $tenantLine = 'Spazi cliente disponibili: ' . implode(', ', array_filter(array_map(static fn(array $row): string => (string) ($row['tenant_name'] ?? ''), $selectableTenants)));
        } elseif ($isPlatformAdmin) {
            $tenantLine = 'Accesso previsto: console piattaforma sotto /login.';
        } else {
            $tenantLine = 'Accesso disponibile dal login unico.';
        }

        $actionLabel = $tokenType === self::TOKEN_TYPE_PASSWORD_RESET
            ? 'Per reimpostare la password usa questo link:'
            : 'Per completare il primo accesso imposta la tua password da questo link:';

        if ($tokenType === self::TOKEN_TYPE_PASSWORD_RESET) {
            $note = $isPlatformAdmin
                ? 'Dopo il salvataggio potrai entrare dal login unico con la tua email e aprire la console piattaforma.'
                : 'Dopo il salvataggio potrai entrare dal login unico con la tua email.';
        } else {
            $note = $isPlatformAdmin
                ? 'Dopo il salvataggio potrai entrare dal login unico con la tua email e aprire la console piattaforma.'
                : 'Dopo il salvataggio potrai entrare dal login unico con la tua email e vedere solo i tuoi spazi.';
        }

        return implode("\n", [
            $greeting,
            '',
            'Ti abbiamo preparato l accesso ad AmbulatorioFacile.',
            $tenantLine,
            '',
            $actionLabel,
            $setupUrl,
            '',
            'Login unico:',
            $loginUrl,
            '',
            $note,
            'Se non hai richiesto tu questa email, puoi ignorarla.',
        ]);
    }

    private function sendEmail(string $to, string $subject, string $message): void
    {
        $result = (new LoginEmailService())->send(
            $to,
            $subject,
            $message,
            null,
            ['logContext' => 'platform access email']
        );

        if (empty($result['ok'])) {
            throw new \RuntimeException('Invio email non riuscito.' . (!empty($result['error']) ? ' ' . (string) $result['error'] : ''));
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createAccessToken(
        int $platformUserId,
        ?int $tenantId,
        string $tokenType,
        string $emailTo,
        int $ttlSeconds,
        array $metadata = []
    ): string {
        $platformUserId = (int) $platformUserId;
        if ($platformUserId <= 0) {
            throw new \InvalidArgumentException('Platform user non valido.');
        }

        $tenantId = $tenantId !== null && $tenantId > 0 ? $tenantId : null;
        $tokenType = trim($tokenType);
        if ($tokenType === '') {
            throw new \InvalidArgumentException('Tipo token non valido.');
        }

        $this->tokensModel
            ->where('id_platform_user', $platformUserId)
            ->where('token_type', $tokenType)
            ->where('used_at', null)
            ->set(['used_at' => date('Y-m-d H:i:s')])
            ->update();

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + max(300, $ttlSeconds));

        $tokenId = (int) $this->tokensModel->insert([
            'id_platform_user' => $platformUserId,
            'id_tenant' => $tenantId,
            'token_type' => $tokenType,
            'token_hash' => $tokenHash,
            'email_to' => $emailTo,
            'expires_at' => $expiresAt,
            'metadata_json' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);

        if ($tokenId <= 0) {
            throw new \RuntimeException('Creazione token di accesso non riuscita.');
        }

        return $rawToken;
    }
}
