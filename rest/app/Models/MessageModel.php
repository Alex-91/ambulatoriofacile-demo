<?php
namespace App\Models;

use CodeIgniter\Model;
use Config\Database;
use App\Libraries\Crypto_helper;
use App\Libraries\SystemUserMask;
use App\Services\StaffDoctorAccessService;

class MessageModel extends Model
{
    /** Tabella messaggi principali */
    protected $table      = 'dap10_message';
    protected $primaryKey = 'id_message';
    protected $returnType = 'array';

    /** Tabella delle reply */
    protected string $replyTable = 'dap10_message_reply';

    /** Tabelle "delete" e mappa inoltri */
    protected string $deleteTable      = 'dap10_message_delete';
    protected string $replyDeleteTable = 'dap10_message_reply_delete';
    protected string $forwardMapTable  = 'dap17_inoltro_message';

    /** (Opz.) Tabella allegati – se non esiste, lasciare null e usare lo stub */
    protected ?string $attachTable = null; // es: 'dap10_message_files'

    protected Crypto_helper $crypto;

    public function __construct(?Crypto_helper $crypto = null)
    {
        parent::__construct();
        $this->crypto = $crypto ?? new Crypto_helper();
    }

    protected function normalizeMailboxTipoPers(int $tipoPers): int
    {
        return $tipoPers === StaffDoctorAccessService::TIPO_ADMIN
            ? StaffDoctorAccessService::TIPO_SEGRETERIA
            : $tipoPers;
    }

    /** Estrae l’id numerico da “M:142219”, “M-142219” o “142219” */
    public static function parseUid(string $uid): ?int
    {
        $uid = urldecode(trim($uid));
        if (preg_match('~^[A-Z][\-:]([0-9]+)$~i', $uid, $m)) return (int)$m[1];
        if (ctype_digit($uid)) return (int)$uid;
        return null;
    }

