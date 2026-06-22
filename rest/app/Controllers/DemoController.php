<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class DemoController extends Controller
{
    private const UNIFIED_DEMO_KEY = 'ambulatoriofacile_demo';
    private const UNIFIED_DEMO_LABEL = 'Demo AmbulatorioFacile';

    /**
     * @var list<string>
     */
    protected $helpers = ['url', 'portal'];

    public function index()
    {
        $runtimeReport = $this->loadLatestReport('demo_runtime_*.json');
        $seedReport = $this->loadLatestReport('demo_seed_*.json');
        $isLocal = $this->isLocalHost();
        $demoAccounts = $this->demoAccounts($seedReport);

        return view('demo/showcase', [
            'brandName' => 'AmbulatorioFacile',
            'brandDescription' => 'Piattaforma operativa per prenotazioni, comunicazione e notifiche.',
            'runtimeStatus' => $this->buildRuntimeStatus($runtimeReport),
            'seedStatus' => $this->buildSeedStatus($seedReport),
            'demoAccounts' => $demoAccounts,
            'demoAccountGroups' => $this->demoAccountGroups($demoAccounts),
            'demoCredentials' => $this->demoCredentialsSummary($seedReport),
            'demoChecklist' => $this->demoChecklist(),
            'showLocalAccess' => $isLocal,
            'showTechnicalStatus' => $isLocal,
            'commercialHighlights' => $this->commercialHighlights(),
            'commercialPackages' => $this->commercialPackages(),
        ]);
    }

    public function vertical(string $profileId)
    {
        return redirect()->to(site_url('/'));
    }

    public function access(?string $profileId = null)
    {
        $seedReport = $this->loadLatestReport('demo_seed_*.json');
        $isLocal = $this->isLocalHost();
        $demoAccounts = $this->demoAccounts($seedReport);

        return view('demo/access', [
            'brandName' => 'AmbulatorioFacile',
            'brandDescription' => 'Piattaforma operativa per prenotazioni, comunicazione e notifiche.',
            'demoLabel' => self::UNIFIED_DEMO_LABEL,
            'showLocalAccess' => $isLocal,
            'showTechnicalStatus' => $isLocal,
            'demoAccounts' => $demoAccounts,
            'demoAccountGroups' => $this->demoAccountGroups($demoAccounts),
            'seedStatus' => $this->buildSeedStatus($seedReport),
            'demoCredentials' => $this->demoCredentialsSummary($seedReport),
            'demoChecklist' => $this->demoChecklist(),
        ]);
    }

    public function requestDemo()
    {
        $isLocal = $this->isLocalHost();
        $feedback = session()->getFlashdata('demo_request_feedback');
        $errors = session()->getFlashdata('demo_request_errors');
        $old = session()->getFlashdata('demo_request_old');

        return view('demo/request', [
            'brandName' => 'AmbulatorioFacile',
            'brandDescription' => 'Piattaforma operativa per prenotazioni, comunicazione e notifiche.',
            'showLocalAccess' => $isLocal,
            'showTechnicalStatus' => $isLocal,
            'demoRequestContext' => $this->unifiedDemoRequestContext(),
            'demoCredentials' => $this->demoCredentialsSummary($this->loadLatestReport('demo_seed_*.json')),
            'requestFeedback' => is_array($feedback) ? $feedback : null,
            'requestErrors' => is_array($errors) ? $errors : [],
            'requestOld' => is_array($old) ? $old : [],
        ]);
    }

    public function submitDemoRequest()
    {
        $demoContext = $this->unifiedDemoRequestContext();
        $payload = [
            'full_name' => trim((string) $this->request->getPost('full_name')),
            'business_name' => trim((string) $this->request->getPost('business_name')),
            'email' => trim((string) $this->request->getPost('email')),
            'phone' => trim((string) $this->request->getPost('phone')),
            'contact_role' => trim((string) $this->request->getPost('contact_role')),
            'vertical' => self::UNIFIED_DEMO_KEY,
            'team_size' => trim((string) $this->request->getPost('team_size')),
            'preferred_slot' => trim((string) $this->request->getPost('preferred_slot')),
            'notes' => trim((string) $this->request->getPost('notes')),
            'privacy' => (string) $this->request->getPost('privacy'),
            'website' => trim((string) $this->request->getPost('website')),
        ];

        $errors = $this->validateDemoRequestPayload($payload);
        if ($errors !== []) {
            session()->setFlashdata('demo_request_errors', $errors);
            session()->setFlashdata('demo_request_old', $payload);
            session()->setFlashdata('demo_request_feedback', [
                'ok' => false,
                'message' => 'Controlla i campi evidenziati e riprova.',
            ]);

            return redirect()->to($this->demoRequestReturnUrl($payload['vertical']))->withInput();
        }

        if ($payload['website'] !== '') {
            session()->setFlashdata('demo_request_feedback', [
                'ok' => true,
                'message' => 'Richiesta registrata correttamente.',
            ]);

            return redirect()->to($this->demoRequestReturnUrl($payload['vertical']));
        }

        try {
            $requestId = 'demo-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
            $record = [
                'request_id' => $requestId,
                'created_at' => date('c'),
                'brand' => 'AmbulatorioFacile',
                'vertical' => $payload['vertical'],
                'vertical_label' => (string) ($demoContext['label'] ?? self::UNIFIED_DEMO_LABEL),
                'full_name' => $payload['full_name'],
                'business_name' => $payload['business_name'],
                'email' => strtolower($payload['email']),
                'phone' => $payload['phone'],
                'contact_role' => $payload['contact_role'],
                'team_size' => $payload['team_size'],
                'preferred_slot' => $payload['preferred_slot'],
                'notes' => $payload['notes'],
                'source' => 'demo-guided-request',
                'ip' => (string) $this->request->getIPAddress(),
                'user_agent' => substr((string) ($this->request->getServer('HTTP_USER_AGENT') ?? ''), 0, 255),
                'notification' => [
                    'status' => 'pending',
                    'attempted' => false,
                    'sent' => false,
                    'recipients' => [],
                    'message' => '',
                    'checked_at' => '',
                ],
            ];

            $saveResult = $this->storeDemoRequest($record);
        } catch (\Throwable $e) {
            log_message('error', '[DemoController::submitDemoRequest] salvataggio lead demo fallito: {message}', [
                'message' => $e->getMessage(),
            ]);

            session()->setFlashdata('demo_request_errors', []);
            session()->setFlashdata('demo_request_old', $payload);
            session()->setFlashdata('demo_request_feedback', [
                'ok' => false,
                'message' => 'La richiesta non e stata salvata correttamente. Riprova tra poco.',
            ]);

            return redirect()->to($this->demoRequestReturnUrl($payload['vertical']));
        }

        $notificationResult = $this->notifyDemoRequest($record);
        $record['notification'] = $notificationResult;
        $this->updateStoredDemoRequestJson((string) ($saveResult['json_file'] ?? ''), $record);

        session()->setFlashdata('demo_request_feedback', [
            'ok' => true,
            'message' => 'Richiesta registrata correttamente. Ora puoi usarla come lead commerciale della demo guidata.',
            'request_id' => $requestId,
            'storage_label' => $this->isLocalHost() ? $saveResult['json_file'] : '',
            'notification_note' => $this->isLocalHost() ? ($notificationResult['message'] ?? '') : '',
        ]);

        return redirect()->to($this->demoRequestReturnUrl($payload['vertical']));
    }

    public function requestInbox()
    {
        if (! $this->isLocalHost()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $profiles = $this->loadVerticalProfiles();
        $filters = $this->requestInboxFilters();
        $allRequests = $this->loadDemoRequests(250);
        $filteredRequests = $this->applyRequestInboxFilters($allRequests, $filters);

        return view('demo/request_inbox', [
            'brandName' => 'AmbulatorioFacile',
            'brandDescription' => 'Piattaforma operativa per prenotazioni, comunicazione e notifiche.',
            'showLocalAccess' => true,
            'requests' => $filteredRequests,
            'storagePath' => WRITEPATH . 'demo_requests',
            'filters' => $filters,
            'requestStats' => $this->requestInboxStats($allRequests, $filteredRequests, $filters),
            'verticalOptions' => $this->requestInboxVerticalOptions($profiles, $allRequests),
            'notificationOptions' => $this->notificationStatusOptions(),
            'exportUrl' => $this->requestInboxExportUrl($filters),
        ]);
    }

    public function exportRequestInbox()
    {
        if (! $this->isLocalHost()) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $filters = $this->requestInboxFilters();
        $requests = $this->applyRequestInboxFilters($this->loadDemoRequests(500), $filters);
        $filename = 'demo-requests-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-store, max-age=0')
            ->setBody($this->buildRequestInboxCsv($requests));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadVerticalProfiles(): array
    {
        $directory = ROOTPATH . 'productization' . DIRECTORY_SEPARATOR . 'vertical-profiles';
        if (! is_dir($directory)) {
            return [];
        }

        $profiles = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded)) {
                continue;
            }

            $decoded['file_name'] = basename($path);
            $profiles[] = $decoded;
        }

        usort(
            $profiles,
            static fn(array $a, array $b): int => strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''))
        );

        return $profiles;
    }

    private function isLocalHost(): bool
    {
        $host = strtolower((string) ($this->request->getServer('HTTP_HOST') ?? ''));
        $host = trim((string) preg_replace('/:\d+$/', '', $host));

        return in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadLatestReport(string $pattern): ?array
    {
        $directory = WRITEPATH . 'demo_setup' . DIRECTORY_SEPARATOR;
        $matches = glob($directory . $pattern) ?: [];
        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a)
        );

        $content = file_get_contents($matches[0]);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed>|null $report
     * @return array<string, mixed>
     */
    private function buildRuntimeStatus(?array $report): array
    {
        return [
            'status' => (string) ($report['status'] ?? 'missing'),
            'destination' => (string) ($report['destination'] ?? ''),
            'finished_at' => (string) ($report['finished_at'] ?? ''),
            'missing_assets' => (int) count($report['missing_assets'] ?? []),
            'missing_assets_summary' => array_slice((array) ($report['missing_assets_summary'] ?? []), 0, 5),
            'notes' => array_slice((array) ($report['notes'] ?? []), 0, 4),
        ];
    }

    /**
     * @param array<string, mixed>|null $report
     * @return array<string, mixed>
     */
    private function buildSeedStatus(?array $report): array
    {
        $summary = (array) ($report['summary'] ?? []);

        return [
            'status' => (string) ($report['status'] ?? 'missing'),
            'finished_at' => (string) ($report['finished_at'] ?? ''),
            'database' => (string) ($report['target_db'] ?? $report['database'] ?? (env('database.default.database') ?: 'dottorapp_demo')),
            'brand' => (string) ($report['brand'] ?? 'AmbulatorioFacile'),
            'counts' => $summary !== [] ? $summary : (array) ($report['counts'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed>|null $seedReport
     * @return list<array<string, mixed>>
     */
    private function demoAccounts(?array $seedReport): array
    {
        $password = (string) ($seedReport['password'] ?? 'Demo2026');
        $seedLabels = $this->demoAccountLabels($seedReport);

        $accounts = [
            [
                'group_key' => 'main',
                'group_title' => 'Accessi principali',
                'group_note' => 'Questi sono gli account da usare per provare il flusso demo standard di AmbulatorioFacile.',
                'role' => 'Admin demo',
                'username' => 'demo.admin',
                'label' => 'Admin demo Conti Giulia',
                'note' => 'Ideale per aprire la demo, spiegare moduli, ruoli e verificare le funzioni di base dello spazio.',
                'otp' => '',
                'scenarios' => ['ruoli e moduli', 'configurazione studio', 'verifiche finali'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Accessi principali',
                'group_note' => 'Questi sono gli account da usare per provare il flusso demo standard di AmbulatorioFacile.',
                'role' => 'Segreteria',
                'username' => 'demo.segreteria',
                'label' => 'Segreteria Colombo Sara',
                'note' => 'Perfetto per mostrare agenda del giorno, conferme, spostamenti, memo e presa appuntamenti per altri dottori.',
                'otp' => '2510',
                'scenarios' => ['agenda operativa', 'cross booking', 'reminder'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Accessi principali',
                'group_note' => 'Questi sono gli account da usare per provare il flusso demo standard di AmbulatorioFacile.',
                'role' => 'Dietista con OTP',
                'username' => 'demo.dietista',
                'label' => 'Dietista Rossi Elena',
                'note' => 'Mostra il lato professionista con MFA, agenda personale, messaggi e continuita del percorso paziente.',
                'otp' => '2510',
                'scenarios' => ['accesso MFA', 'agenda dottore', 'messaggi e follow-up'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Accessi principali',
                'group_note' => 'Questi sono gli account da usare per provare il flusso demo standard di AmbulatorioFacile.',
                'role' => 'Collaboratrice',
                'username' => 'demo.nutrizionista',
                'label' => 'Biologa nutrizionista Riva Marta',
                'note' => 'Utile per provare visibilita differenziate, presa appuntamenti interna e convivenza tra piu professionisti.',
                'otp' => '',
                'scenarios' => ['secondo professionista', 'permessi differenziati', 'agenda condivisa'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Accessi principali',
                'group_note' => 'Questi sono gli account da usare per provare il flusso demo standard di AmbulatorioFacile.',
                'role' => 'Portale paziente',
                'username' => 'demo.portal.nutri',
                'label' => 'Bianchi Laura',
                'note' => 'Serve per far vedere il lato paziente e completare la demo con accesso esterno controllato.',
                'otp' => '',
                'scenarios' => ['portale paziente', 'continuita', 'accesso esterno'],
            ],
            [
                'group_key' => 'quick',
                'group_title' => 'Accessi rapidi demo',
                'group_note' => 'Alias pronti per entrare al volo nelle viste operative senza perdere tempo in presentazione.',
                'role' => 'Accesso rapido',
                'username' => 'demo.admin->demo.segreteria',
                'label' => 'Accesso rapido segreteria',
                'note' => 'La scorciatoia piu utile per passare subito alla vista segreteria durante la demo commerciale.',
                'otp' => '2510',
                'scenarios' => ['demo veloce', 'vista segreteria', 'agenda e conferme'],
            ],
        ];

        foreach ($accounts as $index => $account) {
            $username = (string) ($account['username'] ?? '');
            $accounts[$index]['password'] = $password;
            $accounts[$index]['login_url'] = $this->demoLoginUrl($username, self::UNIFIED_DEMO_KEY);
            if ($username !== '' && array_key_exists($username, $seedLabels)) {
                $accounts[$index]['label'] = $seedLabels[$username];
            }
        }

        return $accounts;
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @return list<array<string, mixed>>
     */
    private function demoAccountGroups(array $accounts): array
    {
        $groups = [];
        foreach ($accounts as $account) {
            $groupKey = (string) ($account['group_key'] ?? '');
            if ($groupKey === '') {
                continue;
            }

            if (!array_key_exists($groupKey, $groups)) {
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'title' => (string) ($account['group_title'] ?? 'Account demo'),
                    'note' => (string) ($account['group_note'] ?? ''),
                    'accounts' => [],
                ];
            }

            $groups[$groupKey]['accounts'][] = $account;
        }

        return array_values($groups);
    }

    /**
     * @param array<string, mixed>|null $seedReport
     * @return array<string, string>
     */
    private function demoCredentialsSummary(?array $seedReport): array
    {
        return [
            'password' => (string) ($seedReport['password'] ?? 'Demo2026'),
            'otp' => '2510',
            'login_url' => site_url('login') . '?demo=1',
            'official_login_url' => $this->officialLoginUrl(),
            'demo_home_url' => site_url('/'),
            'demo_access_url' => site_url('access'),
            'demo_request_url' => site_url('richiesta'),
        ];
    }

    /**
     * @param array<string, mixed>|null $seedReport
     * @return array<string, string>
     */
    private function demoAccountLabels(?array $seedReport): array
    {
        $labels = [];
        foreach ((array) ($seedReport['accounts'] ?? []) as $account) {
            $username = trim((string) ($account['username'] ?? ''));
            if ($username === '') {
                continue;
            }

            $labels[$username] = (string) ($account['label'] ?? $username);
        }

        return $labels;
    }

    /**
     * @return list<array<string, string>>
     */
    private function validateDemoRequestPayload(array $payload): array
    {
        $errors = [];

        if (mb_strlen($payload['full_name']) < 3) {
            $errors['full_name'] = 'Inserisci nome e cognome del referente.';
        }

        if (mb_strlen($payload['business_name']) < 2) {
            $errors['business_name'] = 'Inserisci il nome della struttura o attivita.';
        }

        if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Inserisci un indirizzo email valido.';
        }

        if ($payload['phone'] !== '' && preg_match('/^[0-9+\s().\/-]{6,30}$/', $payload['phone']) !== 1) {
            $errors['phone'] = 'Inserisci un numero di telefono valido oppure lascia il campo vuoto.';
        }

        if ((string) ($payload['vertical'] ?? '') !== self::UNIFIED_DEMO_KEY) {
            $errors['vertical'] = 'Contesto demo non valido.';
        }

        if ($payload['contact_role'] !== '' && mb_strlen($payload['contact_role']) > 80) {
            $errors['contact_role'] = 'Il ruolo del referente e troppo lungo.';
        }

        if ($payload['team_size'] !== '' && mb_strlen($payload['team_size']) > 60) {
            $errors['team_size'] = 'Indica una dimensione team piu breve.';
        }

        if ($payload['preferred_slot'] !== '' && !in_array($payload['preferred_slot'], ['mattina', 'pomeriggio', 'sera', 'flessibile'], true)) {
            $errors['preferred_slot'] = 'Seleziona una fascia valida per la demo.';
        }

        if ($payload['notes'] !== '' && mb_strlen($payload['notes']) > 1200) {
            $errors['notes'] = 'Mantieni le note entro 1200 caratteri.';
        }

        if ($payload['privacy'] !== '1') {
            $errors['privacy'] = 'Devi confermare il consenso privacy per inviare la richiesta.';
        }

        return $errors;
    }

    /**
     * @return list<array<string, string>>
     */
    private function commercialHighlights(): array
    {
        return [
            [
                'eyebrow' => 'Agenda operativa',
                'title' => 'Calendario, sale e piu operatori nello stesso flusso',
                'body' => 'La parte piu forte del prodotto e gia pronta: disponibilita, appuntamenti, sedi e coordinamento operativo senza uscire dal gestionale.',
            ],
            [
                'eyebrow' => 'Riduzione no-show',
                'title' => 'Reminder e conferme con canali gia pensati per l uso reale',
                'body' => 'WhatsApp, SMS, email e tracciabilita del promemoria permettono di raccontare subito un ritorno operativo concreto.',
            ],
            [
                'eyebrow' => 'Team e sicurezza',
                'title' => 'Ruoli, comunicazione interna e accessi protetti',
                'body' => 'Posta, chat, visibilita moduli e OTP aiutano a posizionare il prodotto come piattaforma di lavoro, non come semplice agenda.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commercialPackages(): array
    {
        return [
            [
                'tag' => 'Start',
                'title' => 'Ingresso essenziale',
                'fit' => 'Studio singolo o centro che vuole partire con agenda e reminder di base.',
                'items' => [
                    'Agenda operativa e utenti base',
                    'Una sede o un centro principale',
                    'Reminder email come attivazione leggera',
                ],
            ],
            [
                'tag' => 'Team',
                'title' => 'Pacchetto piu naturale',
                'fit' => 'La proposta ideale per chi lavora con piu operatori, reception o coordinamento team.',
                'items' => [
                    'Piu operatori con ruoli distinti',
                    'Chat e posta interna',
                    'Booking e reminder WhatsApp o SMS',
                ],
            ],
            [
                'tag' => 'Pro',
                'title' => 'Crescita e multi-sede',
                'fit' => 'Per strutture che hanno piu sedi, piu sale o piu visibilita operativa da governare.',
                'items' => [
                    'Multi-sede e ruoli avanzati',
                    'Log operativi e controllo processi',
                    'Configurazioni verticali e onboarding guidato',
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @return array<string, string>
     */
    private function profileLinks(array $profiles): array
    {
        $links = [];
        foreach ($profiles as $profile) {
            $profileId = (string) ($profile['profile_id'] ?? '');
            if ($profileId === '') {
                continue;
            }

            $links[$profileId] = site_url('demo/vertical/' . $this->profileSlug($profileId));
        }

        return $links;
    }

    private function demoRequestReturnUrl(string $profileId): string
    {
        return site_url('richiesta');
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function notifyDemoRequest(array $record): array
    {
        $recipients = $this->demoRequestNotificationRecipients();
        if ($recipients === []) {
            return [
                'status' => 'skipped',
                'attempted' => false,
                'sent' => false,
                'recipients' => [],
                'message' => 'Notifica email non attiva: nessun destinatario configurato per le richieste demo.',
                'checked_at' => date('c'),
            ];
        }

        $config = null;
        try {
            $mailer = \Config\Services::email(null, false);
            $config = config('Email');

            $fromEmail = trim((string) ($config->fromEmail ?? ''));
            $fromName = trim((string) ($config->fromName ?? ''));
            if ($fromEmail === '') {
                $fromEmail = (string) (env('email.fromEmail') ?: 'noreply@ambulatoriofacile.it');
            }
            if ($fromName === '') {
                $fromName = (string) (env('email.fromName') ?: 'AmbulatorioFacile');
            }

            $subject = '[Demo guidata] ' . (string) ($record['vertical_label'] ?? $record['vertical'] ?? 'Richiesta');
            $message = $this->buildDemoRequestNotificationMessage($record);

            $mailer->clear(true);
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->setTo($recipients);
            $mailer->setSubject($subject);
            $mailer->setMessage($message);

            if (! $mailer->send()) {
                $debugMessage = trim(strip_tags((string) $mailer->printDebugger(['headers', 'subject'])));
                log_message('error', '[DemoController::notifyDemoRequest] invio mail fallito | request_id={requestId} | recipients={recipients} | protocol={protocol} | host={host} | port={port} | crypto={crypto} | debugger={debugger}', [
                    'requestId' => (string) ($record['request_id'] ?? ''),
                    'recipients' => implode(', ', $recipients),
                    'protocol' => (string) ($config->protocol ?? ''),
                    'host' => (string) ($config->SMTPHost ?? ''),
                    'port' => (string) ($config->SMTPPort ?? ''),
                    'crypto' => (string) ($config->SMTPCrypto ?? ''),
                    'debugger' => $debugMessage !== '' ? $debugMessage : 'n/a',
                ]);

                return [
                    'status' => 'failed',
                    'attempted' => true,
                    'sent' => false,
                    'recipients' => $recipients,
                    'message' => 'Lead salvato, ma la notifica email non e stata inviata. Controlla la configurazione SMTP/demo.',
                    'checked_at' => date('c'),
                ];
            }

            log_message('info', '[DemoController::notifyDemoRequest] notifica mail demo inviata | request_id={requestId} | recipients={recipients}', [
                'requestId' => (string) ($record['request_id'] ?? ''),
                'recipients' => implode(', ', $recipients),
            ]);

            return [
                'status' => 'sent',
                'attempted' => true,
                'sent' => true,
                'recipients' => $recipients,
                'message' => 'Notifica email inviata a: ' . implode(', ', $recipients),
                'checked_at' => date('c'),
            ];
        } catch (\Throwable $e) {
            log_message('error', '[DemoController::notifyDemoRequest] eccezione invio mail demo | request_id={requestId} | message={message}', [
                'requestId' => (string) ($record['request_id'] ?? ''),
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'attempted' => true,
                'sent' => false,
                'recipients' => $recipients,
                'message' => 'Lead salvato, ma la notifica email ha generato un errore applicativo.',
                'checked_at' => date('c'),
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function demoRequestNotificationRecipients(): array
    {
        $raw = trim((string) (env('demo.requestNotificationRecipients') ?: env('email.recipients') ?: ''));
        if ($raw === '') {
            return [];
        }

        $recipients = preg_split('/[;,]+/', $raw) ?: [];
        $recipients = array_values(array_filter(array_map(
            static fn(string $email): string => strtolower(trim($email)),
            $recipients
        ), static fn(string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false));

        return array_values(array_unique($recipients));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function buildDemoRequestNotificationMessage(array $record): string
    {
        $lines = [
            'AmbulatorioFacile' . ' - nuova richiesta demo guidata',
            '',
            'ID richiesta: ' . (string) ($record['request_id'] ?? '-'),
            'Data: ' . (string) ($record['created_at'] ?? '-'),
            'Verticale: ' . (string) ($record['vertical_label'] ?? $record['vertical'] ?? '-'),
            'Struttura: ' . (string) ($record['business_name'] ?? '-'),
            'Referente: ' . (string) ($record['full_name'] ?? '-'),
            'Email: ' . (string) ($record['email'] ?? '-'),
            'Telefono: ' . ((string) ($record['phone'] ?? '') !== '' ? (string) $record['phone'] : '-'),
            'Ruolo: ' . ((string) ($record['contact_role'] ?? '') !== '' ? (string) $record['contact_role'] : '-'),
            'Team: ' . ((string) ($record['team_size'] ?? '') !== '' ? (string) $record['team_size'] : '-'),
            'Fascia preferita: ' . ((string) ($record['preferred_slot'] ?? '') !== '' ? (string) $record['preferred_slot'] : '-'),
            '',
            'Note:',
            (string) (($record['notes'] ?? '') !== '' ? $record['notes'] : 'Nessuna nota aggiuntiva.'),
        ];

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDemoRequests(int $limit = 100): array
    {
        $directory = WRITEPATH . 'demo_requests' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . 'demo-*.json') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $requests = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                continue;
            }

            $decoded['file_name'] = basename($file);
            $requests[] = $this->enrichDemoRequest($decoded);
        }

        return $requests;
    }

    /**
     * @return array<string, string>
     */
    private function requestInboxFilters(): array
    {
        return [
            'vertical' => $this->normalizeProfileId((string) $this->request->getGet('vertical')),
            'notification_status' => $this->sanitizeNotificationStatus((string) $this->request->getGet('notification_status')),
            'q' => trim((string) $this->request->getGet('q')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    private function applyRequestInboxFilters(array $requests, array $filters): array
    {
        $vertical = (string) ($filters['vertical'] ?? '');
        $notificationStatus = (string) ($filters['notification_status'] ?? '');
        $query = trim((string) ($filters['q'] ?? ''));

        return array_values(array_filter(
            $requests,
            function (array $request) use ($vertical, $notificationStatus, $query): bool {
                if (
                    $vertical !== ''
                    && $this->normalizeProfileId((string) ($request['vertical'] ?? '')) !== $vertical
                ) {
                    return false;
                }

                if (
                    $notificationStatus !== ''
                    && (string) (($request['notification']['status'] ?? '') ?: '') !== $notificationStatus
                ) {
                    return false;
                }

                if ($query !== '' && mb_stripos($this->requestSearchHaystack($request), $query, 0, 'UTF-8') === false) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * @param list<array<string, mixed>> $allRequests
     * @param list<array<string, mixed>> $filteredRequests
     * @param array<string, string> $filters
     * @return array<string, int>
     */
    private function requestInboxStats(array $allRequests, array $filteredRequests, array $filters): array
    {
        return [
            'total' => count($allRequests),
            'filtered' => count($filteredRequests),
            'sent' => $this->countRequestsByNotificationStatus($allRequests, 'sent'),
            'attention' => $this->countRequestsByNotificationStatus($allRequests, 'failed')
                + $this->countRequestsByNotificationStatus($allRequests, 'pending')
                + $this->countRequestsByNotificationStatus($allRequests, 'skipped'),
            'active_filters' => count(array_filter($filters, static fn(string $value): bool => $value !== '')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, string>>
     */
    private function requestInboxVerticalOptions(array $profiles, array $requests): array
    {
        $labels = [];
        foreach ($profiles as $profile) {
            $profileId = $this->normalizeProfileId((string) ($profile['profile_id'] ?? ''));
            if ($profileId === '') {
                continue;
            }

            $labels[$profileId] = (string) ($profile['label'] ?? $profileId);
        }

        foreach ($requests as $request) {
            $profileId = $this->normalizeProfileId((string) ($request['vertical'] ?? ''));
            if ($profileId === '' || isset($labels[$profileId])) {
                continue;
            }

            $labels[$profileId] = (string) ($request['vertical_label'] ?? $profileId);
        }

        asort($labels);

        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }

    /**
     * @return list<array<string, string>>
     */
    private function notificationStatusOptions(): array
    {
        $options = [];
        foreach ($this->notificationStatusDefinitions() as $value => $meta) {
            $options[] = [
                'value' => $value,
                'label' => (string) ($meta['label'] ?? $value),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, string> $filters
     */
    private function requestInboxExportUrl(array $filters): string
    {
        $query = array_filter($filters, static fn(string $value): bool => $value !== '');
        $url = site_url('richieste-locali/export');

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function buildRequestInboxCsv(array $requests): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile generare l export CSV delle richieste demo.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");

            $header = [
                'request_id',
                'created_at',
                'vertical',
                'vertical_label',
                'business_name',
                'full_name',
                'email',
                'phone',
                'contact_role',
                'team_size',
                'preferred_slot',
                'notes',
                'notification_status',
                'notification_label',
                'notification_message',
                'file_name',
            ];

            fputcsv($handle, $header, ';');
            foreach ($requests as $request) {
                fputcsv($handle, array_values($this->flattenDemoRequestForExport($request)), ';');
            }

            rewind($handle);
            $csv = stream_get_contents($handle);

            return $csv === false ? '' : $csv;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, string>
     */
    private function flattenDemoRequestForExport(array $request): array
    {
        $notification = is_array($request['notification'] ?? null) ? $request['notification'] : [];

        return [
            'request_id' => (string) ($request['request_id'] ?? ''),
            'created_at' => (string) ($request['created_at'] ?? ''),
            'vertical' => (string) ($request['vertical'] ?? ''),
            'vertical_label' => (string) ($request['vertical_label'] ?? ''),
            'business_name' => (string) ($request['business_name'] ?? ''),
            'full_name' => (string) ($request['full_name'] ?? ''),
            'email' => (string) ($request['email'] ?? ''),
            'phone' => (string) ($request['phone'] ?? ''),
            'contact_role' => (string) ($request['contact_role'] ?? ''),
            'team_size' => (string) ($request['team_size'] ?? ''),
            'preferred_slot' => (string) ($request['preferred_slot'] ?? ''),
            'notes' => trim((string) ($request['notes'] ?? '')),
            'notification_status' => (string) ($notification['status'] ?? ''),
            'notification_label' => (string) ($request['notification_label'] ?? ''),
            'notification_message' => (string) ($notification['message'] ?? ''),
            'file_name' => (string) ($request['file_name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function enrichDemoRequest(array $request): array
    {
        $notification = is_array($request['notification'] ?? null) ? $request['notification'] : [];
        $status = $this->sanitizeNotificationStatus((string) ($notification['status'] ?? ''));
        if ($status === '') {
            $status = 'pending';
        }

        $definitions = $this->notificationStatusDefinitions();
        $meta = $definitions[$status] ?? $definitions['pending'];
        $notification['status'] = $status;
        $request['notification'] = $notification;
        $request['notification_label'] = (string) ($meta['label'] ?? 'In attesa');
        $request['notification_tone'] = (string) ($meta['tone'] ?? 'neutral');

        return $request;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function notificationStatusDefinitions(): array
    {
        return [
            'pending' => [
                'label' => 'In attesa',
                'tone' => 'neutral',
            ],
            'sent' => [
                'label' => 'Inviata',
                'tone' => 'success',
            ],
            'failed' => [
                'label' => 'Errore',
                'tone' => 'danger',
            ],
            'skipped' => [
                'label' => 'Non attiva',
                'tone' => 'warning',
            ],
        ];
    }

    private function sanitizeNotificationStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return '';
        }

        return array_key_exists($status, $this->notificationStatusDefinitions()) ? $status : '';
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function countRequestsByNotificationStatus(array $requests, string $status): int
    {
        return count(array_filter(
            $requests,
            static fn(array $request): bool => (string) (($request['notification']['status'] ?? '') ?: '') === $status
        ));
    }

    /**
     * @param array<string, mixed> $request
     */
    private function requestSearchHaystack(array $request): string
    {
        return implode(
            ' ',
            [
                (string) ($request['request_id'] ?? ''),
                (string) ($request['vertical'] ?? ''),
                (string) ($request['vertical_label'] ?? ''),
                (string) ($request['business_name'] ?? ''),
                (string) ($request['full_name'] ?? ''),
                (string) ($request['email'] ?? ''),
                (string) ($request['phone'] ?? ''),
                (string) ($request['contact_role'] ?? ''),
                (string) ($request['team_size'] ?? ''),
                (string) ($request['preferred_slot'] ?? ''),
                (string) ($request['notes'] ?? ''),
            ]
        );
    }

    private function demoLoginUrl(string $username, string $profileId): string
    {
        $query = ['demo' => '1'];

        if ($username !== '') {
            $query['u'] = $username;
        }

        return site_url('login') . '?' . http_build_query($query);
    }

    private function officialLoginUrl(): string
    {
        $configuredBaseUrl = trim((string) env('APP_PUBLIC_ACCESS_BASE_URL', ''));
        if ($configuredBaseUrl !== '') {
            return portal_public_access_url('login');
        }

        $scheme = strtolower((string) ($this->request->getServer('HTTP_X_FORWARDED_PROTO') ?: $this->request->getUri()->getScheme() ?: 'https'));
        $host = (string) ($this->request->getServer('HTTP_X_FORWARDED_HOST') ?: $this->request->getServer('HTTP_HOST') ?: $this->request->getUri()->getHost());
        $host = trim((string) explode(',', $host)[0]);
        if ($host === '') {
            return '/login';
        }

        return $scheme . '://' . preg_replace('/:\d+$/', '', $host) . '/login';
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @return list<array<string, mixed>>
     */
    private function withDemoLoginLinks(array $accounts, string $profileId): array
    {
        foreach ($accounts as $index => $account) {
            $username = (string) ($account['username'] ?? '');
            $accounts[$index]['login_url'] = $this->demoLoginUrl($username, $profileId);
        }

        return $accounts;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function storeDemoRequest(array $record): array
    {
        $directory = WRITEPATH . 'demo_requests' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossibile creare la directory delle richieste demo.');
        }

        $jsonFile = $directory . $record['request_id'] . '.json';
        $jsonPayload = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false || file_put_contents($jsonFile, $jsonPayload) === false) {
            throw new \RuntimeException('Impossibile salvare il file JSON della richiesta demo.');
        }

        $csvFile = $directory . 'requests.csv';
        $isNewCsv = !is_file($csvFile);
        $handle = fopen($csvFile, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile aprire il file CSV delle richieste demo.');
        }

        $csvRecord = $record;
        unset($csvRecord['notification']);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Impossibile bloccare il file CSV delle richieste demo.');
            }

            if ($isNewCsv) {
                fputcsv($handle, array_keys($csvRecord), ';');
            }
            fputcsv($handle, array_values($csvRecord), ';');
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        log_message('info', '[DemoController::submitDemoRequest] richiesta demo registrata | request_id={requestId} | vertical={vertical} | email={email}', [
            'requestId' => (string) ($record['request_id'] ?? ''),
            'vertical' => (string) ($record['vertical'] ?? ''),
            'email' => (string) ($record['email'] ?? ''),
        ]);

        return [
            'json_file' => $jsonFile,
            'csv_file' => $csvFile,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function updateStoredDemoRequestJson(string $jsonFile, array $record): void
    {
        if ($jsonFile === '') {
            return;
        }

        $jsonPayload = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false || file_put_contents($jsonFile, $jsonPayload) === false) {
            log_message('error', '[DemoController::updateStoredDemoRequestJson] aggiornamento JSON fallito | file={file}', [
                'file' => $jsonFile,
            ]);
        }
    }

    private function profileSlug(string $profileId): string
    {
        return str_replace('_', '-', strtolower(trim($profileId)));
    }

    private function normalizeProfileId(string $profileId): string
    {
        return str_replace('-', '_', strtolower(trim($profileId)));
    }

    /**
     * @param list<array<string, mixed>> $profiles
     * @return array<string, mixed>|null
     */
    private function findProfile(array $profiles, string $profileId): ?array
    {
        foreach ($profiles as $profile) {
            if ($this->normalizeProfileId((string) ($profile['profile_id'] ?? '')) === $profileId) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function verticalPlaybooks(): array
    {
        return [
            'medical' => [
                'headline' => 'Percorso demo per studi di dietistica, nutrizione clinica e percorsi alimentari personalizzati',
                'subheadline' => 'Mostra in pochi minuti come agenda, segreteria, follow-up e comunicazione col paziente riducono caos operativo in uno studio nutrizionale da 3 persone.',
                'outcomes' => [
                    'Riduzione del tempo speso tra chiamate, spostamenti agenda e conferme manuali.',
                    'Controllo chiaro su professionisti, stanze e appuntamenti nello stesso flusso operativo.',
                    'Maggiore continuita tra segreteria, dietista, collaboratrice e paziente.',
                ],
                'buyer_signals' => [
                    'Studio con titolare, collaboratrice e una persona di segreteria.',
                    'Molti follow-up ricorrenti e continue richieste di spostamento o conferma visita.',
                    'Bisogno di tenere insieme prima visita, controlli e comunicazione paziente senza usare tre strumenti diversi.',
                ],
                'pain_points' => [
                    'Agenda frammentata tra agenda condivisa, WhatsApp e telefonate.',
                    'Conferme, spostamenti e richieste paziente gestiti in modo poco tracciabile.',
                    'Poca chiarezza su cosa vede la segreteria e cosa resta in carico ai professionisti.',
                ],
                'demo_flow' => [
                    [
                        'title' => 'Segreteria operativa',
                        'steps' => [
                            'Apro la dashboard moduli e mostro l agenda del giorno dello studio nutrizionale.',
                            'Cerco disponibilita, scelgo professionista e stanza e inserisco una prima visita o un controllo.',
                            'Chiudo con reminder, conferma e promemoria organizzativo.',
                        ],
                    ],
                    [
                        'title' => 'Professionisti e coordinamento',
                        'steps' => [
                            'Entro come dietista e faccio vedere agenda, posta e continuita dei follow-up.',
                            'Mostro i collegamenti tra segreteria e professionisti, con chat interna e visibilita per ruolo.',
                            'Faccio vedere come titolare e collaboratrice restano allineate senza uscire dal gestionale.',
                        ],
                    ],
                    [
                        'title' => 'Accesso sicuro e paziente',
                        'steps' => [
                            'Mostro il flusso OTP con account operativo dedicato.',
                            'Se serve, apro il portale paziente per raccontare accesso sicuro e messaggistica.',
                            'Chiudo collegando il tutto a riduzione no-show e continuita del percorso alimentare.',
                        ],
                    ],
                ],
                'pricing_hint' => 'Ingresso ideale come pacchetto Team per studio nutrizionale con 2 professionisti e segreteria dedicata.',
                'next_moves' => [
                    'Preparare una mini landing dedicata a dietistica e nutrizione.',
                    'Rendere sempre piu esplicito il linguaggio di prima visita, controllo e follow-up alimentare.',
                    'Valutare in un secondo step moduli aggiuntivi come piani o pacchetti, senza toccare il core adesso.',
                ],
            ],
            'sport_rehab' => [
                'headline' => 'Percorso demo per fisioterapia, riabilitazione e sport medical',
                'subheadline' => 'Posizionamento piu orientato a sedute, sale, coordinamento team e continuita con il cliente, senza cambiare il cuore operativo della piattaforma.',
                'outcomes' => [
                    'Gestione fluida di sale, terapisti, professionisti e coordinamento front desk.',
                    'Riduzione dei no-show con reminder e accesso OTP controllato.',
                    'Maggiore continuita tra presa appuntamento, follow-up e comunicazione con il cliente.',
                ],
                'buyer_signals' => [
                    'Centro con piu terapisti o piu sale da coordinare.',
                    'Percorsi di recupero con piu appuntamenti distribuiti nel tempo.',
                    'Team che lavora tra reception, coordinamento e professionisti diversi.',
                ],
                'pain_points' => [
                    'Sedute spostate spesso e difficili da riallineare tra piu operatori.',
                    'Comunicazione dispersa tra chiamate, chat esterne e note manuali.',
                    'Scarso controllo su disponibilita reali di sale e professionisti.',
                ],
                'demo_flow' => [
                    [
                        'title' => 'Reception e pianificazione',
                        'steps' => [
                            'Mostro agenda, sale e professionisti in un unico flusso.',
                            'Inserisco una seduta scegliendo centro, sala e terapista.',
                            'Faccio vedere come il coordinamento resta chiaro anche con team misto.',
                        ],
                    ],
                    [
                        'title' => 'Team e continuita',
                        'steps' => [
                            'Evidenzio chat e posta interna per seguire il caso senza uscire dalla piattaforma.',
                            'Mostro ruoli e visibilita per coordinatore, professionista e assistente.',
                            'Chiudo con reminder e follow-up cliente.',
                        ],
                    ],
                    [
                        'title' => 'Esperienza cliente',
                        'steps' => [
                            'Accedo al portale demo sport rehab.',
                            'Racconto il flusso di conferma accesso e reminder.',
                            'Collego la demo al tema aderenza al percorso e riduzione dei vuoti agenda.',
                        ],
                    ],
                ],
                'pricing_hint' => 'Ingresso ideale come pacchetto Team per centro single-site o Pro per piu sale e piu professionisti.',
                'next_moves' => [
                    'Preparare copy meno clinico e piu orientato a percorso e recupero.',
                    'Aggiungere una terminologia esposta per centro, sala, professionista e cliente.',
                    'Valutare in un secondo tempo funzioni commerciali come pacchetti o cicli, senza toccare il core ora.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $seedReport
     * @return list<array<string, mixed>>
     */
    private function accountsForProfile(string $profileId, ?array $seedReport): array
    {
        return $this->demoAccounts($seedReport);
    }

    /**
     * @return array<string, string>
     */
    private function unifiedDemoRequestContext(): array
    {
        return [
            'id' => self::UNIFIED_DEMO_KEY,
            'label' => self::UNIFIED_DEMO_LABEL,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function demoChecklist(): array
    {
        return [
            [
                'title' => 'Ingresso generale',
                'username' => 'demo.admin',
                'goal' => 'Apri moduli, ruoli, configurazioni e controlla che la base comune della piattaforma sia completa.',
            ],
            [
                'title' => 'Vista segreteria veloce',
                'username' => 'demo.admin->demo.segreteria',
                'goal' => 'Mostra agenda del giorno, ricerca disponibilita, inserimento appuntamento per un altro dottore e note operative.',
            ],
            [
                'title' => 'Vista segreteria diretta',
                'username' => 'demo.segreteria',
                'goal' => 'Controlla conferme, reminder, blocchi giornata e comportamento delle funzioni condivise.',
            ],
            [
                'title' => 'Vista professionista con OTP',
                'username' => 'demo.dietista',
                'goal' => 'Verifica MFA, agenda per singolo dottore, memo, posta e notifiche ricevute quando altri prenotano per lui.',
            ],
            [
                'title' => 'Secondo professionista',
                'username' => 'demo.nutrizionista',
                'goal' => 'Prova visibilita differenziate, presa appuntamenti interna e convivenza tra piu professionisti.',
            ],
            [
                'title' => 'Portale paziente',
                'username' => 'demo.portal.nutri',
                'goal' => 'Chiudi il giro dal lato paziente e verifica il percorso esterno della demo.',
            ],
        ];
    }
}

