<?php

namespace App\Models;

use App\Libraries\WhatsappAppointmentNote;
use CodeIgniter\Model;

class LegacyWhatsappAppointmentsModel extends Model
{
    private const APPOINTMENTS_TABLE = 'dap12_agenda_appuntamenti';
    private const SLOTS_TABLE = 'dap11_agenda_slot';
    private const CLIENTS_TABLE = 'dap02_clients';
    private const PERSONALE_TABLE = 'dap03_personale';
    private const SMS_DOCTORS_TABLE = 'dap39_sms_dot';
    private const MULTIPLES_TABLE = 'dap47_sms_app_multipli';

    protected $db;
    private ?bool $hasIdClientColumn = null;
    private ?bool $hasEsitatoColumn = null;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    public function findPendingAppointmentsByPhone(string $cellulare, string $startDate, string $endDate): array
    {
        $this->prepareDatabaseSession();

        [$joinsSql, $phoneMatchSql] = $this->buildPhoneLookupSql();
        $doctorTitleSql = $this->doctorFieldSubquery('qualifica');
        $doctorSurnameSql = $this->doctorFieldSubquery('cognome');
        $doctorNameSql = $this->doctorFieldSubquery('nome');

        $sql = "
            SELECT
                a.id_appuntamento AS appointment_id,
                s.ora_inizio AS appointment_date,
                DATE_FORMAT(s.ora_inizio, '%d/%m/%Y %H:%i') AS appointment_date_format,
                a.id_dot AS doctor_id,
                {$doctorTitleSql} AS doctor_title,
                {$doctorSurnameSql} AS doctor_surname,
                {$doctorNameSql} AS doctor_name,
                a.stato AS appointment_state,
                a.nome AS patient_name,
                a.cognome AS patient_surname
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN " . self::SLOTS_TABLE . " s
                ON s.id_slot = a.id_slot
            INNER JOIN " . self::SMS_DOCTORS_TABLE . " sms
                ON sms.id_dot = a.id_dot
               AND sms.conferma = 1
            {$joinsSql}
            WHERE ({$phoneMatchSql})
              AND a.stato <> 'ANNULLATO'
              " . $this->buildEsitatoPendingSql('a') . "
              AND DATE(s.ora_inizio) BETWEEN ? AND ?
            ORDER BY s.ora_inizio ASC, a.id_appuntamento ASC
        ";

        return $this->db->query($sql, [$cellulare, $cellulare, $startDate, $endDate])->getResultArray();
    }

