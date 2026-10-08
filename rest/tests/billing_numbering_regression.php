<?php
require __DIR__ . '/_support/billing_port_bootstrap.php';
require APPPATH . 'Database/Migrations/2026-10-08-100001_ProtectBillingNumbering.php';

use App\Services\BillingNumberingService;
use App\Database\Migrations\ProtectBillingNumbering;
use Config\Database;

function verifyNumbering(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function numberingDb(string $path = ':memory:') {
    return Database::connect(['DBDriver'=>'SQLite3', 'database'=>$path, 'DBPrefix'=>'', 'DBDebug'=>true, 'busyTimeout'=>15000], false);
}
function issueNumber($db, array $config, string $date, bool $commit = true): string {
    $db->transBegin();
    $service = new BillingNumberingService();
    $service->lock($db);
    $number = $service->allocate($db, $config, $date);
    $db->table('billing_documents')->insert(['document_number'=>$number, 'issue_date'=>$date]);
    $commit ? $db->transCommit() : $db->transRollback();
    return $number;
}
if (($argv[1] ?? '') === 'worker') {
    $db = numberingDb($argv[2]);
    for ($i=0; $i<15; $i++) issueNumber($db, [], '2026-10-08');
    exit(0);
}
$db = numberingDb();
$db->query('CREATE TABLE billing_documents (id INTEGER PRIMARY KEY, document_number VARCHAR(32) NOT NULL, issue_date DATE)');
$migration = new ProtectBillingNumbering(Database::forge($db));
$migration->up();
$migration->up();
verifyNumbering(issueNumber($db, [], '2026-12-31') === 'FT-2026-0001', 'First annual number');
verifyNumbering(issueNumber($db, [], '2027-01-01') === 'FT-2027-0001', 'Annual reset');
verifyNumbering(issueNumber($db, [], '2026-12-31') === 'FT-2026-0002', 'Backdated year retains counter');
issueNumber($db, [], '2027-01-01', false);
verifyNumbering(issueNumber($db, [], '2027-01-01') === 'FT-2027-0002', 'Rollback restores counter');
$continuous = ['numbering'=>['mode'=>'continuous','digits'=>2]];
verifyNumbering(issueNumber($db, $continuous, '2026-12-31') === 'FT-03', 'Continuous starts above historical maximum');
verifyNumbering(issueNumber($db, $continuous, '2027-01-01') === 'FT-04', 'Continuous crosses year');
verifyNumbering(issueNumber($db, ['document_code_prefix'=>'ABC'] + $continuous, '2027-01-01') === 'ABC-05', 'Prefix does not reset');
$db->table('billing_documents')->insert(['document_number'=>'FT-2028-0001','issue_date'=>'2028-01-01']);
verifyNumbering(issueNumber($db, [], '2028-01-01') === 'FT-2028-0002', 'Legacy collision skipped');
try {
    $db->table('billing_documents')->insert(['document_number'=>'FT-2026-0001','issue_date'=>'2026-11-01']);
    throw new RuntimeException('Database accepted duplicate on another date');
} catch (\CodeIgniter\Database\Exceptions\DatabaseException $expected) {}
foreach ([['mode'=>'invalid'], ['digits'=>0], ['digits'=>9], ['digits'=>'x']] as $invalid) {
    try { BillingNumberingService::config($invalid); throw new LogicException('Invalid settings accepted'); }
    catch (RuntimeException $expected) {}
}
$dirty = numberingDb();
$dirty->query('CREATE TABLE billing_documents (document_number VARCHAR(32), issue_date DATE)');
$dirty->query("INSERT INTO billing_documents VALUES ('X', '2026-01-01'), ('X', '2026-02-01')");
try { (new ProtectBillingNumbering(Database::forge($dirty)))->up(); throw new LogicException('Migration accepted duplicates'); }
catch (RuntimeException $expected) {}
verifyNumbering($dirty->table('billing_documents')->countAllResults() === 2, 'Legacy documents preserved');

// Independent processes race through the same real database and transaction boundaries.
$path = tempnam(sys_get_temp_dir(), 'billing-numbering-');
try {
    $parallel = numberingDb($path);
    $parallel->query('CREATE TABLE billing_documents (id INTEGER PRIMARY KEY, document_number VARCHAR(32) NOT NULL, issue_date DATE)');
    (new ProtectBillingNumbering(Database::forge($parallel)))->up();
    $workers = [];
    for ($i=0; $i<4; $i++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __FILE__, 'worker', $path], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        verifyNumbering(proc_close($process) === 0, 'Concurrent worker failed: '.$output);
    }
    verifyNumbering($parallel->table('billing_documents')->countAllResults() === 60, 'All concurrent writes persisted');
    verifyNumbering((int) $parallel->table('billing_numbering_counters')->get()->getRowArray()['last_number'] === 60, 'No duplicate or lost progressives');
    $parallel->close();
} finally { unlink($path); }
echo "PASS: annual/continuous, rollback, legacy collisions, database uniqueness, invalid config, migration refusal, 60 concurrent emissions\n";
