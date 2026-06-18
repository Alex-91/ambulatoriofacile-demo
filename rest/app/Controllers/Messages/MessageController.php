<?php namespace App\Controllers\Messages;

use App\Controllers\BaseController;
use App\Libraries\DatabaseConfig;
use App\Libraries\Crypto_helper;
use App\Libraries\FileCrypt;
use App\Services\MessageService;
use App\Services\SessionNavigationService;
use App\Services\StaffDoctorAccessService;
class MessageController extends BaseController
{
    private MessageService $svc;
    private SessionNavigationService $navigation;

    public function __construct()
    {
        $db   = \Config\Database::connect();
        $dbCfg = new DatabaseConfig();
        $this->svc = new MessageService($db, $dbCfg);
        $this->navigation = new SessionNavigationService();
    }

    /**
     * ID "logico" usato dal nuovo modulo messaggi.
     *
     * REGOLE:
     * - Paziente  (tipoUser=3)  -> usa id_client (dap02_clients.id_client)
     * - Personale (tipoUser!=3) -> usa id_personale (dap03_personale.id_personale)
     *
     * Nel login tu salvi già tutto dentro session()->get('utente_sess'):
     *  - per paziente:  $obj->id_utente = id_client
     *  - per personale: $obj->id_utente = id_personale
     */
    private function meId(): int
    {
        $meObj = session()->get('utente_sess');

        // 1) via oggetto sessione (preferita)
        if (is_object($meObj) && isset($meObj->id_utente)) {
            $id = (int)$meObj->id_utente;
            if ($id > 0) {
                session()->set('actorId', $id); // alias comodo
                return $id;
            }
        }

        // 2) fallback: ricostruisco da tipoUser + campi sessione
        $tipoUser = (int)(session()->get('tipoUser') ?? 0);

        if ($tipoUser === 3) {
            // paziente: provo a leggere id_client dall'oggetto
            if (is_object($meObj) && isset($meObj->id_client)) {
                $id = (int)$meObj->id_client;
                if ($id > 0) {
                    session()->set('actorId', $id);
                    return $id;
                }
            }
            // ultimo fallback (NON ideale): userId (dap01_users.id_user)
            $id = (int)(session()->get('userId') ?? 0);
            if ($id > 0) {
                session()->set('actorId', $id);
                return $id;
            }
        } else {
            // personale: id_personale
            if (is_object($meObj) && isset($meObj->id_personale)) {
                $id = (int)$meObj->id_personale;
                if ($id > 0) {
                    session()->set('actorId', $id);
                    return $id;
                }
            }
        }

        throw new \RuntimeException('Sessione non valida: manca id_utente/id_personale/id_client');
    }

    private function myRoleLabel(): string
    {
        return $this->svc->getRoleLabelFromSession();
    }

    private function logMailboxRoleSnapshot(string $stage, array $extra = []): void
    {
        $utente = session()->get('utente_sess');

        $context = [
            'stage'            => $stage,
            'sessionTipoUser'  => (int)(session()->get('tipoUser') ?? 0),
            'sessionUserId'    => (int)(session()->get('userId') ?? 0),
            'sessionDoctorId'  => (int)(session()->get('selectedDoctorId') ?? 0),
            'utenteIdUser'     => (int)($utente->id_user ?? 0),
            'utenteIdPersonale'=> (int)($utente->id_personale ?? 0),
            'utenteIdUtente'   => (int)($utente->id_utente ?? 0),
            'utenteTipoPers'   => (int)($utente->tipo_pers ?? 0),
            'utenteTipoStr'    => (string)($utente->tipo_stringa ?? ''),
            'uri'              => (string)($this->request->getUri()->getPath() ?? ''),
        ];

        foreach ($extra as $key => $value) {
            if (is_bool($value)) {
                $context[$key] = $value ? 1 : 0;
                continue;
            }

            $context[$key] = $value;
        }

        log_message(
            'error',
            'MESSAGES role snapshot [{stage}] tipoUser={sessionTipoUser} rawTipoPers={utenteTipoPers} tipoStringa={utenteTipoStr} sessionUserId={sessionUserId} utenteIdUser={utenteIdUser} utenteIdPersonale={utenteIdPersonale} utenteIdUtente={utenteIdUtente} selectedDoctorId={sessionDoctorId} uri={uri} extra=' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $context
        );
    }

    private function refreshNavigation(bool $force = false): void
    {
        $this->navigation->refreshCurrentSession($force);
    }

    private function mailboxStaffTipoFromRole(string $role): int
    {
        return match (strtoupper(trim($role))) {
            'SEGRETERIA' => StaffDoctorAccessService::TIPO_SEGRETERIA,
            'INFERMIERE' => StaffDoctorAccessService::TIPO_INFERMIERE,
            default => 0,
        };
    }

