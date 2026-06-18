<?php
namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;

class ClientsModel extends Model
{
    protected $table      = 'dap02_clients';
    protected $primaryKey = 'id_client';

    protected $allowedFields = [
        'avviso_mail', 'cellulare', 'citta', 'codice_fiscale', 'cognome', 
        'email', 'id_personale', 'id_user', 'indirizzo', 'nome', 'provincia', 'vector_id'
    ];

    // timestamps (se li hai in tabella)
  /*  protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';*/

    /* =========================
     * METODI GIÀ ESISTENTI
     * ========================= */

    public function __construct()
    {
        parent::__construct();

        $db = \Config\Database::connect();
        $dbConfig = new DatabaseConfig();
        $dbConfig->setEncryptionConfig($db);
    }

    public function getCellulareByUserId($userId)
    {
        log_message('info', 'Recupero cellulare per userId: ' . $userId);

        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        // NB: uso l'alias 'd' come nel tuo esempio originale
        $sql = "SELECT " . $crypto_helper->decryptSenzaAlias('d.cellulare') . " AS cellulare
                FROM dap02_clients d
                WHERE d.id_user = " . (int)$userId;

        log_message('debug', 'Eseguito SQL: ' . $sql);

        $query  = $db->query($sql);
        $result = $query->getResultArray();

        if (!empty($result)) {
            log_message('info', 'Cellulare trovato per userId: ' . $userId);
            return $result[0]['cellulare'];
        } else {
            log_message('warning', 'Nessun cellulare trovato per userId: ' . $userId);
            return null;
        }
    }
     public function searchClientsLike(?string $nome, ?string $cognome, ?string $cf, int $limit = 30): array
    {
        $db = \Config\Database::connect();

        $nome    = mb_strtolower(trim((string)$nome));
        $cognome = mb_strtolower(trim((string)$cognome));
        $cf      = mb_strtolower(trim((string)$cf));

        $dNome      = $this->decryptExprWithAlias('c.nome');
        $dCognome   = $this->decryptExprWithAlias('c.cognome');
        $dCf        = $this->decryptExpr('c.codice_fiscale');
        $dNomeSA    = $this->decryptExpr('c.nome');
        $dCognomeSA = $this->decryptExpr('c.cognome');
        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                {$dNome}    ,
                {$dCognome} ,
                COALESCE(NULLIF(u.username, ''), NULLIF({$dCf}, '')) AS codice_fiscale
            FROM dap02_clients c
            LEFT JOIN dap01_users u ON u.id_user = c.id_user
            WHERE 1=1
        ";
       
        $params = [];

        if ($nome !== '') {
            $sql .= " AND LOWER(CAST({$dNomeSA} AS CHAR)) LIKE ? ";
            $params[] = '%' . $nome . '%';
        }
        if ($cognome !== '') {
            $sql .= " AND LOWER(CAST({$dCognomeSA} AS CHAR)) LIKE ? ";
            $params[] = '%' . $cognome . '%';
        }
        if ($cf !== '') {
            $sql .= " AND (LOWER(COALESCE(u.username, '')) LIKE ? OR LOWER(COALESCE(CAST({$dCf} AS CHAR), '')) LIKE ?) ";
            $params[] = '%' . $cf . '%';
            $params[] = '%' . $cf . '%';
        }

        $sql .= " ORDER BY nome,cognome LIMIT " . (int)$limit;
 //die($sql);
        return $db->query($sql, $params)->getResultArray();
    }

