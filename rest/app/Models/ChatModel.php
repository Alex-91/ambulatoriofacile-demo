<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;
use App\Libraries\Crypto_helper;
use App\Services\StaffDoctorAccessService;

class ChatModel extends Model
{
    protected $db;
    protected $crypto;
    protected StaffDoctorAccessService $staffDoctorAccess;

    // =========================================================
    // MAPPING RUOLI SU dap03_personale.tipo
    // CAMBIA QUI se i valori reali sono diversi
    // =========================================================
    public const TIPO_DOTTORE    = 1;
    public const TIPO_INFERMIERE = 2;
    public const TIPO_SEGRETERIA = 3;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::connect();
        $this->crypto = new Crypto_helper();
        $this->staffDoctorAccess = new StaffDoctorAccessService($this->db);
    }

    // =========================================================
    // SESSION USER
    // =========================================================
    public function getSessionUser()
    {
        $s = session();

        $me = $s->get('user');
        if (!$me) $me = $s->get('utente');
        if (!$me) $me = $s->get('auth_user');

        if (is_array($me)) $me = (object)$me;

        // IMPORTANT: devo avere id_user
        if (!$me || empty($me->id_user)) return null;

        // Se in sessione non ho "tipo" (ruolo personale), lo recupero da DB
        if (empty($me->tipo)) {
            $info = $this->getPersonaleByUserId((int)$me->id_user);
            if ($info) {
                $me->tipo = (int)$info['tipo'];
            }
        }

        return $me;
    }

    // =========================================================
    // PERSONALE BY USER
    // =========================================================
    public function getPersonaleByUserId(int $idUser): ?array
    {
        // nome/cognome/email potrebbero essere cifrati -> decrypt
        $sql = "
            SELECT
                p.id_personale,
                p.id_user,
                p.tipo,
                " . $this->crypto->decrypt('p.nome') . ",
                " . $this->crypto->decrypt('p.cognome') . ",
                " . $this->crypto->decrypt('p.email') . "
            FROM dap03_personale p
            WHERE p.id_user = ?
            LIMIT 1
        ";
        $row = $this->db->query($sql, [$idUser])->getRowArray();
        if (!$row) return null;

        $row['id_personale'] = (int)$row['id_personale'];
        $row['id_user']      = (int)$row['id_user'];
        $row['tipo']         = (int)$row['tipo'];

        return $row;
    }

    // =========================================================
    // GET USER IDS BY ROLE (da dap03_personale.tipo)
    // =========================================================
    private function getUserIdsByTipo(int $tipo): array
    {
        $sql = "SELECT p.id_user FROM dap03_personale p WHERE p.tipo = ? AND p.id_user IS NOT NULL";
        $res = $this->db->query($sql, [$tipo])->getResultArray();

        $out = [];
        foreach ($res as $r) $out[] = (int)$r['id_user'];
        return $out;
    }

    public function resolveChatTipoPers(int $userId, int $rawTipo): int
    {
        return $this->staffDoctorAccess->normalizeMailboxStaffTipo($rawTipo);
    }

    // =========================================================
    // LISTA MEDICI (id_user + nome completo)
    // =========================================================
    public function getDoctorsList(int $staffUserId = 0, int $staffTipo = 0): array
    {
        $staffTipo = $this->resolveChatTipoPers($staffUserId, $staffTipo);
        $params = [self::TIPO_DOTTORE];
        $staffDoctorUserIds = [];

        if (in_array($staffTipo, [self::TIPO_SEGRETERIA, self::TIPO_INFERMIERE], true)) {
            $staffDoctorUserIds = $this->staffDoctorAccess->getDoctorUserIdsForStaffUser($staffUserId, $staffTipo, 'chat');
            if (empty($staffDoctorUserIds)) {
                return [];
            }
        }

        $sql = "
            SELECT
                p.id_user,
                " . $this->crypto->decrypt('p.nome') . ",
                " . $this->crypto->decrypt('p.cognome') . "
            FROM dap03_personale p
            WHERE p.tipo = ?
              AND p.id_user IS NOT NULL
              AND (p.is_active IS NULL OR p.is_active = 0 OR p.is_active = 1)
        ";

        if (!empty($staffDoctorUserIds)) {
            $placeholders = implode(',', array_fill(0, count($staffDoctorUserIds), '?'));
            $sql .= " AND p.id_user IN ($placeholders)";
            $params = array_merge($params, $staffDoctorUserIds);
        }

        $sql .= " ORDER BY p.cognome ASC, p.nome ASC";

        $rows = $this->db->query($sql, $params)->getResultArray();

        foreach ($rows as &$r) {
            $r['id_user'] = (int)$r['id_user'];
            $nome = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''));
            $r['nome_completo'] = $nome !== '' ? $nome : ('Medico #' . $r['id_user']);
        }
        unset($r);

        return $rows;
    }

    // =========================================================
    // THREADS: "per medico" (group_key = segreteria_{doc} / infermieri_{doc})
    // =========================================================
    public function getOrCreateDoctorGroupThread(int $doctorUserId, string $baseKey, string $baseTitle): int
    {
        $groupKey = $baseKey . '_' . $doctorUserId;

        // 1) esiste?
        $sqlFind = "SELECT id_thread FROM dap_chat_thread WHERE group_key = ? LIMIT 1";
        $row = $this->db->query($sqlFind, [$groupKey])->getRowArray();

        if ($row) {
            $threadId = (int)$row['id_thread'];
        } else {
            // 2) crea thread group
            $sqlIns = "
                INSERT INTO dap_chat_thread (thread_type, group_key, title, created_at)
                VALUES ('group', ?, ?, NOW())
            ";
            $this->db->query($sqlIns, [$groupKey, $baseTitle]);
            $threadId = (int)$this->db->insertID();
        }

        // 3) membri: medico + tutto lo staff corretto
        $staffTipo = ($baseKey === 'segreteria') ? self::TIPO_SEGRETERIA : self::TIPO_INFERMIERE;
        $this->ensureDoctorGroupMembers($threadId, $doctorUserId, $staffTipo);

        return $threadId;
    }

    private function ensureDoctorGroupMembers(int $threadId, int $doctorUserId, int $staffTipo): void
    {
        $members = [$doctorUserId];

        $staffIds = $this->staffDoctorAccess->getStaffUserIdsForDoctorUser($doctorUserId, $staffTipo, 'chat');
        foreach ($staffIds as $id) {
            $members[] = (int)$id;
        }

        $members = array_values(array_unique(array_filter($members, static fn($id): bool => (int)$id > 0)));

        $existingRows = $this->db->query(
            "SELECT id_user FROM dap_chat_thread_user WHERE id_thread = ?",
            [$threadId]
        )->getResultArray();

        $existingIds = array_map(static fn(array $row): int => (int)($row['id_user'] ?? 0), $existingRows);
        $existingIds = array_values(array_unique(array_filter($existingIds, static fn(int $id): bool => $id > 0)));

        $sqlIns = "INSERT INTO dap_chat_thread_user (id_thread, id_user, last_read_at) VALUES (?, ?, NULL)";

        foreach (array_diff($members, $existingIds) as $uid) {
            $this->db->query($sqlIns, [$threadId, $uid]);
        }

        $toDelete = array_values(array_diff($existingIds, $members));
        if (!empty($toDelete)) {
            $this->db->table('dap_chat_thread_user')
                ->where('id_thread', $threadId)
                ->whereIn('id_user', $toDelete)
                ->delete();
        }
    }

    private function parseDoctorGroupKey(?string $groupKey): ?array
    {
        if (!is_string($groupKey)) {
            return null;
        }

        if (!preg_match('/^(segreteria|infermieri)_(\d+)$/', $groupKey, $matches)) {
            return null;
        }

        $baseKey = $matches[1];

        return [
            'base_key'       => $baseKey,
            'doctor_user_id' => (int)$matches[2],
            'staff_tipo'     => $baseKey === 'segreteria' ? self::TIPO_SEGRETERIA : self::TIPO_INFERMIERE,
        ];
    }

    private function syncDoctorGroupMembersByThreadId(int $threadId): ?array
    {
        if ($threadId <= 0) {
            return null;
        }

        $row = $this->db->query(
            "SELECT thread_type, group_key FROM dap_chat_thread WHERE id_thread = ? LIMIT 1",
            [$threadId]
        )->getRowArray();

        if (!$row || ($row['thread_type'] ?? '') !== 'group') {
            return null;
        }

        $meta = $this->parseDoctorGroupKey($row['group_key'] ?? null);
        if ($meta === null) {
            return null;
        }

        $this->ensureDoctorGroupMembers($threadId, (int)$meta['doctor_user_id'], (int)$meta['staff_tipo']);

        return $meta;
    }

    public function canStaffAccessDoctor(int $staffUserId, int $staffTipo, int $doctorUserId): bool
    {
        $staffTipo = $this->resolveChatTipoPers($staffUserId, $staffTipo);
        return $this->staffDoctorAccess->canStaffUserAccessDoctorUser($staffUserId, $staffTipo, $doctorUserId, 'chat');
    }

    // =========================================================
    // THREADS visibili per ruolo
    // =========================================================
   public function getThreadsForRole(int $meUserId, int $meTipo): array
{
    $meTipo = $this->resolveChatTipoPers($meUserId, $meTipo);

    // MEDICO: mantiene i 2 thread (anche se vuoti, va bene)
    if ($meTipo === self::TIPO_DOTTORE) {
        $tSeg = $this->getOrCreateDoctorGroupThread($meUserId, 'segreteria', 'Segreteria');
        $tInf = $this->getOrCreateDoctorGroupThread($meUserId, 'infermieri', 'Infermieri');

        // qui possono essere anche vuoti: ok
        $a = $this->getThreadListItem($tSeg, 'Segreteria');
        $b = $this->getThreadListItem($tInf, 'Infermieri');

        // se sono vuoti last_preview sarÃ  "", ma il medico li deve vedere comunque
        return [$a, $b];
    }

    // SEGRETERIA: solo thread NON vuoti (non crea nulla!)
    if ($meTipo === self::TIPO_SEGRETERIA) {
        return $this->getNonEmptyStaffThreads($meUserId, 'segreteria', 'Segreteria');
    }

    // INFERMIERE: solo thread NON vuoti (non crea nulla!)
    if ($meTipo === self::TIPO_INFERMIERE) {
        return $this->getNonEmptyStaffThreads($meUserId, 'infermieri', 'Infermieri');
    }

    return [];
}
public function clearThreadMessages(int $threadId): void
{
    $sql = "DELETE FROM dap_chat_message WHERE id_thread = ?";
    $this->db->query($sql, [$threadId]);
}