    private function resolveMailboxDoctorContext(string $role, ?int $candidateDoctorId = null): int
    {
        $staffTipo = $this->mailboxStaffTipoFromRole($role);
        if ($staffTipo <= 0) {
            $this->logMailboxRoleSnapshot('resolveMailboxDoctorContext.notStaff', [
                'role'              => $role,
                'candidateDoctorId' => (int)($candidateDoctorId ?? 0),
                'staffTipo'         => $staffTipo,
            ]);
            return 0;
        }

        $staffPersonaleId = $this->meId();
        $storedDoctorId = (int)(session()->get('selectedDoctorId') ?? 0);
        $doctorPersonaleId = (int)($candidateDoctorId ?? $storedDoctorId);

        if ($doctorPersonaleId <= 0) {
            $this->logMailboxRoleSnapshot('resolveMailboxDoctorContext.noDoctor', [
                'role'              => $role,
                'candidateDoctorId' => (int)($candidateDoctorId ?? 0),
                'storedDoctorId'    => $storedDoctorId,
                'staffTipo'         => $staffTipo,
            ]);
            return 0;
        }

        $access = new StaffDoctorAccessService(\Config\Database::connect());
        if (!$access->canStaffAccessDoctor($staffPersonaleId, $staffTipo, $doctorPersonaleId, 'posta')) {
            $this->logMailboxRoleSnapshot('resolveMailboxDoctorContext.accessDenied', [
                'role'              => $role,
                'candidateDoctorId' => (int)($candidateDoctorId ?? 0),
                'storedDoctorId'    => $storedDoctorId,
                'doctorPersonaleId' => $doctorPersonaleId,
                'staffPersonaleId'  => $staffPersonaleId,
                'staffTipo'         => $staffTipo,
            ]);
            session()->remove('selectedDoctorId');
            return 0;
        }

        session()->set('selectedDoctorId', $doctorPersonaleId);
        $this->logMailboxRoleSnapshot('resolveMailboxDoctorContext.ok', [
            'role'              => $role,
            'candidateDoctorId' => (int)($candidateDoctorId ?? 0),
            'storedDoctorId'    => $storedDoctorId,
            'doctorPersonaleId' => $doctorPersonaleId,
            'staffPersonaleId'  => $staffPersonaleId,
            'staffTipo'         => $staffTipo,
        ]);
        return $doctorPersonaleId;
    }

    private function buildMailboxSidebarData(string $role, int $selectedDoctorId = 0): array
    {
        $staffTipo = $this->mailboxStaffTipoFromRole($role);
        if ($staffTipo <= 0) {
            return [
                'menu_items' => [],
                'dottori' => [],
                'contDott' => 0,
                'selectedDoctorId' => null,
                'showDoctorsFilter' => false,
            ];
        }

        $staffPersonaleId = $this->meId();
        $access = new StaffDoctorAccessService(\Config\Database::connect());
        $doctorIds = $access->getDoctorPersonaleIdsForStaff($staffPersonaleId, $staffTipo, 'posta');
        $countsByDoctor = $this->svc->countUnreadInboxThreadsByDoctorForStaff($role, $doctorIds);

        $dottori = [];
        $contDott = 0;
        foreach ($this->getMailboxSidebarDoctorRows($doctorIds) as $row) {
            $doctorId = (int)($row['id_personale'] ?? 0);
            if ($doctorId <= 0) {
                continue;
            }

            $conteggio = (int)($countsByDoctor[$doctorId] ?? 0);
            $contDott += $conteggio;
            $dottori[$doctorId] = [
                'titolo' => (string)($row['titolo'] ?? ('Dottore #' . $doctorId)),
                'conteggio' => $conteggio,
            ];
        }

        return [
            'menu_items' => [],
            'dottori' => $dottori,
            'contDott' => $contDott,
            'selectedDoctorId' => isset($dottori[$selectedDoctorId]) ? $selectedDoctorId : null,
            'showDoctorsFilter' => !empty($dottori),
        ];
    }

