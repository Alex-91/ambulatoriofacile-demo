<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper; // Importa la libreria

class AuthCodeModel extends Model
{
    protected $table = 'dap16_auth_code'; // Nome della tabella reale
    protected $primaryKey = 'authCode'; // Chiave primaria (se è unica per ogni record)
    
    protected $allowedFields = ['authCode', 'cellulare', 'data_ins', 'vector_id'];
    protected $useTimestamps = false; // Se non hai campi created_at e updated_at

    /**
     * Recupera un record in base al codice di autenticazione
     */
    public function getByAuthCode($authCode)
    {
        return $this->where('authCode', $authCode)->first();
    }

    /**
     * Inserisce un nuovo record nella tabella
     */
    public function insertAuth($data)
    {
        return $this->insert($data);
    }

    public function checkOtp($authCode,$cellulare)
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $authCode = trim((string)$authCode);
        $cellulare = trim((string)$cellulare);
        if ($authCode === '' || $cellulare === '') {
            return false;
        }

        $sql = "SELECT 1
                FROM dap16_auth_code d
                WHERE d.cellulare = " . $crypto_helper->encrypt_select_login('?') . "
                  AND d.authCode = ?
                  AND d.data_ins >= NOW() - INTERVAL 2 MINUTE
                LIMIT 1";

        $row = $db->query($sql, [$cellulare, $authCode])->getRowArray();
        return !empty($row);
    }

    public function getLatestValidOtp(string $cellulare): ?string
    {
        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();

        $sql = "SELECT authCode
                FROM dap16_auth_code d
                WHERE d.cellulare = " . $crypto_helper->encrypt_select_login('?') . "
                  AND d.data_ins >= NOW() - INTERVAL 2 MINUTE
                ORDER BY d.data_ins DESC
                LIMIT 1";

        $row = $db->query($sql, [$cellulare])->getRowArray();
        if (empty($row['authCode'])) {
            return null;
        }

        return (string)$row['authCode'];
    }
}
