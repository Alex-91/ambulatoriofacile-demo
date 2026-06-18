<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;
use App\Libraries\Crypto_helper;
use App\Services\AgendaDoctorIdService;

class PersonaleModel extends Model
{
    protected $table      = 'dap03_personale';
    protected $primaryKey = 'id_personale';
    protected $returnType = 'array';

    protected $allowedFields = [
        'id_user', 'nome', 'cognome', 'qualifica', 'tipo', 'email',
        'cellulare', 'vector_id', 'is_dot', 'sostituto', 'titolare',
        'luogo', 'is_active', 'show_in_agenda', 'show_in_posta', 'show_in_chat'
    ];

    protected Crypto_helper $crypto;
    private array $personaleFieldExistsCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->crypto = new Crypto_helper();
    }

    private function hasPersonaleField(string $field): bool
    {
        if (array_key_exists($field, $this->personaleFieldExistsCache)) {
            return $this->personaleFieldExistsCache[$field];
        }

        try {
            $exists = $this->db->fieldExists($field, $this->table);
        } catch (\Throwable $e) {
            $exists = false;
        }

        $this->personaleFieldExistsCache[$field] = $exists;
        return $exists;
    }

    private function getVisibilitySelectSql(string $alias = 'p'): string
    {
        $parts = [];

        foreach (['show_in_agenda', 'show_in_posta', 'show_in_chat'] as $field) {
            if ($this->hasPersonaleField($field)) {
                $parts[] = "{$alias}.{$field}";
            } else {
                $parts[] = "1 AS {$field}";
            }
        }

        return implode(",\n                ", $parts);
    }

    /* =========================================================
     * METODI ESISTENTI (LI LASCIO)
     * ========================================================= */

    public function findAllDecrypted(): array
    {
        $db = Database::connect();

        $DEC_NOME      = $this->crypto->decrypt('p.nome');
        $DEC_COGNOME   = $this->crypto->decrypt('p.cognome');
        $DEC_QUALIFICA = $this->crypto->decrypt('p.qualifica');
        $DEC_EMAIL     = $this->crypto->decrypt('p.email');
        $DEC_CELL      = $this->crypto->decrypt('p.cellulare');
        $visibilitySelect = $this->getVisibilitySelectSql('p');

        $sql = "
            SELECT 
                p.id_personale,
                p.id_user,
                $DEC_NOME  ,
                $DEC_COGNOME,
                $DEC_QUALIFICA,
                $DEC_EMAIL,
                $DEC_CELL,
                p.tipo,
                p.is_dot,
                p.sostituto,
                p.titolare,
                p.luogo,
                p.is_active,
                {$visibilitySelect}
            FROM dap03_personale p
            WHERE p.tipo = 1 AND p.sostituto = 0
            ORDER BY cognome
        ";

        return $db->query($sql)->getResultArray();
    }
/**
 * Dettaglio personale decriptato per id_user.
 * Serve per pagina profilo personale (segreteria/infermiere/dottore).
 */
