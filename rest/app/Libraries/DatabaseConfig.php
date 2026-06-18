<?php
namespace App\Libraries;

use CodeIgniter\Database\ConnectionInterface;

class DatabaseConfig
{
    private const ALLOWED_CHARSETS = ['latin1', 'utf8', 'utf8mb4'];

    public function __construct()
    {
        // Costruttore vuoto o con logica necessaria
    }

    // Questo metodo esegue le query di configurazione
    public function setEncryptionConfig(ConnectionInterface $db, string $charset = 'latin1')
    {
        $charset = strtolower(trim($charset));
        if (!in_array($charset, self::ALLOWED_CHARSETS, true)) {
            $charset = 'latin1';
        }

        //var_dump(getenv('DB_ENCRYPTION_KEY'));
        //var_dump($_ENV['DB_ENCRYPTION_KEY']);
        $db->query("SET @key_str = SHA2('".$_ENV['DB_ENCRYPTION_KEY']."', 512)");
        $db->query("SET NAMES {$charset}");
        $db->query("SET block_encryption_mode = '".$_ENV['DB_ENCRYPTION_MODE']."'");
        $db->query("SET @init_vector = RANDOM_BYTES(16)");
    }
}
