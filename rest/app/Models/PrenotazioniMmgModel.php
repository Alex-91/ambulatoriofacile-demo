<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;
use App\Libraries\Crypto_helper;

class PrenotazioniMmgModel extends Model
{
    /**
     * Ritorna:
     * - codice fiscale paziente (decriptato)
     * - username paziente (NON criptato)
     */
 public function getPatientIdentityFromSession(int $idDot = 0): array
{
    log_message('error', "[MMG::getPatientIdentityFromSession] START idDot={$idDot}");

    $utente = session()->get('utente_sess');
    if (!$utente) {
        log_message('error', '[MMG::getPatientIdentityFromSession] NO SESSION utente_sess');
        throw new \RuntimeException('Utente non in sessione');
    }

    $db = \Config\Database::connect();
    $crypto = new \App\Libraries\Crypto_helper();

    $idUser = (int)($utente->id_user ?? 0);
    if ($idUser <= 0) $idUser = (int)(session()->get('userId') ?? 0);
    if ($idUser <= 0) {
        log_message('error', '[MMG::getPatientIdentityFromSession] FAIL id_user not found');
        throw new \RuntimeException('Impossibile determinare id_user');
    }

    // CF = username dap01 (non criptato)
    $sql = "
        SELECT
            u.username AS cod_fisc,
            " . $crypto->decrypt('c.nome') . "      ,
            " . $crypto->decrypt('c.cognome') . "   ,
            " . $crypto->decrypt('c.cellulare') . " 
        FROM dap01_users u
        JOIN dap02_clients c ON c.id_user = u.id_user
        WHERE u.id_user = ?
        LIMIT 1
    ";

    $sqlOneLine = preg_replace('/\s+/', ' ', trim($sql));
    log_message('error', "[MMG::getPatientIdentityFromSession] SQL={$sqlOneLine} | idUser={$idUser}");

    $row = $db->query($sql, [$idUser])->getRowArray();
    if (!$row) {
        log_message('error', '[MMG::getPatientIdentityFromSession] FAIL user/client not found');
        throw new \RuntimeException('Cliente non trovato');
    }

    $codFis  = strtoupper(trim((string)($row['cod_fisc'] ?? '')));
    $nome    = trim((string)($row['nome'] ?? ''));
    $cognome = trim((string)($row['cognome'] ?? ''));
    $cell    = trim((string)($row['cellulare'] ?? ''));

    log_message('error', "[MMG::getPatientIdentityFromSession] RESULT codFis={$codFis} nome={$nome} cognome={$cognome} cell={$cell}");

    return [
        'cod_fisc'  => $codFis,
        'nome'      => $nome,
        'cognome'   => $cognome,
        'cellulare' => $cell,
        'id_user'   => $idUser,
        'id_dot'    => $idDot,
    ];
}


    /**
     * Trova username del dottore assegnato al paziente.
     *
     * ⚠️ Qui devi allineare i campi reali:
     * - in molti schemi: dap02_clients.id_dot (o id_personale_medico) punta a dap03_personale.id_personale
     * - dap03_personale.id_user punta a dap01_users.id_user (username non criptato)
     */
    public function getAssignedDoctorUsernameByPatientUserId(int $idUser): string
    {
        $db = Database::connect();

        $sql = "
            SELECT du.username AS doctor_username
            FROM dap02_clients c
            JOIN dap03_personale p ON p.id_personale = c.id_personale
            JOIN dap01_users du     ON du.id_user = p.id_user
            WHERE c.id_user = ?
            LIMIT 1
        ";

        $row = $db->query($sql, [$idUser])->getRowArray();
        return (string)($row['doctor_username'] ?? '');
    }

    public function getPatientFullIdentityFromSession(): array
{
    $utente = session()->get('utente_sess');
    if (!$utente) {
        throw new \RuntimeException('Utente non in sessione');
    }

    $db = \Config\Database::connect();
    $crypto = new \App\Libraries\Crypto_helper();

    $idUser = (int)($utente->id_user ?? 0);
    if ($idUser <= 0) {
        $idUser = (int)(session()->get('userId') ?? 0);
    }
    if ($idUser <= 0) {
        throw new \RuntimeException('Impossibile determinare id_user');
    }

    /**
     * CF = username (dap01_users)
     * Nome/Cognome/Cellulare = dap02_clients (criptati)
     */
    $sql = "
        SELECT
            u.username AS cod_fisc,
            " . $crypto->decrypt('c.nome') . "      ,
            " . $crypto->decrypt('c.cognome') . "   ,
            " . $crypto->decrypt('c.cellulare') . " 
        FROM dap01_users u
        JOIN dap02_clients c ON c.id_user = u.id_user
        WHERE u.id_user = ?
        LIMIT 1
    ";

    log_message('error', '[MMG::getPatientFullIdentityFromSession] SQL=' . $sql . ' | idUser=' . $idUser);

    $row = $db->query($sql, [$idUser])->getRowArray();
    if (!$row) {
        throw new \RuntimeException('Cliente non trovato in dap02_clients');
    }

    $out = [
        'cod_fisc'  => strtoupper(trim((string)$row['cod_fisc'])),
        'nome'      => trim((string)($row['nome'] ?? '')),
        'cognome'   => trim((string)($row['cognome'] ?? '')),
        'cellulare' => trim((string)($row['cellulare'] ?? '')),
        'id_user'   => $idUser,
    ];

    log_message(
        'error',
        '[MMG::getPatientFullIdentityFromSession] RESULT cod_fisc=' . $out['cod_fisc'] .
        ' nome=' . $out['nome'] .
        ' cognome=' . $out['cognome'] .
        ' cell=' . $out['cellulare']
    );

    return $out;
}


}