public function getPersonaleDecryptedByUserId(int $idUser): ?array
{
    $db = Database::connect();

    $DEC_NOME      = $this->crypto->decryptSenzaAlias('p.nome');
    $DEC_COGNOME   = $this->crypto->decryptSenzaAlias('p.cognome');
    $DEC_QUALIFICA = $this->crypto->decryptSenzaAlias('p.qualifica');
    $DEC_EMAIL     = $this->crypto->decryptSenzaAlias('p.email');
    $DEC_CELL      = $this->crypto->decryptSenzaAlias('p.cellulare');
    $visibilitySelect = $this->getVisibilitySelectSql('p');

    $sql = "
        SELECT
            p.id_personale,
            p.id_user,
            {$DEC_NOME}      AS nome,
            {$DEC_COGNOME}   AS cognome,
            {$DEC_QUALIFICA} AS qualifica,
            {$DEC_EMAIL}     AS email,
            {$DEC_CELL}      AS cellulare,
            p.tipo,
            p.sostituto,
            p.titolare,
            p.luogo,
            p.is_active,
            p.is_dot,
            {$visibilitySelect}
        FROM dap03_personale p
        WHERE p.id_user = ?
        LIMIT 1
    ";

    $row = $db->query($sql, [$idUser])->getRowArray();
    return $row ?: null;
}

    public function getDoctorsListForSelect(): array
    {
        $db = Database::connect();

        // NB: nel tuo schema is_dot commenta "0 = dottore"
        $DEC_NOME      = $this->crypto->decrypt_concat('p.nome');
        $DEC_COGNOME   = $this->crypto->decrypt_concat('p.cognome');
        $DEC_QUALIFICA = $this->crypto->decrypt_concat('p.qualifica');

        $sql = "
            SELECT
                p.id_personale,
                CONCAT(
                    IFNULL($DEC_QUALIFICA,''),
                    ' ',
                    $DEC_COGNOME,
                    ' ',
                    $DEC_NOME
                ) AS nominativo
            FROM dap03_personale p
            WHERE p.is_dot = 0
              AND p.sostituto = 0
              AND p.tipo = 1
            ORDER BY nominativo
        ";
       // die($sql);
        return $db->query($sql)->getResultArray();
    }

    /* =========================================================
     * MODIFICA PERSONALE (NUOVI METODI)
     * ========================================================= */

    /**
     * Ricerca LIKE case-insensitive:
     * - nome/cognome: decrypt su dap03_personale
     * - CF: LIKE su dap01_users.username (non cifrato)
     *
     * Ritorna: id_personale, id_user, nome, cognome, codice_fiscale(username)
     */
    public function searchPersonaleLike(?string $nome, ?string $cognome, ?string $cf, int $limit = 30): array
    {
        $db = Database::connect();

        $nome    = mb_strtolower(trim((string)$nome));
        $cognome = mb_strtolower(trim((string)$cognome));
        $cf      = mb_strtolower(trim((string)$cf));

        $DEC_NOME    = $this->crypto->decrypt('p.nome');
        $DEC_COGNOME = $this->crypto->decrypt('p.cognome');
        $dNomeSA    = $this->crypto->decrypt_concat('p.nome');
        $dCognomeSA = $this->crypto->decrypt_concat('p.cognome');
        $sql = "
            SELECT
                p.id_personale,
                p.id_user,
                {$DEC_NOME}   ,
                {$DEC_COGNOME} ,
                u.username     AS codice_fiscale
            FROM dap03_personale p
            LEFT JOIN dap01_users u ON u.id_user = p.id_user
            WHERE 1=1
        ";

        $params = [];

        if ($nome !== '') {
            $sql .= " AND LOWER(CAST({$dNomeSA}AS CHAR)) LIKE ? ";
            $params[] = '%' . $nome . '%';
        }

        if ($cognome !== '') {
            $sql .= " AND LOWER(CAST({$dCognomeSA}AS CHAR)) LIKE ? ";
            $params[] = '%' . $cognome . '%';
        }

        if ($cf !== '') {
            $sql .= " AND LOWER(u.username) LIKE ? ";
            $params[] = '%' . $cf . '%';
        }

        $sql .= " ORDER BY cognome,nome LIMIT " . (int)$limit;

        return $db->query($sql, $params)->getResultArray();
    }

    /**
     * Dettaglio personale decriptato per id_personale.
     * Serve per popolare la form di modifica.
     */
    public function getPersonaleDecryptedById(int $idPersonale): ?array
    {
        $db = Database::connect();

        $DEC_NOME      = $this->crypto->decryptSenzaAlias('p.nome');
        $DEC_COGNOME   = $this->crypto->decryptSenzaAlias('p.cognome');
        $DEC_QUALIFICA = $this->crypto->decryptSenzaAlias('p.qualifica');
        $DEC_EMAIL     = $this->crypto->decryptSenzaAlias('p.email');
        $DEC_CELL      = $this->crypto->decryptSenzaAlias('p.cellulare');
        $visibilitySelect = $this->getVisibilitySelectSql('p');

        $sql = "
            SELECT
                p.id_personale,
                p.id_user,
                {$DEC_NOME}      AS nome,
                {$DEC_COGNOME}   AS cognome,
                {$DEC_QUALIFICA} AS qualifica,
                {$DEC_EMAIL}     AS email,
                {$DEC_CELL}      AS cellulare,
                p.tipo,
                p.sostituto,
                p.titolare,
                p.luogo,
                p.is_active,
                p.is_dot,
                {$visibilitySelect}
            FROM dap03_personale p
            WHERE p.id_personale = ?
            LIMIT 1
        ";

        $row = $db->query($sql, [$idPersonale])->getRowArray();
        return $row ?: null;
    }

    public function updateEmailByUserId(int $idUser, string $email): bool
    {
        $db = Database::connect();

        $sql = "UPDATE dap03_personale
                SET email=" . $this->crypto->encrypt_select_pulito($email) . "
                WHERE id_user=" . (int)$idUser . "
                LIMIT 1";

        try {
            $db->query($sql);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'updateEmailByUserId personale ERROR: ' . $e->getMessage());
            return false;
        }
    }

    public function updateModuleVisibilityFlags(int $idPersonale, array $flags): bool
    {
        $db = Database::connect();

        if ($idPersonale <= 0) {
            return false;
        }

        $set = [];

        if ($this->hasPersonaleField('show_in_agenda')) {
            $set[] = 'show_in_agenda=' . (!empty($flags['show_in_agenda']) ? 1 : 0);
        }
        if ($this->hasPersonaleField('show_in_posta')) {
            $set[] = 'show_in_posta=' . (!empty($flags['show_in_posta']) ? 1 : 0);
        }
        if ($this->hasPersonaleField('show_in_chat')) {
            $set[] = 'show_in_chat=' . (!empty($flags['show_in_chat']) ? 1 : 0);
        }

        if ($set === []) {
            log_message('error', 'updateModuleVisibilityFlags ERROR: colonne flag non presenti su dap03_personale');
            return false;
        }

        $sql = 'UPDATE dap03_personale SET ' . implode(', ', $set)
             . ' WHERE id_personale=' . (int)$idPersonale . ' LIMIT 1';

        try {
            $db->query($sql);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'updateModuleVisibilityFlags ERROR: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update del personale:
     * - campi testo cifrati: nome, cognome, qualifica, email, cellulare
     * - campi non cifrati: tipo, luogo, titolare, sostituto, is_active
     */
    public function updatePersonaleEncrypted(int $idPersonale, array $dataPlain): bool
    {
        $db = Database::connect();

        $nome      = (string)($dataPlain['nome'] ?? '');
        $cognome   = (string)($dataPlain['cognome'] ?? '');
        $qualifica = (string)($dataPlain['qualifica'] ?? '');
        $email     = (string)($dataPlain['email'] ?? '');
        $cell      = (string)($dataPlain['cellulare'] ?? '');

        $tipo      = (int)($dataPlain['id_tipo'] ?? null);
        $luogo     = (int)($dataPlain['id_gruppo'] ?? 0);

        $titolare  = !empty($dataPlain['titolare']) ? 1 : 0;
        $sostituto = !empty($dataPlain['sostituto']) ? 1 : 0;
        $showInAgenda = !empty($dataPlain['show_in_agenda']) ? 1 : 0;
        $showInPosta  = !empty($dataPlain['show_in_posta']) ? 1 : 0;
        $showInChat   = !empty($dataPlain['show_in_chat']) ? 1 : 0;

        // se vuoi gestire anche is_active dalla form:
        $isActive  = isset($dataPlain['is_active']) ? (int)$dataPlain['is_active'] : null;

        $set = [];
        $set[] = "nome="      . $this->crypto->encrypt_select_pulito($nome);
        $set[] = "cognome="   . $this->crypto->encrypt_select_pulito($cognome);
        $set[] = "qualifica=" . $this->crypto->encrypt_select_pulito($qualifica);
        $set[] = "email="     . $this->crypto->encrypt_select_pulito($email);
        $set[] = "cellulare=" . $this->crypto->encrypt_select_pulito($cell);

        $set[] = "tipo="      . $tipo;
        $set[] = "luogo="     . $luogo;

        $set[] = "titolare="  . $titolare;
        $set[] = "sostituto=" . $sostituto;

        if ($this->hasPersonaleField('show_in_agenda')) {
            $set[] = "show_in_agenda=" . $showInAgenda;
        }
        if ($this->hasPersonaleField('show_in_posta')) {
            $set[] = "show_in_posta=" . $showInPosta;
        }
        if ($this->hasPersonaleField('show_in_chat')) {
            $set[] = "show_in_chat=" . $showInChat;
        }

        if ($isActive !== null) {
            $set[] = "is_active=" . $isActive;
        }

        $sql = "UPDATE dap03_personale SET " . implode(',', $set) .
               " WHERE id_personale=" . (int)$idPersonale . " LIMIT 1";

        try {
            $db->query($sql);
            $agendaDoctorId = (new AgendaDoctorIdService($db))->ensureForPersonale($idPersonale, $tipo);
            if (in_array($tipo, [1, 2], true) && $agendaDoctorId <= 0) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'updatePersonaleEncrypted ERROR: '.$e->getMessage());
            return false;
        }
    }
}
