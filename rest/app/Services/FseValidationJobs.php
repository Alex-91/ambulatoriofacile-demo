<?php

namespace App\Services;

/** Local-filesystem locks: one shared private root per PHP host, never an NFS semaphore. */
final class FseValidationJobs
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $base = realpath(WRITEPATH);
        if ($base === false) throw new \RuntimeException('Area privata FSE non disponibile.');
        $root ??= $base . '/fse2/validation-jobs';
        $normalized = str_replace('\\', '/', $root);
        $prefix = rtrim(str_replace('\\', '/', $base), '/') . '/';
        if (!str_starts_with($normalized, $prefix) || str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
            throw new \RuntimeException('Area job FSE esterna allo storage privato.');
        }
        $current = $base;
        foreach (explode('/', substr($normalized, strlen($prefix))) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new \RuntimeException('Percorso job FSE non valido.');
            $current .= '/' . $part;
            if (is_link($current)) throw new \RuntimeException('Collegamenti non ammessi nello storage job FSE.');
            if (!is_dir($current) && !@mkdir($current, 0700) && !is_dir($current)) throw new \RuntimeException('Storage job FSE non scrivibile.');
        }
        $this->root = (string) realpath($current);
    }

    /** @return resource The caller owns this lease until every worker handle is closed. */
    public function acquire(int $limit)
    {
        for ($i = 0; $i < max(1, min(8, $limit)); $i++) {
            $lease = $this->openLock($this->root . '/slot-' . $i . '.lock');
            if (flock($lease, LOCK_EX | LOCK_NB)) return $lease;
            fclose($lease);
        }
        throw new \RuntimeException('Validatore FSE occupato: riprovare tra poco. Nessun invio eseguito.');
    }

    /** @return array{directory:string,lease:resource} */
    public function create(): array
    {
        $directory = $this->root . '/' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Creazione job FSE non riuscita.');
        $lease = $this->openLock($directory . '/.active.lock');
        if (!flock($lease, LOCK_EX | LOCK_NB)) { fclose($lease); throw new \RuntimeException('Lock job FSE non disponibile.'); }
        return ['directory' => $directory, 'lease' => $lease];
    }

    /** Never unlink semaphore files: changing their inode would bypass concurrent locks. */
    public static function release($lease): void
    {
        if (is_resource($lease)) { flock($lease, LOCK_UN); fclose($lease); }
    }

    /** Only expired, unlocked, recognized job directories. No recursive deletion, no clinical storage. */
    public function cleanup(bool $apply = false, int $ageSeconds = 86400, ?int $now = null): array
    {
        if ($ageSeconds < 3600) throw new \InvalidArgumentException('Retention job FSE minima: un’ora.');
        $now ??= time();
        $result = ['examined' => 0, 'eligible' => 0, 'removed' => 0, 'skipped' => 0, 'errors' => 0, 'dry_run' => !$apply];
        $maintenance = $this->openLock($this->root . '/maintenance.lock');
        if (!flock($maintenance, LOCK_EX | LOCK_NB)) { fclose($maintenance); throw new \RuntimeException('Manutenzione job FSE già in corso.'); }
        try {
            foreach (new \DirectoryIterator($this->root) as $entry) {
                if (!preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename())) continue;
                if (++$result['examined'] > 500) break;
                $path = $entry->getPathname();
                if ($entry->isLink() || !$entry->isDir() || $entry->getMTime() > $now - $ageSeconds) { $result['skipped']++; continue; }
                $names = array_values(array_diff(scandir($path), ['.', '..']));
                $known = ['input.json', 'output.json', 'stderr.txt', '.active.lock'];
                if (array_diff($names, $known) || !in_array('.active.lock', $names, true)) { $result['skipped']++; continue; }
                foreach ($names as $name) {
                    if (is_link($path . '/' . $name) || !is_file($path . '/' . $name) || filemtime($path . '/' . $name) > $now - $ageSeconds) {
                        $result['skipped']++; continue 2;
                    }
                }
                $lease = $this->openLock($path . '/.active.lock');
                if (!flock($lease, LOCK_EX | LOCK_NB)) { fclose($lease); $result['skipped']++; continue; }
                try {
                    $result['eligible']++;
                    if (!$apply) continue;
                    foreach (['input.json', 'output.json', 'stderr.txt'] as $name) {
                        if (is_file($path . '/' . $name) && !@unlink($path . '/' . $name)) { $result['errors']++; continue 2; }
                    }
                } finally { self::release($lease); }
                if ($apply) {
                    if (@unlink($path . '/.active.lock') && @rmdir($path)) $result['removed']++;
                    else $result['errors']++;
                }
            }
        } finally { self::release($maintenance); }
        return $result;
    }

    private function openLock(string $path)
    {
        if (is_link($path)) throw new \RuntimeException('Lock FSE non sicuro.');
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) throw new \RuntimeException('Lock FSE non disponibile.');
        @chmod($path, 0600);
        return $handle;
    }
}