    private function getMailboxSidebarDoctorRows(array $doctorPersonaleIds): array
    {
        $doctorPersonaleIds = array_values(array_unique(array_filter(
            array_map('intval', $doctorPersonaleIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($doctorPersonaleIds === []) {
            return [];
        }

        $idsSql = implode(',', $doctorPersonaleIds);
        $crypto = new Crypto_helper();
        $qualificaExpr = $crypto->decrypt_concat('p.qualifica');
        $cognomeExpr = $crypto->decrypt_concat('p.cognome');
        $nomeExpr = $crypto->decrypt_concat('p.nome');

        return \Config\Database::connect()->query("
            SELECT
                p.id_personale,
                CONCAT(CONCAT(
                    {$qualificaExpr},
                    ' ',
                    {$cognomeExpr},
                    ' ',
                    {$nomeExpr}
                )) AS titolo,
                {$cognomeExpr} AS cognome
            FROM dap03_personale p
            WHERE p.tipo = 1
              AND p.titolare = 1
              AND p.id_user NOT IN (15,41)
              AND p.id_personale IN ({$idsSql})
            ORDER BY cognome
        ")->getResultArray();
    }

    private function patientSendNoticeBody(?string $targetCode): ?string
    {
        $targetCode = strtoupper(trim((string)$targetCode));

        return match ($targetCode) {
            'MEDICO' => "Si ricorda di non richiedere tramite questo canale certificati di malattia o appuntamenti per visite.\nIl medico leggerà i messaggi appena possibile; non è garantita la lettura in tempi brevi.",
            'INFERMIERE' => "Si ricorda di non richiedere tramite questo canale certificati di malattia o appuntamenti per visite.\nL'infermiere leggerà i messaggi appena possibile; non è garantita la lettura in tempi brevi.",
            'SEGRETERIA' => "Il vostro medico vi ricorda che le richieste saranno evase entro 48 h (sabato e domenica esclusi).\nLe ricette saranno stampate salvo indicazioni diverse da specificare nel testo.",
            default => null,
        };
    }

    private function prepareUploadedReplyAttachments(): array
    {
        $files = $this->request->getFileMultiple('files');
        if (!is_array($files)) {
            return [];
        }

        $this->validateUploadedFilesMaxSize($files);

        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'txt'];
        $prepared = [];

        foreach ($files as $file) {
            if (!$file) {
                continue;
            }

            if ((int)$file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $clientName = trim((string)$file->getClientName());
            if (!$file->isValid()) {
                throw new \RuntimeException(
                    $clientName !== ''
                        ? 'Errore durante il caricamento dell\'allegato "' . $clientName . '"'
                        : 'Errore durante il caricamento dell\'allegato'
                );
            }

            $ext = strtolower((string)$file->getExtension());
            if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                throw new \RuntimeException(
                    $clientName !== ''
                        ? 'Formato allegato non supportato: ' . $clientName
                        : 'Formato allegato non supportato'
                );
            }

            $plainBytes = @file_get_contents($file->getTempName());
            if ($plainBytes === false) {
                throw new \RuntimeException(
                    $clientName !== ''
                        ? 'Impossibile leggere l\'allegato "' . $clientName . '"'
                        : 'Impossibile leggere l\'allegato caricato'
                );
            }

            $cipherBytes = FileCrypt::encryptBytes($plainBytes);
            if ($cipherBytes === false) {
                throw new \RuntimeException(
                    $clientName !== ''
                        ? 'Impossibile cifrare l\'allegato "' . $clientName . '"'
                        : 'Impossibile cifrare l\'allegato caricato'
                );
            }

            $prepared[] = [
                'original_name' => $clientName !== '' ? $clientName : 'allegato.' . $ext,
                'stored_name'   => bin2hex(random_bytes(16)) . '.' . $ext . '.crypto',
                'mime_type'     => (string)$file->getClientMimeType(),
                'file_size'     => strlen($cipherBytes),
                'cipher_bytes'  => $cipherBytes,
            ];
        }

        return $prepared;
    }

public function inbox()
{
    //$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $doctorPersonaleId = 0;
    if (in_array($role, ['SEGRETERIA','INFERMIERE'], true)) {
        $requestedDoctorId = (int)($this->request->getGet('id_dottore') ?? 0);
        $doctorPersonaleId = $this->resolveMailboxDoctorContext(
            $role,
            $requestedDoctorId > 0 ? $requestedDoctorId : null
        );
    }

    $this->logMailboxRoleSnapshot('inbox.afterRoleResolution', [
        'me'                => $me,
        'role'              => $role,
        'requestedDoctorId' => (int)($this->request->getGet('id_dottore') ?? 0),
        'resolvedDoctorId'  => $doctorPersonaleId,
    ]);

    $q = trim((string)($this->request->getGet('q') ?? ''));

    $defaultStatus = ($role === 'PAZIENTE') ? 'all' : 'unhandled';
    $status = strtolower(trim((string)($this->request->getGet('status') ?? $defaultStatus)));
    if (!in_array($status, ['unhandled','handled','all'], true)) $status = $defaultStatus;
    if ($q !== '') $status = 'all';
    if ($role === 'PAZIENTE') $status = 'all';

    // paging persistente (in base a filtri)
    $paging = $this->resolvePaging('inbox', [
        'q'          => $q,
        'status'     => $status,
        'id_dottore' => $doctorPersonaleId,
    ]);

    $data = $this->svc->listInbox(
        $me, $role, $doctorPersonaleId, $q, $status,
        $paging['page'], $paging['per_page']
    );

    // =========================
    // TEST PAGINAZIONE: 400 righe finte
    // attiva con ?test400=1
    // =========================
    $test400 = (int)($this->request->getGet('test400') ?? 0);
    if ($test400 === 1) {
        $TOTAL = 400;

        $page    = max(1, (int)$paging['page']);
        $perPage = max(1, (int)$paging['per_page']);
        $offset  = ($page - 1) * $perPage;

        // Genera solo le righe della pagina corrente
        $start = $offset + 1;
        $end   = min($TOTAL, $offset + $perPage);

        // prendo un "template" (se esiste), altrimenti creo una base minima
        $tpl = $data['rows'][0] ?? [
            'id_thread'        => 0,
            'id_message'       => 0,
            'message_type'     => 'DIRECT',
            'sender_nome'      => 'Mario',
            'sender_cognome'   => 'Rossi',
            'sender_role'      => 'PAZIENTE',
            'root_nome'        => '',
            'root_cognome'     => '',
            'is_read'          => 0,
            'is_handled'       => 0,
            'has_attachments'  => 0,
            'created_human'    => date('H:i'),
            'created_at'       => date('Y-m-d H:i:s'),
            'body_plain'       => 'Messaggio di test',
        ];

        $fakeRows = [];
        for ($i = $start; $i <= $end; $i++) {
            $r = $tpl;

            // IMPORTANTISSIMO: unici per non rompere click/checkbox
            $r['id_message'] = 900000 + $i;
            $r['id_thread']  = 800000 + $i;

            // testo visibile
            $r['body_plain'] = "[TEST {$i}/{$TOTAL}] " . (string)($tpl['body_plain'] ?? 'Messaggio di test');

            // opzionale: alterna letto/gestita per vedere badge e bold
            $r['is_read']    = ($i % 3 === 0) ? 1 : 0;
            $r['is_handled'] = ($i % 4 === 0) ? 1 : 0;

            $fakeRows[] = $r;
        }

        $data['rows'] = $fakeRows;

        // pager coerente
        $data['pager'] = [
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $TOTAL,
            'pages'   => (int)ceil($TOTAL / $perPage),
        ];
    }
    // =========================
    // /TEST PAGINAZIONE
    // =========================

    $sidebarData = $this->buildMailboxSidebarData($role, $doctorPersonaleId);

    return view('posta', [
        'folder'       => 'inbox',
        'boxTitle'     => 'Posta in Arrivo',
        'rows'         => $data['rows'],
        'pager'        => $data['pager'],
        'roleLabel'    => $role,
        'doctorFilter' => $doctorPersonaleId,
        'q'            => $q,
        'status'       => $status,
    ] + $sidebarData);
}


public function setHandled(int $messageId)
{
    ////$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    if ($role === 'PAZIENTE') {
        return redirect()->to(site_url('messaggi/inbox'))->with('err', 'Operazione non consentita');
    }

    $handled = (int)($this->request->getPost('handled') ?? 0);
    $handled = $handled ? 1 : 0;

    $doctorPersonaleId = (int)($this->request->getPost('id_dottore') ?? (session()->get('selectedDoctorId') ?? 0));
    if (!in_array($role, ['SEGRETERIA','INFERMIERE'], true)) $doctorPersonaleId = 0;

    $status = strtolower(trim((string)($this->request->getPost('status') ?? 'all')));
    if (!in_array($status, ['unhandled','handled','all'], true)) $status = 'all';

    $flagsUserId = $me;
    if (in_array($role, ['SEGRETERIA','INFERMIERE'], true) && $doctorPersonaleId > 0) {
        $flagsUserId = $this->svc->resolveFlagsUserIdForContext($me, $role, $doctorPersonaleId);
    }

    // ✅ FIX
    $this->svc->setHandledMessage((int)$messageId, $me, $role, $flagsUserId, $handled);
    //$this->refreshNavigation(true);

    $redir = site_url('messaggi/inbox?status='.$status);
    if (in_array($role, ['SEGRETERIA','INFERMIERE'], true) && $doctorPersonaleId > 0) {
        $redir .= '&id_dottore='.$doctorPersonaleId;
    }
    if ($this->request->getPost('q') !== null) {
        $redir .= '&q='.urlencode((string)$this->request->getPost('q'));
    }

    return redirect()->to($redir)->with('ok', $handled ? 'Segnata come gestita' : 'Segnata come non gestita');
}
public function printThread($threadId)
{
    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $threadId = (int)$threadId;

    // Recupero messaggi (include body_plain + sender_display + attachments)
    $messages = $this->svc->getThreadMessages($me, $role, $threadId);
    $doctorContextId = (int)$this->svc->getDoctorContextIdForThread($threadId);

    // HTML della stampa (vista dedicata)
    $html = view('Posta/thread_print_pdf', [
        'threadId'        => $threadId,
        'messages'        => $messages,
        'roleLabel'       => $role,
        'doctorContextId' => $doctorContextId,
    ]);

    // Dompdf
    try {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);        // immagini remote se un giorno ti servono
        $options->set('defaultFont', 'DejaVu Sans');   // ottimo per accenti/UTF-8

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'conversazione_thread_' . $threadId . '_' . date('Ymd_His') . '.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($dompdf->output());

    } catch (\Throwable $e) {
        // Se Dompdf non è disponibile o errore rendering
        return redirect()->to(site_url('messaggi/thread/' . $threadId))
            ->with('err', 'Impossibile generare il PDF. Dettaglio: ' . $e->getMessage());
    }
}
/**
 * Paginazione + persistenza in sessione.
 * - per_page supportati: 5,10,25,50,100
 * - page e per_page rimangono in sessione per folder + filtri (q/status/id_dottore)
 */
private function resolvePaging(string $folder, array $filters): array
{
    $allowed = [5,10,25,50,100];
    $defaultPerPage = 25;

    $sessKey = 'msg_list_' . $folder;

    $stored = session()->get($sessKey);
    if (!is_array($stored)) $stored = [];

    $storedPerPage = (int)($stored['per_page'] ?? $defaultPerPage);
    if (!in_array($storedPerPage, $allowed, true)) $storedPerPage = $defaultPerPage;

    $storedPage = (int)($stored['page'] ?? 1);
    if ($storedPage < 1) $storedPage = 1;

    $filtersHash = md5(json_encode($filters));

    $getPerPage = (int)($this->request->getGet('per_page') ?? 0);
    $getPage    = (int)($this->request->getGet('page') ?? 0);

    // se cambiano i filtri -> reset page=1
    $prevHash = (string)($stored['filters'] ?? '');
    if ($prevHash !== $filtersHash) {
        $storedPage = 1;
    }

    // per_page scelto dall'utente -> salva e resetta pagina
    if ($getPerPage > 0) {
        if (!in_array($getPerPage, $allowed, true)) $getPerPage = $defaultPerPage;

        if ($getPerPage !== $storedPerPage) {
            $storedPerPage = $getPerPage;
            $storedPage = 1;
        }
    }

    // page scelta dall'utente
    if ($getPage > 0) {
        $storedPage = $getPage;
    }

    session()->set($sessKey, [
        'per_page' => $storedPerPage,
        'page'     => $storedPage,
        'filters'  => $filtersHash,
    ]);

    return ['page' => $storedPage, 'per_page' => $storedPerPage];
}

private function buildListQuery(array $params): string
{
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v === null) continue;
        if ($v === '') continue;
        $clean[$k] = $v;
    }
    return http_build_query($clean);
}

private function resolveAttachmentMime(string $name, ?string $mime, string $bytes): string
{
    $mime = strtolower(trim((string)($mime ?? '')));
    if ($mime !== '' && !in_array($mime, ['application/octet-stream', 'binary/octet-stream'], true)) {
        return $mime;
    }

    try {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = strtolower(trim((string)$finfo->buffer($bytes)));
            if ($detected !== '' && !in_array($detected, ['application/octet-stream', 'binary/octet-stream'], true)) {
                return $detected;
            }
        }
    } catch (\Throwable $e) {
        // fallback on extension below
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $map = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'svg'  => 'image/svg+xml',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'html' => 'text/html',
        'htm'  => 'text/html',
    ];