    public function findClientByCodiceFiscaleInsensitive(string $codiceFiscale): ?array
    {
        $cf = $this->normalizeFiscalCode($codiceFiscale);
        if ($cf === '') {
            return null;
        }

        $crypto = new Crypto_helper();
        $db = \Config\Database::connect();

        $decNome    = $crypto->decryptSenzaAlias('c.nome');
        $decCognome = $crypto->decryptSenzaAlias('c.cognome');
        $decEmail   = $crypto->decryptSenzaAlias('c.email');
        $decCell    = $crypto->decryptSenzaAlias('c.cellulare');
        $decCf      = $crypto->decryptSenzaAlias('c.codice_fiscale');
        $decInd     = $crypto->decryptSenzaAlias('c.indirizzo');
        $decCitta   = $crypto->decryptSenzaAlias('c.citta');
        $decProv    = $crypto->decryptSenzaAlias('c.provincia');

        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                c.id_personale,
                c.avviso_mail,
                c.vector_id,
                {$decNome}    AS nome,
                {$decCognome} AS cognome,
                {$decEmail}   AS email,
                {$decCell}    AS cellulare,
                {$decCf}      AS codice_fiscale,
                {$decInd}     AS indirizzo,
                {$decCitta}   AS citta,
                {$decProv}    AS provincia
            FROM dap02_clients c
            WHERE UPPER(REPLACE(TRIM(COALESCE({$decCf}, '')), ' ', '')) = ?
            ORDER BY CASE WHEN c.id_user IS NOT NULL AND c.id_user > 0 THEN 0 ELSE 1 END, c.id_client ASC
            LIMIT 1
        ";

        $row = $db->query($sql, [$cf])->getRowArray();
        return $row ?: null;
    }