    public function findPendingAppointmentById(int $idAppuntamento): ?array
    {
        if ($idAppuntamento <= 0) {
            return null;
        }

        $this->prepareDatabaseSession();

        [$joinsSql] = $this->buildPhoneLookupSql();
        $doctorTitleSql = $this->doctorFieldSubquery('qualifica');
        $doctorSurnameSql = $this->doctorFieldSubquery('cognome');
        $doctorNameSql = $this->doctorFieldSubquery('nome');
        $currentNameSql = $this->buildCurrentClientFieldSql('nome', 'a.nome');
        $currentSurnameSql = $this->buildCurrentClientFieldSql('cognome', 'a.cognome');
        $currentCellSql = $this->buildCurrentClientFieldSql('cellulare', 'a.cellulare');
        $currentPhoneSql = $this->buildCurrentClientFieldSql('telefono', 'a.telefono');
        $currentEmailSql = $this->buildCurrentClientFieldSql('email', 'a.email');

        $sql = "
            SELECT
                a.id_appuntamento,
                a.id_slot,
                a.id_dot,
                a.id_paziente,
                " . $this->buildIdClientSelectSql('a') . "
                a.nome AS appointment_name,
                a.cognome AS appointment_surname,
                a.cellulare AS appointment_cellulare,
                a.telefono AS appointment_telefono,
                a.email AS appointment_email,
                a.note AS appointment_note,
                a.stato AS appointment_state,
                s.ora_inizio AS appointment_date,
                {$doctorTitleSql} AS doctor_title,
                {$doctorSurnameSql} AS doctor_surname,
                {$doctorNameSql} AS doctor_name,
                {$currentNameSql} AS patient_name,
                {$currentSurnameSql} AS patient_surname,
                {$currentCellSql} AS patient_cellulare,
                {$currentPhoneSql} AS patient_telefono,
                {$currentEmailSql} AS patient_email
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN " . self::SLOTS_TABLE . " s
                ON s.id_slot = a.id_slot
            INNER JOIN " . self::SMS_DOCTORS_TABLE . " sms
                ON sms.id_dot = a.id_dot
               AND sms.conferma = 1
            {$joinsSql}
            WHERE a.id_appuntamento = ?
              AND a.stato <> 'ANNULLATO'
              " . $this->buildEsitatoPendingSql('a') . "
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idAppuntamento])->getRowArray();
        return $row ?: null;
    }

    public function replaceMultipleSelections(string $cellulare, array $appointments): void
    {
        if (!$this->db->tableExists(self::MULTIPLES_TABLE)) {
            return;
        }

        $this->db->table(self::MULTIPLES_TABLE)
            ->where('cellulare', $cellulare)
            ->delete();

        foreach ($appointments as $index => $appointment) {
            $this->db->table(self::MULTIPLES_TABLE)->insert([
                'cellulare'       => $cellulare,
                'indice_menu'     => $index + 1,
                'id_appuntamento' => (int)($appointment['appointment_id'] ?? 0),
                'data'            => $appointment['appointment_date'] ?? null,
            ]);
        }
    }

    public function findPendingMultipleSelection(string $cellulare, int $menuIndex): ?int
    {
        if ($cellulare === '' || $menuIndex <= 0 || !$this->db->tableExists(self::MULTIPLES_TABLE)) {
            return null;
        }

        $sql = "
            SELECT a.id_appuntamento
            FROM " . self::MULTIPLES_TABLE . " m
            INNER JOIN " . self::APPOINTMENTS_TABLE . " a
                ON a.id_appuntamento = m.id_appuntamento
            INNER JOIN " . self::SMS_DOCTORS_TABLE . " sms
                ON sms.id_dot = a.id_dot
               AND sms.conferma = 1
            WHERE m.cellulare = ?
              AND m.indice_menu = ?
              AND a.stato <> 'ANNULLATO'
              " . $this->buildEsitatoPendingSql('a') . "
            ORDER BY a.id_appuntamento ASC
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$cellulare, $menuIndex])->getRowArray();
        return $row ? (int)($row['id_appuntamento'] ?? 0) : null;
    }

    public function markAppointmentConfirmed(int $idAppuntamento, string $noteAppend): bool
    {
        if ($idAppuntamento <= 0) {
            return false;
        }

        $row = $this->db->table(self::APPOINTMENTS_TABLE)
            ->select('id_appuntamento, note')
            ->where('id_appuntamento', $idAppuntamento)
            ->get()
            ->getRowArray();

        if (!$row) {
            return false;
        }

        $update = [
            'note' => $this->appendAppointmentNote((string)($row['note'] ?? ''), $noteAppend),
        ];

        if ($this->appointmentTableHasEsitatoColumn()) {
            $update['esitato'] = 1;
        }

        return (bool)$this->db->table(self::APPOINTMENTS_TABLE)
            ->where('id_appuntamento', $idAppuntamento)
            ->update($update);
    }

    public function markAppointmentCancelled(int $idAppuntamento, string $noteAppend, array $specialPatient): bool
    {
        if ($idAppuntamento <= 0 || empty($specialPatient)) {
            return false;
        }

        $row = $this->db->table(self::APPOINTMENTS_TABLE)
            ->select('id_appuntamento, id_slot, note, stato')
            ->where('id_appuntamento', $idAppuntamento)
            ->get()
            ->getRowArray();

        if (!$row) {
            return false;
        }

        $idSlot = (int)($row['id_slot'] ?? 0);
        $update = [
            'note' => $this->appendAppointmentNote((string)($row['note'] ?? ''), $noteAppend),
            'id_paziente' => (int)($specialPatient['legacy_id_paziente'] ?? 0),
            'cognome' => 'DOT',
            'nome' => 'DOTTORE',
            'telefono' => trim((string)($specialPatient['telefono'] ?? '')),
            'cellulare' => trim((string)($specialPatient['cellulare'] ?? '')),
            'email' => trim((string)($specialPatient['email'] ?? '')),
            'stato' => trim((string)($row['stato'] ?? 'CONFERMATO')) !== '' ? (string)$row['stato'] : 'CONFERMATO',
        ];

        if ($this->appointmentTableHasClientColumn()) {
            $update['id_client'] = (int)($specialPatient['id_client'] ?? 0);
        }

        if ($this->appointmentTableHasEsitatoColumn()) {
            $update['esitato'] = 1;
        }

        $this->db->transStart();

        $this->db->table(self::APPOINTMENTS_TABLE)
            ->where('id_appuntamento', $idAppuntamento)
            ->update($update);

        $this->db->table(self::SLOTS_TABLE)
            ->where('id_slot', $idSlot)
            ->update([
                'stato' => 'PRENOTATO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->db->transComplete();

        return (bool)$this->db->transStatus();
    }

    public function getSpecialDotPatient(): ?array
    {
        $this->prepareDatabaseSession();

        $cognomeExpr = $this->decryptExpr('c.cognome', 'c.vector_id');
        $nomeExpr = $this->decryptExpr('c.nome', 'c.vector_id');
        $telefonoExpr = $this->decryptExpr('c.telefono', 'c.vector_id');
        $cellulareExpr = $this->decryptExpr('c.cellulare', 'c.vector_id');
        $emailExpr = $this->decryptExpr('c.email', 'c.vector_id');
        $pazSpecExpr = $this->decryptExpr('c.paz_spec', 'c.vector_id');

        $sql = "
            SELECT
                c.id_client,
                COALESCE(c.legacy_id_paziente, 0) AS legacy_id_paziente,
                {$cognomeExpr} AS cognome,
                {$nomeExpr} AS nome,
                {$telefonoExpr} AS telefono,
                {$cellulareExpr} AS cellulare,
                {$emailExpr} AS email,
                {$pazSpecExpr} AS paz_spec
            FROM " . self::CLIENTS_TABLE . " c
            WHERE UPPER(TRIM(COALESCE({$cognomeExpr}, ''))) = 'DOT'
              AND TRIM(COALESCE({$pazSpecExpr}, '')) = '*'
            ORDER BY c.id_client ASC
            LIMIT 1
        ";

        $row = $this->db->query($sql)->getRowArray();
        return $row ?: null;
    }

    private function prepareDatabaseSession(): void
    {
        $key = (string)(env('DB_ENCRYPTION_KEY') ?: ($_ENV['DB_ENCRYPTION_KEY'] ?? ''));
        $mode = (string)(env('DB_ENCRYPTION_MODE') ?: ($_ENV['DB_ENCRYPTION_MODE'] ?? 'aes-256-cbc'));

        if ($key === '') {
            return;
        }

        $this->db->query("SET @key_str = SHA2(" . $this->db->escape($key) . ", 512)");
        $this->db->query("SET block_encryption_mode = " . $this->db->escape($mode));
    }

    private function appointmentTableHasClientColumn(): bool
    {
        if ($this->hasIdClientColumn === null) {
            $this->hasIdClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
        }

        return $this->hasIdClientColumn;
    }

    private function appointmentTableHasEsitatoColumn(): bool
    {
        if ($this->hasEsitatoColumn === null) {
            $this->hasEsitatoColumn = $this->db->fieldExists('esitato', self::APPOINTMENTS_TABLE);
        }

        return $this->hasEsitatoColumn;
    }

    private function buildEsitatoPendingSql(string $alias): string
    {
        if (!$this->appointmentTableHasEsitatoColumn()) {
            return '';
        }

        return " AND COALESCE({$alias}.esitato, 0) = 0";
    }

    private function appendAppointmentNote(string $existingNote, string $noteAppend): string
    {
        return WhatsappAppointmentNote::appendToExisting($existingNote, $noteAppend);
    }

    private function buildIdClientSelectSql(string $alias): string
    {
        if (!$this->appointmentTableHasClientColumn()) {
            return "0 AS id_client,";
        }

        return "{$alias}.id_client AS id_client,";
    }

    private function buildPhoneLookupSql(): array
    {
        $joinsSql = "
            LEFT JOIN " . self::CLIENTS_TABLE . " c_legacy
                ON COALESCE(c_legacy.legacy_id_paziente, 0) = COALESCE(a.id_paziente, 0)
        ";

        $cellLegacy = $this->decryptExpr('c_legacy.cellulare', 'c_legacy.vector_id');
        $phoneLegacy = $this->decryptExpr('c_legacy.telefono', 'c_legacy.vector_id');

        if ($this->appointmentTableHasClientColumn()) {
            $joinsSql = "
                LEFT JOIN " . self::CLIENTS_TABLE . " c_id
                    ON c_id.id_client = a.id_client
                LEFT JOIN " . self::CLIENTS_TABLE . " c_legacy
                    ON COALESCE(a.id_client, 0) = 0
                   AND COALESCE(c_legacy.legacy_id_paziente, 0) = COALESCE(a.id_paziente, 0)
            ";

            $cellById = $this->decryptExpr('c_id.cellulare', 'c_id.vector_id');
            $phoneById = $this->decryptExpr('c_id.telefono', 'c_id.vector_id');

            $phoneMatchSql = "
                COALESCE(NULLIF(TRIM({$cellById}), ''), NULLIF(TRIM({$cellLegacy}), ''), TRIM(COALESCE(a.cellulare, '')), '') = ?
                OR COALESCE(NULLIF(TRIM({$phoneById}), ''), NULLIF(TRIM({$phoneLegacy}), ''), TRIM(COALESCE(a.telefono, '')), '') = ?
            ";

            return [$joinsSql, $phoneMatchSql];
        }

        $phoneMatchSql = "
            COALESCE(NULLIF(TRIM({$cellLegacy}), ''), TRIM(COALESCE(a.cellulare, '')), '') = ?
            OR COALESCE(NULLIF(TRIM({$phoneLegacy}), ''), TRIM(COALESCE(a.telefono, '')), '') = ?
        ";

        return [$joinsSql, $phoneMatchSql];
    }

    private function buildCurrentClientFieldSql(string $field, string $fallbackExpr): string
    {
        $fallbackExpr = "TRIM(COALESCE({$fallbackExpr}, ''))";
        $legacyExpr = $this->decryptExpr('c_legacy.' . $field, 'c_legacy.vector_id');

        if ($this->appointmentTableHasClientColumn()) {
            $idExpr = $this->decryptExpr('c_id.' . $field, 'c_id.vector_id');
            return "COALESCE(NULLIF(TRIM({$idExpr}), ''), NULLIF(TRIM({$legacyExpr}), ''), {$fallbackExpr})";
        }

        return "COALESCE(NULLIF(TRIM({$legacyExpr}), ''), {$fallbackExpr})";
    }

    private function doctorFieldSubquery(string $field): string
    {
        $fieldExpr = $this->decryptExpr('d.' . $field, 'd.vector_id');

        return "(
            SELECT {$fieldExpr}
            FROM " . self::PERSONALE_TABLE . " d
            WHERE d.legacy_id_dot = a.id_dot
              AND d.tipo IN (1, 2)
            ORDER BY d.id_personale ASC
            LIMIT 1
        )";
    }

    private function decryptExpr(string $fieldExpr, string $vectorExpr): string
    {
        return "CONVERT(CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }
}