    return $map[$ext] ?? ($mime !== '' ? $mime : 'application/octet-stream');
}

public function bulkHandled()
{
    ////$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    if ($role === 'PAZIENTE') {
        return redirect()->to(site_url('messaggi/inbox'))->with('err', 'Operazione non consentita');
    }

    $ids = $this->request->getPost('ids');
    if (!is_array($ids) || empty($ids)) {
        return redirect()->back()->with('err', 'Seleziona almeno un messaggio');
    }

    $handled = (int)($this->request->getPost('handled') ?? 1);
    $handled = $handled ? 1 : 0;

    $doctorPersonaleId = (int)($this->request->getPost('id_dottore') ?? (session()->get('selectedDoctorId') ?? 0));
    if (!in_array($role, ['SEGRETERIA','INFERMIERE'], true)) $doctorPersonaleId = 0;

    $status = strtolower(trim((string)($this->request->getPost('status') ?? 'all')));
    if (!in_array($status, ['unhandled','handled','all'], true)) $status = 'all';

    $flagsUserId = $me;
    if (in_array($role, ['SEGRETERIA','INFERMIERE'], true) && $doctorPersonaleId > 0) {
        $flagsUserId = $this->svc->resolveFlagsUserIdForContext($me, $role, $doctorPersonaleId);
    }

    $done = $this->svc->setHandledBulk($ids, $me, $role, $flagsUserId, $handled);
    //$this->refreshNavigation(true);

    $redir = site_url('messaggi/inbox?status='.$status);
    if (in_array($role, ['SEGRETERIA','INFERMIERE'], true) && $doctorPersonaleId > 0) {
        $redir .= '&id_dottore='.$doctorPersonaleId;
    }

    return redirect()->to($redir)->with('ok', "Aggiornati: {$done}");
}

   public function sent()
{
    //$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $q = trim((string)($this->request->getGet('q') ?? ''));
    $doctorPersonaleId = in_array($role, ['SEGRETERIA','INFERMIERE'], true)
        ? $this->resolveMailboxDoctorContext($role)
        : 0;

    $paging = $this->resolvePaging('sent', ['q' => $q]);

    $data = $this->svc->listSent($me, $role, $q, $paging['page'], $paging['per_page'], $doctorPersonaleId);
    $sidebarData = $this->buildMailboxSidebarData($role, $doctorPersonaleId);

    return view('posta', [
        'folder'    => 'sent',
        'boxTitle'  => 'Inviati',
        'rows'      => $data['rows'],
        'pager'     => $data['pager'],
        'roleLabel' => $role,
        'q'         => $q,
    ] + $sidebarData);
}
public function drafts()
{
    //$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $q = trim((string)($this->request->getGet('q') ?? ''));
    $doctorPersonaleId = in_array($role, ['SEGRETERIA','INFERMIERE'], true)
        ? $this->resolveMailboxDoctorContext($role)
        : 0;

    $paging = $this->resolvePaging('drafts', ['q' => $q]);

    $data = $this->svc->listDrafts($me, $role, $q, $paging['page'], $paging['per_page']);
    $sidebarData = $this->buildMailboxSidebarData($role, $doctorPersonaleId);

    return view('posta', [
        'folder'    => 'drafts',
        'boxTitle'  => 'Bozze',
        'rows'      => $data['rows'],
        'pager'     => $data['pager'],
        'roleLabel' => $role,
        'q'         => $q,
    ] + $sidebarData);
}


    public function compose()
    {
        //$this->refreshNavigation();

        $me   = $this->meId();
        $role = $this->myRoleLabel();

        $draftId = (int) $this->request->getGet('draft');
        $draft = null;
        if ($draftId > 0) {
            try {
                $draft = $this->svc->loadDraft($draftId, $me, $role);
            } catch (\Throwable $e) {
                log_message('error', 'MESSAGES compose invalid draft: draftId={draftId}, me={me}, role={role}, error={error}', [
                    'draftId' => $draftId,
                    'me'      => $me,
                    'role'    => $role,
                    'error'   => $e->getMessage(),
                ]);

                if (str_contains((string)$e->getMessage(), 'Bozza non trovata')) {
                    return redirect()->to(site_url('messaggi/scrivi'));
                }

                return redirect()
                    ->to(site_url('messaggi/scrivi'))
                    ->with('err', 'Bozza non trovata o non piu accessibile.');
            }
        }

        $doctorPersonaleId = in_array($role, ['SEGRETERIA','INFERMIERE'], true)
            ? $this->resolveMailboxDoctorContext($role)
            : 0;
        $sidebarData = $this->buildMailboxSidebarData($role, $doctorPersonaleId);

        return view('compose/compose', [
            'roleLabel' => $role,
            'draft'     => $draft,
        ] + $sidebarData);
    }

   public function thread($threadId)
{
    //$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $threadId = (int) $threadId;

    $box = strtolower(trim((string)($this->request->getGet('folder') ?? 'inbox')));
    if (!in_array($box, ['inbox','sent','drafts'], true)) $box = 'inbox';

    $messages = $this->svc->getThreadMessages($me, $role, $threadId);
    //$this->refreshNavigation(true);
    $doctorContextId = $this->svc->getDoctorContextIdForThread($threadId);
    $selectedDoctorId = in_array($role, ['SEGRETERIA','INFERMIERE'], true)
        ? $this->resolveMailboxDoctorContext($role, $doctorContextId > 0 ? $doctorContextId : null)
        : 0;
    $sidebarData = $this->buildMailboxSidebarData($role, $selectedDoctorId);

    return view('Posta/read', [
        'threadId'        => $threadId,
        'messages'        => $messages,
        'roleLabel'       => $role,
        'doctorContextId' => $doctorContextId,
        'box'             => $box,
    ] + $sidebarData);
}


    /* ===== actions ===== */