    /**
     * Dati per l’header di reply + routing (mitt/dest/id_mitt/id_dest/da_dottore).
     * Accetta sia id main che id reply.
     */
   public function getReplyContext(int $idMessage, array $session): array
{
    $db = Database::connect();
    $prefixMitt="";
    $prefixDest="";
    // 1) Risalgo sempre all’id radice (main)
    $rootId = $this->resolveRootId($idMessage);
    if (!$rootId) {
        throw new \RuntimeException('Messaggio non trovato');
    }

    // 2) MAIN (decifra oggetto) dalla tabella principale
    $oggettoDec = $this->crypto->decrypt('m.oggetto'); // ... AS oggetto
    $rowMain = $db->query("
    SELECT m.id_message,
           m.id_mitt,
           m.id_dest,
           m.mitt,
           m.dest,
           m.inoltrato,
           m.da_dottore,
           m.dot_seg,
           {$oggettoDec},
           m.vector_id
    FROM {$this->table} m
    WHERE m.id_message = :id:
    LIMIT 1
", ['id' => $rootId])->getRowArray();

    if (!$rowMain) {
        throw new \RuntimeException('Messaggio non trovato');
    }

    // 3) Ultima reply (se presente) per quel thread
    $lastReply = $db->table($this->replyTable)
        ->select('id_message, mitt, dest, id_mitt, id_dest')
        ->where('id_message_ini', $rootId)
        ->orderBy('dataora', 'DESC')
        ->get(1)->getRowArray();

    // 4) Tipologia utente corrente (da sessione)
    $tipoUser         = (int)($session['tipoUser'] ?? 0);   // 3 = cliente/paziente
    $isOperatore      = ($tipoUser !== 3);
    $tipoPers         = $this->normalizeMailboxTipoPers((int)($session['tipoPers'] ?? 1));   // 1=P,2=I,3=S
    $selectedDoctorId = (int)($session['selectedDoctorId'] ?? 0); // opzionale, per segretaria/infermiera
    $isSegOrInf       = $isOperatore && in_array($tipoPers, [2, 3], true);

    // Codice mittente per operatore
    $mittCodeOper = match ($tipoPers) {
        2       => 'I', // infermiere
        3       => 'S', // segreteria
        default => 'P', // dottore/personale
    };

    // 5) Inizializzo struttura di instradamento
    $route = [
        'id_mitt'    => null,
        'id_dest'    => null,
        'mitt'       => null,
        'dest'       => null,
        'da_dottore' => null,
    ];
$currentStaffId = (int)($session['id_personale'] ?? 0);
$staffOnlyThread = (($rowMain['mitt'] ?? '') !== 'C' && ($rowMain['dest'] ?? '') !== 'C');

if ($isOperatore && $staffOnlyThread && $currentStaffId > 0) {

    // io rispondo come me stesso (S/I/P), verso l’altro staff
    $route['mitt']    = $mittCodeOper;
    $route['id_mitt'] = $currentStaffId;
    $route['da_dottore'] = 0;

    // trova l’altro partecipante (main o lastReply)
    if ($lastReply && ($lastReply['mitt'] ?? '') !== 'C' && ($lastReply['dest'] ?? '') !== 'C') {
        // se l’ultima reply l’ho inviata io, rispondo al suo dest; altrimenti rispondo al suo mitt
        if ((int)$lastReply['id_mitt'] === $currentStaffId) {
            $route['dest']    = $lastReply['dest'];
            $route['id_dest'] = (int)$lastReply['id_dest'];
        } else {
            $route['dest']    = $lastReply['mitt'];
            $route['id_dest'] = (int)$lastReply['id_mitt'];
        }
    } else {
        // nessuna reply: uso i 2 partecipanti del main
        if ((int)$rowMain['id_mitt'] === $currentStaffId) {
            $route['dest']    = $rowMain['dest'];
            $route['id_dest'] = (int)$rowMain['id_dest'];
        } else {
            $route['dest']    = $rowMain['mitt'];
            $route['id_dest'] = (int)$rowMain['id_mitt'];
        }
    }

    // IMPORTANTISSIMO: qui puoi fare "return" continuando poi con la parte
    // 7) DESTINATARIO e 8) MITTENTE label (lasciando invariato il resto)
}
    // 6) Calcolo mitt/dest in base a ultima reply o al main
    if ($lastReply) {
        // Esiste almeno una reply nel thread → rispondo rispetto all’ultima
        if ($isOperatore) {
            // Personale: P/I/S sono "io"
            if (in_array($lastReply['mitt'], ['P','S','I'], true)) {
                // ultima reply inviata da un operatore
                $route['id_mitt'] = $lastReply['id_mitt'];
                $route['id_dest'] = $lastReply['id_dest'];
            } else {
                // ultima reply inviata dal cliente
                $route['id_mitt'] = $lastReply['id_dest'];
                $route['id_dest'] = $lastReply['id_mitt'];
            }
            $route['mitt']       = $mittCodeOper; // P/I/S
            $route['dest']       = 'C';
            $route['da_dottore'] = 0;
        } else {
            // Cliente
            if ($lastReply['mitt'] === 'C') {
                $route['dest']    = $lastReply['dest'];
                $route['id_mitt'] = $lastReply['id_mitt'];
                $route['id_dest'] = $lastReply['id_dest'];
            } else {
                $route['id_mitt'] = $lastReply['id_dest'];
                $route['id_dest'] = $lastReply['id_mitt'];
                $route['dest']    = $lastReply['mitt'];
            }
            $route['mitt']       = 'C';
            $route['da_dottore'] = 1;
        }
    } else {
        // Nessuna reply: decido dal main
        if ((int)$rowMain['da_dottore'] === 1 || (int)$rowMain['dot_seg'] === 1) {
            $route['dest']       = $isOperatore ? 'C' : $mittCodeOper;
            $route['da_dottore'] = $isOperatore ? 0 : 1;
        } else {
            $route['dest']       = 'C';
            $route['da_dottore'] = $isOperatore ? 0 : 1;
        }

        if ($isOperatore) {
            // Mittente è un operatore (dottore / infermiera / segreteria)
            // Se sono segreteria/infermiera e ho un dottore selezionato, uso quello come id_mitt
            $actorId = ($isSegOrInf && $selectedDoctorId > 0)
                ? $selectedDoctorId
                : $rowMain['id_dest']; // operatore (di solito il dottore del main)

            $route['mitt']    = $mittCodeOper;       // P / I / S
            $route['id_mitt'] = $actorId;            // id_personale (dottore selezionato o destinatario main)
            $route['id_dest'] = $rowMain['id_mitt']; // cliente
        } else {
            // Mittente è il cliente/paziente
            $route['mitt']    = 'C';
            $route['id_mitt'] = $rowMain['id_dest']; // cliente
            $route['id_dest'] = $rowMain['id_mitt']; // personale
        }
    }

    // 7) DESTINATARIO (per "Rispondi a: ...")
    // - se personale: QUALIFICA + Nome + Cognome
    // - se cliente:   Nome + Cognome
    if ($route['dest'] !== 'C') {
        // personale
        $qual = $this->crypto->decryptSenzaAlias('p.qualifica');
        $cog  = $this->crypto->decryptSenzaAlias('p.cognome');
        $nom  = $this->crypto->decryptSenzaAlias('p.nome');
        $destinatario = $db->query("
            SELECT CONCAT($qual, ' ', $nom, ' ', $cog) AS destinatario
            FROM dap03_personale p
            WHERE p.id_personale = :id:
            LIMIT 1
        ", ['id' => $route['id_dest']])->getRow('destinatario') ?? '';
    } else {
        // cliente
        $cog = $this->crypto->decryptSenzaAlias('c.cognome');
        $nom = $this->crypto->decryptSenzaAlias('c.nome');
        $destinatario = $db->query("
            SELECT CONCAT($nom, ' ', $cog) AS destinatario
            FROM dap02_clients c
            WHERE c.id_client = :id:
            LIMIT 1
        ", ['id' => $route['id_dest']])->getRow('destinatario') ?? '';
        $destinatario = SystemUserMask::getMaskedClientDisplayName((int)$route['id_dest'], $destinatario);
    }
     $prefixDest = match ($route['dest']) {
            'S' => 'Alla Segreteria per conto di ',
            'I' => "All'infermiere per conto di ",
            default => '',
        };
    // 8) MITTENTE descrittivo (per "Da: ...")
    // - C : Nome Cognome
    // - P : Qualifica Nome Cognome
    // - S : "Da parte della Segreteria per conto di " + Qualifica Nome Cognome
    // - I : "Da parte dell'infermiere per conto di " + Qualifica Nome Cognome
    if ($route['mitt'] === 'C') {
        // Mittente = cliente
        $cogM = $this->crypto->decryptSenzaAlias('c.cognome');
        $nomM = $this->crypto->decryptSenzaAlias('c.nome');
        $mittente_label = $db->query("
            SELECT CONCAT($nomM, ' ', $cogM) AS mittente
            FROM dap02_clients c
            WHERE c.id_client = :id:
            LIMIT 1
        ", ['id' => $route['id_mitt']])->getRow('mittente') ?? '';
    } else {
        // Mittente = personale (P / I / S)
        $qualM = $this->crypto->decryptSenzaAlias('p.qualifica');
        $cogM  = $this->crypto->decryptSenzaAlias('p.cognome');
        $nomM  = $this->crypto->decryptSenzaAlias('p.nome');

        $baseMitt = $db->query("
            SELECT CONCAT($qualM, ' ', $nomM, ' ', $cogM) AS mittente
            FROM dap03_personale p
            WHERE p.id_personale = :id:
            LIMIT 1
        ", ['id' => $route['id_mitt']])->getRow('mittente') ?? '';

        $prefixMitt = match ($route['mitt']) {
            'S' => 'Alla Segreteria per conto di ',
            'I' => "All'infermiere per conto di ",
            default => '',
        };

        $mittente_label = $prefixMitt . $baseMitt;
    }

    // 9) Return completo
    return [
        'id_message_ini'   => $rootId,
        'oggetto'          => $rowMain['oggetto'] ?? '',
        'dot_seg'          => (int)$rowMain['dot_seg'],
        'route'            => $route,
        'destinatario'     => $destinatario,
        'mittente_label'   => $mittente_label,
        'prefix'           => $prefixMitt,
        'prefixDest'           => $prefixDest,
        // opzionale ma utile se vuoi usarli in view o debug
        'tipoUser'         => $tipoUser,
        'tipoPers'         => $tipoPers,
        'selectedDoctorId' => $isSegOrInf ? $selectedDoctorId : null,
    ];
}




    /** Imposta il campo "gestita" */
    public function setGestita(int $idMessage, int $value): bool
    {
        $db = Database::connect();

        return $db->table($this->table)
            ->where('id_message', $idMessage)
            ->update(['gestita' => $value]);
    }

    /**
     * Recupera l’intero thread (messaggio iniziale + replies) ordinato ASC (vecchio → nuovo).
     * Accetta ID di main o di reply: risale automaticamente all'id radice.
     */
    public function getThread(int $idAny): array
{
    $db = Database::connect();
    log_message('debug', 'THREAD 1');

    // 0) Risalgo all'id radice (se $idAny è reply, trovo id_message_ini)
    $rootId = $this->resolveRootId($idAny);
    if (!$rootId) return [];

    // 1) Messaggio iniziale
    $testoDec   = $this->crypto->decrypt('m.testo');   // ... AS testo
    $oggettoDec = $this->crypto->decrypt('m.oggetto'); // opzionale
    $main = $db->query("
        SELECT m.id_message,
               m.mitt,
               m.dest,
               m.id_mitt,
               m.id_dest,
               m.dataora,
               {$testoDec},
               {$oggettoDec},
               m.dot_seg,
               m.dot_inf,
               m.inoltrato
        FROM {$this->table} m
        WHERE m.id_message = :id:
        LIMIT 1
    ", ['id' => $rootId])->getRowArray();
    if (!$main) return [];

    // ⚙️ Gestione mittente del MAIN con caso "per conto di"
    $dotSeg = (int)($main['dot_seg'] ?? 0);
    $dotInf = (int)($main['dot_inf'] ?? 0);

    $namePartsMain = $this->getNamePartsByMittAndFlags(
        (string)$main['mitt'],
        (int)$main['id_mitt'],
        $dotSeg,
        $dotInf
    );

    $isHtmlMain = (int)($main['is_html'] ?? 1); // fallback a 1
    log_message('debug', 'THREAD 4');

    $thread = [[
        'id_message'       => (int)$main['id_message'],
        'mitt'             => $main['mitt'],
        'dest'             => $main['dest'],
        'id_mitt'          => (int)$main['id_mitt'],
        'id_dest'          => (int)$main['id_dest'],
        'dataora'          => $main['dataora'],
        'testo'            => ($isHtmlMain ? $this->sanitizeHtml($main['testo'] ?? '') : ($main['testo'] ?? '')),
        'is_html'          => $isHtmlMain,
        'allegati'         => $this->getAttachments((int)$main['id_message']),
        'mittente_nome'    => $namePartsMain['mittente_nome'],
        'mittente_cognome' => $namePartsMain['mittente_cognome'],
        'mitt_prefix'      => $namePartsMain['prefix'] ?? '',
        // 👇 nuovo: testo inoltro per la view
        'inoltrato'        => $main['inoltrato'] ?? '',
    ]];

    log_message('debug', 'THREAD 5');

    // 2) Replies ordinate ASC
    $replies = $db->table($this->replyTable . ' r')
        ->select('r.id_message, r.mitt, r.dest, r.id_mitt, r.id_dest, r.dataora, r.inoltrato, r.dot_seg, r.dot_inf')
        ->where('r.id_message_ini', $rootId)
        ->orderBy('r.dataora', 'ASC')
        ->get()->getResultArray();

    foreach ($replies as $r) {
        // decifra testo reply
        $testo = $db->query("
            SELECT {$this->crypto->decrypt('x.testo')}
            FROM {$this->replyTable} x
            WHERE x.id_message = :id:
            LIMIT 1
        ", ['id' => (int)$r['id_message']])->getRow('testo') ?? '';

        $nameParts = $this->getNamePartsByMittAndFlags(
            (string)$r['mitt'],
            (int)$r['id_mitt'],
            (int)($r['dot_seg'] ?? 0),
            (int)($r['dot_inf'] ?? 0)
        );
        log_message('debug', 'THREAD 7');

        $thread[] = [
            'id_message'       => (int)$r['id_message'],
            'mitt'             => $r['mitt'],
            'dest'             => $r['dest'],
            'id_mitt'          => (int)$r['id_mitt'],
            'id_dest'          => (int)$r['id_dest'],
            'dataora'          => $r['dataora'],
            'testo'            => $this->sanitizeHtml($testo), // renderizziamo come HTML
            'is_html'          => 1,
            'allegati'         => $this->getAttachments((int)$r['id_message']),
            'mittente_nome'    => $nameParts['mittente_nome'],
            'mittente_cognome' => $nameParts['mittente_cognome'],
            'mitt_prefix'      => $nameParts['prefix'] ?? '',
            // opzionale, ma coerente: inoltrato anche sulle reply
            'inoltrato'        => $r['inoltrato'] ?? '',
        ];
    }

    log_message('debug', 'THREAD 8');

    // 3) Safety: ordinamento ASC per dataora
    usort($thread, function ($a, $b) {
        return strcmp($a['dataora'] ?? '', $b['dataora'] ?? '');
    });

    return $thread;
}



    /* =======================================================
     *                  LOGICA DI INOLTRO
     * ======================================================= */

    /**
     * Inoltra un thread esistente verso segreteria o infermiera.
     */
       /**
     * Inoltra un thread esistente verso segreteria o infermiera.
     */
        /**
     * Inoltra un thread esistente verso segreteria o infermiera.
     */
        /**
     * Inoltra un thread esistente verso segreteria o infermiera.
     */

        
  public function forward(string $uid)
{
    $utente = session()->get('utente_sess');
    if (!$utente) return redirect()->to(base_url('login'));

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

    $selectedDoctorId = (int)(session()->get('selectedDoctorId') ?? 0);

    // nome medico (se selezionato)
    $doctorName = '';
    $menuData = session()->get('menuData');
    $dottori  = $menuData['dottori'] ?? [];
    if ($selectedDoctorId > 0 && isset($dottori[$selectedDoctorId]['titolo'])) {
        $doctorName = trim((string)$dottori[$selectedDoctorId]['titolo']);
    }

    $model = new MessageModel();

    try {
        $ctx = $model->getReplyContext($id, [
            'tipoUser' => $tipoUser,
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
        'isPatient'        => $isPatient,
        'menuData'         => $menuData,
        'immagineProfilo'  => session()->get('immagine_profilo') ?: 'user.png',
        'nomeVisualizzato' => session()->get('nome_visualizzato') ?: '',

        // ✅ per la view inoltro
        'tipoPers'         => $tipoPers,
        'isDoc'            => $isDoc,
        'isSeg'            => $isSeg,
        'isInf'            => $isInf,
        'selectedDoctorId' => $selectedDoctorId,
        'selectedDoctorName' => $doctorName,
    ]);
}

public function forwardMessage(
    int $idMessage,
    int $destCode,          // 1=P, 2=I, 3=S
    int $destId,            // id_personale reale del destinatario (ORA: lo accettiamo per tutti, non solo P)
    string $testoInoltro,
    array $session
): array {

    // 0) Il paziente NON può inoltrare
    $tipoUser = (int)($session['tipoUser'] ?? 0); // 3 = paziente
    if ($tipoUser === 3) {
        throw new \RuntimeException('Il paziente non può inoltrare messaggi.');
    }

    if (!in_array($destCode, [1,2,3], true)) {
        throw new \RuntimeException('Destinatario non valido per inoltro.');
    }

    // 1) mittente (chi inoltra)
    $tipoPers = $this->normalizeMailboxTipoPers((int)($session['tipoPers'] ?? 0)); // 1=P,2=I,3=S
    $mittCode = match ($tipoPers) {
        1       => 'P',
        2       => 'I',
        3       => 'S',
        default => throw new \RuntimeException('Ruolo mittente non valido per inoltro.'),
    };

    $mittId = (int)($session['id_personale'] ?? $session['id_user'] ?? $session['idUser'] ?? 0);
    if ($mittId <= 0) {
        throw new \RuntimeException('ID mittente non valido per inoltro.');
    }

    // 2) regole inoltro
    // - Medico(P) -> Segreteria(S) o Infermiere(I)
    // - Segreteria(S) -> Medico(P) o Infermiere(I)
    // - Infermiere(I) -> Medico(P) o Segreteria(S)
    if ($tipoPers === 1 && !in_array($destCode, [2,3], true)) {
        throw new \RuntimeException('Il dottore può inoltrare solo a segreteria o infermieri.');
    }
    if ($tipoPers === 3 && !in_array($destCode, [1,2], true)) {
        throw new \RuntimeException('La segreteria può inoltrare solo al dottore o agli infermieri.');
    }
    if ($tipoPers === 2 && !in_array($destCode, [1,3], true)) {
        throw new \RuntimeException('Gli infermieri possono inoltrare solo al dottore o alla segreteria.');
    }

    // 3) dest char + destId
    $destChar = match ($destCode) {
        1 => 'P',
        2 => 'I',
        3 => 'S',
        default => 'S',
    };

    $db = Database::connect();
    $db->transBegin();

    try {

        // 3a) se destId non arriva (UI radio), lo risolviamo con un default (primo record del ruolo)
        if ($destId <= 0) {
           $destId = $this->resolveDefaultPersonaleIdByDestCode($db, $destCode, $session);
        }
        if ($destId <= 0) {
            throw new \RuntimeException('ID destinatario non valido/non risolvibile per inoltro.');
        }

        // 4) risaliamo al "paziente" del thread origine (se esiste) per costruire "per conto di"
        $orig = $db->table($this->table)
            ->select('id_message, mitt, dest, id_mitt, id_dest')
            ->where('id_message', $idMessage)
            ->get()->getRowArray();

        if (!$orig) {
            throw new \RuntimeException('Messaggio origine non trovato.');
        }

        $clientId = 0;
        if (($orig['mitt'] ?? '') === 'C') {
            $clientId = (int)($orig['id_mitt'] ?? 0);
        } elseif (($orig['dest'] ?? '') === 'C') {
            $clientId = (int)($orig['id_dest'] ?? 0);
        }

        $clientName = '';
        if ($clientId > 0) {
            $cog = $this->crypto->decryptSenzaAlias('c.cognome');
            $nom = $this->crypto->decryptSenzaAlias('c.nome');
            $clientName = $db->query("
                SELECT CONCAT($nom,' ',$cog) AS n
                FROM dap02_clients c
                WHERE c.id_client = :id:
                LIMIT 1
            ", ['id' => $clientId])->getRow('n') ?? '';
            $clientName = SystemUserMask::getMaskedClientDisplayName($clientId, $clientName);
        }

        // Nome mittente (personale)
        $qualM = $this->crypto->decryptSenzaAlias('p.qualifica');
        $cogM  = $this->crypto->decryptSenzaAlias('p.cognome');
        $nomM  = $this->crypto->decryptSenzaAlias('p.nome');
        $byName = $db->query("
            SELECT CONCAT($qualM,' ',$nomM,' ',$cogM) AS n
            FROM dap03_personale p
            WHERE p.id_personale = :id:
            LIMIT 1
        ", ['id' => $mittId])->getRow('n') ?? '';

        // 5) metadata inoltro in JSON (in dap10_message.inoltrato)
        $inoltroMeta = [
            'v' => 1,
            'by_role' => $mittCode,     // P/S/I
            'by_id'   => $mittId,
            'by_name' => $byName,
            'for_client_id'   => $clientId,
            'for_client_name' => $clientName,
            'comment' => $testoInoltro,
        ];
        $inoltratoJson = json_encode($inoltroMeta, JSON_UNESCAPED_UNICODE);

        // 6) flags “dot_*” (se ti servono in list/filtri)
        $dotSeg = 0;
        $dotInf = 0;
        if ($mittCode === 'P' && $destChar === 'S') $dotSeg = 1;
        if ($mittCode === 'S' && $destChar === 'P') $dotSeg = 1;
        if ($mittCode === 'P' && $destChar === 'I') $dotInf = 1;
        if ($mittCode === 'I' && $destChar === 'P') $dotInf = 1;

        // 7) nuovo id_message
        $contatore = new \App\Models\ContatoreModel();
        $newId     = $contatore->next('dap10_message');

        // 8) INSERT main: mitt/dest reali = staff↔staff
        // NB: id_mitt = mittId (chi inoltra) -- ok per tutte le regole
        $sqlMain = "
            INSERT INTO {$this->table}
                (id_message, id_mitt, id_dest, oggetto, testo, vector_id,
                 mitt, dest,
                 inf_flag, seg_flag,
                 inoltrato,
                 dot_seg, dot_inf,
                 dataora, letto, eliminato, da_dottore, draft)
            SELECT
                :newId:, :mittId:, :destId:, oggetto, testo, vector_id,
                :mittCode:, :destChar:,
                1, 1,
                :inoltrato:,
                :dotSeg:, :dotInf:,
                NOW(), 0, 0, da_dottore, draft
            FROM {$this->table}
            WHERE id_message = :oldId:
        ";

        $db->query($sqlMain, [
            'newId'     => $newId,
            'oldId'     => $idMessage,
            'destId'    => $destId,
            'mittId'    => $mittId,
            'mittCode'  => $mittCode,
            'destChar'  => $destChar,
            'inoltrato' => $inoltratoJson,
            'dotSeg'    => $dotSeg,
            'dotInf'    => $dotInf,
        ]);

        if ($db->affectedRows() !== 1) {
            throw new \RuntimeException('Impossibile creare il nuovo messaggio inoltrato.');
        }

        // 9) delete mapping: nascondi al mittente e al destinatario
        $db->query("
            INSERT INTO {$this->deleteTable} (id_message, id_utente)
            VALUES (:newId:, :uid:)
        ", ['newId' => $newId, 'uid' => $mittId]);

        $db->query("
            INSERT INTO {$this->deleteTable} (id_message, id_utente)
            VALUES (:newId:, :uid:)
        ", ['newId' => $newId, 'uid' => $destId]);

        // 10) Copia replies e allegati come già fai tu (uguale al tuo codice)
        $db->query("
            INSERT INTO {$this->replyTable}
                (id_mitt, id_dest, testo, dataora, letto, eliminato, oggetto, vector_id, da_dottore,
                 id_message_ini, mitt, dest, seg_flag, inf_flag, inoltrato)
            SELECT
                id_mitt, id_dest, testo, dataora, letto, eliminato, oggetto, vector_id, da_dottore,
                :newId:, mitt, dest, seg_flag, inf_flag, :inoltrato:
            FROM {$this->replyTable}
            WHERE id_message_ini = :oldId:
        ", [
            'newId' => $newId,
            'oldId' => $idMessage,
            'inoltrato' => $inoltratoJson,
        ]);

        $db->query("
            INSERT INTO dap11_attachments (id_message, id_message_reply, nome_real, nome_vis, vector_id)
            SELECT :newId:, 0, a.nome_real, a.nome_vis, a.vector_id
            FROM dap11_attachments a
            LEFT JOIN {$this->replyTable} r ON r.id_message = a.id_message_reply
            WHERE a.id_message = :oldId:
               OR r.id_message_ini = :oldId:
        ", ['newId' => $newId, 'oldId' => $idMessage]);

        // reply delete mapping (come nel tuo)
        $db->query("
            INSERT INTO {$this->replyDeleteTable} (id_message, id_utente)
            SELECT id_message, id_mitt
            FROM {$this->replyTable}
            WHERE id_message_ini = :oldId:
        ", ['oldId' => $idMessage]);

        $db->query("
            INSERT INTO {$this->replyDeleteTable} (id_message, id_utente)
            SELECT id_message, id_dest
            FROM {$this->replyTable}
            WHERE id_message_ini = :oldId:
        ", ['oldId' => $idMessage]);

        // forward map
        $db->table($this->forwardMapTable)->insert([
            'id_message_new' => $newId,
            'id_message'     => $idMessage,
        ]);

        if ($db->transStatus() === false) {
            throw new \RuntimeException('Errore durante la transazione di inoltro.');
        }

        $db->transCommit();

        return ['resp' => 'OK', 'id_message_new' => $newId];

    } catch (\Throwable $e) {
        if ($db->transStatus() !== false) {
            $db->transRollback();
        }
        throw $e;
    }
}

/**
 * Se non arriva destId dalla UI, prendo un “default” per ruolo.
 * (Adatta qui se hai una tabella di assegnazione medico->seg/inf)
 */
private function resolveDefaultPersonaleIdByDestCode($db, int $destCode, array $session = []): int
{
    // Preferisci i default già noti (passati dal controller o in sessione)
    $defaultSeg = (int)($session['defaultSegreteriaId'] ?? 0);
    $defaultInf = (int)($session['defaultInfermieraId'] ?? 0);

    return match ($destCode) {
        3 => $defaultSeg, // segreteria
        2 => $defaultInf, // infermiere
        default => 0,
    };
}




    /* ===========================
     * -------- Helpers ----------
     * =========================== */

    /** Determina l'ID radice del thread partendo da un id che può essere main o reply */
    protected function resolveRootId(int $anyId): ?int
    {
        $db = Database::connect();

        // È un main?
        $row = $db->table($this->table)
            ->select('id_message')
            ->where('id_message', $anyId)
            ->get(1)->getRowArray();
        if ($row) return (int)$row['id_message'];

        // È una reply? prendo id_message_ini
        $row = $db->table($this->replyTable)
            ->select('id_message_ini')
            ->where('id_message', $anyId)
            ->get(1)->getRowArray();

        return $row ? (int)$row['id_message_ini'] : null;
    }

    /** Nome+qualifica decrittati per PERSONALE */
     function getPersonaleNameParts(int $id): array
    {
        $db   = Database::connect();
        $cog  = $this->crypto->decryptSenzaAlias('p.cognome');
        $nom  = $this->crypto->decryptSenzaAlias('p.nome');
        $qual = $this->crypto->decryptSenzaAlias('p.qualifica');

        $r = $db->query("
            SELECT 
                $cog  AS cognome,
                $nom  AS nome,
                $qual AS qualifica
            FROM dap03_personale p
            WHERE p.id_personale = :id:
            LIMIT 1
        ", ['id' => $id])->getRowArray();
        log_message('error',print_r($r,true));
        // mittente_nome = "Dott. Mario", mittente_cognome = "Rossi"
        $qualifica = trim((string)($r['qualifica'] ?? ''));
        $nome      = trim((string)($r['nome'] ?? ''));
        $cognome   = trim((string)($r['cognome'] ?? ''));

        return [
            'mittente_cognome'    => trim(($qualifica !== '' ? $qualifica.' ' : '').$cognome),
            'mittente_nome' => $nome,
        ];
    }

    /** Nome e cognome decrittati per CLIENTE */
    protected function getClienteNameParts(int $id): array
    {
        $db  = Database::connect();
        $cog = $this->crypto->decryptSenzaAlias('c.cognome');
        $nom = $this->crypto->decryptSenzaAlias('c.nome');

        $r = $db->query("
            SELECT $cog AS cognome, $nom AS nome
            FROM dap02_clients c
            WHERE c.id_client = :id:
            LIMIT 1
        ", ['id' => $id])->getRowArray();

        if ($r && SystemUserMask::isMaskedClientId($id)) {
            return [
                'mittente_cognome' => SystemUserMask::SYSTEM_USER_LABEL,
                'mittente_nome'    => '',
            ];
        }

        return [
            'mittente_cognome' => $r['cognome'] ?? '',
            'mittente_nome'    => $r['nome'] ?? '',
        ];
    }

    private function hasResolvedNameParts(array $parts): bool
    {
        return trim((string)($parts['mittente_nome'] ?? '')) !== ''
            || trim((string)($parts['mittente_cognome'] ?? '')) !== '';
    }

    protected function getNamePartsByMittAndFlags(string $mittCode, int $idMitt, int $dotSeg = 0, int $dotInf = 0): array
    {
        $mittCode = strtoupper(trim($mittCode));

        if ($mittCode === 'C') {
            return $this->getClienteNameParts($idMitt);
        }

        $parts = $this->getPersonaleNameParts($idMitt);
        if ($this->hasResolvedNameParts($parts)) {
            switch ($mittCode) {
                case 'S':
                    $parts['prefix'] = "Da parte della Segreteria per conto di ";
                    break;
                case 'I':
                    $parts['prefix'] = "Da parte dell'infermiere per conto di ";
                    break;
                default:
                    $parts['prefix'] = '';
                    break;
            }

            return $parts;
        }

        if ($mittCode === 'P' && ($dotSeg === 1 || $dotInf === 1)) {
            $clientParts = $this->getClienteNameParts($idMitt);
            if ($this->hasResolvedNameParts($clientParts)) {
                $clientParts['prefix'] = 'Da parte del medico per conto di ';
                return $clientParts;
            }
        }

        $parts['prefix'] = '';
        return $parts;
    }

    /** Restituisce [nome, cognome] in base al codice mittente (C = cliente, P/I/S = personale) */
   protected function getNamePartsByMitt(string $mittCode, int $idMitt): array
    {
        $parts = $this->getNamePartsByMittAndFlags($mittCode, $idMitt);
        log_message('debug','PARTI:'.($parts['prefix'] ?? ''));
        return $parts;
    }
public function getDoctorOwnerByMessage(int $idMessageIni): ?array
{
    $sql = "
        SELECT p.id_personale, CONCAT(p.cognome,' ',p.nome) AS nome
        FROM dap10_message m
        JOIN dap03_personale p ON p.id_personale = m.id_dest
        WHERE m.id_message = ?
          AND p.tipo_pers = 1
        LIMIT 1
    ";

    return $this->db->query($sql, [$idMessageIni])->getRowArray();
}


    /** Sanitizzazione minima dell’HTML (se hai già una whitelist server-side, restituisci $html) */
    protected function sanitizeHtml(string $html): string
    {
        // TODO: integrare con sanitizer/whitelist se necessario
        return $html;
    }

    /**
     * Allegati del messaggio (stub).
     */

    public function getAttachmentRow(int $idAttachment): ?array
{
    $db            = Database::connect();
    $crypto_helper = new \App\Libraries\Crypto_helper();

    $DEC_REAL = $crypto_helper->decryptSenzaAlias('a.nome_real');
    $DEC_VIS  = $crypto_helper->decryptSenzaAlias('a.nome_vis');

    $sql = "
        SELECT 
            a.id_attachments,
            {$DEC_REAL} AS nome_real,
            {$DEC_VIS}  AS nome_vis,
            a.id_message,
            a.id_message_reply,
            UPPER(HEX(a.vector_id)) AS vector_id_hex
        FROM dap11_attachments a
        WHERE a.id_attachments = :id:
        LIMIT 1
    ";

    $row = $db->query($sql, ['id' => $idAttachment])->getRowArray();
    return $row ?: null;
}

/*public function getAttachmentRow(int $idAttachment): ?array
{
    $db            = Database::connect();
    $crypto_helper = new \App\Libraries\Crypto_helper();

    $DEC_REAL = $crypto_helper->decryptSenzaAlias('a.nome_real');

    $sql = "
        SELECT 
            a.id_attachments,
            {$DEC_REAL} AS nome_real,
            a.id_message,
            UPPER(HEX(a.vector_id)) AS vector_id_hex
        FROM dap11_attachments a
        WHERE a.id_attachments = :id:
        LIMIT 1
    ";

    $row = $db->query($sql, ['id' => $idAttachment])->getRowArray();
    return $row ?: null;
}*/
 protected function getAttachments(int $idMessageAny): array
{
    $db            = Database::connect();
    $crypto_helper = new \App\Libraries\Crypto_helper();

    // Decrypt path e nome visibile
    $DEC_REAL = $crypto_helper->decryptSenzaAlias('a.nome_real');
    $DEC_VIS  = $crypto_helper->decryptSenzaAlias('a.nome_vis');

    // 1) Allegati legati come REPLY (id_message_reply = id)
    $sqlReply = "
        SELECT 
            a.id_attachments,
            {$DEC_REAL} AS nome_real,
            {$DEC_VIS}  AS nome_vis,
            UPPER(HEX(a.vector_id)) AS vector_id_hex
        FROM dap11_attachments a
        WHERE a.id_message_reply = :id:
    ";

    $rows = $db->query($sqlReply, ['id' => $idMessageAny])->getResultArray();

    // 2) Se non trovo nulla, considero che sia il messaggio principale
    if (empty($rows)) {
        $sqlMain = "
            SELECT 
                a.id_attachments,
                {$DEC_REAL} AS nome_real,
                {$DEC_VIS}  AS nome_vis,
                UPPER(HEX(a.vector_id)) AS vector_id_hex
            FROM dap11_attachments a
            WHERE a.id_message = :id:
              AND (a.id_message_reply IS NULL OR a.id_message_reply = 0)
        ";

        $rows = $db->query($sqlMain, ['id' => $idMessageAny])->getResultArray();
    }

    $out = [];

    foreach ($rows as $r) {
        $pathRel = trim($r['nome_real'] ?? '');
        $nomeVis = trim($r['nome_vis'] ?? '');

        if ($nomeVis === '' && $pathRel !== '') {
            $nomeVis = basename(str_replace('\\', '/', $pathRel));
        }
        if ($nomeVis === '') {
            $nomeVis = 'allegato';
        }

        // Estensione per capire se è immagine
        $ext = strtolower(pathinfo($nomeVis, PATHINFO_EXTENSION));

        // 🔹 URL NON punta alla cartella, ma al controller
        $url = site_url('posta/attachment/' . (int) $r['id_attachments']);

        $out[] = [
            'id'   => (int) $r['id_attachments'],
            'nome'=> $nomeVis,
            'tipo'=> $ext,
            'size'=> '',          // opzionale, se vuoi puoi calcolare filesize
            'url' => $url,
        ];
    }

    return $out;
}



    /** Helper per formattare le dimensioni file */
    protected function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $power = (int)floor(log($bytes, 1024));
        return number_format($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    /**
     * Restituisce il prossimo id_message (equivalente a getIdMessage del vecchio PHP).
     */
    protected function getNextMessageId($db): int
    {
        $row = $db->query("
            SELECT IFNULL(MAX(id_message) + 1, 1) AS id_message
            FROM {$this->table}
        ")->getRowArray();

        return (int)($row['id_message'] ?? 1);
    }

    /**
     * Inserisce un NUOVO messaggio in dap10_message.
     *
     * Firma compatibile con il legacy / MessageService.
     */
    public function inserisciNuovo(
        int $idMessage,
        int $idMitt,
        int $idDest,
        string $subject,
        string $message,
        string $mitt,
        string $dest,
        int $segFlag,
        int $infFlag,
        int $daDottore,
        int $draft,
        bool $isHtml = true
    ): void {
        $db = $this->db;

        if ($subject === '') {
            $subject = 'Messaggio';
        }

        // Cifratura oggetto/testo con Crypto_helper
        $sqlOggetto = $this->crypto->encrypt($subject);
        $sqlTesto   = $this->crypto->encrypt($message);

        $sql = "
            INSERT INTO {$this->table} (
                id_message,
                id_mitt,
                id_dest,
                oggetto,
                testo,
                vector_id,
                dataora,
                letto,
                eliminato,
                da_dottore,
                mitt,
                dest,
                seg_flag,
                inf_flag,
                draft
            ) VALUES (
                {$idMessage},
                {$idMitt},
                {$idDest},
                {$sqlOggetto},
                {$sqlTesto},
                @init_vector,
                NOW(),
                0,
                0,
                {$daDottore},
                " . $db->escape($mitt) . ",
                " . $db->escape($dest) . ",
                {$segFlag},
                {$infFlag},
                {$draft}
            )
        ";

        $db->query($sql);

        if ($db->affectedRows() <= 0) {
            throw new \RuntimeException('Inserimento messaggio fallito');
        }
    }

    /**
     * Registra il messaggio nelle tabelle di delete per mittente e destinatario.
     *
     * Usato per la tabella principale (dap10_message_delete).
     */
    public function insertDeletePair(int $idMessage, int $idMitt, int $idDest): bool
    {
        $db = $this->db;

        if ($idMessage <= 0) {
            log_message('debug', 'insertDeletePair chiamato senza idMessage valido');
            return false;
        }

        $tabDelete = $this->deleteTable; // 'dap10_message_delete'

        // Riga per il mittente
        if ($idMitt > 0) {
            $db->query("
                INSERT INTO {$tabDelete} (id_message, id_utente, eliminato)
                VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE eliminato = VALUES(eliminato)
            ", [$idMessage, $idMitt]);
        }

        // Riga per il destinatario (se diverso dal mittente)
        if ($idDest > 0 && $idDest !== $idMitt) {
            $db->query("
                INSERT INTO {$tabDelete} (id_message, id_utente, eliminato)
                VALUES (?, ?, 0)
                ON DUPLICATE KEY UPDATE eliminato = VALUES(eliminato)
            ", [$idMessage, $idDest]);
        }

        return true;
    }
}
