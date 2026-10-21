<?php
require __DIR__ . '/billing_numbering_regression.php';
require APPPATH . 'Database/Migrations/2026-10-21-100001_AddBillingSoftDeletion.php';

$db = numberingDb();
$db->query('CREATE TABLE billing_documents (id_billing_document INTEGER PRIMARY KEY AUTOINCREMENT, document_number VARCHAR(32) NOT NULL, issue_date DATE, local_state TEXT, updated_by INT, created_at DATETIME, updated_at DATETIME)');
(new App\Database\Migrations\ProtectBillingNumbering(Config\Database::forge($db)))->up();
$migration = new App\Database\Migrations\AddBillingSoftDeletion(Config\Database::forge($db));
$migration->up();
$migration->up();
$number = issueNumber($db, [], '2026-10-21');
$db->table('billing_documents')->update(['local_state'=>'issued']);
$model = new App\Models\BillingDocumentModel($db);
$context = new class($db, $model) extends App\Services\BillingTenantDatabaseContextService {
    public function __construct(private $testDb, private $testModel) {}
    public function resolveTenantContext(int $tenantId = 0): array {
        return ['db'=>$this->testDb, 'documents'=>$this->testModel];
    }
};
$reflection = new ReflectionClass(App\Services\BillingTsBridgeService::class);
$bridge = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('billingContext')->setValue($bridge, $context);
$bridge->deleteBillingDocument(42, 1, 7);
verifyNumbering($model->find(1) === null, 'Deleted invoice hidden from normal access');
$archived = $model->withDeleted()->find(1);
verifyNumbering($archived['document_number'] === $number && (int)$archived['updated_by'] === 7 && $archived['deleted_at'] !== null, 'Original number, author and deletion timestamp retained');
verifyNumbering(issueNumber($db, [], '2026-10-21') === 'FT-2026-0002', 'Deletion never rewinds numbering');
try {
    $db->table('billing_documents')->insert(['document_number'=>$number,'issue_date'=>'2026-10-21']);
    throw new LogicException('Archived number reused');
} catch (CodeIgniter\Database\Exceptions\DatabaseException $expected) {}
$continuous = ['numbering'=>['mode'=>'continuous','digits'=>4]];
verifyNumbering(issueNumber($db, $continuous, '2026-10-21') === 'FT-0003', 'Continuous starts above archived history');
verifyNumbering(issueNumber($db, [], '2026-10-21') === 'FT-2026-0004', 'Returning to annual never resumes stale counter');
verifyNumbering(issueNumber($db, $continuous, '2026-10-21') === 'FT-0005', 'Returning to continuous never resumes stale counter');
echo "PASS: service deletion, retained fiscal record, unique archived number, unchanged progress and mode switching\n";
