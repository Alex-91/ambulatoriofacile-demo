<?php
namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Capability installed only by the private CLI lab bootstrap, never by a request or .env. */
class FseSyntheticAppBoundary
{
    public function isActive(): bool
    {
        if (!defined('FSE_SYNTHETIC_APP_LAB_BOOT') || !defined('ENVIRONMENT') || ENVIRONMENT !== 'development') return false;
        $root = realpath((string) FSE_SYNTHETIC_APP_LAB_BOOT);
        $base = realpath(ROOTPATH . 'writable/fse-app-labs');
        if (!$root || !$base || dirname($root) !== $base || !preg_match('/^[a-f0-9]{32}$/D', basename($root))) return false;
        if (realpath(WRITEPATH) !== realpath($root . '/writable')) return false;
        try {
            $marker = json_decode((string) file_get_contents($root . '/lab.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) { return false; }
        return ($marker['mode'] ?? '') === 'FSE_SYNTHETIC_APP_LAB'
            && ($marker['port'] ?? 0) === 33079 && ($marker['web_port'] ?? 0) === 8088
            && getenv('FSE2_ALLOW_PRODUCTION') === 'false' && getenv('FSE2_ALLOW_TOSCANA_STAGE') === 'false';
    }

    public function assertDatabase(int $tenant, BaseConnection $db): void
    {
        if (!$this->isActive() || !in_array($tenant, [42, 43], true) || $db->DBDriver !== 'MySQLi'
            || $db->hostname !== '127.0.0.1' || (int) $db->port !== 33079
            || $db->database !== ($tenant === 42 ? 'fselab_a' : 'fselab_b')) throw new \RuntimeException('LAB_BOUNDARY');
        // A different process on the same port is not sufficient: verify its datadir too.
        $data = realpath((string) ($db->query('SELECT @@datadir AS path')->getRowArray()['path'] ?? ''));
        if (!$data || $data !== realpath(FSE_SYNTHETIC_APP_LAB_BOOT . '/mysql')) throw new \RuntimeException('LAB_BOUNDARY');
    }
}