    public function fillMissingClientDataAndLinkUser(int $idClient, int $idUser, array $dataPlain, int $idDot = 0): bool
    {
        $existing = $this->getClientDecryptedByClientId($idClient);
        if (!$existing) {
            return false;
        }

        $db = \Config\Database::connect();

        $patch = [];
        $encryptedFields = [
            'nome',
            'cognome',
            'email',
            'cellulare',
            'codice_fiscale',
            'indirizzo',
            'citta',
            'provincia',
        ];

        foreach ($encryptedFields as $field) {
            $existingValue = trim((string)($existing[$field] ?? ''));
            $newValue = trim((string)($dataPlain[$field] ?? ''));
            if ($existingValue === '' && $newValue !== '') {
                $patch[$field] = $newValue;
            }
        }

        $set = [];
        if ((int)($existing['id_user'] ?? 0) <= 0 && $idUser > 0) {
            $set[] = 'id_user=' . (int)$idUser;
        }

        if ((int)($existing['id_personale'] ?? 0) <= 0 && $idDot > 0) {
            $set[] = 'id_personale=' . (int)$idDot;
        }

        if (isset($dataPlain['avviso_mail']) && (int)($existing['avviso_mail'] ?? 0) === 0 && (int)$dataPlain['avviso_mail'] === 1) {
            $set[] = 'avviso_mail=1';
        }

        if ($patch !== []) {
            $db->query('SET @sync_vector = RANDOM_BYTES(16)');
            $set[] = 'vector_id = COALESCE(vector_id, @sync_vector)';

            foreach ($patch as $field => $value) {
                $set[] = $field . '=' . $this->encryptWithVectorFallbackSql($db, $value);
            }
        }

        if ($set === []) {
            return true;
        }

        $sql = "UPDATE dap02_clients SET " . implode(', ', $set)
            . " WHERE id_client=" . (int)$idClient . " LIMIT 1";

        try {
            $db->query($sql);
            (new DoctorPatientSearchModel())->syncClient($idClient);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'fillMissingClientDataAndLinkUser ERROR: ' . $e->getMessage());
            return false;
        }
    }

    
    /**
     * Dettaglio cliente decriptato per id_client (admin).
     * NB: il CF qui è quello di dap02 (spesso vuoto) -> in controller lo sovrascriviamo con username.
     */
    public function getClientDecryptedByClientId(int $idClient): ?array
    {
        $crypto = new Crypto_helper();
        $db = \Config\Database::connect();

        $decNome    = $crypto->decryptSenzaAlias('c.nome');
        $decCognome = $crypto->decryptSenzaAlias('c.cognome');
        $decEmail   = $crypto->decryptSenzaAlias('c.email');
        $decCell    = $crypto->decryptSenzaAlias('c.cellulare');
        $decCf      = $crypto->decryptSenzaAlias('c.codice_fiscale');
        $decInd     = $crypto->decryptSenzaAlias('c.indirizzo');
        $decCitta   = $crypto->decryptSenzaAlias('c.citta');
        $decProv    = $crypto->decryptSenzaAlias('c.provincia');

        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                c.id_personale,
                c.avviso_mail,
                c.vector_id,
                {$decNome}    AS nome,
                {$decCognome} AS cognome,
                {$decEmail}   AS email,
                {$decCell}    AS cellulare,
                {$decCf}      AS codice_fiscale,
                {$decInd}     AS indirizzo,
                {$decCitta}   AS citta,
                {$decProv}    AS provincia
            FROM dap02_clients c
            WHERE c.id_client = ?
            LIMIT 1
        ";

        $row = $db->query($sql, [$idClient])->getRowArray();
        return $row ?: null;
    }

    /* =========================
     * UPDATE (GIÀ USATO DA TE)
     * ========================= */

    /**
     * Aggiorna dap02_clients criptando i campi e aggiornando id_personale.
     * $dataPlain contiene valori in chiaro.
     */
    public function updateClientEncrypted(int $idClient, array $dataPlain, int $idDot): bool
    {
        $db = \Config\Database::connect();
        $crypto = new Crypto_helper();

        $nome      = (string)($dataPlain['nome'] ?? '');
        $cognome   = (string)($dataPlain['cognome'] ?? '');
        $email     = (string)($dataPlain['email'] ?? '');
        $cellulare = (string)($dataPlain['cellulare'] ?? '');
        $cf        = (string)($dataPlain['codice_fiscale'] ?? '');
        $indirizzo = (string)($dataPlain['indirizzo'] ?? '');
        $citta     = (string)($dataPlain['citta'] ?? '');
        $provincia = (string)($dataPlain['provincia'] ?? '');
        $avviso    = (int)($dataPlain['avviso_mail'] ?? 0);

        $set = [];
        $set[] = "nome="           . $crypto->encrypt_select_pulito($nome);
        $set[] = "cognome="        . $crypto->encrypt_select_pulito($cognome);
        $set[] = "email="          . $crypto->encrypt_select_pulito($email);
        $set[] = "cellulare="      . $crypto->encrypt_select_pulito($cellulare);

        // anche se dap02 CF è spesso vuoto, qui lo riallineo col CF vero (username)
        $set[] = "codice_fiscale=" . $crypto->encrypt_select_pulito($cf);

        $set[] = "indirizzo="      . $crypto->encrypt_select_pulito($indirizzo);
        $set[] = "citta="          . $crypto->encrypt_select_pulito($citta);
        $set[] = "provincia="      . $crypto->encrypt_select_pulito($provincia);

        // NON criptati:
        $set[] = "avviso_mail="  . $avviso;
        $set[] = "id_personale=" . (int)$idDot;

        $sql = "UPDATE dap02_clients SET " . implode(',', $set) .
               " WHERE id_client=" . (int)$idClient . " LIMIT 1";

        log_message('debug', 'UPDATE dap02_clients => ' . $sql);

        try {
            $db->query($sql);
            (new DoctorPatientSearchModel())->syncClient($idClient);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'updateClientEncrypted ERROR: ' . $e->getMessage());
            return false;
        }
    }

    
    public function getClientById($id)
    {
        log_message('info', 'Recupero cliente con ID: ' . $id);
        $client = $this->where('id_client', $id)->first();

        if ($client) {
            log_message('info', 'Cliente trovato con ID: ' . $id);
        } else {
            log_message('warning', 'Nessun cliente trovato con ID: ' . $id);
        }

        return $client;
    }
    
    public function getAllClients()
    {
        log_message('info', 'Recupero tutti i clienti');
        $clients = $this->findAll();

        if (empty($clients)) {
            log_message('warning', 'Nessun cliente trovato');
        } else {
            log_message('info', 'Tutti i clienti recuperati');
        }

        return $clients;
    }
    
    public function insertClient($data)
    {
        log_message('info', 'Inserimento nuovo cliente');
        $result = $this->insert($data);

        if ($result) {
            log_message('info', 'Cliente inserito con successo');
        } else {
            log_message('error', 'Errore nell\'inserimento del cliente');
        }

        return $result;
    }
    
    public function updateClient($id, $data)
    {
        log_message('info', 'Aggiornamento cliente con ID: ' . $id);
        $result = $this->update($id, $data);

        if ($result) {
            log_message('info', 'Cliente con ID ' . $id . ' aggiornato con successo');
        } else {
            log_message('error', 'Errore nell\'aggiornamento del cliente con ID: ' . $id);
        }

        return $result;
    }
    
    public function deleteClient($id)
    {
        log_message('info', 'Eliminazione cliente con ID: ' . $id);
        $result = $this->delete($id);

        if ($result) {
            log_message('info', 'Cliente con ID ' . $id . ' eliminato con successo');
        } else {
            log_message('error', 'Errore nell\'eliminazione del cliente con ID: ' . $id);
        }

        return $result;
    }

    /* =========================
     * METODI NUOVI / INTEGRATI
     * ========================= */

    /**
     * Ritorna l'elenco degli id_client per un dato id_personale.
     * Utile quando il personale invia a “tutti i contatti”.
     *
     * @return int[] elenco di id_client
     */
    public function getIdsByPersonale(int $idPersonale): array
    {
        $db = \Config\Database::connect();
        $rows = $db->table($this->table)
            ->select('id_client')
            ->where('id_personale', $idPersonale)
            ->get()->getResultArray();

        return array_map(static fn($r) => (int)$r['id_client'], $rows);
    }

    /**
     * Informazioni per notifica al cliente (coerenti col legacy).
     * Restituisce: id_user, nome (cliente), cellulare (cliente),
     * mittente (qualifica+cognome+nome del personale), qualifica.
     * Filtra solo se avviso_mail = 1.
     */
    public function getInfoNotificaCliente(int $idClient): ?array
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $decA_nome      = $crypto_helper->decrypt('a.nome');
        $decA_cellulare = $crypto_helper->decrypt('a.cellulare');
        $decB_qualifica = $crypto_helper->decrypt_concat('b.qualifica');
        $decB_cognome   = $crypto_helper->decrypt_concat('b.cognome');
        $decB_nome      = $crypto_helper->decrypt_concat('b.nome');

        $sql = "
            SELECT 
                b.id_user,
                {$decA_nome},
                {$decA_cellulare},
                CONCAT(IFNULL({$decB_qualifica},''),' ',{$decB_cognome},' ',{$decB_nome}) AS mittente,
                {$crypto_helper->decrypt('b.qualifica')} 
            FROM dap02_clients a
            JOIN dap03_personale b ON a.id_personale = b.id_personale
            WHERE a.id_client = ? AND a.avviso_mail = 1
            LIMIT 1
        ";
         log_message('error', $sql);
        $row = $db->query($sql, [$idClient])->getRowArray();
        return $row ?: null;
    }

    /**
     * Recupera il cellulare decrittato per id_client.
     */
    public function getCellulareByClientId(int $idClient): ?string
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $sql = "SELECT " . $crypto_helper->decryptSenzaAlias('a.cellulare') . " AS cellulare
                FROM dap02_clients a
                WHERE a.id_client = ?";

        $row = $db->query($sql, [$idClient])->getRowArray();
        return $row['cellulare'] ?? null;
    }

    /**
     * Lista base (id, nome completo decrittato) per un dato personale.
     * Utile per popolare select/autocomplete.
     */
    public function getBasicListByPersonale(int $idPersonale): array
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $decNome    = $crypto_helper->decryptSenzaAlias('a.nome');
        $decCognome = $crypto_helper->decryptSenzaAlias('a.cognome');

        $sql = "
            SELECT a.id_client,
                   CONCAT({$decCognome}, ' ', {$decNome}) AS nominativo
            FROM dap02_clients a
            WHERE a.id_personale = ?
            ORDER BY nominativo
        ";

        return $db->query($sql, [$idPersonale])->getResultArray();
    }

    /**
 * Recupera il client (DECRIPTATO) partendo da id_user.
 * Ritorna array con campi plain + id_client + vector_id.
 */