public function delete($messageId)
{
    //$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $flagsUserId = $me;
    $redir = site_url('messaggi/inbox');

    // ✅ se segreteria/infermiere: flags e redirect devono stare sul "dottore in contesto"
    if (in_array($role, ['SEGRETERIA', 'INFERMIERE'], true)) {
        $threadId = $this->svc->getThreadIdByMessage((int)$messageId);
        if ($threadId > 0) {
            $doctorContextId = (int)$this->svc->getDoctorContextIdForThread($threadId);
            if ($doctorContextId > 0) {
                $flagsUserId = $this->svc->resolveFlagsUserIdForContext($me, $role, $doctorContextId);
                $redir = site_url('messaggi/inbox?id_dottore=' . $doctorContextId);
            }
        }
    }

    $this->svc->softDeleteMessage((int)$messageId, $me, $role, $flagsUserId);
    //$this->refreshNavigation(true);

    // ✅ dopo elimina torna sempre alla posta in arrivo (non redirect()->back())
    return redirect()->to($redir)->with('ok', 'Messaggio eliminato');
}
public function bulkDelete()
{
    //$this->refreshNavigation();

    $me   = $this->meId();
    $role = $this->myRoleLabel();

    $ids = $this->request->getPost('ids');
    if (!is_array($ids) || empty($ids)) {
        return redirect()->back()->with('err', 'Seleziona almeno un messaggio');
    }

    // per mantenere il contesto dottore (se segreteria/infermiere)
    $doctorPersonaleId = (int)($this->request->getPost('id_dottore') ?? (session()->get('selectedDoctorId') ?? 0));

    $deleted = 0;

    foreach ($ids as $id) {
        $messageId = (int)$id;
        if ($messageId <= 0) continue;

        $flagsUserId = $me;

        // ✅ stessa logica della delete singola: flags e redirect sul dottore in contesto
        if (in_array($role, ['SEGRETERIA', 'INFERMIERE'], true)) {
            $threadId = $this->svc->getThreadIdByMessage($messageId);
            if ($threadId > 0) {
                $doctorContextId = (int)$this->svc->getDoctorContextIdForThread($threadId);
                if ($doctorContextId > 0) {
                    $flagsUserId = $this->svc->resolveFlagsUserIdForContext($me, $role, $doctorContextId);
                    $doctorPersonaleId = $doctorContextId; // per redirect finale
                }
            }
        }

        $this->svc->softDeleteMessage($messageId, $me, $role, $flagsUserId);
        $deleted++;
    }

    $redir = site_url('messaggi/inbox');
    if (in_array($role, ['SEGRETERIA','INFERMIERE'], true) && $doctorPersonaleId > 0) {
        $redir = site_url('messaggi/inbox?id_dottore=' . $doctorPersonaleId);
    }

    //$this->refreshNavigation(true);

    return redirect()->to($redir)->with('ok', $deleted > 0 ? 'Messaggi eliminati' : 'Nessun messaggio eliminato');
}


    public function sendDraft()
    {
        //$this->refreshNavigation();

        $me   = $this->meId();
        $role = $this->myRoleLabel();

        $draftId = (int) $this->request->getPost('id_draft');
        $patientSendNoticeBody = null;

        log_message('error', 'MESSAGES sendDraft start: draftId={draftId}, me={me}, role={role}', [
            'draftId' => $draftId,
            'me'      => $me,
            'role'    => $role,
        ]);

        try {
            if ($role === 'PAZIENTE' && $draftId > 0) {
                $draft = $this->svc->loadDraft($draftId, $me, $role);
                $patientSendNoticeBody = $this->patientSendNoticeBody((string)($draft['patient_target_code'] ?? ''));
            }

            $res = $this->svc->sendFromDraft($draftId, $me, $role);
            //$this->refreshNavigation(true);
        } catch (\Throwable $e) {
            if (str_contains((string)$e->getMessage(), 'Bozza non trovata')) {
                log_message('error', 'MESSAGES sendDraft missing draft: draftId={draftId}, me={me}, role={role}, error={error}', [
                    'draftId' => $draftId,
                    'me'      => $me,
                    'role'    => $role,
                    'error'   => $e->getMessage(),
                ]);

                return redirect()->to(site_url('messaggi/scrivi'));
            }

            $target = site_url('messaggi/scrivi' . ($draftId > 0 ? '?draft=' . $draftId : ''));
            log_message('error', 'MESSAGES sendDraft failed: draftId={draftId}, me={me}, role={role}, error={error}', [
                'draftId' => $draftId,
                'me'      => $me,
                'role'    => $role,
                'error'   => $e->getMessage(),
            ]);
            return redirect()->to($target)->with('err', $e->getMessage());
        }

        $threadUrl = site_url('messaggi/thread/' . (int)$res['thread_id']) . '?folder=sent';

        log_message('error', 'MESSAGES sendDraft success: draftId={draftId}, me={me}, role={role}, threadId={threadId}, messageId={messageId}, redirect={redirect}', [
            'draftId'  => $draftId,
            'me'       => $me,
            'role'     => $role,
            'threadId' => (int)($res['thread_id'] ?? 0),
            'messageId'=> (int)($res['message_id'] ?? 0),
            'redirect' => $threadUrl,
        ]);

        $redirect = redirect()
            ->to($threadUrl)
            ->with('ok', 'Messaggio inviato');

        if ($patientSendNoticeBody !== null) {
            $redirect = $redirect->with('patient_send_notice_body', $patientSendNoticeBody);
        }

        return $redirect;
    }

    public function reply($parentMessageId)
    {
        //$this->refreshNavigation();

        $me   = $this->meId();
        $role = $this->myRoleLabel();

        try {
            $body = (string) $this->request->getPost('body');
            $attachments = $this->prepareUploadedReplyAttachments();
            $res = $this->svc->reply((int)$parentMessageId, $me, $role, $body, $attachments);
            //$this->refreshNavigation(true);

            return redirect()->to("/messaggi/thread/{$res['thread_id']}")->with('ok', 'Risposta inviata');
        } catch (\Throwable $e) {
            return redirect()->back()
                ->withInput()
                ->with('err', $e->getMessage())
                ->with('reply_form_open', (int)$parentMessageId);
        }
    }