public function getThreadIdsForUser(int $userId): array
{
    $sql = "SELECT DISTINCT id_thread
            FROM dap_chat_thread_user
            WHERE id_user = ?";

    $query = $this->db->query($sql, [$userId]);
    $rows  = $query->getResultArray();

    $ids = [];
    foreach ($rows as $row) {
        $ids[] = (int)$row['id_thread'];
    }

    return $ids;
}

public function clearManyThreadsMessages(array $threadIds): void
{
    if (empty($threadIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));

    $sql = "DELETE FROM dap_chat_message
            WHERE id_thread IN ($placeholders)";

    $this->db->query($sql, $threadIds);
}

public function getLastPreviewMapForUser(int $userId): array
{
    $visibility = $this->buildVisibleThreadScopeForUser($userId);

    $sql = "
        SELECT
            m.id_thread,
            LEFT(m.body, 80) AS last_preview
        FROM dap_chat_message m
        INNER JOIN dap_chat_thread t
            ON t.id_thread = m.id_thread
        INNER JOIN (
            SELECT m2.id_thread, MAX(m2.id_message) AS max_id
            FROM dap_chat_message m2
            GROUP BY m2.id_thread
        ) x ON x.max_id = m.id_message
        INNER JOIN dap_chat_thread_user tu
            ON tu.id_thread = m.id_thread
           AND tu.id_user = ?
        WHERE (tu.cleared_at IS NULL OR m.created_at > tu.cleared_at)
          {$visibility['sql']}
    ";

    $rows = $this->db->query($sql, array_merge([$userId], $visibility['params']))->getResultArray();

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['id_thread']] = (string)($r['last_preview'] ?? '');
    }
    return $map;
}


