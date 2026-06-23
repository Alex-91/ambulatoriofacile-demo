<?php

namespace App\Services;

class DemoAccessService
{
    public const SESSION_KEY_ACTIVE = 'demo_public_session';
    public const SESSION_KEY_CURRENT = 'demo_public_current_account';
    public const SESSION_KEY_SWITCH_ACCOUNTS = 'demo_public_switch_accounts';

    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function isPublicAccessEnabled(): bool
    {
        $raw = env('DEMO_PUBLIC_ROLE_SWITCH_ENABLED');
        if ($raw === null || $raw === false || trim((string) $raw) === '') {
            $raw = env('demo.publicRoleSwitchEnabled');
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentationAccounts(): array
    {
        return [
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli principali',
                'group_note' => 'Un click, nessuna credenziale da inserire, cambio ruolo sempre disponibile dal menu utente della demo.',
                'role' => 'Admin demo',
                'username' => 'demo.admin',
                'candidate_usernames' => ['demo.admin'],
                'label' => 'Admin demo Conti Giulia',
                'note' => 'Vista completa per aprire la demo, mostrare moduli, schede, configurazioni e quadro generale del prodotto.',
                'otp' => '',
                'redirect_route' => 'admin',
                'scenarios' => ['overview prodotto', 'moduli e ruoli', 'configurazione'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli principali',
                'group_note' => 'Un click, nessuna credenziale da inserire, cambio ruolo sempre disponibile dal menu utente della demo.',
                'role' => 'Segreteria',
                'username' => 'demo.segreteria',
                'candidate_usernames' => ['demo.segreteria', 'demo.frontdesk.med'],
                'label' => 'Segreteria Colombo Sara',
                'note' => 'Perfetta per agenda del giorno, conferme, spostamenti, promemoria e presa appuntamenti per altri professionisti.',
                'otp' => '2510',
                'redirect_route' => '',
                'scenarios' => ['agenda operativa', 'cross booking', 'reminder'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli principali',
                'group_note' => 'Un click, nessuna credenziale da inserire, cambio ruolo sempre disponibile dal menu utente della demo.',
                'role' => 'Dietista',
                'username' => 'demo.dietista',
                'candidate_usernames' => ['demo.dietista', 'demo.cardiologia'],
                'label' => 'Dietista Rossi Elena',
                'note' => 'Mostra il lato professionista: agenda personale, schede, posta, chat e continuita del percorso paziente.',
                'otp' => '2510',
                'redirect_route' => 'agenda',
                'scenarios' => ['agenda professionista', 'posta e chat', 'follow-up'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli principali',
                'group_note' => 'Un click, nessuna credenziale da inserire, cambio ruolo sempre disponibile dal menu utente della demo.',
                'role' => 'Collaboratrice',
                'username' => 'demo.nutrizionista',
                'candidate_usernames' => ['demo.nutrizionista'],
                'label' => 'Biologa nutrizionista Riva Marta',
                'note' => 'Utile per verificare visibilita differenziate, convivenza tra piu professionisti e gestione interna degli appuntamenti.',
                'otp' => '',
                'redirect_route' => 'agenda',
                'scenarios' => ['permessi differenziati', 'secondo professionista', 'agenda condivisa'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli principali',
                'group_note' => 'Un click, nessuna credenziale da inserire, cambio ruolo sempre disponibile dal menu utente della demo.',
                'role' => 'Portale paziente',
                'username' => 'demo.portal.nutri',
                'candidate_usernames' => ['demo.portal.nutri', 'demo.portal.med'],
                'label' => 'Bianchi Laura',
                'note' => 'Chiude la demo dal lato paziente e fa vedere il percorso esterno controllato con dati fittizi.',
                'otp' => '',
                'redirect_route' => '',
                'scenarios' => ['area paziente', 'continuita', 'visione esterna'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso demo parallelo utile per mostrare che la stessa base regge studi, centri e team con taglio diverso.',
                'role' => 'Fisioterapista',
                'username' => 'demo.fisio1',
                'candidate_usernames' => ['demo.fisio1'],
                'label' => 'Fisioterapista Riva Marco',
                'note' => 'Percorso sport rehab con agenda dedicata, visite e pazienti del centro.',
                'otp' => '',
                'redirect_route' => 'agenda',
                'scenarios' => ['sport rehab', 'agenda fisioterapia', 'presa in carico'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso demo parallelo utile per mostrare che la stessa base regge studi, centri e team con taglio diverso.',
                'role' => 'Osteopata',
                'username' => 'demo.osteopata',
                'candidate_usernames' => ['demo.osteopata'],
                'label' => 'Osteopata Pace Lorenzo',
                'note' => 'Secondo professionista del centro sport per verificare convivenza e distribuzione operativa tra operatori.',
                'otp' => '',
                'redirect_route' => 'agenda',
                'scenarios' => ['secondo operatore', 'team sport', 'pazienti condivisi'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso demo parallelo utile per mostrare che la stessa base regge studi, centri e team con taglio diverso.',
                'role' => 'Front desk sport',
                'username' => 'demo.frontdesk.sport',
                'candidate_usernames' => ['demo.frontdesk.sport'],
                'label' => 'Coordinamento Sala Irene',
                'note' => 'Mostra coordinamento operativo, assegnazione slot e gestione delle richieste del centro.',
                'otp' => '',
                'redirect_route' => '',
                'scenarios' => ['front desk', 'coordinamento', 'agenda centro'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso demo parallelo utile per mostrare che la stessa base regge studi, centri e team con taglio diverso.',
                'role' => 'Paziente sport',
                'username' => 'demo.portal.sport',
                'candidate_usernames' => ['demo.portal.sport'],
                'label' => 'Marini Chiara',
                'note' => 'Vista paziente del percorso sportivo, utile per chiudere la prova anche sul secondo scenario.',
                'otp' => '',
                'redirect_route' => '',
                'scenarios' => ['area paziente sport', 'visione esterna', 'percorso completo'],
            ],
        ];
    }

    public function buildEntryUrl(string $username): string
    {
        helper('url');

        return site_url('demo/entra') . '?' . http_build_query([
            'u' => trim($username),
        ]);
    }

    public function accessLandingUrl(): string
    {
        helper('url');

        return site_url('access');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentationAccount(string $requestedUsername): ?array
    {
        $requestedUsername = $this->normalizeUsername($requestedUsername);
        if ($requestedUsername === '') {
            return null;
        }

        foreach ($this->presentationAccounts() as $account) {
            $candidates = $this->normalizedCandidateUsernames($account);
            if (in_array($requestedUsername, $candidates, true)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function loginAccount(string $requestedUsername): array
    {
        if (!$this->isPublicAccessEnabled()) {
            throw new \RuntimeException('Accesso demo diretto non abilitato.');
        }

        $account = $this->findPresentationAccount($requestedUsername);
        if ($account === null) {
            throw new \RuntimeException('Ruolo demo non disponibile.');
        }

        $resolvedUsername = $this->resolveExistingUsernameForAccount($account);
        if ($resolvedUsername === null) {
            throw new \RuntimeException('Account demo non trovato nel database attivo.');
        }

        $user = $this->findLegacyUserByUsername($resolvedUsername);
        if ($user === null) {
            throw new \RuntimeException('Account demo non trovato nel database attivo.');
        }

        $result = (new LegacyLoginHandoffService())->bootstrapDemoSessionByUserId(
            (int) ($user['id_user'] ?? 0),
            $resolvedUsername
        );

        if (($result['resp'] ?? 'KO') !== 'OK') {
            throw new \RuntimeException('Impossibile aprire la sessione demo richiesta.');
        }

        $this->decorateCurrentSession($account, $resolvedUsername);

        return [
            'account' => $account,
            'redirectUrl' => $this->resolveRedirectUrl($account, $result),
            'resolved_username' => $resolvedUsername,
        ];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $result
     */
    private function resolveRedirectUrl(array $account, array $result): string
    {
        helper('url');

        $fallback = trim((string) ($account['redirect_route'] ?? ''));
        $serviceRedirect = trim((string) ($result['redirectUrl'] ?? ''));

        if ($serviceRedirect !== '' && $serviceRedirect !== 'auth') {
            return site_url($serviceRedirect);
        }

        if ($fallback !== '') {
            return site_url($fallback);
        }

        return site_url('/');
    }

    /**
     * @param array<string, mixed> $account
     */
    private function decorateCurrentSession(array $account, string $resolvedUsername): void
    {
        $currentAccount = [
            'username' => (string) ($account['username'] ?? ''),
            'session_username' => $resolvedUsername,
            'role' => (string) ($account['role'] ?? ''),
            'label' => (string) ($account['label'] ?? ''),
            'note' => (string) ($account['note'] ?? ''),
            'access_url' => $this->accessLandingUrl(),
        ];

        $switchAccounts = [];
        foreach ($this->presentationAccounts() as $candidate) {
            $switchAccounts[] = [
                'username' => (string) ($candidate['username'] ?? ''),
                'role' => (string) ($candidate['role'] ?? ''),
                'label' => (string) ($candidate['label'] ?? ''),
                'entry_url' => $this->buildEntryUrl((string) ($candidate['username'] ?? '')),
                'is_current' => (string) ($candidate['username'] ?? '') === (string) ($account['username'] ?? ''),
            ];
        }

        session()->set([
            self::SESSION_KEY_ACTIVE => true,
            self::SESSION_KEY_CURRENT => $currentAccount,
            self::SESSION_KEY_SWITCH_ACCOUNTS => $switchAccounts,
            'loginSource' => 'demo_public_access',
        ]);
    }

    /**
     * @param array<string, mixed> $account
     * @return list<string>
     */
    private function normalizedCandidateUsernames(array $account): array
    {
        $candidates = $account['candidate_usernames'] ?? [];
        if (!is_array($candidates) || $candidates === []) {
            $candidates = [(string) ($account['username'] ?? '')];
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeUsername((string) $candidate);
            if ($candidate === '' || in_array($candidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function resolveExistingUsernameForAccount(array $account): ?string
    {
        foreach ($this->normalizedCandidateUsernames($account) as $candidate) {
            if ($this->findLegacyUserByUsername($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLegacyUserByUsername(string $username): ?array
    {
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            return null;
        }

        $query = $this->db->table('dap01_users')
            ->select('id_user, username, tipo_user, datascadenza')
            ->where('username', $username)
            ->get(1);

        if ($query === false) {
            return null;
        }

        $row = $query->getRowArray();
        return is_array($row) ? $row : null;
    }

    private function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }
}
