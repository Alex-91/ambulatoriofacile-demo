<?php
declare(strict_types=1);

/** Private, immutable baseline for the isolated public demo. Never connects to login. */
final class AlignedDemoSnapshot
{
    private const DATABASE = 'ambulatoriofacile_demo';
    private const FILE_ROOTS = ['writable/clinical-private/4', 'writable/uploads', 'writable/fse', 'writable/ts', 'writable/tenants/demo-ambulatoriofacile', '../upload'];
    private const EPHEMERAL = ['dap14_agenda_lock', 'dap16_auth_code', 'device_links', 'push_subscriptions', 'push_outbox', 'otp_delivery_logs', 'platform_user_access_tokens', 'patient_booking_otp', 'patient_booking_outbox'];
    private string $root;
    private string $home;
    private mysqli $db;

    public static function run(string $action, array $options = []): array
    {
        if (!in_array($action, ['capture', 'verify', 'restore'], true)) throw new RuntimeException('Azione snapshot non valida.');
        $instance = new self($options['rest_root'] ?? dirname(__DIR__));
        $lock = fopen($instance->home . '/operation.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Un reset demo è già in corso.');
        @chmod($instance->home . '/operation.lock', 0600);
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) { @chown($instance->home . '/operation.lock', 'www-data'); @chgrp($instance->home . '/operation.lock', 'www-data'); }
        try {
            $name = (string) ($options['baseline'] ?? self::env('DEMO_ALIGNED_BASELINE', 'master-aligned-20261023'));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{5,80}$/D', $name)) throw new RuntimeException('Nome snapshot non valido.');
            $path = $instance->home . '/' . $name;
            if ($action === 'capture') {
                $tenant = $instance->db->query("SELECT db_name,metadata_json FROM platform_tenants WHERE tenant_key='demo_ambulatoriofacile'")->fetch_assoc();
                if (!$tenant || $tenant['db_name'] !== self::DATABASE || (json_decode($tenant['metadata_json'] ?? '', true)['source'] ?? '') !== 'master-test-demo-alignment') throw new RuntimeException('La demo non risulta allineata al master.');
                if (file_exists($path)) throw new RuntimeException('La base demo esiste già e non viene sovrascritta.');
                $instance->capture($path, true);
            }
            $snapshot = $instance->load($path);
            if ($action === 'restore') return $instance->restore($snapshot, $path, (string) ($options['start_date'] ?? date('Y-m-d')));
            $instance->checkSchema($snapshot);
            return ['ok' => true, 'baseline' => $name, 'tables' => count($snapshot['tables']), 'files' => count($snapshot['files']), 'report_path' => $path . '/manifest.json'];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function __construct(string $root)
    {
        if (self::env('DB_DATABASE', self::env('database.default.database')) !== self::DATABASE || !in_array(strtolower(self::env('DEMO_SITE_ENABLED')), ['1', 'true', 'yes'], true)) throw new RuntimeException('Reset consentito soltanto sul database della demo pubblica.');
        $resolved = realpath($root);
        if ($resolved === false || is_link($root) || !is_dir($resolved . '/app') || !is_dir($resolved . '/writable')) throw new RuntimeException('Directory runtime demo non valida.');
        $this->root = str_replace('\\', '/', $resolved);
        $this->home = $this->root . '/writable/demo-baselines';
        self::directory($this->home);
        self::write($this->home . '/.htaccess', "Require all denied\n");
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->db = new mysqli(self::env('DB_HOST', self::env('database.default.hostname')), self::env('DB_USERNAME', self::env('database.default.username')), self::env('DB_PASSWORD', self::env('database.default.password')), self::DATABASE, (int) self::env('DB_PORT', self::env('database.default.port', '3306')));
        $this->db->query('SET character_set_results=binary');
    }

    private static function env(string $key, string $default = ''): string { $v = getenv($key); return $v === false || $v === '' ? $default : $v; }
    private static function id(string $value): string { if (!preg_match('/^[A-Za-z0-9_]+$/D', $value)) throw new RuntimeException('Identificativo DB non ammesso.'); return '`' . $value . '`'; }
    private static function directory(string $path): void
    {
        if (is_link($path)) throw new RuntimeException('Directory privata snapshot non disponibile.');
        if (!is_dir($path)) {
            $missing = []; $parent = $path;
            while (!is_dir($parent)) { if (is_link($parent) || dirname($parent) === $parent) throw new RuntimeException('Directory privata non valida.'); $missing[] = $parent; $parent = dirname($parent); }
            foreach (array_reverse($missing) as $new) {
                if (!mkdir($new, 0700)) throw new RuntimeException('Directory privata snapshot non disponibile.');
                if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) { @chown($new, 'www-data'); @chgrp($new, 'www-data'); }
            }
        }
        @chmod($path, 0700);
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) { @chown($path, 'www-data'); @chgrp($path, 'www-data'); }
    }
    private static function write(string $path, string $bytes): void
    {
        self::directory(dirname($path));
        if (is_link($path) || file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Scrittura snapshot incompleta.');
        @chmod($path, 0600);
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) { @chown($path, 'www-data'); @chgrp($path, 'www-data'); }
    }
    private function tables(): array
    {
        return $this->db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . self::DATABASE . "' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
    }
    private function columns(string $table): array { return $this->db->query('SHOW COLUMNS FROM ' . self::id($table))->fetch_all(MYSQLI_ASSOC); }
    private function files(string $directory): array
    {
        if (!is_dir($directory)) return [];
        if (is_link($directory)) throw new RuntimeException('Collegamento simbolico nello storage demo.');
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Collegamento simbolico nello storage demo.');
            if (!$entry->isFile()) continue;
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
            if (preg_match('#(^|/)(cache|logs|session|tmp|notifications|reminder_state)(/|$)#', $relative)) continue;
            $out[$relative] = $entry->getPathname();
        }
        ksort($out); return $out;
    }
    private function storageRoot(int $index): string
    {
        if (!isset(self::FILE_ROOTS[$index])) throw new RuntimeException('Radice storage non valida.');
        return $this->root . '/' . self::FILE_ROOTS[$index];
    }
    private function capture(string $path, bool $clean): void
    {
        self::directory($path);
        $snapshot = ['version' => 1, 'database' => self::DATABASE, 'anchor_date' => date('Y-m-d'), 'tables' => [], 'files' => []];
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            foreach ($this->tables() as $table) {
                $name = $table['TABLE_NAME']; $columns = $this->columns($name); $fields = [];
                foreach ($columns as $column) if (!str_contains($column['Extra'], 'GENERATED') || str_contains($column['Extra'], 'DEFAULT_GENERATED')) $fields[$column['Field']] = $column['Type'];
                $data = [];
                if (!$clean || !in_array($name, self::EPHEMERAL, true)) {
                    $select = [];
                    foreach ($fields as $field => $type) $select[] = self::id($field) . (preg_match('/^bit\(/i', $type) ? '+0' : '');
                    $result = $this->db->query('SELECT ' . implode(',', $select) . ' FROM ' . self::id($name));
                    while ($row = $result->fetch_row()) $data[] = array_map(static fn($v) => $v === null ? null : base64_encode((string) $v), $row);
                }
                $snapshot['tables'][$name] = ['engine' => $table['ENGINE'], 'fields' => $fields, 'rows' => $data];
            }
            foreach (self::FILE_ROOTS as $index => $_) foreach ($this->files($this->storageRoot($index)) as $relative => $source) {
                $bytes = file_get_contents($source);
                if ($bytes === false) throw new RuntimeException('File demo non leggibile.');
                $stored = 'files/' . $index . '/' . $relative;
                self::write($path . '/' . $stored, $bytes);
                if (!hash_equals(hash('sha256', $bytes), hash_file('sha256', $source))) throw new RuntimeException('File cambiato durante la copia.');
                $snapshot['files'][] = ['root' => $index, 'relative' => $relative, 'stored' => $stored, 'sha256' => hash('sha256', $bytes)];
            }
            $this->db->commit();
            $compressed = gzencode(json_encode($snapshot, JSON_THROW_ON_ERROR), 6);
            if ($compressed === false) throw new RuntimeException('Compressione snapshot fallita.');
            self::write($path . '/dataset.json.gz', $compressed);
            self::write($path . '/manifest.json', json_encode(['version' => 1, 'database' => self::DATABASE, 'sha256' => hash('sha256', $compressed), 'created_at' => date('c')], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    private function load(string $path): array
    {
        if (is_link($path) || is_link($path . '/dataset.json.gz') || is_link($path . '/manifest.json')) throw new RuntimeException('Percorso snapshot non ammesso.');
        $manifest = json_decode((string) @file_get_contents($path . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $compressed = @file_get_contents($path . '/dataset.json.gz');
        if (!is_string($compressed) || ($manifest['database'] ?? '') !== self::DATABASE || !hash_equals((string) ($manifest['sha256'] ?? ''), hash('sha256', $compressed))) throw new RuntimeException('Integrità della base demo non verificata.');
        $json = gzdecode($compressed);
        if ($json === false) throw new RuntimeException('Base demo illeggibile.');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($data['version'] ?? 0) !== 1 || ($data['database'] ?? '') !== self::DATABASE || !isset($data['tables']['platform_tenants'], $data['tables']['dap12_agenda_appuntamenti'])) throw new RuntimeException('Contenuto base demo non valido.');
        foreach ($data['tables'] as $name => $table) {
            self::id($name);
            foreach ($table['fields'] as $field => $_) self::id($field);
            foreach ($table['rows'] as $row) {
                if (count($row) !== count($table['fields'])) throw new RuntimeException('Riga snapshot incompleta.');
                foreach ($row as $value) if ($value !== null && base64_decode($value, true) === false) throw new RuntimeException('Riga snapshot non valida.');
            }
        }
        foreach ($data['files'] as $file) {
            if (!isset(self::FILE_ROOTS[$file['root']]) || $file['relative'] === '' || preg_match('#(^|/)\.{1,2}(/|$)|[\x00-\x1F\\\\:]#u', $file['relative']) || str_starts_with($file['relative'], '/') || $file['stored'] !== 'files/' . $file['root'] . '/' . $file['relative']) throw new RuntimeException('File snapshot non ammesso.');
            $source = $path . '/' . $file['stored'];
            if (!is_file($source) || is_link($source) || !hash_equals($file['sha256'], hash_file('sha256', $source))) throw new RuntimeException('Allegato della base demo danneggiato.');
        }
        return $data;
    }
    private function checkSchema(array $snapshot): void
    {
        $current = array_column($this->tables(), 'ENGINE', 'TABLE_NAME');
        if (array_keys($current) !== array_keys($snapshot['tables'])) throw new RuntimeException('Schema demo cambiato: preparare una nuova base prima del reset.');
        foreach ($snapshot['tables'] as $name => $table) {
            $columns = array_column($this->columns($name), null, 'Field');
            foreach ($table['fields'] as $field => $type) if (!isset($columns[$field]) || $columns[$field]['Type'] !== $type) throw new RuntimeException('Colonne demo cambiate: base da aggiornare.');
            foreach ($columns as $field => $column) if (!isset($table['fields'][$field]) && $column['Null'] === 'NO' && $column['Default'] === null && !preg_match('/GENERATED|auto_increment/i', $column['Extra'])) throw new RuntimeException('Nuova colonna obbligatoria: preparare una nuova base demo.');
            if ($current[$name] !== 'InnoDB' && (count($table['rows']) > 0 || (int) $this->db->query('SELECT COUNT(*) FROM ' . self::id($name))->fetch_row()[0] > 0)) throw new RuntimeException('Il reset richiede tabelle dati transazionali.');
        }
    }
    public static function weekShift(string $anchor, string $target): int
    {
        $zone = new DateTimeZone('Europe/Rome');
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, $zone); $b = DateTimeImmutable::createFromFormat('!Y-m-d', $target, $zone);
        if (!$a || !$b || $a->format('Y-m-d') !== $anchor || $b->format('Y-m-d') !== $target) throw new RuntimeException('Data reset non valida.');
        $a = $a->modify('monday this week'); $b = $b->modify('monday this week');
        return (int) $a->diff($b)->format('%r%a');
    }
    private function restore(array $snapshot, string $path, string $date): array
    {
        $shift = self::weekShift($snapshot['anchor_date'], $date);
        $this->checkSchema($snapshot);
        $run = date('Ymd-His') . '-' . bin2hex(random_bytes(4)); $backup = $this->home . '/backups/' . $run;
        $marker = $this->root . '/writable/demo-reset-in-progress';
        self::write($marker, date('c'));
        $cleanup = true; $committed = false;
        try {
            $this->capture($backup, false);
            $this->db->query('SET FOREIGN_KEY_CHECKS=0'); $this->db->begin_transaction();
            foreach ($snapshot['tables'] as $name => $table) {
                if ($table['engine'] !== 'InnoDB') continue;
                $this->db->query('DELETE FROM ' . self::id($name));
                $fields = array_keys($table['fields']);
                $shiftAgenda = $shift !== 0 && preg_match('/^(dap[0-9]+_agenda|dap13_visite_domiciliari|dap31_block_dom|dap37_block_memo|patient_booking_(absences|blocks)|patient_online_bookings)/', $name);
                foreach (array_chunk($table['rows'], 100) as $chunk) {
                    $values = [];
                    foreach ($chunk as $row) {
                        $cells = [];
                        foreach ($row as $i => $encoded) {
                            if ($encoded === null) { $cells[] = 'NULL'; continue; }
                            $bytes = base64_decode($encoded, true); $type = $table['fields'][$fields[$i]];
                            $sql = "UNHEX('" . bin2hex($bytes) . "')";
                            if (preg_match('/^bit\(/i', $type)) { $cells[] = 'CAST(' . $sql . ' AS UNSIGNED)'; continue; }
                            if ($shiftAgenda && preg_match('/^(date|datetime|timestamp)\b/i', $type) && !in_array($fields[$i], ['created_at', 'updated_at', 'deleted_at'], true)) $sql = 'DATE_ADD(' . $sql . ',INTERVAL ' . $shift . ' DAY)';
                            $cells[] = strtolower($type) === 'json' ? 'CONVERT(' . $sql . ' USING utf8mb4)' : $sql;
                        }
                        $values[] = '(' . implode(',', $cells) . ')';
                    }
                    $this->db->query('INSERT INTO ' . self::id($name) . ' (' . implode(',', array_map([self::class, 'id'], $fields)) . ') VALUES ' . implode(',', $values));
                }
                if (in_array($name, self::EPHEMERAL, true)) $this->db->query('DELETE FROM ' . self::id($name));
                elseif ((int) $this->db->query('SELECT COUNT(*) FROM ' . self::id($name))->fetch_row()[0] !== count($table['rows'])) throw new RuntimeException('Conteggi ripristino demo non validi.');
            }
            $cleanup = false;
            $this->restoreFiles($snapshot, $path);
            $window = $this->db->query('SELECT MIN(data_slot) start_date,MAX(data_slot) end_date FROM dap11_agenda_slot')->fetch_assoc();
            $result = ['ok' => true, 'baseline' => basename($path), 'shift_days' => $shift, 'report' => ['summary' => ['agenda_window' => $window, 'agenda_slots' => count($snapshot['tables']['dap11_agenda_slot']['rows'] ?? []), 'agenda_appointments' => count($snapshot['tables']['dap12_agenda_appuntamenti']['rows'])]], 'report_path' => $backup . '/reset-report.json'];
            self::write($result['report_path'], json_encode($result, JSON_THROW_ON_ERROR));
            $this->db->commit(); $committed = true; $cleanup = true;
            $this->db->query('SET FOREIGN_KEY_CHECKS=1');
            foreach (['writable/session', 'writable/tenants/demo-ambulatoriofacile/session'] as $relative) {
                $directory = $this->root . '/' . $relative;
                if (is_dir($directory) && !is_link($directory)) foreach (new DirectoryIterator($directory) as $file) if ($file->isFile() && !$file->isLink() && $file->getFilename() !== 'index.html') @unlink($file->getPathname());
            }
            return $result;
        } catch (Throwable $error) {
            if ($committed) throw new RuntimeException('Dati demo ripristinati; controllo finale da ripetere.', 0, $error);
            $this->db->rollback(); $this->db->query('SET FOREIGN_KEY_CHECKS=1');
            if (is_file($backup . '/manifest.json')) { $before = $this->load($backup); $this->restoreFiles($before, $backup); $cleanup = true; }
            throw new RuntimeException('Reset demo annullato; dati precedenti preservati (codice ' . (int) $error->getCode() . ').', 0, $error);
        } finally { if ($cleanup && is_file($marker)) unlink($marker); }
    }
    private function restoreFiles(array $snapshot, string $path): void
    {
        $expected = [];
        foreach ($snapshot['files'] as $file) {
            $target = $this->storageRoot($file['root']) . '/' . $file['relative'];
            self::write($target, (string) file_get_contents($path . '/' . $file['stored']));
            $expected[$file['root']][$file['relative']] = true;
        }
        foreach (self::FILE_ROOTS as $index => $_) foreach ($this->files($this->storageRoot($index)) as $relative => $file) if (!isset($expected[$index][$relative]) && !unlink($file)) throw new RuntimeException('Impossibile rimuovere un allegato di prova.');
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    date_default_timezone_set('Europe/Rome');
    try {
        $options = [];
        foreach (array_slice($argv, 2) as $arg) if (preg_match('/^--(baseline|start-date)=(.+)$/D', $arg, $m)) $options[str_replace('-', '_', $m[1])] = $m[2];
        echo json_encode(AlignedDemoSnapshot::run($argv[1] ?? 'verify', $options), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } catch (Throwable $e) { fwrite(STDERR, 'Snapshot demo fallito: ' . $e->getMessage() . PHP_EOL); exit(1); }
}