public function getUnreadCountsMapForUser(int $userId): array
{
    $visibility = $this->buildVisibleThreadScopeForUser($userId);

    $sql = "
        SELECT
            m.id_thread,
            COUNT(*) AS unread_count
        FROM dap_chat_message m
        INNER JOIN dap_chat_thread t
            ON t.id_thread = m.id_thread
        INNER JOIN dap_chat_thread_user tu
            ON tu.id_thread = m.id_thread
           AND tu.id_user = ?
        WHERE m.sender_id <> ?
          AND (
                tu.last_read_at IS NULL OR m.created_at > tu.last_read_at
              )
          AND (
                tu.cleared_at IS NULL OR m.created_at > tu.cleared_at
              )
          {$visibility['sql']}
        GROUP BY m.id_thread
    ";

    $rows = $this->db->query($sql, array_merge([$userId, $userId], $visibility['params']))->getResultArray();

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['id_thread']] = (int)$r['unread_count'];
    }
    return $map;
}


    private function getThreadListItem(int $threadId, ?string $titleOverride = null): array
    {
        $sqlThread = "SELECT id_thread, thread_type, group_key, title, created_at FROM dap_chat_thread WHERE id_thread = ? LIMIT 1";
        $row = $this->db->query($sqlThread, [$threadId])->getRowArray();

        if (!$row) {
            return ['id_thread' => $threadId, 'title' => $titleOverride ?? ('Thread #' . $threadId)];
        }

       $me = session()->get('utente_sess');
            $meId = $me ? (int)$me->id_user : 0;

            $sqlLast = "
                SELECT m.id_message, m.body, m.created_at
                FROM dap_chat_message m
                INNER JOIN dap_chat_thread_user tu
                ON tu.id_thread = m.id_thread AND tu.id_user = ?
                WHERE m.id_thread = ?
                AND (tu.cleared_at IS NULL OR m.created_at > tu.cleared_at)
                ORDER BY m.id_message DESC
                LIMIT 1
            ";
            $last = $this->db->query($sqlLast, [$meId, $threadId])->getRowArray();


        return [
            'id_thread'     => (int)$row['id_thread'],
            'title'         => $titleOverride ?? ($row['title'] ?? ('Thread #' . $row['id_thread'])),
            'group_key'     => $row['group_key'] ?? null,
            'thread_type'   => $row['thread_type'] ?? null,
            'created_at'    => $row['created_at'] ?? null,
            'last_id'       => $last ? (int)$last['id_message'] : 0,
            'last_preview'  => $last ? mb_substr((string)$last['body'], 0, 40) : '',
            'last_at'       => $last ? (string)$last['created_at'] : '',
        ];
    }

    public function getThreadInfo(int $threadId): ?array
    {
        $sql = "SELECT id_thread, thread_type, group_key, title, created_at FROM dap_chat_thread WHERE id_thread = ? LIMIT 1";
        $row = $this->db->query($sql, [$threadId])->getRowArray();
        if (!$row) return null;

        $row['id_thread'] = (int)$row['id_thread'];
        return $row;
    }

    public function canAccessThread(int $threadId, int $userId): bool
    {
        $meta = $this->syncDoctorGroupMembersByThreadId($threadId);
        if ($meta !== null && $userId !== (int)$meta['doctor_user_id']) {
            $viewer = $this->getPersonaleByUserId($userId);
            $viewerTipo = $this->resolveChatTipoPers($userId, (int)($viewer['tipo'] ?? 0));

            if ($viewerTipo !== (int)$meta['staff_tipo']) {
                return false;
            }

            if (!$this->staffDoctorAccess->canStaffUserAccessDoctorUser($userId, $viewerTipo, (int)$meta['doctor_user_id'], 'chat')) {
                return false;
            }
        }

        $sql = "SELECT 1 FROM dap_chat_thread_user WHERE id_thread = ? AND id_user = ? LIMIT 1";
        $row = $this->db->query($sql, [$threadId, $userId])->getRowArray();
        return (bool)$row;
    }
