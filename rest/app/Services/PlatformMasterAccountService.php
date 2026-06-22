<?php

namespace App\Services;

use App\Models\PlatformUsersModel;

class PlatformMasterAccountService
{
    private PlatformUsersModel $usersModel;
    private PlatformAdminAccessService $adminAccess;
    private PlatformAccessService $platformAccess;

    public function __construct(
        ?PlatformUsersModel $usersModel = null,
        ?PlatformAdminAccessService $adminAccess = null,
        ?PlatformAccessService $platformAccess = null
    ) {
        $this->usersModel = $usersModel ?? new PlatformUsersModel();
        $this->adminAccess = $adminAccess ?? new PlatformAdminAccessService();
        $this->platformAccess = $platformAccess ?? new PlatformAccessService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listConfiguredMasterAccounts(): array
    {
        return $this->listManagedMasterAccounts();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listManagedMasterAccounts(): array
    {
        $rowsByEmail = [];
        $bootstrapEmails = $this->adminAccess->configuredMasterEmails();
        $hasPersistentAdmins = $this->adminAccess->hasPersistentPlatformAdmins();

        foreach ($this->adminAccess->listPersistentPlatformAdmins() as $platformUser) {
            $email = $this->normalizeEmail((string) ($platformUser['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $rowsByEmail[$email] = $this->decorateAccountRow($email, $platformUser, [
                'source' => 'database',
                'source_label' => 'Pannello',
                'is_persistent_admin' => true,
                'is_bootstrap_email' => in_array($email, $bootstrapEmails, true),
            ]);
        }

        foreach ($bootstrapEmails as $email) {
            if ($hasPersistentAdmins && !isset($rowsByEmail[$email])) {
                continue;
            }

            $platformUser = $this->usersModel->findByEmailInsensitive($email);
            if (isset($rowsByEmail[$email])) {
                $rowsByEmail[$email]['source'] = 'database+env';
                $rowsByEmail[$email]['source_label'] = 'Pannello + bootstrap';
                $rowsByEmail[$email]['is_bootstrap_email'] = true;
                continue;
            }

            $rowsByEmail[$email] = $this->decorateAccountRow($email, $platformUser, [
                'source' => 'env',
                'source_label' => 'Bootstrap Coolify',
                'is_persistent_admin' => (int) ($platformUser['is_platform_admin'] ?? 0) === 1,
                'is_bootstrap_email' => true,
            ]);
        }

        ksort($rowsByEmail);
        $persistentCount = $this->adminAccess->persistentPlatformAdminCount();
        $currentPlatformUserId = (int) (session()->get('platform_user_id') ?? 0);

        foreach ($rowsByEmail as &$row) {
            $row['is_current_user'] = (int) ($row['platform_user_id'] ?? 0) === $currentPlatformUserId;
            $row['can_revoke'] = !empty($row['is_persistent_admin'])
                && $persistentCount > 1
                && !$row['is_current_user'];
        }
        unset($row);

        return array_values($rowsByEmail);
    }

    /**
     * @return array<string, mixed>
     */
    public function syncConfiguredMasterAccounts(bool $sendAccess = false): array
    {
        $emails = $this->adminAccess->configuredMasterEmails();
        if ($emails === []) {
            throw new \RuntimeException('Non ci sono email bootstrap in Coolify. Usa il form del pannello per creare il primo master piattaforma.');
        }

        $result = [
            'accounts' => [],
            'created_count' => 0,
            'emailed_count' => 0,
            'temp_passwords' => [],
            'warnings' => [],
        ];

        foreach ($emails as $email) {
            try {
                $account = $this->ensureMasterUser($email, [
                    'promote_to_admin' => true,
                ]);

                if (!empty($account['created'])) {
                    $result['created_count']++;
                }

                $tempPassword = trim((string) ($account['temporary_password'] ?? ''));
                if ($tempPassword !== '') {
                    $result['temp_passwords'][$email] = $tempPassword;
                }

                if ($sendAccess) {
                    $this->platformAccess->sendAccessEmailForUser(
                        (int) ($account['platform_user']['id_platform_user'] ?? 0),
                        0,
                        null,
                        'platform_master_bootstrap'
                    );
                    $result['emailed_count']++;
                }

                $result['accounts'][] = $this->decorateAccountRow($email, (array) ($account['platform_user'] ?? []), [
                    'source' => 'database+env',
                    'source_label' => 'Pannello + bootstrap',
                    'is_persistent_admin' => true,
                    'is_bootstrap_email' => true,
                ]);
            } catch (\Throwable $e) {
                $result['warnings'][] = 'Account master ' . $email . ': ' . $e->getMessage();
            }
        }

        if ($result['accounts'] === [] && $result['warnings'] !== []) {
            throw new \RuntimeException(implode(' ', $result['warnings']));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveMasterAccount(array $payload, bool $sendAccess = false): array
    {
        if (!$this->adminAccess->platformAdminFlagAvailable()) {
            throw new \RuntimeException('Aggiorna prima le migration piattaforma per attivare i master gestiti da pannello.');
        }

        $email = $this->normalizeEmail((string) ($payload['email'] ?? ''));
        if ($email === '') {
            throw new \InvalidArgumentException('Email master non valida.');
        }

        $account = $this->ensureMasterUser($email, [
            'first_name' => (string) ($payload['first_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'promote_to_admin' => true,
            'force_password_reset' => (int) ($payload['force_password_reset'] ?? 0) === 1,
        ]);

        $access = [];
        if ($sendAccess) {
            $access = $this->platformAccess->sendAccessEmailForUser(
                (int) ($account['platform_user']['id_platform_user'] ?? 0),
                0,
                null,
                'platform_master_panel_save'
            );
        }

        return [
            'created' => (bool) ($account['created'] ?? false),
            'account' => $this->decorateAccountRow($email, (array) ($account['platform_user'] ?? []), [
                'source' => 'database',
                'source_label' => 'Pannello',
                'is_persistent_admin' => true,
                'is_bootstrap_email' => in_array($email, $this->adminAccess->configuredMasterEmails(), true),
            ]),
            'temporary_password' => (string) ($account['temporary_password'] ?? ''),
            'setup_url' => (string) ($access['setup_url'] ?? ''),
            'token_type' => (string) ($access['token_type'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendConfiguredMasterAccess(string $email): array
    {
        return $this->sendMasterAccess($email);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMasterAccess(string $email): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            throw new \InvalidArgumentException('Email master non valida.');
        }

        $platformUser = $this->usersModel->findByEmailInsensitive($email);
        $isBootstrapEmail = in_array($email, $this->adminAccess->configuredMasterEmails(), true);
        $isPersistentAdmin = (int) ($platformUser['is_platform_admin'] ?? 0) === 1;

        if (!$platformUser && !$isBootstrapEmail) {
            throw new \RuntimeException('Account master non trovato.');
        }

        if ($platformUser && !$isBootstrapEmail && !$isPersistentAdmin) {
            throw new \RuntimeException('Questo account non e abilitato come master piattaforma.');
        }

        $account = $this->ensureMasterUser($email, [
            'promote_to_admin' => $isBootstrapEmail || $isPersistentAdmin,
        ]);

        $access = $this->platformAccess->sendAccessEmailForUser(
            (int) ($account['platform_user']['id_platform_user'] ?? 0),
            0,
            null,
            'platform_master_manual_action'
        );

        $platformUser = (array) ($account['platform_user'] ?? []);

        return [
            'account' => $this->decorateAccountRow($email, $platformUser, [
                'source' => (int) ($platformUser['is_platform_admin'] ?? 0) === 1 ? 'database' : 'env',
                'source_label' => (int) ($platformUser['is_platform_admin'] ?? 0) === 1 ? 'Pannello' : 'Bootstrap Coolify',
                'is_persistent_admin' => (int) ($platformUser['is_platform_admin'] ?? 0) === 1,
                'is_bootstrap_email' => $isBootstrapEmail,
            ]),
            'temporary_password' => (string) ($account['temporary_password'] ?? ''),
            'setup_url' => (string) ($access['setup_url'] ?? ''),
            'token_type' => (string) ($access['token_type'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeMasterAccount(int $platformUserId): array
    {
        if (!$this->adminAccess->platformAdminFlagAvailable()) {
            throw new \RuntimeException('Aggiorna prima le migration piattaforma per revocare i master da pannello.');
        }

        if ($platformUserId <= 0) {
            throw new \InvalidArgumentException('Account master non valido.');
        }

        $platformUser = $this->usersModel->find($platformUserId);
        if (!is_array($platformUser)) {
            throw new \RuntimeException('Account master non trovato.');
        }

        if ((int) ($platformUser['is_platform_admin'] ?? 0) !== 1) {
            throw new \RuntimeException('Questo account non e marcato come master piattaforma.');
        }

        if ((int) (session()->get('platform_user_id') ?? 0) === $platformUserId) {
            throw new \RuntimeException('Non puoi revocare l accesso master all account con cui stai usando la console.');
        }

        if ($this->adminAccess->persistentPlatformAdminCount() <= 1) {
            throw new \RuntimeException('Deve restare almeno un account master piattaforma attivo.');
        }

        $this->usersModel->update($platformUserId, [
            'is_platform_admin' => 0,
        ]);

        $updated = $this->usersModel->find($platformUserId);
        if (!is_array($updated)) {
            throw new \RuntimeException('Account master non disponibile dopo la revoca.');
        }

        return [
            'account' => $this->decorateAccountRow($this->normalizeEmail((string) ($updated['email'] ?? '')), $updated, [
                'source' => 'database',
                'source_label' => 'Pannello',
                'is_persistent_admin' => false,
                'is_bootstrap_email' => in_array(
                    $this->normalizeEmail((string) ($updated['email'] ?? '')),
                    $this->adminAccess->configuredMasterEmails(),
                    true
                ),
            ]),
        ];
    }

    /**
     * @param array<string, mixed>|null $platformUser
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function decorateAccountRow(string $email, ?array $platformUser, array $extra = []): array
    {
        $platformUser = is_array($platformUser) ? $platformUser : null;
        $exists = $platformUser !== null;
        $status = strtolower(trim((string) ($platformUser['status'] ?? '')));
        $needsPasswordSetup = !$exists
            || trim((string) ($platformUser['password_hash'] ?? '')) === ''
            || (int) ($platformUser['must_reset_password'] ?? 1) === 1
            || in_array($status, ['invited', 'pending'], true);

        $statusLabel = !$exists
            ? 'Da creare'
            : ($status !== '' ? $status : 'active');

        $accessLabel = !$exists
            ? 'Da inizializzare'
            : ($needsPasswordSetup ? 'Primo accesso da completare' : 'Operativo');

        $fullName = trim((string) ($platformUser['first_name'] ?? '') . ' ' . (string) ($platformUser['last_name'] ?? ''));

        return array_merge([
            'email' => $email,
            'exists' => $exists,
            'platform_user_id' => (int) ($platformUser['id_platform_user'] ?? 0),
            'status' => $status,
            'status_label' => $statusLabel,
            'needs_password_setup' => $needsPasswordSetup,
            'access_label' => $accessLabel,
            'full_name' => $fullName,
            'last_login_at' => (string) ($platformUser['last_login_at'] ?? ''),
            'is_persistent_admin' => (int) ($platformUser['is_platform_admin'] ?? 0) === 1,
            'is_bootstrap_email' => false,
            'source' => 'database',
            'source_label' => 'Pannello',
            'can_revoke' => false,
            'is_current_user' => false,
        ], $extra);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function ensureMasterUser(string $email, array $options = []): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            throw new \InvalidArgumentException('Email master non valida.');
        }

        $firstName = trim((string) ($options['first_name'] ?? ''));
        $lastName = trim((string) ($options['last_name'] ?? ''));
        $promoteToAdmin = (bool) ($options['promote_to_admin'] ?? false);
        $forcePasswordReset = (bool) ($options['force_password_reset'] ?? false);
        $supportsPersistentAdminFlag = $this->adminAccess->platformAdminFlagAvailable();

        $platformUser = $this->usersModel->findByEmailInsensitive($email);
        $temporaryPassword = '';
        $created = false;

        if (!$platformUser) {
            $temporaryPassword = $this->generateTemporaryPassword();
            $insertPayload = [
                'email' => $email,
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name' => $lastName !== '' ? $lastName : null,
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'status' => 'invited',
                'must_reset_password' => 1,
                'email_verified_at' => null,
                'last_login_at' => null,
            ];

            if ($supportsPersistentAdminFlag) {
                $insertPayload['is_platform_admin'] = $promoteToAdmin ? 1 : 0;
            }

            $platformUserId = (int) $this->usersModel->insert($insertPayload);

            if ($platformUserId <= 0) {
                throw new \RuntimeException('Creazione account master non riuscita.');
            }

            $platformUser = $this->usersModel->find($platformUserId);
            $created = true;
        } else {
            $updatePayload = [];

            if ($firstName !== '') {
                $updatePayload['first_name'] = $firstName;
            }

            if ($lastName !== '') {
                $updatePayload['last_name'] = $lastName;
            }

            if ($promoteToAdmin && $supportsPersistentAdminFlag) {
                $updatePayload['is_platform_admin'] = 1;
            }

            if (trim((string) ($platformUser['password_hash'] ?? '')) === '' || $forcePasswordReset) {
                $temporaryPassword = $this->generateTemporaryPassword();
                $updatePayload['password_hash'] = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                $updatePayload['must_reset_password'] = 1;
                $updatePayload['status'] = 'invited';
            }

            if ($updatePayload !== []) {
                $this->usersModel->update((int) ($platformUser['id_platform_user'] ?? 0), $updatePayload);
                $platformUser = $this->usersModel->find((int) ($platformUser['id_platform_user'] ?? 0));
            }
        }

        if (!$platformUser) {
            throw new \RuntimeException('Account master non disponibile dopo il salvataggio.');
        }

        return [
            'created' => $created,
            'temporary_password' => $temporaryPassword,
            'platform_user' => $platformUser,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function generateTemporaryPassword(): string
    {
        return 'Af!' . substr(bin2hex(random_bytes(6)), 0, 12) . 'aA';
    }
}
