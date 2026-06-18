<?php

namespace App\Models;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use CodeIgniter\Model;
use Config\Database;

class PrenotazioniDataModel extends Model
{
    private const USERS_TABLE = 'dap01_users';
    private const CLIENTS_TABLE = 'dap02_clients';
    private const PERSONALE_TABLE = 'dap03_personale';
    private const CLIENT_DOCTOR_TABLE = 'dap09_client_doctor';
    private const SLOT_TABLE = 'dap11_agenda_slot';
    private const APPOINTMENTS_TABLE = 'dap12_agenda_appuntamenti';
    private const BLOCKED_DAYS_TABLE = 'dap21_agenda_giorni_bloccati';
    private const HOLIDAYS_TABLE = 'dap48_gio_ros';
    private const DOCTOR_SPEC_TABLE = 'dap49_dot_spec';
    private const SPEC_TABLE = 'dap41_spec';

    private Crypto_helper $crypto;
    private array $legacyDoctorCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->crypto = new Crypto_helper();
    }

    private function connectBookingDb()
    {
        $db = Database::connect(null, false);
        $cfg = new DatabaseConfig();
        $cfg->setEncryptionConfig($db);
        return $db;
    }

    private function cleanupAgendaLocks(): void
    {
        (new AgendaLockModel())->cleanupExpiredLocks();
    }

    public function getPazienteIdByCodFis(string $codFis): int
    {
        $codFis = $this->normalizeFiscalCode($codFis);
        if ($codFis === '') {
            return 0;
        }

        $db = $this->connectBookingDb();
        try {
            $cfExpr = $this->decryptExpr('c.codice_fiscale', 'c');
            $row = $db->query(
                "
                SELECT c.id_client
                FROM " . self::CLIENTS_TABLE . " c
                LEFT JOIN " . self::USERS_TABLE . " u ON u.id_user = c.id_user
                WHERE UPPER(REPLACE(TRIM(COALESCE(u.username, '')), ' ', '')) = ?
                   OR UPPER(REPLACE(TRIM(COALESCE({$cfExpr}, '')), ' ', '')) = ?
                ORDER BY CASE WHEN c.id_user IS NOT NULL AND c.id_user > 0 THEN 0 ELSE 1 END, c.id_client ASC
                LIMIT 1
                ",
                [$codFis, $codFis]
            )->getRowArray();

            return (int)($row['id_client'] ?? 0);
        } finally {
            $db->close();
        }
    }

    public function getDoctorIdByUsername(string $doctorUsername): int
    {
        $doctorUsername = trim($doctorUsername);
        if ($doctorUsername === '') {
            return 0;
        }

        $db = $this->connectBookingDb();
        try {
            $row = $db->query(
                "
                SELECT COALESCE(p.legacy_id_dot, 0) AS legacy_id_dot
                FROM " . self::PERSONALE_TABLE . " p
                INNER JOIN " . self::USERS_TABLE . " u
                    ON u.id_user = p.id_user
                WHERE u.username = ?
                ORDER BY p.id_personale ASC
                LIMIT 1
                ",
                [$doctorUsername]
            )->getRowArray();

            return (int)($row['legacy_id_dot'] ?? 0);
        } finally {
            $db->close();
        }
    }

    public function getExistingFutureBooking(int $idPaziente): ?array
    {
        if ($idPaziente <= 0) {
            return null;
        }

        $db = $this->connectBookingDb();
        try {
            $qualificaExpr = $this->decryptExpr('d.qualifica', 'd');
            $nomeExpr = $this->decryptExpr('d.nome', 'd');
            $cognomeExpr = $this->decryptExpr('d.cognome', 'd');

            $sql = "
                SELECT
                    a.id_appuntamento AS id_prenotazione,
                    a.id_appuntamento,
                    s.ora_inizio AS data_ora_ini,
                    s.ora_fine AS data_ora_fin,
                    {$qualificaExpr} AS titolo,
                    {$nomeExpr} AS nome_med,
                    {$cognomeExpr} AS cognome_med,
                    (
                        SELECT sp.titolo
                        FROM " . self::DOCTOR_SPEC_TABLE . " ds
                        INNER JOIN " . self::SPEC_TABLE . " sp
                            ON sp.id_spec = ds.id_spec
                        WHERE ds.id_dot = d.legacy_id_dot
                        ORDER BY ds.id_dot_spec ASC
                        LIMIT 1
                    ) AS specializzazione
                FROM " . self::APPOINTMENTS_TABLE . " a
                INNER JOIN " . self::SLOT_TABLE . " s
                    ON s.id_slot = a.id_slot
                INNER JOIN " . self::PERSONALE_TABLE . " d
                    ON d.legacy_id_dot = a.id_dot
                WHERE a.stato <> 'ANNULLATO'
                  AND (
                        a.id_client = ?
                     OR a.id_paziente = ?
                  )
                  AND s.ora_inizio >= NOW()
                ORDER BY s.ora_inizio ASC, a.id_appuntamento ASC
                LIMIT 1
            ";

            $row = $db->query($sql, [$idPaziente, $idPaziente])->getRowArray();
            return $row ?: null;
        } catch (\Throwable $e) {
            log_message(
                'error',
                '[PrenotazioniDataModel::getExistingFutureBooking] EXCEPTION ' . $e->getMessage() .
                ' in ' . $e->getFile() . ':' . $e->getLine()
            );
            return null;
        } finally {
            $db->close();
        }
    }

    public function resolveDoctorIdFromMainDoctorUserId(int $doctorUserId): int
    {
        if ($doctorUserId <= 0) {
            return 0;
        }

        $db = $this->connectBookingDb();
        try {
            $row = $db->query(
                "
                SELECT COALESCE(legacy_id_dot, 0) AS legacy_id_dot
                FROM " . self::PERSONALE_TABLE . "
                WHERE id_personale = ?
                   OR id_user = ?
                ORDER BY CASE WHEN id_personale = ? THEN 0 ELSE 1 END, id_personale ASC
                LIMIT 1
                ",
                [$doctorUserId, $doctorUserId, $doctorUserId]
            )->getRowArray();

            return (int)($row['legacy_id_dot'] ?? 0);
        } finally {
            $db->close();
        }
    }

    public function deleteBookingByPrenotazioneId(int $idPrenotazione): array
    {
        $db = $this->connectBookingDb();

        try {
            if ($idPrenotazione <= 0) {
                return ['ok' => false, 'err' => 'invalid_params'];
            }

            $db->transBegin();

            $row = $db->query(
                "
                SELECT id_slot, stato
                FROM " . self::APPOINTMENTS_TABLE . "
                WHERE id_appuntamento = ?
                LIMIT 1
                FOR UPDATE
                ",
                [$idPrenotazione]
            )->getRowArray();

            if (!$row) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'not_found'];
            }

            $idSlot = (int)($row['id_slot'] ?? 0);

            $db->table(self::APPOINTMENTS_TABLE)
                ->where('id_appuntamento', $idPrenotazione)
                ->update(['stato' => 'ANNULLATO']);

            if ($idSlot > 0) {
                $hasOtherActive = $db->table(self::APPOINTMENTS_TABLE)
                    ->where('id_slot', $idSlot)
                    ->where('stato <>', 'ANNULLATO')
                    ->countAllResults();

                if ($hasOtherActive === 0) {
                    $db->table(self::SLOT_TABLE)
                        ->where('id_slot', $idSlot)
                        ->update([
                            'stato' => 'LIBERO',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }
            }

            if (!$db->transStatus()) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'update_failed'];
            }

            $db->transCommit();

            return [
                'ok' => true,
                'id_prenotazione' => $idPrenotazione,
                'id_appuntamento' => $idPrenotazione,
            ];
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniDataModel::deleteBookingByPrenotazioneId] EXCEPTION ' . $e->getMessage());
            if ($db->transStatus()) {
                $db->transRollback();
            }
            return ['ok' => false, 'err' => 'exception'];
        } finally {
            $db->close();
        }
    }

    public function getPazienteIdByTriadeAndDot(string $nome, string $cognome, string $cellulare, int $idDotPrenotazioni): int
    {
        $nome = trim($nome);
        $cognome = trim($cognome);
        $cellulare = trim($cellulare);
        if ($nome === '' || $cognome === '' || $cellulare === '' || $idDotPrenotazioni <= 0) {
            return 0;
        }

        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDotPrenotazioni);
        if ($idPersonale <= 0) {
            return 0;
        }

        $db = $this->connectBookingDb();
        try {
            $sql = "
                SELECT c.id_client
                FROM " . self::CLIENTS_TABLE . " c
                LEFT JOIN " . self::CLIENT_DOCTOR_TABLE . " cd
                    ON cd.id_client = c.id_client
                WHERE LOWER(COALESCE({$this->decryptExpr('c.nome', 'c')}, '')) = ?
                  AND LOWER(COALESCE({$this->decryptExpr('c.cognome', 'c')}, '')) = ?
                  AND LOWER(COALESCE({$this->decryptExpr('c.cellulare', 'c')}, '')) = ?
                  AND (
                        c.id_personale = ?
                     OR cd.id_dot = ?
                  )
                ORDER BY c.id_client ASC
                LIMIT 1
            ";

            $row = $db->query($sql, [
                mb_strtolower($nome),
                mb_strtolower($cognome),
                mb_strtolower($cellulare),
                $idPersonale,
                $idPersonale,
            ])->getRowArray();

            return (int)($row['id_client'] ?? 0);
        } finally {
            $db->close();
        }
    }

    public function getAvailableSlots(int $idMedico, string $fromDateTime, int $limit = 10): array
    {
        $this->cleanupAgendaLocks();

        $db = $this->connectBookingDb();

        try {
            $fromDateTimeNorm = date('Y-m-d H:i:s', strtotime($fromDateTime));

            $sql = "
                SELECT
                    s.id_slot AS id_appuntamento,
                    s.ora_inizio AS data_ora_ini,
                    s.ora_fine AS data_ora_fin
                FROM " . self::SLOT_TABLE . " s
                LEFT JOIN " . self::APPOINTMENTS_TABLE . " a
                    ON a.id_slot = s.id_slot
                   AND a.stato <> 'ANNULLATO'
                WHERE s.id_dot = ?
                  AND s.ora_inizio >= ?
                  AND s.stato = 'LIBERO'
                  AND a.id_appuntamento IS NULL
                ORDER BY s.ora_inizio ASC
                LIMIT " . (int)$limit;

            return $db->query($sql, [$idMedico, $fromDateTimeNorm])->getResultArray();
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniDataModel::getAvailableSlots] EXCEPTION ' . $e->getMessage());
            return [];
        } finally {
            $db->close();
        }
    }

    public function getFirstAvailableSlotsAuto(
        int $idDot,
        int $limit = 10,
        int $maxDaysScan = 120,
        bool $debug = true,
        ?string $fromYmd = null
    ): array {
        $this->cleanupAgendaLocks();

        $db = $this->connectBookingDb();

        try {
            $todayYmd = date('Y-m-d');
            $startDay = $fromYmd ?: $todayYmd;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDay)) {
                $startDay = $todayYmd;
            }
            if ($startDay < $todayYmd) {
                $startDay = $todayYmd;
            }

            $endDay = date('Y-m-d', strtotime($startDay . ' +' . max(1, $maxDaysScan) . ' days'));
            $fromDateTime = ($startDay === $todayYmd) ? date('Y-m-d H:i:s') : ($startDay . ' 00:00:00');

            $sql = "
                SELECT
                    s.ora_inizio AS data_ora_ini,
                    DATE(s.ora_inizio) AS giorno,
                    DATE_FORMAT(s.ora_inizio, '%H:%i') AS ora
                FROM " . self::SLOT_TABLE . " s
                LEFT JOIN " . self::APPOINTMENTS_TABLE . " a
                    ON a.id_slot = s.id_slot
                   AND a.stato <> 'ANNULLATO'
                LEFT JOIN " . self::BLOCKED_DAYS_TABLE . " gb
                    ON gb.id_dot = s.id_dot
                   AND gb.data_agenda = s.data_slot
                WHERE s.id_dot = ?
                  AND s.data_slot >= ?
                  AND s.data_slot <= ?
                  AND s.ora_inizio >= ?
                  AND s.stato = 'LIBERO'
                  AND a.id_appuntamento IS NULL
                  AND gb.id_dot IS NULL
                  AND DAYOFWEEK(s.data_slot) <> 1
                  AND NOT EXISTS (
                        SELECT 1
                        FROM " . self::HOLIDAYS_TABLE . " gr
                        WHERE gr.gio_ros IN (
                            DATE_FORMAT(s.data_slot, '%d/%m'),
                            DATE_FORMAT(s.data_slot, '%d/%m/%Y'),
                            CONCAT(DAY(s.data_slot), '/', MONTH(s.data_slot)),
                            CONCAT(DAY(s.data_slot), '/', MONTH(s.data_slot), '/', YEAR(s.data_slot))
                        )
                  )
                ORDER BY s.ora_inizio ASC
                LIMIT " . (int)$limit;

            $rows = $db->query($sql, [$idDot, $startDay, $endDay, $fromDateTime])->getResultArray();

            if ($debug) {
                log_message('error', '[PrenotazioniDataModel::getFirstAvailableSlotsAuto] RESULT rows=' . count($rows));
            }

            return $rows ?? [];
        } catch (\Throwable $e) {
            log_message(
                'error',
                '[PrenotazioniDataModel::getFirstAvailableSlotsAuto] EXCEPTION ' .
                $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            );
            return [];
        } finally {
            $db->close();
        }
    }

    public function getOrCreatePazienteId(string $codFis, string $nome, string $cognome, string $cellulare, int $idDot): int
    {
        $idClient = $this->getPazienteIdByCodFis($codFis);
        if ($idClient > 0) {
            return $idClient;
        }

        if ($nome !== '' && $cognome !== '' && $cellulare !== '') {
            $idClient = $this->getPazienteIdByTriadeAndDot($nome, $cognome, $cellulare, $idDot);
            if ($idClient > 0) {
                return $idClient;
            }
        }

        if ($nome === '' || $cognome === '') {
            return 0;
        }

        $payload = [
            'cognome' => $cognome,
            'nome' => $nome,
            'cellulare' => $cellulare,
            'cod_fis' => $codFis,
        ];

        $pazientiModel = new PazientiModel();
        return $pazientiModel->savePatientAndLink($payload, $idDot);
    }

    public function bookSlot(int $idMedico, int $idPaziente, string $slotIni, ?string $note = null, ?int $idOpe = null): array
    {
        $this->cleanupAgendaLocks();

        $db = $this->connectBookingDb();

        try {
            $db->transBegin();

            $slot = $db->table(self::SLOT_TABLE)
                ->select('id_slot, id_dot, ora_inizio, ora_fine, stato')
                ->where('id_dot', $idMedico)
                ->where('ora_inizio', $slotIni)
                ->get()
                ->getRowArray();

            if (!$slot) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'slot_non_trovato'];
            }

            $idSlot = (int)($slot['id_slot'] ?? 0);
            if ($idSlot <= 0 || ($slot['stato'] ?? '') !== 'LIBERO') {
                $db->transRollback();
                return ['ok' => false, 'err' => 'slot_occupato'];
            }

            $existing = $db->table(self::APPOINTMENTS_TABLE)
                ->where('id_slot', $idSlot)
                ->where('stato <>', 'ANNULLATO')
                ->get()
                ->getRowArray();

            if ($existing) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'slot_occupato'];
            }

            $client = $this->getClientSnapshotById($db, $idPaziente);
            if (!$client) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'paziente_non_trovato'];
            }

            $insert = [
                'id_slot' => $idSlot,
                'id_dot' => $idMedico,
                'id_paziente' => $idPaziente,
                'id_client' => $idPaziente,
                'cognome' => (string)($client['cognome'] ?? ''),
                'nome' => (string)($client['nome'] ?? ''),
                'telefono' => (string)($client['telefono'] ?? ''),
                'cellulare' => (string)($client['cellulare'] ?? ''),
                'email' => (string)($client['email'] ?? ''),
                'note' => $note,
                'motivo_visita' => null,
                'indirizzo_visita' => null,
                'comune_visita' => null,
                'stato' => 'CONFERMATO',
                'created_by' => $idOpe,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            $db->table(self::APPOINTMENTS_TABLE)->insert($insert);
            $idApp = (int)$db->insertID();
            if ($idApp <= 0) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'insert_dap12_failed'];
            }

            $db->table(self::SLOT_TABLE)
                ->where('id_slot', $idSlot)
                ->update([
                    'stato' => 'PRENOTATO',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if (!$db->transStatus()) {
                $db->transRollback();
                return ['ok' => false, 'err' => 'update_slot_failed'];
            }

            $db->transCommit();

            return [
                'ok' => true,
                'id_appuntamento' => $idApp,
                'id_prenotazione' => $idApp,
                'data_ora_ini' => (string)($slot['ora_inizio'] ?? $slotIni),
                'data_ora_fin' => (string)($slot['ora_fine'] ?? ''),
            ];
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniDataModel::bookSlot] EXCEPTION ' . $e->getMessage());
            if ($db->transStatus()) {
                $db->transRollback();
            }
            return ['ok' => false, 'err' => 'exception'];
        } finally {
            $db->close();
        }
    }

    private function getClientSnapshotById($db, int $idClient): ?array
    {
        $row = $db->query(
            "
            SELECT
                c.id_client,
                {$this->decryptExpr('c.nome', 'c')} AS nome,
                {$this->decryptExpr('c.cognome', 'c')} AS cognome,
                {$this->decryptExpr('c.telefono', 'c')} AS telefono,
                {$this->decryptExpr('c.cellulare', 'c')} AS cellulare,
                {$this->decryptExpr('c.email', 'c')} AS email
            FROM " . self::CLIENTS_TABLE . " c
            WHERE c.id_client = ?
            LIMIT 1
            ",
            [$idClient]
        )->getRowArray();

        return $row ?: null;
    }

    private function resolvePersonaleIdFromLegacyDot(int $legacyIdDot): int
    {
        if ($legacyIdDot <= 0) {
            return 0;
        }

        if (array_key_exists($legacyIdDot, $this->legacyDoctorCache)) {
            return $this->legacyDoctorCache[$legacyIdDot];
        }

        $db = $this->connectBookingDb();
        try {
            $row = $db->table(self::PERSONALE_TABLE)
                ->select('id_personale')
                ->where('legacy_id_dot', $legacyIdDot)
                ->get()
                ->getRowArray();

            $this->legacyDoctorCache[$legacyIdDot] = (int)($row['id_personale'] ?? 0);
            return $this->legacyDoctorCache[$legacyIdDot];
        } finally {
            $db->close();
        }
    }

    private function decryptExpr(string $fieldExpr, string $alias): string
    {
        return 'CONVERT(CAST(AES_DECRYPT(UNHEX(' . $fieldExpr . '), @key_str, ' . $alias . '.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)';
    }

    private function normalizeFiscalCode(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }
}