private function sendPushToService(int $userId, string $body, ?int $threadId = null, ?int $messageId = null): void
{
    log_message('debug', '[sendPushToService] start user_id={uid} thread_id={tid} message_id={mid}', [
        'uid' => $userId,
        'tid' => (int)($threadId ?? 0),
        'mid' => (int)($messageId ?? 0),
    ]);

    if ($userId <= 0) {
        log_message('error', '[sendPushToService] userId non valido');
        return;
    }

    $url = base_url('chat');
    if ($threadId !== null && $threadId > 0) {
        $url = base_url('chat?thread=' . (int)$threadId);
    }

    $payload = [
        'type'      => 'chat',
        'title'     => 'AmbulatorioFacile',
        'body'      => $body,
        'icon'      => base_url('notifications/icon.svg'),
        'badge'     => base_url('notifications/badge.svg'),
        'sticky'    => true,
        'messageId' => (int)($messageId ?? 0),
        'tag'       => 'chat-msg-' . (int)($messageId ?? time()),
        'data'      => [
            'url'       => $url,
            'threadId'  => (int)($threadId ?? 0),
            'messageId' => (int)($messageId ?? 0),
        ],
    ];

    log_message('debug', '[sendPushToService] sending user_id={uid} payload={payload}', [
        'uid'     => $userId,
        'payload' => json_encode($payload),
    ]);

    $result = service('push')->sendToUser($userId, $payload);

    log_message('debug', '[sendPushToService] result user_id={uid} result={result}', [
        'uid'    => $userId,
        'result' => json_encode($result),
    ]);
}
     private function sendPushToClient(int $idClient, string $body, ?int $threadId = null): void
    {
        log_message('debug', '[sendPushToClient] start client_id={cid} thread_id={tid}', [
            'cid' => $idClient,
            'tid' => (int)($threadId ?? 0),
        ]);

        $client = $this->db->query(
            "SELECT id_user FROM dap02_clients WHERE id_client=? LIMIT 1",
            [$idClient]
        )->getRowArray();

        if (!$client) {
            log_message('error', "[sendPushToClient] Cliente {id} non trovato", ['id' => $idClient]);
            return;
        }

        $userId = (int)($client['id_user'] ?? 0);
        if ($userId <= 0) {
            log_message('error', "[sendPushToClient] Nessun userId associato al cliente {id}", ['id' => $idClient]);
            return;
        }

        $url = base_url('chat');
        if ($threadId !== null && $threadId > 0) {
            $url = base_url('chat?thread=' . (int)$threadId);
        }

        $payload = [
            'title'  => 'AmbulatorioFacile',
            'body'   => $body,
            'sticky' => true,
            'data'   => [
                'url' => $url,
            ],
        ];

        

        log_message('debug', '[sendPushToClient] sending user_id={uid} url={url}', [
            'uid' => $userId,
            'url' => $url,
        ]);

        service('push')->sendToUser($userId, $payload);

        log_message('debug', '[sendPushToClient] sent user_id={uid}', ['uid' => $userId]);
    }

    // =========================================================
    // MESSAGES
    // =========================================================
    public function sendMessage(int $threadId, int $senderId, string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }

    $this->syncDoctorGroupMembersByThreadId($threadId);

    $sql = "
        INSERT INTO dap_chat_message (id_thread, sender_id, body, created_at)
        VALUES (?, ?, ?, NOW())
    ";
    $this->db->query($sql, [$threadId, $senderId, $text]);

    $messageId = (int) $this->db->insertID();

    // Recupera tutti gli utenti del thread, escluso il mittente
   log_message('error', 'CHAT PUSH - INIZIO INVIO NOTIFICHE');

