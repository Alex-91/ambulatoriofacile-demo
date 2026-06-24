<?php

namespace App\Models;

use CodeIgniter\Model;

class AgendaLockModel extends Model
{
    private const LOCK_TTL_SECONDS = 120;

    protected $db;
    private ?bool $hasAppointmentSlotLinkTable = null;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    public function lockSlot(int $idSlot, int $idOpe): array
    {
        if ($idSlot <= 0 || $idOpe <= 0) {
            return [
                'status'  => false,
                'message' => 'Parametri lock non validi.'
            ];
        }

        $now = date('Y-m-d H:i:s');
        $exp = date('Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS);

        $this->cleanupExpiredLocks();

        $slot = $this->db->table('dap11_agenda_slot')
            ->select('id_slot, id_dot, data_slot, stato')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        if (!$slot) {
            return [
                'status'  => false,
                'message' => 'Slot non trovato.'
            ];
        }

        $statoSlot = strtoupper(trim((string)($slot['stato'] ?? '')));
        if ($statoSlot === 'PRENOTATO') {
            return [
                'status'  => false,
                'message' => 'Lo slot risulta gia prenotato.'
            ];
        }

        if ($statoSlot === 'CHIUSO') {
            return [
                'status'  => false,
                'message' => 'La giornata risulta bloccata.'
            ];
        }

        $hasAppointment = $this->hasNonCancelledAppointmentForSlot($idSlot);

        if ($hasAppointment) {
            $this->restoreUnlockedSlotState($idSlot, $now, true);

            return [
                'status'  => false,
                'message' => 'Lo slot risulta gia prenotato.'
            ];
        }

        $existing = $this->db->table('dap14_agenda_lock')
            ->where('id_slot', $idSlot)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', $now)
            ->get()
            ->getRowArray();

        if ($existing) {
            return [
                'status'  => false,
                'message' => 'Slot attualmente in modifica da un altro operatore.'
            ];
        }

        $token = bin2hex(random_bytes(32));

        $this->db->transStart();

        $this->db->table('dap14_agenda_lock')->insert([
            'id_slot'    => $idSlot,
            'id_ope'     => $idOpe,
            'token_lock' => $token,
            'locked_at'  => $now,
            'expires_at' => $exp,
            'stato'      => 'ATTIVO'
        ]);

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->update([
                'stato'      => 'BLOCCATO',
                'updated_at' => $now
            ]);

        $this->db->transComplete();

        return [
            'status'     => true,
            'message'    => 'Lock acquisito.',
            'token_lock' => $token,
            'expires_at' => $exp
        ];
    }

    public function refreshLock(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [
                'status'  => false,
                'message' => 'Lock non disponibile.'
            ];
        }

        $this->cleanupExpiredLocks();

        $now = date('Y-m-d H:i:s');
        $exp = date('Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS);

        $row = $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $token)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', $now)
            ->get()
            ->getRowArray();

        if (!$row) {
            return [
                'status'  => false,
                'message' => 'Lock non più disponibile.'
            ];
        }

        $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $token)
            ->update([
                'expires_at' => $exp
            ]);

