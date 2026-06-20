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
        $rows = [];

        foreach ($this->adminAccess->configuredMasterEmails() as $email) {
            $platformUser = $this->usersModel->findByEmailInsensitive($email);
            $rows[] = $this->decorateAccountRow($email, $platformUser);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncConfiguredMasterAccounts(bool $sendAccess = false): array
    {
        $emails = $this->adminAccess->configuredMasterEmails();
        if ($emails === []) {
            throw new \RuntimeException('Configura prima PLATFORM_MASTER_EMAILS per preparare gli account master.');
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
                $account = $this->ensureMasterUser($email);

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

                $result['accounts'][] = $this->decorateAccountRow($email, (array) ($account['platform_user'] ?? []));
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
     * @return array<string, mixed>
     */
    public function sendConfiguredMasterAccess(string $email): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            throw new \InvalidArgumentException('Email master non valida.');
        }

        if (!in_array($email, $this->adminAccess->configuredMasterEmails(), true)) {
            throw new \RuntimeException('Questa email non e tra gli account master configurati.');
        }

        $account = $this->ensureMasterUser($email);
        $access = $this->platformAccess->sendAccessEmailForUser(
            (int) ($account['platform_user']['id_platform_user'] ?? 0),
            0,
            null,
            'platform_master_manual_action'
        );

        return [
            'account' => $this->decorateAccountRow($email, (array) ($account['platform_user'] ?? [])),
            'temporary_password' => (string) ($account['temporary_password'] ?? ''),
            'setup_url' => (string) ($access['setup_url'] ?? ''),
            'token_type' => (string) ($access['token_type'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $platformUser
     * @return array<string, mixed>
     */
    private function decorateAccountRow(string $email, ?array $platformUser): array
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

        return [
            'email' => $email,
            'exists' => $exists,
            'platform_user_id' => (int) ($platformUser['id_platform_user'] ?? 0),
            'status' => $status,
            'status_label' => $statusLabel,
            'needs_password_setup' => $needsPasswordSetup,
            'access_label' => $accessLabel,
            'full_name' => $fullName,
            'last_login_at' => (string) ($platformUser['last_login_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureMasterUser(string $email): array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            throw new \InvalidArgumentException('Email master non valida.');
        }

        $platformUser = $this->usersModel->findByEmailInsensitive($email);
        $temporaryPassword = '';
        $created = false;

        if (!$platformUser) {
            $temporaryPassword = $this->generateTemporaryPassword();
            $platformUserId = (int) $this->usersModel->insert([
                'email' => $email,
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'status' => 'invited',
                'must_reset_password' => 1,
                'email_verified_at' => null,
                'last_login_at' => null,
            ]);

            if ($platformUserId <= 0) {
                throw new \RuntimeException('Creazione account master non riuscita.');
            }

            $platformUser = $this->usersModel->find($platformUserId);
            $created = true;
        } elseif (trim((string) ($platformUser['password_hash'] ?? '')) === '') {
            $temporaryPassword = $this->generateTemporaryPassword();
            $this->usersModel->update((int) ($platformUser['id_platform_user'] ?? 0), [
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'status' => trim((string) ($platformUser['status'] ?? '')) !== ''
                    ? (string) $platformUser['status']
                    : 'invited',
                'must_reset_password' => 1,
            ]);
            $platformUser = $this->usersModel->find((int) ($platformUser['id_platform_user'] ?? 0));
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
