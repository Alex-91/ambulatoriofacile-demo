<?php
// Explicit opt-in; requires a disposable MySQL on loopback port 13368, never .env.
if (($argv[1] ?? '') !== '--isolated-local-mysql') exit('Use --isolated-local-mysql on a disposable server only.');
require __DIR__ . '/_support/billing_port_bootstrap.php';
require APPPATH . 'Database/Migrations/2026-10-08-100001_ProtectBillingNumbering.php';
use App\Services\BillingNumberingService;
use App\Database\Migrations\ProtectBillingNumbering;
use Config\Database;
function mysqlNumberingConnection(string $name = '') {
    return Database::connect(['DBDriver'=>'MySQLi','hostname'=>'127.0.0.1','port'=>13368,'username'=>'root','password'=>'','database'=>$name,'DBPrefix'=>'','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci'], false);
}
function mysqlNumberingIssue($db, string $date, array $template = [], bool $commit = true): string {
    $db->transBegin();
    try {
        $numbering = new BillingNumberingService();
        $numbering->lock($db);
        $number = $numbering->allocate($db, $template, $date);
        $db->table('billing_documents')->insert(['document_number'=>$number,'issue_date'=>$date]);
        if (!$db->transStatus()) throw new RuntimeException('Write failed');
        $commit ? $db->transCommit() : $db->transRollback();
        return $number;
    } catch (Throwable $e) { $db->transRollback(); throw $e; }
}
function mysqlNumberingCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if (($argv[2] ?? '') === 'worker') {
    $name = $argv[3] ?? '';
    if (!preg_match('/^af_numbering_test_[a-f0-9]{16}$/D', $name)) exit(2);
    $db = mysqlNumberingConnection($name);
    for ($i=0;$i<20;$i++) mysqlNumberingIssue($db,'2026-10-08');
    exit(0);
}
$name = 'af_numbering_test_'.bin2hex(random_bytes(8));
$admin = mysqlNumberingConnection();
$admin->query('CREATE DATABASE '.$name);
try {
    $db = mysqlNumberingConnection($name);
    $db->query('CREATE TABLE billing_documents (id INT AUTO_INCREMENT PRIMARY KEY, document_number VARCHAR(32) NOT NULL, issue_date DATE NOT NULL) ENGINE=InnoDB');
    (new ProtectBillingNumbering(Database::forge($db)))->up();
    $workers=[];
    for ($i=0;$i<4;$i++) {
        $pipes=[];
        $p=proc_open([PHP_BINARY,'-d','xdebug.mode=off',__FILE__,'--isolated-local-mysql','worker',$name],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]); $workers[]=[$p,$pipes];
    }
    foreach ($workers as [$p,$pipes]) {
        $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        mysqlNumberingCheck(proc_close($p)===0,'Worker failed: '.$out);
    }
    mysqlNumberingCheck($db->table('billing_documents')->countAllResults()===80,'Missing concurrent invoices');
    mysqlNumberingCheck(mysqlNumberingIssue($db,'2026-10-08',[],false)==='FT-2026-0081','Wrong rollback number');
    mysqlNumberingCheck(mysqlNumberingIssue($db,'2026-10-08')==='FT-2026-0081','Rollback consumed number');
    mysqlNumberingCheck(mysqlNumberingIssue($db,'2027-01-01')==='FT-2027-0001','Annual reset failed');
    $continuous=['numbering'=>['mode'=>'continuous','digits'=>4]];
    mysqlNumberingCheck(mysqlNumberingIssue($db,'2027-01-01',$continuous)==='FT-0082','Continuous baseline wrong');
    mysqlNumberingCheck(mysqlNumberingIssue($db,'2028-01-01',$continuous)==='FT-0083','Continuous reset unexpectedly');
    try {
        $db->table('billing_documents')->insert(['document_number'=>'FT-2026-0001','issue_date'=>'2026-11-01']);
        throw new RuntimeException('Unique constraint did not reject duplicate');
    } catch (\CodeIgniter\Database\Exceptions\DatabaseException $expected) {}
    $db->close();
} finally { $admin->query('DROP DATABASE '.$name); $admin->close(); }
echo "PASS MySQL/InnoDB: 80 concurrent emissions, unique index, rollback, annual reset and continuous numbering\n";