public function getClientDecryptedByUserId(int $userId): ?array
{
    $crypto = new \App\Libraries\Crypto_helper();
    $db = \Config\Database::connect();

    $decNome        = $crypto->decryptSenzaAlias('c.nome');
    $decCognome     = $crypto->decryptSenzaAlias('c.cognome');
    $decEmail       = $crypto->decryptSenzaAlias('c.email');
    $decCell        = $crypto->decryptSenzaAlias('c.cellulare');
    $decCf          = $crypto->decryptSenzaAlias('c.codice_fiscale');
    $decInd         = $crypto->decryptSenzaAlias('c.indirizzo');
    $decCitta       = $crypto->decryptSenzaAlias('c.citta');
    $decProv        = $crypto->decryptSenzaAlias('c.provincia');

    $sql = "
        SELECT
            c.id_client,
            c.id_user,
            c.id_personale,
            c.avviso_mail,
            c.vector_id,
            {$decNome}        AS nome,
            {$decCognome}     AS cognome,
            {$decEmail}       AS email,
            {$decCell}        AS cellulare,
            {$decCf}          AS codice_fiscale,
            {$decInd}         AS indirizzo,
            {$decCitta}       AS citta,
            {$decProv}        AS provincia
        FROM dap02_clients c
        WHERE c.id_user = ?
        LIMIT 1
    ";

    $row = $db->query($sql, [$userId])->getRowArray();
    return $row ?: null;
}

