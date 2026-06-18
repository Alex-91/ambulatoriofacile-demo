<?php
namespace App\Models;

use CodeIgniter\Model;
use Config\Database;
use App\Libraries\Crypto_helper;

class MessageReplyModel extends Model
{
    protected $table      = 'dap10_message_reply';
    protected $primaryKey = 'id_message';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_message','id_mitt','id_dest','testo','dataora','letto','eliminato',
        'oggetto','vector_id','da_dottore','id_message_ini','mitt','dest',
        'seg_flag','inf_flag','email','inoltrato','draft','dot_seg','dot_inf',
    ];

    /** Crypto helper */
    protected Crypto_helper $crypto;

    public function __construct(?Crypto_helper $crypto = null)
    {
        parent::__construct();
        $this->crypto = $crypto ?? new Crypto_helper();
    }

    /**
     * INSERT della reply con cifratura lato SQL costruita dalla Crypto_helper.
     */
    public function inserisciReply(
        int $idReply,
        int $idMessageIni,
        int $idMitt,
        int $idDest,
        string $mitt,      // 'C'|'P'|'S'|'I'
        string $dest,      // 'C'|'P'|'S'|'I'
        string $oggetto,
        string $testo,
        int $segFlag,
        int $infFlag,
        int $daDottore,
        int $draft,
        int $dotSeg = 0,
        int $dotInf = 0,
        ?int $email = null,
        ?int $inoltrato = null
    ): void {
        $db = Database::connect();

        // Frammenti SQL di cifratura (usano @key_str e @init_vector come nel legacy)
        $oggettoEncSql = $this->crypto->encrypt_insert(':oggetto:'); // HEX(AES_ENCRYPT(:oggetto:,@key_str,@init_vector))
        $testoEncSql   = $this->crypto->encrypt_insert(':testo:');

        $sql = "
            INSERT INTO {$this->table}
            (id_message, id_message_ini, id_mitt, id_dest, mitt, dest, oggetto, testo,
             seg_flag, inf_flag, da_dottore, vector_id, draft, dot_seg, dot_inf)
            VALUES
            ({id_reply}, {id_ini}, {id_mitt}, {id_dest}, :mitt:, :dest:, {$oggettoEncSql}, {$testoEncSql},
             {seg_flag}, {inf_flag}, {da_dottore}, @init_vector, {draft}, {dot_seg}, {dot_inf})
        ";

        $repl = [
            '{id_reply}'   => (string)$idReply,
            '{id_ini}'     => (string)$idMessageIni,
            '{id_mitt}'    => (string)$idMitt,
            '{id_dest}'    => (string)$idDest,
            '{seg_flag}'   => (string)$segFlag,
            '{inf_flag}'   => (string)$infFlag,
            '{da_dottore}' => (string)$daDottore,
            '{draft}'      => (string)$draft,
            '{dot_seg}'    => (string)$dotSeg,
            '{dot_inf}'    => (string)$dotInf,

        ];

        $ok = $db->query(strtr($sql, $repl), [
            'mitt'    => $mitt,
            'dest'    => $dest,
            'oggetto' => $oggetto,
            'testo'   => $testo,
        ]);

        if ($ok === false) {
            $err = $db->error();
            log_message('error', 'INSERT reply failed: {code} {msg}', ['code'=>$err['code']??'-', 'msg'=>$err['message']??'-']);
            throw new \RuntimeException('Errore inserimento reply');
        }
    }

    /** Inserisce in `dap10_message_reply_delete` per mittente e destinatario */
    public function insertDeletePair(int $idReply, int $idUtenteA, int $idUtenteB): void
    {
        Database::connect()->table('dap10_message_reply_delete')->insertBatch([
            ['id_message' => $idReply, 'id_utente' => $idUtenteA],
            ['id_message' => $idReply, 'id_utente' => $idUtenteB],
        ]);
    }

    /**
     * Recupera il contesto per la reply:
     * - se esiste una reply: prende mitt/dest/id_mitt/id_dest dalla reply più recente e
     *   i flag + oggetto decifrato dal messaggio iniziale
     * - altrimenti, tutto dal messaggio iniziale
     */

        protected function resolveRootId(int $anyId): ?int
    {
        $db = Database::connect();

        // È un main?
        $row = $db->table("dap10_message")
            ->select('id_message')
            ->where('id_message', $anyId)
            ->get(1)->getRowArray();
        if ($row) return (int)$row['id_message'];

        // È una reply? prendo id_message_ini
        $row = $db->table("dap10_message_reply")
            ->select('id_message_ini')
            ->where('id_message', $anyId)
            ->get(1)->getRowArray();

        return $row ? (int)$row['id_message_ini'] : null;
    }

    public function getContextForReply(int $idMessage): ?array
    {
        $db = Database::connect();

        $tblMain  = 'dap10_message';
        $tblReply = 'dap10_message_reply';
 //var_dump($idMessage);
        // ultima reply
        $rootId = $this->resolveRootId($idMessage);
       // var_dump($rootId);
            $qLast = $db->table($tblReply)
                ->select('id_message')
                ->where('id_message_ini', $rootId)
                ->orderBy('dataora', 'DESC')
                ->get(1);

        if ($qLast === false) {
            $err = $db->error();
            log_message('error', 'getContextForReply: last reply query error {code} {msg}', [
                'code' => $err['code'] ?? '-', 'msg' => $err['message'] ?? '-'
            ]);
            return null;
        }
        $last = $qLast->getRowArray();

        // decrypt dell’oggetto del messaggio iniziale (alias m.)
        $oggettoDec = $this->crypto->decrypt('m.oggetto'); // ... AS oggetto
        //var_dump($last);
        if ($last) {
            $builder = $db->table("$tblReply r")
                ->select("
                    r.mitt, r.dest, r.id_mitt, r.id_dest,
                    m.seg_flag, m.inf_flag, m.dot_seg, m.dot_inf,
                    {$oggettoDec},r.id_message_ini 
                ", false)
                ->join("$tblMain m", 'm.id_message = r.id_message_ini', 'inner')
                ->where('r.id_message', (int)$last['id_message']);
        } else {
            $builder = $db->table("$tblMain m")
                ->select("
                    m.mitt, m.dest, m.id_mitt, m.id_dest,m.id_message as id_message_ini,
                    m.seg_flag, m.inf_flag, m.dot_seg, m.dot_inf,
                    {$oggettoDec}
                ", false)
                ->where('m.id_message', $idMessage);
        }
        log_message('debug','m.mitt, m.dest, m.id_mitt, m.id_dest,m.id_message as id_message_ini,
                    m.seg_flag, m.inf_flag, m.dot_seg, m.dot_inf,'.$oggettoDec);
        $q = $builder->get(1);
        if ($q === false) {
            $err = $db->error();
            log_message('error', 'getContextForReply: final query error {code} {msg}', [
                'code' => $err['code'] ?? '-', 'msg' => $err['message'] ?? '-'
            ]);
            return null;
        }

        return $q->getRowArray() ?: null;
    }

    /**
     * Rende l’HTML della bolla (Direct Chat) per una reply appena inserita
     * (utile se vuoi fare append live via AJAX).
     */
    public function renderHtmlReply(int $idReply): string
    {
        $db = Database::connect();

        // dati base reply
        $row = $db->table($this->table)
            ->select('id_message, mitt, dest, id_mitt, id_dest, dataora')
            ->where('id_message', $idReply)
            ->get(1)->getRowArray();

        if (!$row) return '';

        // testo decifrato (alias r.)
        $testoDec = $this->crypto->decrypt('r.testo'); // ... AS testo
        $reply = $db->query("
            SELECT {$testoDec}
            FROM {$this->table} r
            WHERE r.id_message = :id:
            LIMIT 1
        ", ['id' => $idReply])->getRowArray();

        $bodyHtml = $reply['testo'] ?? '';

        // nome visuale del mittente
        $display = $this->getDisplayName($row['mitt'], (int)$row['id_mitt']);

        // “me” a dx: paziente('C') se tipoUser=3, altrimenti P/I/S
        $isMe = $this->isCurrentUser($row['mitt']);
        $sideClass = $isMe ? ' right' : '';
        $who  = $isMe ? 'Tu' : htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
        $when = htmlspecialchars(is_numeric($row['dataora']) ? date('d/m/Y H:i', (int)$row['dataora']) : (string)$row['dataora'], ENT_QUOTES, 'UTF-8');

        return '
        <div class="direct-chat-msg'.$sideClass.'">
          <div class="meta">
            <span class="direct-chat-name '.($isMe ? 'pull-right' : 'pull-left').'">'.$who.'</span>
            <span class="direct-chat-timestamp '.($isMe ? 'pull-left' : 'pull-right').'">'.$when.'</span>
          </div>
          <div class="direct-chat-text" style="clear:both;">
            '.$bodyHtml.'
          </div>
        </div>';
    }

    /** “Me” = paziente C se tipoUser=3; altrimenti P/I/S */
    protected function isCurrentUser(string $mittCode): bool
    {
        $tipoUser = (int)(session()->get('tipoUser') ?? 0); // 3 = paziente
        if ($tipoUser === 3) return $mittCode === 'C';
        return in_array($mittCode, ['P','I','S'], true);
    }

    /** Nome visuale del mittente (usa decrypt_concat per evitare ambiguità vector_id) */
    protected function getDisplayName(string $mittCode, int $idMitt): string
    {
        $db = Database::connect();

        if ($mittCode === 'C') {
            $cog = $this->crypto->decrypt_concat('c.cognome');
            $nom = $this->crypto->decrypt_concat('c.nome');
            return $db->query("
                SELECT CONCAT($cog, ' ', $nom) AS nome
                FROM dap02_clients c
                WHERE c.id_client = :id:
                LIMIT 1
            ", ['id' => $idMitt])->getRow('nome') ?? 'Cliente';
        }

        $qual = $this->crypto->decrypt_concat('p.qualifica');
        $cog  = $this->crypto->decrypt_concat('p.cognome');
        $nom  = $this->crypto->decrypt_concat('p.nome');
        return $db->query("
            SELECT CONCAT($qual, ' ', $cog, ' ', $nom) AS nome
            FROM dap03_personale p
            WHERE p.id_personale = :id:
            LIMIT 1
        ", ['id' => $idMitt])->getRow('nome') ?? 'Operatore';
    }
}
