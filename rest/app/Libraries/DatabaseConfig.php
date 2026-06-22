<?php
namespace App\Libraries;

use CodeIgniter\Database\ConnectionInterface;

class DatabaseConfig
{
    private const ALLOWED_CHARSETS = ['latin1', 'utf8', 'utf8mb4'];

    private function resolveEncryptionKey(): string
    {
        return (string) (
            env('DB_ENCRYPTION_KEY')
            ?: env('database.default.DB_ENCRYPTION_KEY')
            ?: ($_ENV['DB_ENCRYPTION_KEY'] ?? '')
            ?: ($_ENV['database.default.DB_ENCRYPTION_KEY'] ?? '')
        );
    }

    private function resolveEncryptionMode(): string
    {
        return (string) (
            env('DB_ENCRYPTION_MODE')
            ?: ($_ENV['DB_ENCRYPTION_MODE'] ?? '')
            ?: 'aes-256-cbc'
        );
    }

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

        $key = $this->resolveEncryptionKey();
        $mode = $this->resolveEncryptionMode();

        if ($key === '') {
            log_message('error', 'DatabaseConfig::setEncryptionConfig skipped: encryption key non configurata.');
            return;
        }

        $db->query('SET @key_str = SHA2(' . $db->escape($key) . ', 512)');
        $db->query("SET NAMES {$charset}");
        $db->query('SET block_encryption_mode = ' . $db->escape($mode));
        $db->query("SET @init_vector = RANDOM_BYTES(16)");
    }
}