log_message('error', 'CHAT PUSH - threadId: ' . $threadId);
log_message('error', 'CHAT PUSH - senderId: ' . $senderId);

$sqlUsers = "
    SELECT id_user
    FROM dap_chat_thread_user
    WHERE id_thread = ?
      AND id_user <> ?
";

log_message('error', 'CHAT PUSH - Query SQL: ' . $sqlUsers);

$query = $this->db->query($sqlUsers, [$threadId, $senderId]);

log_message('error', 'CHAT PUSH - Query eseguita');

$recipients = $query->getResultArray();

log_message('error', 'CHAT PUSH - Numero destinatari trovati: ' . count($recipients));
log_message('error', 'CHAT PUSH - Risultati: ' . json_encode($recipients));

// Testo notifica
$notificationText = 'Hai ricevuto un nuovo messaggio in chat.';

foreach ($recipients as $recipient) {

    $recipientId = (int) ($recipient['id_user'] ?? 0);

    log_message('error', 'CHAT PUSH - Elaboro destinatario: ' . json_encode($recipient));
    log_message('error', 'CHAT PUSH - recipientId: ' . $recipientId);

    if ($recipientId > 0) {

        log_message('error', 'CHAT PUSH - Invio push a userId: ' . $recipientId);

          $this->sendPushToService(
            $recipientId,
            $notificationText,
            $threadId
        );

        log_message('error', 'CHAT PUSH - Push inviata a userId: ' . $recipientId);

    } else {

        log_message('error', 'CHAT PUSH - recipientId non valido');

    }
}