public function attachment(int $attachmentId)
{
    $me   = $this->meId();
    $role = $this->myRoleLabel();

    log_message('error', '[attachment] INIZIO attachmentId={attachmentId} userId={userId} role={role}', [
        'attachmentId' => $attachmentId,
        'userId'       => $me,
        'role'         => $role,
    ]);

    try {
        $att = $this->svc->getAttachmentForUser($attachmentId, $me, $role);

        log_message('error', '[attachment] Allegato trovato: {att}', [
            'att' => json_encode($att, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $path = $this->svc->resolveAttachmentPath($att);

        log_message('error', '[attachment] Path risolto: {path}', [
            'path' => $path,
        ]);
    } catch (\Throwable $e) {
        log_message('error', '[attachment] Errore recupero allegato attachmentId={attachmentId}: {message}', [
            'attachmentId' => $attachmentId,
            'message'      => $e->getMessage(),
        ]);

        return $this->response->setStatusCode(404)->setBody('Allegato non disponibile');
    }

    $name = trim((string)($att['original_name'] ?? ''));
    if ($name === '') {
        $name = preg_replace('/\.crypto$/i', '', basename($path));
    }
    $mime     = (string)($att['mime_type'] ?? 'application/octet-stream');
    $download = ((int)($this->request->getGet('download') ?? 0) === 1);

    log_message('error', '[attachment] Dati output: name={name}, mime={mime}, download={download}', [
        'name'     => $name,
        'mime'     => $mime,
        'download' => $download ? '1' : '0',
    ]);

    log_message('error', '[attachment] Controllo file path={path} exists={exists} is_file={is_file} readable={readable}', [
        'path'     => $path,
        'exists'   => file_exists($path) ? '1' : '0',
        'is_file'  => is_file($path) ? '1' : '0',
        'readable' => is_readable($path) ? '1' : '0',
    ]);

    if (file_exists($path) && is_file($path)) {
        log_message('error', '[attachment] Dimensione file path={path} size={size}', [
            'path' => $path,
            'size' => (string)@filesize($path),
        ]);
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        $lastError = error_get_last();

        log_message('error', '[attachment] file_get_contents FALLITA path={path} errore={errore}', [
            'path'   => $path,
            'errore' => json_encode($lastError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->response->setStatusCode(404)->setBody('Allegato non disponibile');
    }

    log_message('error', '[attachment] file_get_contents OK path={path} bytes={bytes}', [
        'path'  => $path,
        'bytes' => strlen($bytes),
    ]);

    // se è .crypto, decifra prima di inviare al browser
    if (preg_match('/\.crypto$/i', (string)$path)) {
        log_message('error', '[attachment] File .crypto rilevato path={path}', [
            'path' => $path,
        ]);

        $dec = FileCrypt::decryptStoredPayload($bytes);
        if ($dec === false) {
            log_message('error', '[attachment] decryptBytes FALLITA path={path}', [
                'path' => $path,
            ]);

            return $this->response->setStatusCode(500)->setBody('Errore nella decrittazione del file.');
        }

        $bytes = $dec;

        log_message('error', '[attachment] decryptBytes OK path={path} plain_bytes={bytes}', [
            'path'  => $path,
            'bytes' => strlen($bytes),
        ]);
    }

    $mime = $this->resolveAttachmentMime($name, $mime, $bytes);

    $disposition = $download ? 'attachment' : 'inline';

    log_message('error', '[attachment] Invio risposta path={path} disposition={disposition} filename={filename}', [
        'path'        => $path,
        'disposition' => $disposition,
        'filename'    => $name,
    ]);

    return $this->response
        ->setHeader('Content-Type', $mime)
        ->setHeader('Content-Disposition', $disposition . '; filename="' . addslashes($name) . '"')
        ->setBody($bytes);
}
    public function forward($messageId)
    {
        $me   = $this->meId();
        $role = $this->myRoleLabel();

        $dest = (string) $this->request->getPost('dest');
        $note = (string) $this->request->getPost('note');

        $res  = $this->svc->forward((int)$messageId, $me, $role, $dest, $note);
        return redirect()->to("/messaggi/thread/{$res['thread_id']}")->with('ok','Inoltro inviato');
    }

    /* ===== API ===== */

    public function apiDraftSave()
    {
        $me   = $this->meId();
        $role = $this->myRoleLabel();

        $payload = $this->request->getJSON(true) ?? [];
        try {
            $res = $this->svc->saveDraft($payload, $me, $role);
            return $this->response->setJSON($res);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    public function apiDraftLoad($draftId)
    {
        $me = $this->meId();
        $role = $this->myRoleLabel();
        try {
            $draft = $this->svc->loadDraft((int)$draftId, $me, $role);
            return $this->response->setJSON(['ok'=>true,'draft'=>$draft]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(404)->setJSON(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }

    public function apiPatients()
    {
        $me   = $this->meId();
        $role = $this->myRoleLabel();

        if ($role !== 'DOTTORE') {
            return $this->response->setStatusCode(403)->setJSON(['ok'=>false,'error'=>'Non autorizzato']);
        }

        $term = (string) $this->request->getGet('q');
        $rows = $this->svc->autocompletePatientsForDoctor($me, $term); // $me = id_personale
        return $this->response->setJSON(['ok'=>true,'items'=>$rows]);
    }

    public function apiUploadDraftAttachment()
    {
        $me = $this->meId();
        $role = $this->myRoleLabel();
        try {
            $draftId = (int)$this->request->getPost('id_draft');
            if ($draftId <= 0) return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'error'=>'Bozza mancante']);

            $files = $this->request->getFiles();
            if (!isset($files['files'])) return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'error'=>'Nessun file']);

            $this->validateUploadedFilesMaxSize($files['files']);

            $allowedExt = ['pdf','jpg','jpeg','png','doc','docx','txt'];

            foreach ($files['files'] as $f) {
                $ext = strtolower($f->getExtension());
                if (!in_array($ext, $allowedExt, true)) continue;

                $baseDir = WRITEPATH . 'uploads/messages/drafts/' . $draftId . '/';
                if (!is_dir($baseDir)) @mkdir($baseDir, 0770, true);

               $stored = bin2hex(random_bytes(16)) . '.' . $ext . '.crypto';

    // Leggo dal tmp e cifro
    $tmpPath = $f->getTempName();
    $plainBytes = @file_get_contents($tmpPath);
    if ($plainBytes === false) {
        continue; // oppure log + errore
    }

    $cipherBytes = FileCrypt::encryptBytes($plainBytes);
    if ($cipherBytes === false) {
        continue; // oppure log + errore
    }

    // Scrivo io il file cifrato nel path finale (NON uso move per non salvare in chiaro)
    $finalPath = $baseDir . $stored;
    if (@file_put_contents($finalPath, $cipherBytes) === false) {
            continue; // oppure log + errore
    }

    // Meta come prima (ATTENZIONE: file_size = size cifrata, se vuoi puoi mettere size originale)
    $meta = [
        'original_name' => $f->getClientName(),
        'stored_name'   => $stored,
        'mime_type'     => $f->getClientMimeType(),
        'file_size'     => strlen($cipherBytes),
        'storage_path'  => $finalPath,
    ];

    $this->svc->addDraftAttachment($draftId, $me, $role, $meta);
            }

            $list = $this->svc->listDraftAttachments($draftId, $me, $role);
            return $this->response->setJSON(['ok'=>true,'attachments'=>$list]);
        } catch (\Throwable $e) {
            log_message('error', 'apiUploadDraftAttachment error: {message}', [
                'message' => $e->getMessage(),
            ]);

            $statusCode = $e instanceof \RuntimeException ? 400 : 500;

            return $this->response
                ->setStatusCode($statusCode)
                ->setJSON([
                    'ok'    => false,
                    'error' => $e instanceof \RuntimeException
                        ? $e->getMessage()
                        : 'Errore durante il caricamento dell\'allegato',
                ]);
        }
    }

    public function apiDeleteDraftAttachment($attachmentId)
    {
        $me = $this->meId();
        $role = $this->myRoleLabel();
        try {
            $this->svc->deleteDraftAttachment((int)$attachmentId, $me, $role);
            return $this->response->setJSON(['ok'=>true]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON(['ok'=>false,'error'=>$e->getMessage()]);
        }
    }
}
