<?php
namespace App\Models;

use CodeIgniter\Model;
use Config\Database;
use App\Libraries\Crypto_helper;

class SostitutiModel extends Model
{
    protected $table      = 'dap18_sostituto';
    protected $primaryKey = 'id_sost';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_personale',
        'id_personale_da_sostituire',
        'data_inizio',
        'data_fine',
    ];

    /**
     * Medici selezionabili: dap03_personale.tipo=1
     * (decriptati) -> id_personale + nominativo
     */
    public function getMediciTipo1(): array
    {
        $db = Database::connect();
        $crypto = new Crypto_helper();

        $DEC_NOME    = $crypto->decrypt_concat('p.nome');
        $DEC_COGNOME = $crypto->decrypt_concat('p.cognome');
        $DEC_QUAL    = $crypto->decrypt_concat('p.qualifica');

        $sql = "
            SELECT
                p.id_personale,
                CONCAT(
                    IFNULL($DEC_QUAL,''),
                    ' ',
                    $DEC_COGNOME,
                    ' ',
                    $DEC_NOME
                ) AS nominativo
            FROM dap03_personale p
            WHERE p.tipo = 1
            ORDER BY nominativo
        ";

        return $db->query($sql)->getResultArray();
    }

    /**
     * Lista sostituzioni con nomi decriptati (per tabella)
     */
    public function listAllWithNames(): array
    {
        $db = Database::connect();
        $crypto = new Crypto_helper();

        $DEC_NOME    = $crypto->decrypt_concat('p.nome');
        $DEC_COGNOME = $crypto->decrypt_concat('p.cognome');
        $DEC_QUAL    = $crypto->decrypt_concat('p.qualifica');

        $DEC_NOME2    = $crypto->decrypt_concat('p2.nome');
        $DEC_COGNOME2 = $crypto->decrypt_concat('p2.cognome');
        $DEC_QUAL2    = $crypto->decrypt_concat('p2.qualifica');

        $sql = "
            SELECT
                s.id_sost,
                s.id_personale,
                s.id_personale_da_sostituire,
                s.data_inizio,
                s.data_fine,
                CONCAT(IFNULL($DEC_QUAL2,''),' ',$DEC_COGNOME2,' ',$DEC_NOME2) AS medico_da_sostituire,
                CONCAT(IFNULL($DEC_QUAL,''),' ',$DEC_COGNOME,' ',$DEC_NOME)     AS sostituto
            FROM dap18_sostituto s
            JOIN dap03_personale p  ON p.id_personale  = s.id_personale
            JOIN dap03_personale p2 ON p2.id_personale = s.id_personale_da_sostituire
            ORDER BY s.data_inizio DESC, s.id_sost DESC
        ";

        return $db->query($sql)->getResultArray();
    }

    /**
     * Blocca solo sovrapposizioni duplicate per la stessa coppia
     * medico da sostituire + sostituto.
     * Cosi piu sostituti diversi possono coprire lo stesso medico
     * nello stesso giorno.
     */
    public function hasPairOverlap(int $idDaSostituire, int $idSostituto, string $inizio, string $fine): bool
    {
        $db = Database::connect();

        // overlap se: nuovo_inizio <= esistente_fine AND nuovo_fine >= esistente_inizio
        $cnt = $db->table('dap18_sostituto')
            ->where('id_personale_da_sostituire', $idDaSostituire)
            ->where('id_personale', $idSostituto)
            ->where("data_inizio <=", $fine)
            ->where("data_fine >=", $inizio)
            ->countAllResults();

        return $cnt > 0;
    }
}
