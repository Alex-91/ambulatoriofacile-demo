<?php

namespace App\Controllers\Posta;
use CodeIgniter\Exceptions\PageNotFoundException;
use App\Controllers\BaseController;
use App\Models\MessagesModel;
use App\Models\MessageModel;
use App\Libraries\Crypto_helper;
use App\Libraries\SystemUserMask;
use Config\Database;
use App\Models\AttachmentTempModel;
use App\Services\SessionNavigationService;
use App\Services\StaffDoctorAccessService;
use Config\Services;
use Dompdf\Dompdf;
use Dompdf\Options;

class PostaController extends BaseController
{
    private ?SessionNavigationService $navigation = null;

    /* ===========================
     * ------- UTILITIES ---------
     * =========================== */

    protected function navigation(): SessionNavigationService
    {
        if ($this->navigation === null) {
            $this->navigation = new SessionNavigationService();
        }

        return $this->navigation;
    }

    /**
     * Ritorna l'id_personale dell'utente loggato.
     * Prova prima da utente_sess->id_personale, altrimenti da session('userId').
     */
    protected function getCurrentUserId(): ?int
    {
        $utente = session()->get('utente_sess');
        if ($utente && isset($utente->id_personale)) {
            return (int) $utente->id_personale;
        }
        $fallback = session()->get('userId');
        return $fallback ? (int) $fallback : null;
    }

    protected function logMailboxRoleSnapshot(string $stage, array $extra = []): void
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
            'POSTA role snapshot [{stage}] tipoUser={sessionTipoUser} rawTipoPers={utenteTipoPers} tipoStringa={utenteTipoStr} sessionUserId={sessionUserId} utenteIdUser={utenteIdUser} utenteIdPersonale={utenteIdPersonale} utenteIdUtente={utenteIdUtente} selectedDoctorId={sessionDoctorId} uri={uri} extra=' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $context
        );
    }

    protected function normalizeMailboxTipoPers(int $tipoPers): int
    {
        $normalized = $tipoPers === StaffDoctorAccessService::TIPO_ADMIN
            ? StaffDoctorAccessService::TIPO_SEGRETERIA
            : $tipoPers;

        if ($tipoPers === StaffDoctorAccessService::TIPO_ADMIN || !in_array($tipoPers, [
            StaffDoctorAccessService::TIPO_DOTTORE,
            StaffDoctorAccessService::TIPO_INFERMIERE,
            StaffDoctorAccessService::TIPO_SEGRETERIA,
            StaffDoctorAccessService::TIPO_ADMIN,
        ], true)) {
            $this->logMailboxRoleSnapshot('normalizeMailboxTipoPers', [
                'rawTipoPersArg'        => $tipoPers,
                'normalizedTipoPersArg' => $normalized,
            ]);
        }

        return $normalized;
    }

    protected function isSegreteriaOrInfermiere(int $tipoPers): bool
    {
        $tipoPers = $this->normalizeMailboxTipoPers($tipoPers);
        return in_array($tipoPers, [StaffDoctorAccessService::TIPO_INFERMIERE, StaffDoctorAccessService::TIPO_SEGRETERIA], true);
    }

    protected function getValidatedSelectedDoctorId(int $tipoPers, ?int $candidateDoctorId = null): int
    {
        $rawTipoPers = $tipoPers;
        $tipoPers = $this->normalizeMailboxTipoPers($tipoPers);
        if (!$this->isSegreteriaOrInfermiere($tipoPers)) {
            $this->logMailboxRoleSnapshot('getValidatedSelectedDoctorId.notStaff', [
                'rawTipoPersArg'        => $rawTipoPers,
                'normalizedTipoPersArg' => $tipoPers,
                'candidateDoctorId'     => (int)($candidateDoctorId ?? 0),
            ]);
            session()->remove('selectedDoctorId');
            return 0;
        }

        $utente = session()->get('utente_sess');
        $staffPersonaleId = (int)($utente->id_personale ?? 0);
        $doctorId = (int)($candidateDoctorId ?? (session()->get('selectedDoctorId') ?? 0));

        if ($staffPersonaleId <= 0 || $doctorId <= 0) {
            $this->logMailboxRoleSnapshot('getValidatedSelectedDoctorId.missingIds', [
                'rawTipoPersArg'        => $rawTipoPers,
                'normalizedTipoPersArg' => $tipoPers,
                'candidateDoctorId'     => (int)($candidateDoctorId ?? 0),
                'resolvedDoctorId'      => $doctorId,
                'staffPersonaleId'      => $staffPersonaleId,
            ]);
            session()->remove('selectedDoctorId');
            return 0;
        }

        $access = new StaffDoctorAccessService(Database::connect());
        if (!$access->canStaffAccessDoctor($staffPersonaleId, $tipoPers, $doctorId, 'posta')) {
            $this->logMailboxRoleSnapshot('getValidatedSelectedDoctorId.accessDenied', [
                'rawTipoPersArg'        => $rawTipoPers,
                'normalizedTipoPersArg' => $tipoPers,
                'candidateDoctorId'     => (int)($candidateDoctorId ?? 0),
                'resolvedDoctorId'      => $doctorId,
                'staffPersonaleId'      => $staffPersonaleId,
            ]);
            session()->remove('selectedDoctorId');
            return 0;
        }

        session()->set('selectedDoctorId', $doctorId);
        $this->logMailboxRoleSnapshot('getValidatedSelectedDoctorId.ok', [
            'rawTipoPersArg'        => $rawTipoPers,
            'normalizedTipoPersArg' => $tipoPers,
            'candidateDoctorId'     => (int)($candidateDoctorId ?? 0),
            'resolvedDoctorId'      => $doctorId,
            'staffPersonaleId'      => $staffPersonaleId,
        ]);
        return $doctorId;
    }

    protected function refreshMenuDataForCurrentUser(bool $force = false): void
    {
        $utente = session()->get('utente_sess');
        if (!$utente || empty($utente->id_user)) {
            return;
        }

        try {
            $this->navigation()->refreshCurrentSession($force);
        } catch (\Throwable $e) {
            log_message('error', 'POSTA refreshMenuDataForCurrentUser error: ' . $e->getMessage());
        }
    }