log_message('error', 'CHAT PUSH - FINE INVIO NOTIFICHE');

    return $messageId;
}

    public function getMessages(int $threadId, int $limit = 200): array
{
    $limit = max(1, min(500, (int)$limit));

    $me = session()->get('utente_sess');
    $meId = $me ? (int)$me->id_user : 0;

    $sql = "
    SELECT
        m.id_message,
        m.id_thread,
        m.sender_id,
        m.body,
        m.created_at,
        a.original_name,
        a.stored_name,
        a.mime_type,
        a.file_size,
        " . $this->crypto->decrypt('p.nome') . ",
        " . $this->crypto->decrypt('p.cognome') . "
    FROM dap_chat_message m
    INNER JOIN dap_chat_thread_user tu
        ON tu.id_thread = m.id_thread AND tu.id_user = ?
    LEFT JOIN dap03_personale p
        ON p.id_user = m.sender_id
    LEFT JOIN dap_chat_attachments a
        ON a.id_message = m.id_message
    WHERE m.id_thread = ?
      AND (tu.cleared_at IS NULL OR m.created_at > tu.cleared_at)
    ORDER BY m.id_message ASC
    LIMIT $limit
";

    $rows = $this->db->query($sql, [$meId, $threadId])->getResultArray();

    foreach ($rows as &$r) {
        $r['id_message'] = (int)$r['id_message'];
        $r['id_thread']  = (int)$r['id_thread'];
        $r['sender_id']  = (int)$r['sender_id'];

        $n = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''));
        $r['sender_name'] = $n !== '' ? $n : ('User #' . $r['sender_id']);
    }
    unset($r);

    return $rows;
}


    public function getMessagesAfter(int $threadId, int $afterId, int $limit = 200): array
{
    $limit = max(1, min(500, (int)$limit));
    $afterId = (int)$afterId;

    $me = session()->get('utente_sess');
    $meId = $me ? (int)$me->id_user : 0;

   $sql = "
    SELECT
        m.id_message,
        m.id_thread,
        m.sender_id,
        m.body,
        m.created_at,
        a.original_name,
        a.stored_name,
        a.mime_type,
        a.file_size,
        " . $this->crypto->decrypt('p.nome') . ",
        " . $this->crypto->decrypt('p.cognome') . "
    FROM dap_chat_message m
    INNER JOIN dap_chat_thread_user tu
        ON tu.id_thread = m.id_thread AND tu.id_user = ?
    LEFT JOIN dap03_personale p
        ON p.id_user = m.sender_id
    LEFT JOIN dap_chat_attachments a
        ON a.id_message = m.id_message
    WHERE m.id_thread = ?
      AND m.id_message > ?
      AND (tu.cleared_at IS NULL OR m.created_at > tu.cleared_at)
    ORDER BY m.id_message ASC
    LIMIT $limit
";

    $rows = $this->db->query($sql, [$meId, $threadId, $afterId])->getResultArray();

    foreach ($rows as &$r) {
        $r['id_message'] = (int)$r['id_message'];
        $r['id_thread']  = (int)$r['id_thread'];
        $r['sender_id']  = (int)$r['sender_id'];

        $n = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''));
        $r['sender_name'] = $n !== '' ? $n : ('User #' . $r['sender_id']);
    }
    unset($r);

    return $rows;
}

    /**
 * Ritorna l'id_thread se esiste giÃ  il group thread per quel medico e baseKey, altrimenti 0.
 */
public function findDoctorGroupThread(int $doctorUserId, string $baseKey): int
{
    $groupKey = $baseKey . '_' . $doctorUserId;
    $sql = "SELECT id_thread FROM dap_chat_thread WHERE group_key = ? LIMIT 1";
    $row = $this->db->query($sql, [$groupKey])->getRowArray();
    return $row ? (int)$row['id_thread'] : 0;
}
public function getUnreadCountsForThreads(int $userId, array $threadIds): array
{
    if (empty($threadIds)) return [];

    $placeholders = implode(',', array_fill(0, count($threadIds), '?'));

    // params: userId + threadIds + userId (per sender_id <> me)
    $params = array_merge([$userId], $threadIds, [$userId]);

    $sql = "
        SELECT
            m.id_thread,
            COUNT(*) AS unread_count
        FROM dap_chat_message m
        INNER JOIN dap_chat_thread_user tu
            ON tu.id_thread = m.id_thread
           AND tu.id_user = ?
        WHERE m.id_thread IN ($placeholders)
          AND m.sender_id <> ?
          AND (
                tu.last_read_at IS NULL
                OR m.created_at > tu.last_read_at
              )
        GROUP BY m.id_thread
    ";

    $rows = $this->db->query($sql, $params)->getResultArray();

    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['id_thread']] = (int)$r['unread_count'];
    }
    return $map;
}
public function getUnreadCount(int $threadId, int $userId): int
{
    // Conta i messaggi dopo last_read_at, esclusi quelli inviati da me
    $sql = "
        SELECT COUNT(*) AS c
        FROM dap_chat_message m
        INNER JOIN dap_chat_thread_user tu
            ON tu.id_thread = m.id_thread
           AND tu.id_user = ?
        WHERE m.id_thread = ?
          AND m.sender_id <> ?
          AND (
                tu.last_read_at IS NULL
                OR m.created_at > tu.last_read_at
              )
    ";
    $row = $this->db->query($sql, [$userId, $threadId, $userId])->getRowArray();
    return (int)($row['c'] ?? 0);
}