public function updateEmailByUserId(int $userId, string $email): bool
{
    $db = \Config\Database::connect();
    $crypto = new \App\Libraries\Crypto_helper();

    $sql = "UPDATE dap02_clients
            SET email=" . $crypto->encrypt_select_pulito($email) . "
            WHERE id_user=" . (int)$userId . "
            LIMIT 1";

    try {
        $db->query($sql);
        return true;
    } catch (\Throwable $e) {
        log_message('error', 'updateEmailByUserId clients ERROR: ' . $e->getMessage());
        return false;
    }
}

/**
 * Aggiorna dap02_clients criptando i campi.
 * $dataPlain contiene valori in chiaro.
 */

    private function encryptWithVectorFallbackSql($db, string $value): string
    {
        return "HEX(AES_ENCRYPT(" . $db->escape($value) . ", @key_str, COALESCE(vector_id, @sync_vector)))";
    }

    private function decryptExpr(string $fieldExpr): string
    {
        $dotPos = strrpos($fieldExpr, '.');
        $vectorExpr = $dotPos === false
            ? 'vector_id'
            : substr($fieldExpr, 0, $dotPos + 1) . 'vector_id';

        return "CONVERT(CAST(AES_DECRYPT(UNHEX(" . $fieldExpr . "), @key_str, " . $vectorExpr . ") AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }

    private function decryptExprWithAlias(string $fieldExpr): string
    {
        $dotPos = strrpos($fieldExpr, '.');
        $alias = $dotPos === false ? $fieldExpr : substr($fieldExpr, $dotPos + 1);

        return $this->decryptExpr($fieldExpr) . ' AS ' . $alias;
    }

    private function normalizeFiscalCode(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
        return $value;
    }





}
