<?php

namespace App\Models;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use CodeIgniter\Model;
use Config\Database;

class PrenotazioniSpecialistiModel extends Model
{
    private const SPEC_TABLE = 'dap41_spec';
    private const DOCTOR_SPEC_TABLE = 'dap49_dot_spec';

    private Crypto_helper $crypto;

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

    public function getSpecializzazioni(): array
    {
        $db = $this->connectBookingDb();

        try {
            return $db->query(
                "SELECT id_spec, descr, icona, titolo
                 FROM " . self::SPEC_TABLE . "
                 ORDER BY titolo ASC"
            )->getResultArray() ?? [];
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniSpecialistiModel::getSpecializzazioni] EX ' . $e->getMessage());
            return [];
        } finally {
            $db->close();
        }
    }

    public function getMediciBySpec(int $idSpec): array
    {
        $db = $this->connectBookingDb();

        try {
            $qualificaExpr = $this->decryptExpr('p.qualifica');
            $nomeExpr = $this->decryptExpr('p.nome');
            $cognomeExpr = $this->decryptExpr('p.cognome');
            $emailExpr = $this->decryptExpr('p.email');
            $cellExpr = $this->decryptExpr('p.cellulare');

            $sql = "
                SELECT
                    ds.id_dot_spec,
                    p.legacy_id_dot AS id_dot,
                    ds.id_spec,
                    ds.indirizzo,
                    ds.cap,
                    ds.telefono AS tel_spec,
                    ds.citta,
                    {$qualificaExpr} AS titolo,
                    {$nomeExpr} AS nome,
                    {$cognomeExpr} AS cognome,
                    {$cellExpr} AS tel_dot,
                    {$emailExpr} AS email
                FROM " . self::DOCTOR_SPEC_TABLE . " ds
                INNER JOIN dap03_personale p
                    ON p.legacy_id_dot = ds.id_dot
                WHERE ds.id_spec = ?
                ORDER BY {$cognomeExpr} ASC, {$nomeExpr} ASC
            ";

            return $db->query($sql, [$idSpec])->getResultArray() ?? [];
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniSpecialistiModel::getMediciBySpec] EX ' . $e->getMessage());
            return [];
        } finally {
            $db->close();
        }
    }

    public function getMedicoByIdDot(int $idDot): ?array
    {
        $db = $this->connectBookingDb();

        try {
            $qualificaExpr = $this->decryptExpr('p.qualifica');
            $nomeExpr = $this->decryptExpr('p.nome');
            $cognomeExpr = $this->decryptExpr('p.cognome');
            $emailExpr = $this->decryptExpr('p.email');
            $cellExpr = $this->decryptExpr('p.cellulare');

            $row = $db->query(
                "
                SELECT
                    p.legacy_id_dot AS id_dot,
                    {$qualificaExpr} AS titolo,
                    {$nomeExpr} AS nome,
                    {$cognomeExpr} AS cognome,
                    {$cellExpr} AS telefono,
                    {$emailExpr} AS email
                FROM dap03_personale p
                WHERE p.legacy_id_dot = ?
                LIMIT 1
                ",
                [$idDot]
            )->getRowArray();

            return $row ?: null;
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniSpecialistiModel::getMedicoByIdDot] EX ' . $e->getMessage());
            return null;
        } finally {
            $db->close();
        }
    }

    public function getSpecById(int $idSpec): ?array
    {
        $db = $this->connectBookingDb();

        try {
            $row = $db->query(
                "SELECT id_spec, descr, icona, titolo
                 FROM " . self::SPEC_TABLE . "
                 WHERE id_spec = ?
                 LIMIT 1",
                [$idSpec]
            )->getRowArray();

            return $row ?: null;
        } catch (\Throwable $e) {
            log_message('error', '[PrenotazioniSpecialistiModel::getSpecById] EX ' . $e->getMessage());
            return null;
        } finally {
            $db->close();
        }
    }

    private function decryptExpr(string $fieldExpr): string
    {
        return 'CONVERT(CAST(AES_DECRYPT(UNHEX(' . $fieldExpr . '), @key_str, p.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)';
    }
}