public function getTotalUnreadForUser(int $userId): int
{
    $visibility = $this->buildVisibleThreadScopeForUser($userId);

    $sql = "
        SELECT COUNT(*) AS c
        FROM dap_chat_message m
        INNER JOIN dap_chat_thread t
            ON t.id_thread = m.id_thread
        INNER JOIN dap_chat_thread_user tu
            ON tu.id_thread = m.id_thread
           AND tu.id_user = ?
        WHERE m.sender_id <> ?
          AND (
                tu.last_read_at IS NULL
                OR m.created_at > tu.last_read_at
              )
          AND (
                tu.cleared_at IS NULL
                OR m.created_at > tu.cleared_at
              )
          {$visibility['sql']}
    ";

    $row = $this->db->query($sql, array_merge([$userId, $userId], $visibility['params']))->getRowArray();
    return (int)($row['c'] ?? 0);
}

public function markThreadRead(int $threadId, int $userId): void
{
    // segna come letto "adesso" per quell'utente in quel thread
    $sql = "UPDATE dap_chat_thread_user
            SET last_read_at = NOW()
            WHERE id_thread = ? AND id_user = ?
            LIMIT 1";
    $this->db->query($sql, [$threadId, $userId]);
}
    public function clearThreadForUser(int $threadId, int $userId): void
{
    $sql = "UPDATE dap_chat_thread_user
            SET cleared_at = NOW()
            WHERE id_thread = ? AND id_user = ?
            LIMIT 1";
    $this->db->query($sql, [$threadId, $userId]);
}
public function clearAllThreadsForUser(int $userId): void
{
    $sql = "UPDATE dap_chat_thread_user
            SET cleared_at = NOW()
            WHERE id_user = ?";
    $this->db->query($sql, [$userId]);
}

private function syncDoctorGroupMembersForStaffScope(string $baseKey, array $doctorUserIds): void
{
    $doctorUserIds = array_values(array_unique(array_filter(array_map('intval', $doctorUserIds), static fn(int $id): bool => $id > 0)));
    if (empty($doctorUserIds)) {
        return;
    }

    $staffTipo = $baseKey === 'segreteria' ? self::TIPO_SEGRETERIA : self::TIPO_INFERMIERE;
    $like = $baseKey . '\_%';
    $doctorPlaceholders = implode(',', array_fill(0, count($doctorUserIds), '?'));
    $sql = "
        SELECT
            t.id_thread,
            CAST(SUBSTRING_INDEX(t.group_key, '_', -1) AS UNSIGNED) AS doctor_user_id
        FROM dap_chat_thread t
        WHERE t.thread_type = 'group'
          AND t.group_key LIKE ? ESCAPE '\\\\'
          AND CAST(SUBSTRING_INDEX(t.group_key, '_', -1) AS UNSIGNED) IN ($doctorPlaceholders)
    ";

    $params = array_merge([$like], $doctorUserIds);
    $rows = $this->db->query($sql, $params)->getResultArray();

    foreach ($rows as $row) {
        $this->ensureDoctorGroupMembers(
            (int)($row['id_thread'] ?? 0),
            (int)($row['doctor_user_id'] ?? 0),
            $staffTipo
        );
    }
}

/**
 * Lista thread di gruppo NON VUOTI per segreteria/infermiere:
 * - prende solo thread dove l'utente Ã¨ membro
 * - esclude i thread senza messaggi (join sull'ultimo messaggio)
 * - ricava il medico da group_key (es. segreteria_35 -> doctor_user_id = 35)
 */