public function pdfThread()
{
    $utente = session()->get('utente_sess');
    if (!$utente) return redirect()->to(base_url('login'));

    $this->refreshMenuDataForCurrentUser();

    $tipoPers         = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 0));
    $isSegOrInf       = $this->isSegreteriaOrInfermiere($tipoPers);
    $selectedDoctorId = $this->getValidatedSelectedDoctorId($tipoPers);

    if ($isSegOrInf && $selectedDoctorId <= 0) {
        return redirect()->to(site_url('posta'));
    }

    // GET params
    $box = (string) ($this->request->getGet('box') ?? 'inbox');
    $box = in_array($box, ['inbox', 'sent'], true) ? $box : 'inbox';

    $compoundId = (string) ($this->request->getGet('uid') ?? '');

    // Proprietario mailbox
    $userId = $this->getMailboxOwnerId();
    if (!$userId) return redirect()->to(base_url('login'));

    // Validazione compoundId
    [$src, $id] = $this->parseCompoundId($compoundId);
    if (!$src || !$id) {
        return redirect()->to(site_url($box === 'sent' ? 'inviata' : 'posta'));
    }

    /** @var MessagesModel $model */
    $model = model(MessagesModel::class);

    // Carica messaggio
    $msg = $model->getOne($box, $src, $id, $userId);
    if (!$msg) {
        return redirect()->to(site_url($box === 'sent' ? 'inviata' : 'posta'));
    }

    /** @var MessageModel $threadModel */
    $threadModel = model(MessageModel::class);

    // rootId
    if ($src === 'R') {
        $rootId = (int) ($msg['id_message_ini'] ?? 0);
    } else {
        $rootId = (int) ($msg['id_message'] ?? $id);
    }
    if ($rootId <= 0) $rootId = (int) $id;

    // Thread completo
    $thread = $threadModel->getThread($rootId);

    // Fallback thread vuoto
    if (empty($thread)) {
        $thread = [[
            'id_message'       => (int) $rootId,
            'mitt'             => $msg['mitt']             ?? '',
            'dest'             => $msg['dest']             ?? '',
            'dataora'          => $msg['dataora']          ?? '',
            'testo'            => $msg['testo']            ?? '',
            'is_html'          => (int) ($msg['is_html']    ?? 1),
            'allegati'         => $threadModel->getAttachments($rootId),
            'mittente_nome'    => $msg['mittente_nome']    ?? '',
            'mittente_cognome' => $msg['mittente_cognome'] ?? '',
            'mitt_prefix'      => $msg['mitt_prefix']      ?? '',
            'inoltrato'        => $msg['inoltrato']        ?? '',
            'email'            => $msg['email']            ?? '',
            'oggetto'          => $msg['oggetto']          ?? '',
        ]];
    }

    // ===== Helpers =====
    $tz = 'Europe/Rome';

    $composeName = function(array $m, string $prefixKey='mitt_prefix', string $cognomeKey='mittente_cognome', string $nomeKey='mittente_nome'): string {
        $s = trim(($m[$prefixKey] ?? '').' '.($m[$cognomeKey] ?? '').' '.($m[$nomeKey] ?? ''));
        $s = preg_replace('/\s+/', ' ', $s);
        return trim((string)$s);
    };

    $plainFromHtml = function(string $html): string {
        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
        $html = preg_replace('/<\/\s*p\s*>/i', "\n\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    };

    // ===== Ricavo NOME DOTTORE (session -> thread) =====
    $doctorName = '';
    try {
        $menuData = session()->get('menuData');
        $dottori  = $menuData['dottori'] ?? [];
        if ($selectedDoctorId > 0 && isset($dottori[$selectedDoctorId]['titolo'])) {
            $doctorName = trim((string)$dottori[$selectedDoctorId]['titolo']);
        }
    } catch (\Throwable $e) {}

    if ($doctorName === '') {
        foreach ($thread as $m) {
            if (strtoupper((string)($m['mitt'] ?? '')) === 'P') {
                $n = $composeName($m);
                if ($n !== '') { $doctorName = $n; break; }
            }
        }
    }
    if ($doctorName === '') $doctorName = 'Dottore';

    // ===== Ricavo NOME PAZIENTE (thread -> session fallback) =====
    $patientName = '';
    foreach ($thread as $m) {
        if (strtoupper((string)($m['mitt'] ?? '')) === 'C') {
            $n = $composeName($m);
            if ($n !== '') { $patientName = $n; break; }
        }
    }
    if ($patientName === '') {
        $patientId = 0;

        foreach ($thread as $m) {
            if (strtoupper((string)($m['dest'] ?? '')) === 'C' && (int)($m['id_dest'] ?? 0) > 0) {
                $patientId = (int)$m['id_dest'];
                break;
            }

            if (strtoupper((string)($m['mitt'] ?? '')) === 'C' && (int)($m['id_mitt'] ?? 0) > 0) {
                $patientId = (int)$m['id_mitt'];
                break;
            }
        }

        if ($patientId > 0) {
            $crypto = new Crypto_helper();
            $db = Database::connect();
            $decNome = $crypto->decryptSenzaAlias('c.nome');
            $decCognome = $crypto->decryptSenzaAlias('c.cognome');

            $row = $db->query("
                SELECT CONCAT($decNome, ' ', $decCognome) AS patient_name
                FROM dap02_clients c
                WHERE c.id_client = ?
                LIMIT 1
            ", [$patientId])->getRowArray();

            $patientName = trim((string)($row['patient_name'] ?? ''));
            $patientName = SystemUserMask::getMaskedClientDisplayName($patientId, $patientName);
        }
    }

    if ($patientName === '') {
        $patientName = trim((string)(session()->get('nome_visualizzato') ?? ''));
    }

    // ===== Stampato da (mai nome operatore segreteria/infermieri) =====
    $tipoUserSess = (int)(session()->get('tipoUser') ?? 0); // 3 = paziente
    if ($tipoUserSess === 3) {
        $viewerLabel = ($patientName !== '') ? $patientName : 'Paziente';
    } else {
        $viewerLabel = match ($tipoPers) {
            1       => $doctorName,     // âœ… nome dottore
            2       => 'Infermieri',    // âœ… maschera
            3       => 'Segreteria',    // âœ… maschera
            default => 'Operatore',
        };
    }

    // ===== Mascheramento mittente/destinatario nel thread =====
    $labelForCode = function(string $code) use ($doctorName): string {
        $c = strtoupper(trim($code));
        return match ($c) {
            'S' => 'Segreteria',
            'I' => 'Infermieri',
            'P' => $doctorName,     // âœ… nome vero dottore (non mascherare)
            'C' => 'Paziente',
            default => $c !== '' ? $c : 'Utente',
        };
    };

    $displayFrom = function(array $m) use ($labelForCode, $composeName, $doctorName): string {
        $code = strtoupper((string)($m['mitt'] ?? ''));

        if (in_array($code, ['S','I'], true)) return $labelForCode($code);

        if ($code === 'P') {
            $real = $composeName($m);
            return $real !== '' ? $real : $doctorName;
        }

        if ($code === 'C') {
            $real = $composeName($m);
            return $real !== '' ? $real : 'Paziente';
        }

        $real = $composeName($m);
        return $real !== '' ? $real : $labelForCode($code);
    };

    $displayTo = function(array $m) use ($labelForCode, $patientName): string {
        $code = strtoupper((string)($m['dest'] ?? ''));

        if (in_array($code, ['S','I'], true)) return $labelForCode($code);
        if ($code === 'P') return $labelForCode('P');
        if ($code === 'C') return $patientName !== '' ? $patientName : 'Paziente';

        return $code !== '' ? $labelForCode($code) : 'Destinatario';
    };

    // ===== Header PDF =====
    $current    = $thread[0] ?? $msg;
    $subject    = (string) ($current['oggetto'] ?? 'Conversazione');
    $stampatoIl = date('d/m/Y H:i');

    $fromName  = $displayFrom($current);
    $fromEmail = (string) ($current['email'] ?? '');

    // ===== CSS + HTML =====
    $css = <<<CSS
@page { margin: 22px 22px; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color:#111; }

.header { border-bottom: 2px solid #2c8895; padding-bottom: 10px; margin-bottom: 14px; }
.h-title { font-size: 16px; font-weight: 700; margin: 0 0 6px 0; }
.meta { font-size: 10px; color:#222; line-height: 1.55; }
.badge { display:inline-block; padding:2px 8px; border-radius: 10px; font-size: 9px; border:1px solid #2c8895; color:#2c8895; margin-left:6px; }
.kv { margin-top: 2px; }

.msg { border:1px solid #e1e1e1; border-radius: 10px; padding:10px 12px; margin:0 0 10px 0; page-break-inside: avoid; }
.msg-head { border-bottom:1px solid #ededed; padding-bottom:6px; margin-bottom:8px; }
.msg-from { font-weight:700; font-size:10px; }
.msg-time { float:right; font-size:10px; color:#333; }
.msg-body { white-space: pre-wrap; line-height: 1.5; }

.att { margin-top:8px; font-size:10px; color:#222; }
.att ul { margin:4px 0 0 16px; padding:0; }
.att li { margin:0 0 2px 0; }

.footer { position: fixed; bottom: -8px; left:0; right:0; font-size:9px; color:#666; text-align:right; }
.small-muted { color:#666; font-size:9px; }
CSS;

    $safeSubject = esc($subject);

    $html  = "<html><head><meta charset='utf-8'><style>{$css}</style></head><body>";
    $html .= "<div class='header'>";
    $html .= "<div class='h-title'>Conversazione</div>";
    $html .= "<div class='meta'>";
    $html .= "<div class='kv'><strong>Oggetto:</strong> {$safeSubject}<span class='badge'>AmbulatorioFacile</span></div>";

    // âœ… qui mettiamo chiaro paziente + dottore
    if ($patientName !== '') {
        $html .= "<div class='kv'><strong>Paziente:</strong> ".esc($patientName)."</div>";
    }
    $html .= "<div class='kv'><strong>Dottore:</strong> ".esc($doctorName)."</div>";

    // âœ… stampato da: mai nome segreteria/infermieri
    $html .= "<div class='kv'><strong>Stampato da:</strong> ".esc($viewerLabel)."</div>";

    // Meta base
    $html .= "<div class='kv'><strong>Da:</strong> ".esc($fromName).($fromEmail ? " <span class='small-muted'>(".esc($fromEmail).")</span>" : "")."</div>";
    $html .= "<div class='kv'><strong>Totale messaggi:</strong> ".count($thread)." &nbsp; <strong>Stampato il:</strong> ".esc($stampatoIl)."</div>";
    $html .= "</div>";
    $html .= "</div>";

    foreach ($thread as $m) {
        $dt = !empty($m['dataora']) ? \CodeIgniter\I18n\Time::parse($m['dataora'], $tz) : null;
        $mWhen = $dt ? $dt->toLocalizedString('d MMM y HH:mm') : '';

        $fromM = $displayFrom($m);
        $toM   = $displayTo($m);

        $isHtml = !empty($m['is_html'] ?? 0);
        $raw    = (string)($m['testo'] ?? '');
        $bodyText = $isHtml ? $plainFromHtml($raw) : $raw;
        $body = nl2br(esc($bodyText), false);

        $html .= "<div class='msg'>";
        $html .= "<div class='msg-head'><span class='msg-from'>Da: ".esc($fromM)." | A: ".esc($toM)."</span>";
        $html .= "<span class='msg-time'>".esc($mWhen)."</span><div style='clear:both'></div></div>";
        $html .= "<div class='msg-body'>{$body}</div>";

        $alleg = $m['allegati'] ?? [];
        if (!empty($alleg) && is_array($alleg)) {
            $html .= "<div class='att'><strong>Allegati:</strong><ul>";
            foreach ($alleg as $a) {
                $nome = esc($a['nome'] ?? 'allegato');
                $tipo = !empty($a['tipo']) ? " (".esc($a['tipo']).")" : "";
                $html .= "<li>{$nome}{$tipo}</li>";
            }
            $html .= "</ul></div>";
        }

        $html .= "</div>";
    }

    $html .= "<div class='footer'>Conversazione â€” {$safeSubject}</div>";
    $html .= "</body></html>";

    // PDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'conversazione_' . preg_replace('/[^a-zA-Z0-9\-_]+/', '_', $subject) . '.pdf';

    return $this->response
        ->setHeader('Content-Type', 'application/pdf')
        ->setHeader('Content-Disposition', 'inline; filename="'.$filename.'"')
        ->setBody($dompdf->output());
}




    /**
     * Ritorna l'id "proprietario" della mailbox corrente.
     *
     * - Se sei dottore (tipo_pers = 1) â†’ il tuo id_personale.
     * - Se sei infermiera/segreteria (2/3) â†’ l'id del dottore selezionato in sessione.
     * - Altrimenti cade sul vecchio meccanismo (userId).
     */
    protected function getMailboxOwnerId(): ?int
    {
        $utente   = session()->get('utente_sess');
        $fallback = session()->get('userId');

        if (!$utente) {
            return $fallback ? (int) $fallback : null;
        }

        $tipoPers = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 0));

        // Se infermiera o segreteria â†’ lavoro sempre per conto del dottore selezionato
        if ($this->isSegreteriaOrInfermiere($tipoPers)) {
            $selectedDoctorId = $this->getValidatedSelectedDoctorId($tipoPers);
            if ($selectedDoctorId > 0) {
                return $selectedDoctorId;
            }
        }

        // Dottore o altri casi â†’ uso id_personale se presente
        if (isset($utente->id_personale)) {
            return (int) $utente->id_personale;
        }
        else
            {
                return (int) $utente->id_client;
            }

        return $fallback ? (int) $fallback : null;
    }

    /**
     * Risponde JSON di errore breve.
     */
    protected function jsonError(string $err, int $code = 400)
    {
        $this->response->setStatusCode($code);
        return $this->response->setJSON(['ok' => false, 'err' => $err]);
    }

    /**
     * Valida un compoundId tipo "M:123" o "R:456".
     * Ritorna array [src, id] oppure [null, null] se invalido.
     */
    protected function parseCompoundId(?string $compoundId): array
    {
        if (!is_string($compoundId)) return [null, null];
        if (!preg_match('/^(M|R):(\d+)$/', $compoundId, $m)) return [null, null];
        return [$m[1], (int) $m[2]];
    }

    /* ===========================
     * --------- ACTIONS ---------
     * =========================== */

public function index()
{
    $utente = session()->get('utente_sess');
    if (!$utente) return redirect()->to(base_url('login'));

    log_message('error', 'DEBUG POSTA sostituzione: userIdSession={sid}, id_user_obj={uid}, id_personale_obj={pid}', [
    'sid' => session()->get('userId'),
    'uid' => $utente->id_user ?? null,
    'pid' => $utente->id_personale ?? null,
]);
    $this->refreshMenuDataForCurrentUser();

    $isDoctor = false;
    $userId   = $this->getCurrentUserId();          // id_personale dell'utente loggato
    $tipoPers = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 1));    // 1 = dottore, 2 = infermiera, 3 = segreteria

    // id_dottore passato in GET (se c'Ã¨)
    $idDottoreGet = (int) ($this->request->getGet('id_dottore') ?? 0);

    // Dottore selezionato salvato in sessione (solo per segreteria/infermiera)
    $storedDoctorId = (int) (session()->get('selectedDoctorId') ?? 0);

    $isSegOrInf       = $this->isSegreteriaOrInfermiere($tipoPers);
    $selectedDoctorId = 0;

    if ($isSegOrInf) {
        if ($idDottoreGet > 0) {
            // Ho scelto un dottore via GET â†’ lo salvo in sessione
            $selectedDoctorId = $this->getValidatedSelectedDoctorId($tipoPers, $idDottoreGet);
        } elseif ($storedDoctorId > 0) {
            // Nessun id in GET ma ho giÃ  selezionato un dottore prima â†’ uso quello
            $selectedDoctorId = $this->getValidatedSelectedDoctorId($tipoPers, $storedDoctorId);
        } else {
            // Nessun dottore selezionato â†’ costringo a scegliere
            $selectedDoctorId = 0;
            session()->remove('selectedDoctorId');
        }
    } else {
        // Se non sono segreteria/infermiera non ha senso mantenere selectedDoctorId
        session()->remove('selectedDoctorId');
    }

    $this->logMailboxRoleSnapshot('index.afterRoleResolution', [
        'rawTipoPersIndex'        => (int)($utente->tipo_pers ?? 0),
        'normalizedTipoPersIndex' => $tipoPers,
        'isSegOrInf'              => $isSegOrInf,
        'idDottoreGet'            => $idDottoreGet,
        'storedDoctorId'          => $storedDoctorId,
        'selectedDoctorId'        => $selectedDoctorId,
    ]);

    log_message('info', 'POSTA index(): tipoPers={tipo}, idDottoreGet={get}, storedDoctorId={stored}, selectedDoctorId={sel}', [
        'tipo'   => $tipoPers,
        'get'    => $idDottoreGet,
        'stored' => $storedDoctorId,
        'sel'    => $selectedDoctorId,
    ]);

    // Se Ã¨ segreteria / infermiera e NON ha ancora scelto un dottore â†’ mostro solo messaggio
    if ($isSegOrInf && $selectedDoctorId <= 0) {
        log_message('info', 'POSTA index(): segreteria/infermiera senza dottore selezionato -> richiedo selezione');

         return view('posta', [
            'messages'               => [],
            'rangeStart'             => 0,
            'rangeEnd'               => 0,
            'total'                  => 0,
            'q'                      => $this->request->getGet('q'),
            'unread'                 => 0,
            'page'                   => 1,
            'perPage'                => 25,
            'hasPrev'                => false,
            'hasNext'                => false,
            'requireDoctorSelection' => true,
            'selectedDoctorId'       => null,
            'gestitaFilter'          => 'all',
            'folder'                 => 'inbox', // ðŸ‘ˆ aggiungi
        ]);
    }

    if ($isSegOrInf && $selectedDoctorId > 0) {
        $isDoctor = true; // sto lavorando sulla mailbox di un dottore, seppur loggato come segreteria/infermiera
    }

    // Se ho un dottore selezionato â†’ uso QUELLO come proprietario mailbox
    $targetUserId = $selectedDoctorId > 0 ? $selectedDoctorId : $userId;

    // ===============================
    //  FILTRI: q e gestita
    // ===============================
    $model   = new MessagesModel();
    $q       = $this->request->getGet('q');
    $perPage = 25;

    // gestita: 'all' | '0' | '1'
    $gestitaFilter = $this->request->getGet('gestita');
    if ($gestitaFilter !== '0' && $gestitaFilter !== '1') {
        $gestitaFilter = 'all';
    }

    $filters = [
        'q'       => $q,
        'gestita' => $gestitaFilter === 'all' ? null : (int)$gestitaFilter,
    ];

        $list   = $model->getInboxLatest($targetUserId, $filters, $perPage, $isDoctor);
    $unread = $model->countUnread($targetUserId);

    return view('posta', [
        'messages'               => $list['data'],
        'rangeStart'             => $list['start'],
        'rangeEnd'               => $list['end'],
        'total'                  => $list['total'],
        'q'                      => $q,
        'unread'                 => $unread,
        'page'                   => $list['page'],
        'perPage'                => $list['perPage'],
        'hasPrev'                => $list['page'] > 1,
        'hasNext'                => ($list['page'] * $list['perPage']) < $list['total'],
        'requireDoctorSelection' => false,
        'selectedDoctorId'       => $selectedDoctorId ?: null,
        'gestitaFilter'          => $gestitaFilter,
        // QUI AGGIUNGIAMO:
        'folder'                 => 'inbox',
    ]);

}

    /**
     * ðŸ“¤ Posta inviata
     */
    public function sent()
    {
        $utente = session()->get('utente_sess');
        if (!$utente) {
            return redirect()->to(base_url('login'));
        }

        // Pazienti e personale (dottore/infermiere/segreteria) usano ormai il modulo nuovo.
        if (in_array((int)(session()->get('tipoUser') ?? 0), [2, 3], true)) {
            $qs = $this->request->getGet();
            $target = site_url('messaggi/inviati');
            if (!empty($qs)) {
                $target .= '?' . http_build_query($qs);
            }

            return redirect()->to($target);
        }

        $tipoPers         = $this->normalizeMailboxTipoPers((int)($utente->tipo_pers ?? 0));
        $isSegOrInf       = in_array($tipoPers, [2, 3], true);
        $selectedDoctorId = (int)(session()->get('selectedDoctorId') ?? 0);

        // Se segreteria/infermiera e NON ha ancora scelto un dottore,
        // mostro lo stesso messaggio di "seleziona dottore" usato per l'inbox
        if ($isSegOrInf && $selectedDoctorId <= 0) {
            return view('posta', [
                'messages'               => [],
                'rangeStart'             => 0,
                'rangeEnd'               => 0,
                'total'                  => 0,
                'q'                      => $this->request->getGet('q'),
                'unread'                 => 0,
                'page'                   => 1,
                'perPage'                => 25,
                'hasPrev'                => false,
                'hasNext'                => false,
                'requireDoctorSelection' => true,
                'selectedDoctorId'       => null,
                'gestitaFilter'          => 'all',
                'folder'                 => 'sent', // ðŸ‘ˆ importante
            ]);
        }

        // Owner della mailbox (dottore se segreteria/infermiera)
        $ownerId = $this->getMailboxOwnerId();
        if (!$ownerId) {
            return redirect()->to(base_url('login'));
        }

        $model   = new MessagesModel();
        $q       = $this->request->getGet('q');
        $perPage = 25;

        // pagina corrente
        $page = (int)($this->request->getGet('page') ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $filters = [
            'q'    => $q,
            'page' => $page,
        ];

        $list   = $model->getSentLatest($ownerId, $filters, $perPage);
        $unread = $model->countUnread($ownerId); // per il badge in alto, resta quello dell'inbox

        return view('posta', [
            'messages'               => $list['data'],
            'rangeStart'             => $list['start'],
            'rangeEnd'               => $list['end'],
            'total'                  => $list['total'],
            'q'                      => $q,
            'unread'                 => $unread,
            'page'                   => $list['page'],
            'perPage'                => $list['perPage'],
            'hasPrev'                => $list['page'] > 1,
            'hasNext'                => ($list['page'] * $list['perPage']) < $list['total'],
            'requireDoctorSelection' => false,
            'selectedDoctorId'       => $selectedDoctorId ?: null,
            'gestitaFilter'          => 'all',     // nelle inviate non ha molto senso il filtro gestita
            'folder'                 => 'sent',    // ðŸ‘ˆ fondamentale per la view
        ]);
    }


    public function star($compoundId)
    {
        $this->response->setContentType('application/json');

        $userId = $this->getMailboxOwnerId();
        if (!$userId) return $this->jsonError('auth', 401);

        // Valida compoundId
        if (!preg_match('/^(M|R):(\d+)$/', (string) $compoundId, $m)) {
            return $this->jsonError('bad_id', 400);
        }
        [$all, $src, $id] = $m;

        $model = new MessagesModel();
        $ok    = $model->toggleStar($src, (int) $id, $userId);

        return $this->response->setJSON(['ok' => (bool) $ok]);
    }

    public function bulkDelete()
{
    $this->response->setContentType('application/json');

    $userId = $this->getMailboxOwnerId();
    if (!$userId) {
        return $this->jsonError('auth', 401);
    }

    $ids = $this->request->getPost('ids') ?? [];
    $ids = array_values(array_filter((array) $ids, fn($v) => (string)$v !== ''));

    if (empty($ids)) {
        return $this->jsonError('no_ids', 400);
    }

    /** @var MessagesModel $model */
    $model = model(MessagesModel::class);
    $aff   = $model->deleteMany($ids, $userId);
    $this->refreshMenuDataForCurrentUser(true);

    return $this->response->setJSON([
        'ok'       => true,
        'affected' => (int)$aff,
        'csrfName' => csrf_token(),
        'csrfHash' => csrf_hash(),
    ]);
}
public function bulkGestita()
{
    $this->response->setContentType('application/json');

    $userId = $this->getMailboxOwnerId();
    if (!$userId) {
        return $this->jsonError('auth', 401);
    }

    $ids = $this->request->getPost('ids') ?? [];
    $ids = array_values(array_filter((array)$ids, fn($v) => (string)$v !== ''));

    if (empty($ids)) {
        return $this->jsonError('no_ids', 400);
    }

    /** @var MessagesModel $model */
    $model = model(MessagesModel::class);
    $aff   = $model->toggleGestitaMany($ids);
    $this->refreshMenuDataForCurrentUser(true);

    return $this->response->setJSON([
        'ok'       => true,
        'affected' => (int)$aff,
        'csrfName' => csrf_token(),
        'csrfHash' => csrf_hash(),
    ]);
}

/*public function compose($draftId = null)
{
    $utente = $this->getSessionUser();
    if (!$utente) return redirect()->to('/login');

    // risolvi mittente (stessa logica del DraftController)
    if (($utente->tipo ?? null) == 3) { $mitt='C'; $id_mitt=(int)($utente->id_client ?? 0); }
    else if (($utente->tipo ?? null) == 2) { $mitt='P'; $id_mitt=(int)($utente->id_personale ?? 0); }
    else { $mitt='P'; $id_mitt=(int)($utente->id_user ?? 0); }

    $data = [
        'draftId' => 0,
        'oggetto' => '',
        'testo'   => '',
        'dest'    => null,
        'id_dest' => null,
        'attachments' => ['def'=>[], 'temp'=>[]],
        'sessid'  => session_id(),
    ];

    if ($draftId) {
        $m = new \App\Models\DraftModel();
        $d = $m->getDraft((int)$draftId, $id_mitt, $mitt);
        if ($d) {
            $data['draftId'] = (int)$d['id_message'];
            $data['oggetto'] = $d['oggetto'] ?? '';
            $data['testo']   = $d['testo'] ?? 'dddddd';
            $data['dest']    = $d['dest'] ?? null;
            $data['id_dest'] = $d['id_dest'] ?? null;
            $data['attachments'] = $d['attachments'] ?? ['def'=>[], 'temp'=>[]];
        }
    }

    return view('posta/compose', $data);
}*/


public function read()
{
    $utente = session()->get('utente_sess');
    if (!$utente) {
        return redirect()->to(base_url('login'));
    }

    $this->refreshMenuDataForCurrentUser();

    $tipoPers         = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 0));
    $isSegOrInf       = $this->isSegreteriaOrInfermiere($tipoPers);
    $selectedDoctorId = $this->getValidatedSelectedDoctorId($tipoPers);

    if ($isSegOrInf && $selectedDoctorId <= 0) {
        log_message('info', 'read(): segreteria/infermiera senza dottore selezionato -> redirect a posta');
        return redirect()->to(site_url('posta'));
    }

    // âœ… BOX: inbox / sent (arriva dalla view)
    $box = (string) ($this->request->getPost('box') ?? $this->request->getGet('box') ?? 'inbox');
    $box = in_array($box, ['inbox', 'sent'], true) ? $box : 'inbox';

    // 1) Input
    $compoundId = (string) $this->request->getPost('uid'); // "M:123" / "R:456"

    // 2) Proprietario mailbox
    $userId = $this->getMailboxOwnerId();
    if (!$userId) {
        log_message('info', 'read(): nessun proprietario mailbox in sessione, redirect a login');
        return redirect()->to(base_url('login'));
    }

    log_message('info', 'read(): compoundId={id}, ownerId={uid}, box={box}, tipoPers={tipo}, selectedDoctorId={doc}', [
        'id'   => $compoundId,
        'uid'  => $userId,
        'box'  => $box,
        'tipo' => $tipoPers,
        'doc'  => $selectedDoctorId,
    ]);

    // 3) Validazione compoundId
    [$src, $id] = $this->parseCompoundId($compoundId);
    if (!$src || !$id) {
        log_message('info', 'read(): compoundId non valido -> {id}', ['id' => $compoundId]);
        return redirect()->to(site_url($box === 'sent' ? 'posta/inviati' : 'posta'));
    }

    /** @var MessagesModel $model */
    $model = model(MessagesModel::class);

    // âœ… 4) Carica messaggio corrente: inbox vs sent
    $msg = $model->getOne($box, $src, $id, $userId);
    if (!$msg) {
        log_message('info', 'read(): nessun messaggio trovato per src={src}, id={id}, ownerId={uid}, box={box}', [
            'src' => $src,
            'id'  => $id,
            'uid' => $userId,
            'box' => $box,
        ]);
        return redirect()->to(site_url($box === 'sent' ? 'posta/inviati' : 'posta'));
    }

    log_message('debug', 'read(): messaggio trovato -> uid={uid}, letto={letto}', [
        'uid'   => $compoundId,
        'letto' => $msg['letto'] ?? null,
    ]);

    // âœ… 5) Marca come letto SOLO inbox (consigliato)
    if ($box === 'inbox' && (int) ($msg['letto'] ?? 0) === 0) {
        try {
            $model->markRead($src, $id, $userId);
            $msg['letto'] = 1;
            $this->refreshMenuDataForCurrentUser(true);
            log_message('debug', 'read(): messaggio marcato come letto -> src={src}, id={id}, ownerId={uid}', [
                'src' => $src,
                'id'  => $id,
                'uid' => $userId,
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'read(): markRead fallita -> {m}', ['m' => $e->getMessage()]);
        }
    }

    // 6) Carica THREAD (ASC)
    try {
        /** @var MessageModel $threadModel */
        $threadModel = model(MessageModel::class);

        // âœ… rootId corretto: se apro una reply, il thread Ã¨ id_message_ini
        $rootId = 0;
        if ($src === 'R') {
            $rootId = (int) ($msg['id_message_ini'] ?? 0);
        } else {
            $rootId = (int) ($msg['id_message'] ?? $id);
        }
        if ($rootId <= 0) $rootId = (int) $id;

        $thread = $threadModel->getThread($rootId);

        if (empty($thread)) {
            $thread = [[
                'id_message'       => (int) $rootId,
                'mitt'             => $msg['mitt']             ?? '',
                'dest'             => $msg['dest']             ?? '',
                'id_mitt'          => (int) ($msg['id_mitt']    ?? 0),
                'id_dest'          => (int) ($msg['id_dest']    ?? 0),
                'dataora'          => $msg['dataora']          ?? '',
                'testo'            => $msg['testo']            ?? '',
                'is_html'          => 1,
                'allegati'         => $threadModel->getAttachments($rootId),
                'mittente_nome'    => $msg['mittente_nome']    ?? '',
                'mittente_cognome' => $msg['mittente_cognome'] ?? '',
                'mitt_prefix'      => $msg['mitt_prefix']      ?? '',
                'inoltrato'        => $msg['inoltrato']        ?? '',
            ]];
        }

    } catch (\Throwable $e) {
        log_message('error', 'read(): errore load thread -> {m}', ['m' => $e->getMessage()]);
        $thread = [];
    }

    // 7) View
    return view('Posta/read', [
        'src'              => $src,
        'box'              => $box,          // âœ… PASSALO ALLA VIEW
        'compoundId'       => $compoundId,
        'thread'           => $thread,
        'tipoPers'         => $tipoPers,
        'isSegOrInf'       => $isSegOrInf,
        'selectedDoctorId' => $isSegOrInf ? $selectedDoctorId : null,
    ]);
}

public function attachment(int $id)
{
    log_message('error', '[ATTACHMENT] START id=' . $id);

    $utente = session()->get('utente_sess');
    if (!$utente) {
        log_message('error', '[ATTACHMENT] utente NON loggato');
        return redirect()->to(base_url('login'));
    }
    log_message('error', '[ATTACHMENT] utente loggato id_user=' . ($utente->id_user ?? 'N/A'));

    $model = new MessageModel();
    $row   = $model->getAttachmentRow($id);

    if (!$row) {
        log_message('error', '[ATTACHMENT] attachment NON trovato nel DB id=' . $id);
        throw PageNotFoundException::forPageNotFound('Allegato non trovato');
    }

    log_message('error', '[ATTACHMENT] DB ROW=' . json_encode($row));

    $messageId = (int)($row['id_message'] ?? 0);
    if ($messageId <= 0) {
        log_message('error', '[ATTACHMENT] id_message NON valido');
        throw PageNotFoundException::forPageNotFound('Messaggio allegato non valido');
    }

    $nomeReal = trim((string)($row['nome_real'] ?? ''));
    if ($nomeReal === '') {
        log_message('error', '[ATTACHMENT] nome_real VUOTO');
        throw PageNotFoundException::forPageNotFound('Nome allegato vuoto');
    }

    $encryptedFilename = basename(str_replace('\\', '/', $nomeReal));
    log_message('error', '[ATTACHMENT] encryptedFilename=' . $encryptedFilename);

    // âœ… Base upload = /upload nella root del sito (filesystem)
    $baseUpload = rtrim(
        (string) (env('LEGACY_UPLOAD_PATH') ?: (dirname(rtrim(ROOTPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'upload')),
        DIRECTORY_SEPARATOR
    );
    log_message('error', '[ATTACHMENT] baseUpload=' . $baseUpload);

    if (!is_dir($baseUpload)) {
        log_message('error', '[ATTACHMENT] baseUpload non valido');
        return Services::response()->setStatusCode(500)->setBody('Cartella upload non disponibile.');
    }

    $fullPath = $baseUpload . DIRECTORY_SEPARATOR . $messageId . DIRECTORY_SEPARATOR . $encryptedFilename;
    log_message('error', '[ATTACHMENT] path iniziale=' . $fullPath);

    // fallback inoltro
    if (!is_file($fullPath)) {
        log_message('error', '[ATTACHMENT] file NON trovato nel path iniziale');

        $db = Database::connect();
        $orig = $db->table('dap17_inoltro_message')
            ->select('id_message')
            ->where('id_message_new', $messageId)
            ->get(1)->getRowArray();

        log_message('error', '[ATTACHMENT] fallback inoltro=' . json_encode($orig));

        if (!empty($orig['id_message'])) {
            $origId   = (int)$orig['id_message'];
            $fullPath = $baseUpload . DIRECTORY_SEPARATOR . $origId . DIRECTORY_SEPARATOR . $encryptedFilename;
            log_message('error', '[ATTACHMENT] path fallback=' . $fullPath);
        }
    }

    if (!is_file($fullPath)) {
        log_message('error', '[ATTACHMENT] FILE NON TROVATO DEFINITIVO path=' . $fullPath);
        throw PageNotFoundException::forPageNotFound('File allegato non trovato su disco');
    }

    log_message('error', '[ATTACHMENT] file TROVATO');

    $ALGORITHM = getenv('FILE_CRYPT_ALGO') ?: 'AES-256-CBC';
    $IV        = getenv('FILE_CRYPT_IV')   ?: '12dasdq3g5b2434b';
    $password  = getenv('FILE_CRYPT_KEY')  ?: '123456';
    log_message('error', '[ATTACHMENT] decrypt params algo=' . $ALGORITHM . ' iv=' . $IV);

    $encryptedBytes = @file_get_contents($fullPath);
    if ($encryptedBytes === false) {
        log_message('error', '[ATTACHMENT] ERRORE file_get_contents path=' . $fullPath);
        return Services::response()->setStatusCode(500)->setBody('Errore lettura file.');
    }

    $decryptedBytes = openssl_decrypt($encryptedBytes, $ALGORITHM, $password, OPENSSL_RAW_DATA, $IV);
    if ($decryptedBytes === false) {
        log_message('error', '[ATTACHMENT] ERRORE openssl_decrypt');
        return Services::response()->setStatusCode(500)->setBody('Errore nella decrittazione del file.');
    }

    $nomeVis = trim((string)($row['nome_vis'] ?? ''));
    $downloadName = $nomeVis !== '' ? basename($nomeVis) : preg_replace('/\.crypto$/i', '', $encryptedFilename);
    if (!$downloadName) $downloadName = 'allegato';

    $ext  = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));
    $mime = ($ext === 'pdf') ? 'application/pdf' : 'application/octet-stream';

    $forceDownload = (bool)$this->request->getGet('download');
    $disposition = $forceDownload ? 'attachment' : (in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','bmp'], true) ? 'inline' : 'attachment');

    $response = Services::response();
    $response->setHeader('Content-Type', $mime);
    $response->setHeader('Content-Disposition', $disposition . '; filename="' . addslashes($downloadName) . '"');
    $response->setBody($decryptedBytes);

    log_message('error', '[ATTACHMENT] END OK');

    return $response;
}



/*public function attachment(int $id)
{
    // ðŸ” Verifica login
    $utente = session()->get('utente_sess');
    if (!$utente) {
        return redirect()->to(base_url('login'));
    }

    $model = new MessageModel();
    $row   = $model->getAttachmentRow($id);

    if (!$row) {
        throw PageNotFoundException::forPageNotFound('Allegato non trovato');
    }

    $pathRel =  str_replace(".crypto","",trim($row['nome_real'] ?? ''));;
    if ($pathRel === '') {
        throw PageNotFoundException::forPageNotFound('Percorso allegato vuoto');
    }
$fullPath = 'https://www.ambulatoriofacile.it/upload/'.$row['id_message']
         . '/'
         . ltrim($pathRel, '/');
    log_message('error',$fullPath); 

    /*$fullPath = rtrim(ROOTPATH . 'upload' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR)
              . DIRECTORY_SEPARATOR
              . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pathRel);*/

    /*if (!is_file($fullPath)) {
        throw PageNotFoundException::forPageNotFound('File allegato non trovato su disco');
    }

    $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';

    $map = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'bmp'  => 'image/bmp',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    if (isset($map[$ext])) {
        $mime = $map[$ext];
    }

    // ðŸ‘‰ se c'Ã¨ ?download=1 forzo attachment, altrimenti inline per immagini/pdf
    $forceDownload = (bool) $this->request->getGet('download');

    if ($forceDownload) {
        $disposition = 'attachment';
    } else {
        $disposition = in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','pdf'])
            ? 'inline'
            : 'attachment';
    }

    $filename = basename($fullPath);

    $response = Services::response();
    $response->setHeader('Content-Type', $mime);
    $response->setHeader(
        'Content-Disposition',
        $disposition . '; filename="' . $filename . '"'
    );

    $response->setBody(file_get_contents($fullPath));

    return $response;
}*/


    /**
     * Autocomplete contatti per Select2
     */
    public function contacts()
    {
        $this->response->setContentType('application/json');

        $q = trim($this->request->getPost('q') ?? '');
        if (mb_strlen($q) < 3) {
            return $this->response->setJSON([]);
        }

        $db            = Database::connect();
        $crypto_helper = new Crypto_helper();

        // Protegge la stringa per l'uso nel LIKE
        $escaped = $db->escapeLikeString($q);

        $sql = "
            SELECT 
                id_client,
                " . $crypto_helper->decrypt('c.nome') . ",
                " . $crypto_helper->decrypt('c.cognome') . "
            FROM dap02_clients c
            WHERE (
                " . $crypto_helper->decryptSenzaAlias('c.nome') . " LIKE '%{$escaped}%'
                OR " . $crypto_helper->decryptSenzaAlias('c.cognome') . " LIKE '%{$escaped}%'
                OR CONCAT(" . $crypto_helper->decryptSenzaAlias('c.cognome') . ", ' ', " . $crypto_helper->decryptSenzaAlias('c.nome') . ") LIKE '%{$escaped}%'
            )
            ORDER BY cognome ASC, nome ASC
            LIMIT 20
        ";

        $rows = $db->query($sql)->getResultArray();

        $results = array_map(function ($r) {
            $nome = trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''));
            $text = SystemUserMask::getMaskedClientDisplayName(
                (int)($r['id_client'] ?? 0),
                $nome !== '' ? $nome : 'Senza nome'
            );
            return [
                'id'   => (int) $r['id_client'],
                'text' => $text,
            ];
        }, $rows);

        return $this->response->setJSON($results);
    }

    /**
     * Schermata reply.
     * - Se segreteria/infermiera (tipo_pers 2/3) deve esserci un selectedDoctorId in sessione,
     *   altrimenti rimando alla posta per selezionare il dottore.
     * - Passo alla view selectedDoctorId per evidenziare il dottore nel menu (come in inbox/read).
     */
    public function reply(string $uid)
    {
        $utente = session()->get('utente_sess');
        if (!$utente) return redirect()->to(base_url('login'));

        $this->refreshMenuDataForCurrentUser();

        $tipoPers         = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 1));
        $isSegOrInf       = $this->isSegreteriaOrInfermiere($tipoPers);
        $selectedDoctorId = $this->getValidatedSelectedDoctorId($tipoPers);
        log_message('debug', "ID REPLY:".$uid);
        // Se sono segreteria/infermiera e non ho ancora selezionato un dottore,
        // non ha senso aprire la reply: rimando a POSTA per scelta dottore.
        if ($isSegOrInf && $selectedDoctorId <= 0) {
            log_message('debug', 'reply(): segreteria/infermiera senza dottore selezionato -> redirect a posta');
            return redirect()->to(base_url('posta'));
        }

        $id = MessageModel::parseUid($uid);
          log_message('debug', "ID REPLY2:".$id);
        if (!$id) {
            log_message('warning', 'Reply UID non valido: {uid}', ['uid' => $uid]);
            return redirect()->to(base_url('posta'))->with('message_error', 'Messaggio non valido');
        }

        $model = new MessageModel();

        try {
            $ctx = $model->getReplyContext($id, [
                'tipoUser'         => session()->get('tipoUser'), // 3 = paziente
                'tipoPers'         => $tipoPers,                  // 1=P,2=I,3=S (da utente_sess)
                'selectedDoctorId' => $selectedDoctorId,
                'id_personale'     => $utente->id_personale ?? null,
                'id_user'          => $utente->id_user ?? null,
            ]);
  log_message('debug', "ID REPLY:".$id);
            // Thread dal piÃ¹ vecchio al piÃ¹ recente
            $thread = $model->getThread($id);
          //  log_message('debug', $thread);
        } catch (\Throwable $e) {
            log_message('error', 'Reply2 ctx/thread error: {m}', ['m' => $e->getMessage()]);
            return redirect()->to(base_url('posta'))->with('message_error', 'Impossibile aprire la conversazione');
        }

        return view('posta_reply', [
            'ctx'              => $ctx,
            'thread'           => $thread,
            'isPatient'        => ((int) session()->get('tipoUser') === 3),
            'menuData'         => session()->get('menuData'),
            'immagineProfilo'  => session()->get('immagine_profilo') ?: 'user.png',
            'nomeVisualizzato' => session()->get('nome_visualizzato') ?: '',
            // info per gestire il menu / dottore selezionato
            'tipoPers'         => $tipoPers,
            'isSegOrInf'       => $isSegOrInf,
            'selectedDoctorId' => $isSegOrInf ? $selectedDoctorId : null,
        ]);
    }

    /**
     * Schermata INOLTRO
     */
    /*public function forward(string $uid)
    {
        $utente = session()->get('utente_sess');
        if (!$utente) return redirect()->to(base_url('login'));

        $id = MessageModel::parseUid($uid);
        if (!$id) {
            log_message('warning', 'Forward UID non valido: {uid}', ['uid' => $uid]);
            return redirect()->to(base_url('posta'))->with('message_error', 'Messaggio non valido');
        }

        $tipoPers = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 1));
        $model    = new MessageModel();

        try {
            $ctx = $model->getReplyContext($id, [
                'tipoUser' => session()->get('tipoUser'),
                'tipoPers' => $tipoPers,
            ]);

            $thread = $model->getThread($id);
        } catch (\Throwable $e) {
            log_message('error', 'Forward ctx/thread error: {m}', ['m' => $e->getMessage()]);
            return redirect()->to(base_url('posta'))->with('message_error', 'Impossibile aprire la conversazione da inoltrare');
        }

        return view('posta_forward', [
            'ctx'              => $ctx,
            'thread'           => $thread,
            'isPatient'        => ((int) session()->get('tipoUser') === 3),
            'menuData'         => session()->get('menuData'),
            'immagineProfilo'  => session()->get('immagine_profilo') ?: 'user.png',
            'nomeVisualizzato' => session()->get('nome_visualizzato') ?: '',
        ]);
    }*/

       public function forward(string $uid)
{
    $utente = session()->get('utente_sess');
    if (!$utente) return redirect()->to(base_url('login'));

    $this->refreshMenuDataForCurrentUser();

    $id = MessageModel::parseUid($uid);
    if (!$id) {
        log_message('warning', 'Forward UID non valido: {uid}', ['uid' => $uid]);
        return redirect()->to(base_url('posta'))->with('message_error', 'Messaggio non valido');
    }

    $tipoUser         = (int)(session()->get('tipoUser') ?? 0); // 3 paziente
    $tipoPers         = $this->normalizeMailboxTipoPers((int)($utente->tipo_pers ?? 0));         // 1=P,2=I,3=S
    $isPatient        = ($tipoUser === 3);
    $isSeg            = (!$isPatient && $tipoPers === 3);
    $isInf            = (!$isPatient && $tipoPers === 2);
    $isDoc            = (!$isPatient && $tipoPers === 1);

    $selectedDoctorId = ($isSeg || $isInf)
        ? $this->getValidatedSelectedDoctorId($tipoPers)
        : 0;

    // nome medico (se selezionato)
    $doctorName = '';
    $menuData = session()->get('menuData');
    $dottori  = $menuData['dottori'] ?? [];
    if ($selectedDoctorId > 0 && isset($dottori[$selectedDoctorId]['titolo'])) {
        $doctorName = trim((string)$dottori[$selectedDoctorId]['titolo']);
    }

    $this->logMailboxRoleSnapshot('forward.beforeView', [
        'uid'                    => $uid,
        'rawTipoPersForward'     => (int)($utente->tipo_pers ?? 0),
        'normalizedTipoPersForward' => $tipoPers,
        'isPatient'              => $isPatient,
        'isDoc'                  => $isDoc,
        'isSeg'                  => $isSeg,
        'isInf'                  => $isInf,
        'selectedDoctorIdLocal'  => $selectedDoctorId,
        'selectedDoctorName'     => $doctorName,
    ]);

    $model = new MessageModel();

    try {
       $ctx = $model->getReplyContext($id, [
    'tipoUser'        => $tipoUser,
    'tipoPers'        => $tipoPers,
    'selectedDoctorId'=> $selectedDoctorId,
    'id_personale'    => $utente->id_personale ?? null,
    'id_user'         => $utente->id_user ?? null,
]);
        $thread = $model->getThread($id);
    } catch (\Throwable $e) {
        log_message('error', 'Forward ctx/thread error: {m}', ['m' => $e->getMessage()]);
        return redirect()->to(base_url('posta'))->with('message_error', 'Impossibile aprire la conversazione da inoltrare');
    }

    return view('posta_forward', [
        'ctx'              => $ctx,
        'thread'           => $thread,
        'isPatient'        => $isPatient,
        'menuData'         => $menuData,
        'immagineProfilo'  => session()->get('immagine_profilo') ?: 'user.png',
        'nomeVisualizzato' => session()->get('nome_visualizzato') ?: '',

        // âœ… per la view inoltro
        'tipoPers'         => $tipoPers,
        'isDoc'            => $isDoc,
        'isSeg'            => $isSeg,
        'isInf'            => $isInf,
        'selectedDoctorId' => $selectedDoctorId,
        'selectedDoctorName' => $doctorName,
    ]);
}


   public function inoltra()
{
    $this->response->setContentType('application/json');

    $utente = session()->get('utente_sess');
    if (!$utente) {
        return $this->jsonError('auth', 401);
    }

    $idMessage     = (int) ($this->request->getPost('id_message') ?? 0);
    $destCode      = (int) ($this->request->getPost('dest_code') ?? 0); // 1=P(dottore),2=I,3=S
    $destId        = (int) ($this->request->getPost('dest_id') ?? 0);   // id_personale reale del destinatario
    $testoInoltro  = (string) ($this->request->getPost('testo_inoltro') ?? '');

    $this->logMailboxRoleSnapshot('inoltra.request', [
        'idMessage'       => $idMessage,
        'destCode'        => $destCode,
        'destId'          => $destId,
        'testoLength'     => function_exists('mb_strlen') ? mb_strlen($testoInoltro) : strlen($testoInoltro),
    ]);

    if ($idMessage <= 0) {
        return $this->jsonError('bad_id', 400);
    }
    log_message('error',$destCode);
    if (!in_array($destCode, [1, 2, 3], true)) {
        return $this->jsonError('bad_dest', 400);
    }

if ($destId <= 0) {
    return $this->jsonError('bad_dest_id', 400);
}

    try {
        $uploadedFiles = $this->request->getFileMultiple('attachment');
        if (is_array($uploadedFiles)) {
            $this->validateUploadedFilesMaxSize($uploadedFiles);
        }
    } catch (\RuntimeException $e) {
        return $this->response->setStatusCode(400)->setJSON([
            'ok'       => false,
            'err'      => $e->getMessage(),
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ]);
    }


    try {
        $tipoPers = $this->normalizeMailboxTipoPers((int) ($utente->tipo_pers ?? 1));

        $model = new MessageModel();

        $res = $model->forwardMessage(
            $idMessage,
            $destCode,
            $destId,
            $testoInoltro,
             [
        'tipoUser'     => session()->get('tipoUser'),
        'tipoPers'     => $tipoPers,
        'id_personale' => $utente->id_personale ?? null,   // âœ… AGGIUNGI QUESTO
        'id_user'      => $utente->id_user ?? null,        // (facoltativo se ti serve)
    ]
        );

        return $this->response->setJSON([
            'ok'             => true,
            'resp'           => $res['resp'] ?? 'OK',
            'id_message_new' => $res['id_message_new'] ?? null,
            'csrfName'       => csrf_token(),
            'csrfHash'       => csrf_hash(),
        ]);
    } catch (\Throwable $e) {
        log_message('error', 'inoltra(): errore inoltro -> {m}', ['m' => $e->getMessage()]);
        return $this->jsonError('server', 500);
    }
}


     public function listAttachmentTemp()
    {
        $this->response->setContentType('application/json');

        
        $sessionConfig = config('Session');
        $sessionCookie = $sessionConfig->cookieName ?? 'ci_session';
        $sessid        = $_COOKIE[$sessionCookie] ?? '';

        $attTemp = new AttachmentTempModel();

        $attachments = $attTemp->getBySession($sessid);

        return $this->response->setJSON([
            'ok'          => true,
            'attachments' => $attachments,
            'csrfName'    => csrf_token(),
            'csrfHash'    => csrf_hash(),
        ]);
    }

    /**
     * Upload di uno o piÃ¹ allegati in tabella dap11_attachments_temp
     * e salvataggio file in /upload/.
     */
  public function uploadAttachmentTemp()
{
    $this->response->setContentType('application/json');

    // Recupero sessid dal cookie di sessione CI
    $sessionConfig = config('Session');
    $sessionCookie = $sessionConfig->cookieName ?? 'ci_session';
    $sessid        = $_COOKIE[$sessionCookie] ?? '';

    $attTemp = new AttachmentTempModel();

    // ID messaggio / reply
    $idMessage      = (int) ($this->request->getPost('id_message') ?? 0);
    $idMessageReply = (int) ($this->request->getPost('id_message_reply') ?? 0);

    // Nel tuo flusso reale, l'allegato Ã¨ legato al thread del messaggio "principale"
    // Se vuoi tenere la logica vecchia, qui puoi scegliere msgIdForPath:
    $msgIdForPath = $idMessage > 0 ? $idMessage : $idMessageReply;

    if ($msgIdForPath <= 0) {
        return $this->response->setJSON([
            'ok'       => false,
            'err'      => 'ID messaggio assente - impossibile determinare il percorso file',
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ]);
    }

    $files = $this->request->getFileMultiple('attachment');
    if (empty($files)) {
        return $this->response->setJSON([
            'ok'       => false,
            'err'      => 'Nessun file ricevuto',
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ]);
    }

    try {
        $this->validateUploadedFilesMaxSize($files);
    } catch (\RuntimeException $e) {
        return $this->response->setJSON([
            'ok'       => false,
            'err'      => $e->getMessage(),
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ]);
    }

    // âœ… Upload base: /upload nella root pubblica del sito (filesystem)
    $baseDir = rtrim(
        (string) (env('LEGACY_UPLOAD_PATH') ?: (dirname(rtrim(ROOTPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'upload')),
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR;
    $targetDir = $baseDir . $msgIdForPath . DIRECTORY_SEPARATOR;

    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
        log_message('error', 'uploadAttachmentTemp: mkdir fallita targetDir=' . $targetDir);
        return $this->response->setJSON([
            'ok'       => false,
            'err'      => 'Impossibile creare cartella upload',
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ]);
    }

    log_message('error', 'uploadAttachmentTemp: targetDir=' . $targetDir);

    // ðŸ” Parametri encrypt (stessi del decrypt)
    $ALGORITHM = getenv('FILE_CRYPT_ALGO') ?: 'AES-256-CBC';
    $IV        = getenv('FILE_CRYPT_IV')   ?: '12dasdq3g5b2434b';
    $password  = getenv('FILE_CRYPT_KEY')  ?: '123456';

    foreach ($files as $file) {
        if (!$file || $file->hasMoved()) {
            log_message('error', 'uploadAttachmentTemp: file non valido o giÃ  mosso');
            continue;
        }

        $nomeVis = (string) $file->getClientName(); // nome originale

        // Leggo bytes dal tmp upload
        $tmpPath = $file->getTempName();
        if (!$tmpPath || !is_file($tmpPath)) {
            log_message('error', 'uploadAttachmentTemp: tmpPath non valido');
            continue;
        }

        $plainBytes = @file_get_contents($tmpPath);
        if ($plainBytes === false) {
            log_message('error', 'uploadAttachmentTemp: file_get_contents tmp fallita tmp=' . $tmpPath);
            continue;
        }

        // Cripto -> bytes binari
        $encryptedBytes = openssl_encrypt($plainBytes, $ALGORITHM, $password, OPENSSL_RAW_DATA, $IV);
        if ($encryptedBytes === false) {
            log_message('error', 'uploadAttachmentTemp: openssl_encrypt FALLITA nomeVis=' . $nomeVis);
            continue;
        }

        // Nome su disco: random + ".crypto"
        // (mantengo anche l'estensione originale prima di .crypto per debug/leggibilitÃ )
        $clientExt = strtolower(pathinfo($nomeVis, PATHINFO_EXTENSION));
        $rand = random_int(100000, 999999);
        $baseName = $file->getRandomName(); // include giÃ  estensione casuale di CI
        // alternativa piÃ¹ leggibile:
        $finalName = $rand . '_' . preg_replace('/[^a-zA-Z0-9\-\_\.]+/', '_', pathinfo($nomeVis, PATHINFO_FILENAME));
        if ($clientExt !== '') $finalName .= '.' . $clientExt;
        $finalName .= '.crypto';

        $destPath = $targetDir . $finalName;

        try {
            $ok = @file_put_contents($destPath, $encryptedBytes);
            if ($ok === false) {
                log_message('error', 'uploadAttachmentTemp: file_put_contents FALLITA dest=' . $destPath);
                continue;
            }
        } catch (\Throwable $e) {
            log_message('error', 'uploadAttachmentTemp: scrittura fallita -> {m}', ['m' => $e->getMessage()]);
            continue;
        }

        log_message('error', 'uploadAttachmentTemp: salvato criptato=' . $destPath);

        // ðŸ‘‰ nel DB SALVO SOLO IL FILENAME (cosÃ¬ attachment() trova /upload/{id_message}/{filename})
        $attTemp->insertTemp([
            'nome_real'        => $finalName,
            'nome_vis'         => $nomeVis,
            'id_message'       => $idMessage ?: null,
            'id_message_reply' => $idMessageReply ?: null,
            'sessid'           => $sessid,
        ]);
    }

    $attachments = $attTemp->getBySession($sessid);

    return $this->response->setJSON([
        'ok'          => true,
        'attachments' => $attachments,
        'csrfName'    => csrf_token(),
        'csrfHash'    => csrf_hash(),
    ]);
}






    /**
     * Cancella un allegato temporaneo (tabella + file fisico).
     */
    public function deleteAttachmentTemp()
    {
        $this->response->setContentType('application/json');

        $id     = (int) ($this->request->getPost('id_attachments') ?? 0);
       
        $sessionConfig = config('Session');
        $sessionCookie = $sessionConfig->cookieName ?? 'ci_session';
        $sessid        = $_COOKIE[$sessionCookie] ?? '';

        if ($id <= 0) {
            return $this->response->setJSON([
                'ok'       => false,
                'err'      => 'ID non valido',
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }

        $attTemp = new AttachmentTempModel();

        // Prendo gli allegati della sessione per recuperare il nome reale decrittato
        $list   = $attTemp->getBySession($sessid);
        $target = null;
        foreach ($list as $row) {
            if ((int) $row['id_attachments'] === $id) {
                $target = $row;
                break;
            }
        }

        if (!$target) {
            return $this->response->setJSON([
                'ok'       => false,
                'err'      => 'Allegato non trovato per questa sessione',
                'csrfName' => csrf_token(),
                'csrfHash' => csrf_hash(),
            ]);
        }

        $baseDir = rtrim(
            (string) (env('LEGACY_UPLOAD_PATH') ?: (dirname(rtrim(ROOTPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'upload')),
            DIRECTORY_SEPARATOR
        );
        $messageId = (int) ($target['id_message'] ?? 0);
        $filePath = $baseDir
            . DIRECTORY_SEPARATOR
            . $messageId
            . DIRECTORY_SEPARATOR
            . basename((string) ($target['nome_real'] ?? ''));

        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $attTemp->deleteById($id);

        // Ritorno lista aggiornata
        $attachments = $attTemp->getBySession($sessid);

        return $this->response->setJSON([
            'ok'          => true,
            'attachments' => $attachments,
            'csrfName'    => csrf_token(),
            'csrfHash'    => csrf_hash(),
        ]);
    }
}