        return [
            'status'     => true,
            'expires_at' => $exp
        ];
    }

    public function unlockSlot(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [
                'status' => true
            ];
        }

        $this->cleanupExpiredLocks();

        $row = $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $token)
            ->where('stato', 'ATTIVO')
            ->get()
            ->getRowArray();

        if (!$row) {
            return [
                'status' => true
            ];
        }

        $this->db->transStart();

        $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $token)
            ->update([
                'stato' => 'RILASCIATO'
            ]);

        $this->restoreUnlockedSlotState((int)$row['id_slot'], date('Y-m-d H:i:s'), true);

        $this->db->transComplete();

        return [
            'status' => true
        ];
    }

    public function cleanupExpiredLocks(): int
    {
        $now = date('Y-m-d H:i:s');

        $expiredRows = $this->db->table('dap14_agenda_lock')
            ->select('id_slot')
            ->where('stato', 'ATTIVO')
            ->where('expires_at <', $now)
            ->get()
            ->getResultArray();

        $orphanRows = [];
        if (!empty($expiredRows) || $this->hasBlockedSlotCandidates()) {
            $orphanRows = $this->db->table('dap11_agenda_slot s')
                ->select('s.id_slot')
                ->where('s.stato', 'BLOCCATO')
                ->where(
                    "NOT EXISTS (
                        SELECT 1
                        FROM dap14_agenda_lock l
                        WHERE l.id_slot = s.id_slot
                          AND l.stato = 'ATTIVO'
                          AND l.expires_at >= " . $this->db->escape($now) . "
                    )",
                    null,
                    false
                )
                ->get()
                ->getResultArray();
        }

        if (empty($expiredRows) && empty($orphanRows)) {
            return 0;
        }

        $slotIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id_slot'] ?? 0),
            array_merge($expiredRows, $orphanRows)
        ))));

        $this->db->transStart();

        if (!empty($expiredRows)) {
            $this->db->table('dap14_agenda_lock')
                ->where('stato', 'ATTIVO')
                ->where('expires_at <', $now)
                ->update([
                    'stato' => 'SCADUTO'
                ]);
        }

        foreach ($slotIds as $slotId) {
            $this->restoreUnlockedSlotState($slotId, $now, true);
        }

        $this->db->transComplete();

        return count($slotIds);
    }

    private function hasNonCancelledAppointmentForSlot(int $idSlot): bool
    {
        if ($idSlot <= 0) {
            return false;
        }

        if ($this->appointmentSlotLinkTableExists()) {
            return $this->db->query(
                "
                SELECT a.id_appuntamento
                FROM dap12_agenda_appuntamenti a
                WHERE a.stato <> 'ANNULLATO'
                  AND (
                        a.id_slot = ?
                        OR EXISTS (
                            SELECT 1
                            FROM dap45_agenda_appuntamenti_slot rel
                            WHERE rel.id_appuntamento = a.id_appuntamento
                              AND rel.id_slot = ?
                        )
                  )
                LIMIT 1
                ",
                [$idSlot, $idSlot]
            )->getRowArray() !== null;
        }

        return $this->db->table('dap12_agenda_appuntamenti')
            ->select('id_appuntamento')
            ->where('id_slot', $idSlot)
            ->where('stato <>', 'ANNULLATO')
            ->get(1)
            ->getRowArray() !== null;
    }

    private function hasBlockedSlotCandidates(): bool
    {
        return $this->db->table('dap11_agenda_slot')
            ->select('id_slot')
            ->where('stato', 'BLOCCATO')
            ->get(1)
            ->getRowArray() !== null;
    }

    public function getBlockedSlotsReport(array $doctorIds, ?int $selectedDot = null): array
    {
        $doctorIds = array_values(array_unique(array_filter(array_map('intval', $doctorIds))));
        if (empty($doctorIds)) {
            return [];
        }

        $this->cleanupExpiredLocks();

        $now = date('Y-m-d H:i:s');
        $nowEscaped = $this->db->escape($now);

        $builder = $this->db->table('dap11_agenda_slot s')
            ->select("
                s.id_slot,
                s.id_dot,
                s.data_slot,
                s.ora_inizio,
                s.ora_fine,
                s.stato,
                (
                    SELECT COUNT(*)
                    FROM dap12_agenda_appuntamenti a
                    WHERE a.id_slot = s.id_slot
                      AND a.stato <> 'ANNULLATO'
                ) AS appuntamenti_attivi,
                (
                    SELECT l.id_lock
                    FROM dap14_agenda_lock l
                    WHERE l.id_slot = s.id_slot
                      AND l.stato = 'ATTIVO'
                      AND l.expires_at >= {$nowEscaped}
                    ORDER BY l.expires_at DESC, l.id_lock DESC
                    LIMIT 1
                ) AS id_lock_attivo,
                (
                    SELECT l.id_ope
                    FROM dap14_agenda_lock l
                    WHERE l.id_slot = s.id_slot
                      AND l.stato = 'ATTIVO'
                      AND l.expires_at >= {$nowEscaped}
                    ORDER BY l.expires_at DESC, l.id_lock DESC
                    LIMIT 1
                ) AS id_ope_lock,
                (
                    SELECT l.locked_at
                    FROM dap14_agenda_lock l
                    WHERE l.id_slot = s.id_slot
                      AND l.stato = 'ATTIVO'
                      AND l.expires_at >= {$nowEscaped}
                    ORDER BY l.expires_at DESC, l.id_lock DESC
                    LIMIT 1
                ) AS locked_at,
                (
                    SELECT l.expires_at
                    FROM dap14_agenda_lock l
                    WHERE l.id_slot = s.id_slot
                      AND l.stato = 'ATTIVO'
                      AND l.expires_at >= {$nowEscaped}
                    ORDER BY l.expires_at DESC, l.id_lock DESC
                    LIMIT 1
                ) AS expires_at,
                (
                    SELECT l.token_lock
                    FROM dap14_agenda_lock l
                    WHERE l.id_slot = s.id_slot
                      AND l.stato = 'ATTIVO'
                      AND l.expires_at >= {$nowEscaped}
                    ORDER BY l.expires_at DESC, l.id_lock DESC
                    LIMIT 1
                ) AS token_lock
            ", false)
            ->whereIn('s.id_dot', $doctorIds)
            ->groupStart()
                ->where('s.stato', 'BLOCCATO')
                ->orWhere(
                    "EXISTS (
                        SELECT 1
                        FROM dap14_agenda_lock la
                        WHERE la.id_slot = s.id_slot
                          AND la.stato = 'ATTIVO'
                          AND la.expires_at >= {$nowEscaped}
                    )",
                    null,
                    false
                )
            ->groupEnd()
            ->orderBy('s.data_slot', 'ASC')
            ->orderBy('s.ora_inizio', 'ASC')
            ->orderBy('s.id_dot', 'ASC');

        if ($selectedDot !== null && $selectedDot > 0) {
            $builder->where('s.id_dot', $selectedDot);
        }

        $rows = $builder->get()->getResultArray();

        return array_map(static function (array $row): array {
            $row['id_slot'] = (int)($row['id_slot'] ?? 0);
            $row['id_dot'] = (int)($row['id_dot'] ?? 0);
            $row['appuntamenti_attivi'] = (int)($row['appuntamenti_attivi'] ?? 0);
            $row['id_lock_attivo'] = (int)($row['id_lock_attivo'] ?? 0);
            $row['id_ope_lock'] = (int)($row['id_ope_lock'] ?? 0);
            return $row;
        }, $rows);
    }

    public function forceUnlockSlot(int $idSlot): array
    {
        if ($idSlot <= 0) {
            return [
                'status'  => false,
                'message' => 'Slot non valido.'
            ];
        }

        $this->cleanupExpiredLocks();

        $slot = $this->db->table('dap11_agenda_slot')
            ->select('id_slot, id_dot, data_slot, stato')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        if (!$slot) {
            return [
                'status'  => false,
                'message' => 'Slot non trovato.'
            ];
        }

        $this->db->transStart();

        $this->db->table('dap14_agenda_lock')
            ->where('id_slot', $idSlot)
            ->where('stato', 'ATTIVO')
            ->update([
                'stato' => 'RILASCIATO'
            ]);

        $newState = $this->restoreUnlockedSlotState($idSlot, date('Y-m-d H:i:s'), true);

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            return [
                'status'  => false,
                'message' => 'Errore durante lo sblocco dello slot.'
            ];
        }

        return [
            'status'      => true,
            'message'     => 'Slot sbloccato correttamente.',
            'slot_state'  => $newState,
            'id_dot'      => (int)($slot['id_dot'] ?? 0),
            'data_slot'   => (string)($slot['data_slot'] ?? ''),
        ];
    }

    private function restoreUnlockedSlotState(int $idSlot, string $timestamp, bool $forceUpdate = false): string
    {
        $slot = $this->db->table('dap11_agenda_slot')
            ->select('id_slot, id_dot, data_slot, stato')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        if (!$slot) {
            return '';
        }

        $hasActiveLock = $this->db->table('dap14_agenda_lock')
            ->where('id_slot', $idSlot)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', $timestamp)
            ->countAllResults() > 0;

        if ($hasActiveLock) {
            return (string)($slot['stato'] ?? '');
        }

        $hasAppointment = $this->db->table('dap12_agenda_appuntamenti')
            ->where('id_slot', $idSlot)
            ->where('stato <>', 'ANNULLATO')
            ->countAllResults() > 0;

        if ($this->appointmentSlotLinkTableExists()) {
            $hasAppointment = $this->db->query(
                "
                SELECT a.id_appuntamento
                FROM dap12_agenda_appuntamenti a
                WHERE a.stato <> 'ANNULLATO'
                  AND (
                        a.id_slot = ?
                        OR EXISTS (
                            SELECT 1
                            FROM dap45_agenda_appuntamenti_slot rel
                            WHERE rel.id_appuntamento = a.id_appuntamento
                              AND rel.id_slot = ?
                        )
                  )
                LIMIT 1
                ",
                [$idSlot, $idSlot]
            )->getRowArray() !== null;
        }

        $isDayBlocked = $this->db->table('dap21_agenda_giorni_bloccati')
            ->where('id_dot', (int)($slot['id_dot'] ?? 0))
            ->where('data_agenda', (string)($slot['data_slot'] ?? ''))
            ->countAllResults() > 0;

        $targetState = $hasAppointment
            ? 'PRENOTATO'
            : ($isDayBlocked ? 'CHIUSO' : 'LIBERO');

        $currentState = strtoupper(trim((string)($slot['stato'] ?? '')));

        if ($forceUpdate || $currentState !== $targetState) {
            $this->db->table('dap11_agenda_slot')
                ->where('id_slot', $idSlot)
                ->update([
                    'stato'      => $targetState,
                    'updated_at' => $timestamp
                ]);
        }

        return $targetState;
    }

    private function appointmentSlotLinkTableExists(): bool
    {
        if ($this->hasAppointmentSlotLinkTable === null) {
            $this->hasAppointmentSlotLinkTable = $this->db->tableExists('dap45_agenda_appuntamenti_slot');
        }

        return $this->hasAppointmentSlotLinkTable;
    }
}