private function getNonEmptyStaffThreads(int $meUserId, string $baseKey, string $label): array
{
    $staffTipo = $baseKey === 'segreteria' ? self::TIPO_SEGRETERIA : self::TIPO_INFERMIERE;
    $doctorUserIds = $this->staffDoctorAccess->getDoctorUserIdsForStaffUser($meUserId, $staffTipo, 'chat');
    if (empty($doctorUserIds)) {
        return [];
    }

    $this->syncDoctorGroupMembersForStaffScope($baseKey, $doctorUserIds);

    $like = $baseKey . '\_%'; // underscore va escapato nel LIKE
    $doctorPlaceholders = implode(',', array_fill(0, count($doctorUserIds), '?'));
    $sql = "
        SELECT
            t.id_thread,
            t.thread_type,
            t.group_key,
            t.title,
            lm.id_message AS last_id,
            LEFT(lm.body, 40) AS last_preview,
            lm.created_at AS last_at,
            CAST(SUBSTRING_INDEX(t.group_key, '_', -1) AS UNSIGNED) AS doctor_user_id,
            " . $this->crypto->decrypt('p.nome') . ",
            " . $this->crypto->decrypt('p.cognome') . "
        FROM dap_chat_thread t
        INNER JOIN dap_chat_thread_user tu
            ON tu.id_thread = t.id_thread AND tu.id_user = ?
        /* ultimo messaggio: se non esiste => thread vuoto => non entra */
        INNER JOIN dap_chat_message lm
    ON lm.id_message = (
        SELECT m2.id_message
        FROM dap_chat_message m2
        WHERE m2.id_thread = t.id_thread
          AND (tu.cleared_at IS NULL OR m2.created_at > tu.cleared_at)
        ORDER BY m2.id_message DESC
        LIMIT 1
    )
        LEFT JOIN dap03_personale p
            ON p.id_user = CAST(SUBSTRING_INDEX(t.group_key, '_', -1) AS UNSIGNED)
        WHERE t.thread_type = 'group'
          AND t.group_key LIKE ? ESCAPE '\\\\'
          AND CAST(SUBSTRING_INDEX(t.group_key, '_', -1) AS UNSIGNED) IN ($doctorPlaceholders)
        ORDER BY lm.id_message DESC
    ";

    $params = array_merge([$meUserId, $like], $doctorUserIds);
    $rows = $this->db->query($sql, $params)->getResultArray();

    foreach ($rows as &$r) {
        $r['id_thread'] = (int)$r['id_thread'];
        $docName = trim(($r['nome'] ?? '') . ' ' . ($r['cognome'] ?? ''));
        if ($docName === '') $docName = 'Medico #' . (int)$r['doctor_user_id'];

        // sovrascrivo title per render in lista
        $r['title'] = $label . ' â€¢ ' . $docName;

        // normalizzo preview
        $r['last_preview'] = (string)($r['last_preview'] ?? '');
        $r['last_at'] = (string)($r['last_at'] ?? '');
    }
    unset($r);

    return $rows;
}

private function buildVisibleThreadScopeForUser(int $userId): array
{
    $viewer = $this->getPersonaleByUserId($userId);
    $viewerTipo = $this->resolveChatTipoPers($userId, (int)($viewer['tipo'] ?? 0));

    if (!in_array($viewerTipo, [self::TIPO_SEGRETERIA, self::TIPO_INFERMIERE], true)) {
        return ['sql' => '', 'params' => []];
    }

    $doctorUserIds = $this->staffDoctorAccess->getDoctorUserIdsForStaffUser($userId, $viewerTipo, 'chat');
    if (empty($doctorUserIds)) {
        return ['sql' => ' AND 1=0 ', 'params' => []];
    }

    $baseKey = $viewerTipo === self::TIPO_SEGRETERIA ? 'segreteria' : 'infermieri';
    $like = $baseKey . '\_%';
    $placeholders = implode(',', array_fill(0, count($doctorUserIds), '?'));

    return [
        'sql' => "
          AND t.thread_type = 'group'
          AND t.group_key LIKE ? ESCAPE '\\\\'
          AND CAST(SUBSTRING_INDEX(t.group_key, '_', -1) AS UNSIGNED) IN ({$placeholders})
        ",
        'params' => array_merge([$like], $doctorUserIds),
    ];
}

}
