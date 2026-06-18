<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Pulisce la migrazione "vecchio script" che ha salvato gli actor id come dap01_users.id_user
 * invece di usare gli actor id corretti del nuovo modulo.
 *
 * Default: DRY RUN
 * Applica davvero solo con: --apply
 *
 * Esempi:
 *   php allinea_cleanup_old_migration.php --doctors=22
 *   php allinea_cleanup_old_migration.php --doctors=22,35 --apply
 */

$DB = [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => 'root',
    'db'   => 'mail',
];

$DEFAULT_DOCTORS = [22];

function normalizeDoctorIds($configured, array $argv): array
{
    $raw = $configured;

    if (PHP_SAPI !== 'cli' && isset($_GET['doctors'])) {
        $raw = (string)$_GET['doctors'];
    }

    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if (preg_match('/^--doctors?=(.+)$/i', $arg, $m)) {
            $raw = $m[1];
        }
    }

    if (is_int($raw)) {
        $raw = [$raw];
    } elseif (is_string($raw)) {
        $raw = preg_split('/[\s,;|]+/', trim($raw)) ?: [];
    } elseif (!is_array($raw)) {
        $raw = [];
    }

    $ids = [];
    foreach ($raw as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    ksort($ids);
    return array_values($ids);
}

function hasFlag(array $argv, string $flag): bool
{
    if (PHP_SAPI !== 'cli') {
        if ($flag === '--apply' && isset($_GET['apply'])) {
            return in_array((string)$_GET['apply'], ['1', 'true', 'yes', 'on'], true);
        }
    }

    foreach ($argv as $arg) {
        if ((string)$arg === $flag) {
            return true;
        }
    }
    return false;
}

function csvInts(array $ids): string
{
    return implode(',', array_map(static fn ($v) => (string)(int)$v, $ids));
}

function setAdd(array &$set, int $value): void
{
    if ($value > 0) {
        $set[$value] = $value;
    }
}

function loadOldUserScope(mysqli $db, array $doctorIds): array
{
    $scope = [
        'doctor_personale_ids' => [],
        'doctor_user_ids'      => [],
        'patient_user_ids'     => [],
        'seg_user_ids'         => [],
        'inf_user_ids'         => [],
        'all_user_ids'         => [],
    ];

    foreach ($doctorIds as $doctorId) {
        setAdd($scope['doctor_personale_ids'], $doctorId);
    }

    if (!$doctorIds) {
        return $scope;
    }

    $inDoctors = csvInts($doctorIds);

    $res = $db->query("
        SELECT id_personale, id_user
        FROM dap03_personale
        WHERE id_personale IN ({$inDoctors})
    ");
    while ($row = $res->fetch_assoc()) {
        $doctorId = (int)$row['id_personale'];
        $userId   = (int)($row['id_user'] ?? 0);
        setAdd($scope['doctor_personale_ids'], $doctorId);
        setAdd($scope['doctor_user_ids'], $userId);
        setAdd($scope['all_user_ids'], $userId);
    }

    $res = $db->query("
        SELECT id_user
        FROM dap02_clients
        WHERE id_personale IN ({$inDoctors})
          AND id_user IS NOT NULL
          AND id_user > 0
    ");
    while ($row = $res->fetch_assoc()) {
        $userId = (int)$row['id_user'];
        setAdd($scope['patient_user_ids'], $userId);
        setAdd($scope['all_user_ids'], $userId);
    }

    $res = $db->query("
        SELECT p.id_user
        FROM dap14_seg_dot sd
        INNER JOIN dap03_personale p ON p.id_personale = sd.id_seg
        WHERE sd.id_dot IN ({$inDoctors})
          AND p.id_user IS NOT NULL
          AND p.id_user > 0
    ");
    while ($row = $res->fetch_assoc()) {
        $userId = (int)$row['id_user'];
        setAdd($scope['seg_user_ids'], $userId);
        setAdd($scope['all_user_ids'], $userId);
    }

    $res = $db->query("
        SELECT p.id_user
        FROM dap15_inf_dot idt
        INNER JOIN dap03_personale p ON p.id_personale = idt.id_inf
        WHERE idt.id_dot IN ({$inDoctors})
          AND p.id_user IS NOT NULL
          AND p.id_user > 0
    ");
    while ($row = $res->fetch_assoc()) {
        $userId = (int)$row['id_user'];
        setAdd($scope['inf_user_ids'], $userId);
        setAdd($scope['all_user_ids'], $userId);
    }

    return $scope;
}

$doctorIds = normalizeDoctorIds($DEFAULT_DOCTORS, $_SERVER['argv'] ?? []);
$apply     = hasFlag($_SERVER['argv'] ?? [], '--apply');

if (!$doctorIds) {
    throw new RuntimeException('Nessun dottore configurato');
}

$db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db']);
$db->set_charset('utf8');

$scope = loadOldUserScope($db, $doctorIds);
$actorUserIds = array_values($scope['all_user_ids']);

if (!$actorUserIds) {
    throw new RuntimeException('Impossibile ricavare gli id_user legacy del scope selezionato');
}

$inActorUsers = csvInts($actorUserIds);

echo "== CLEANUP VECCHIA MIGRAZIONE ==\n";
echo "Dottori (id_personale): " . implode(',', $doctorIds) . "\n";
echo "Doctor id_user scope  : " . implode(',', array_values($scope['doctor_user_ids'])) . "\n";
echo "Actor user ids scope  : " . count($actorUserIds) . "\n";
echo "Modalità              : " . ($apply ? 'APPLY' : 'DRY RUN') . "\n";

$db->query("DROP TEMPORARY TABLE IF EXISTS tmp_bad_msg");
$db->query("DROP TEMPORARY TABLE IF EXISTS tmp_bad_thread");
$db->query("DROP TEMPORARY TABLE IF EXISTS tmp_bad_att");

$db->query("
    CREATE TEMPORARY TABLE tmp_bad_msg (
        id_message bigint unsigned NOT NULL PRIMARY KEY,
        id_thread  bigint unsigned NOT NULL
    ) ENGINE=Memory
");

$db->query("
    INSERT IGNORE INTO tmp_bad_msg (id_message, id_thread)
    SELECT DISTINCT m.id_message, m.id_thread
    FROM msg_messages m
    INNER JOIN msg_migration_map mm
        ON mm.new_id = m.id_message
       AND mm.old_table IN ('dap10_message', 'dap10_message_reply')
       AND (mm.map_key LIKE 'USER:%' OR mm.map_key LIKE 'ROLE:%')
    WHERE (
        m.sender_user_id IN ({$inActorUsers})
        OR COALESCE(m.recipient_user_id, -1) IN ({$inActorUsers})
        OR m.root_author_user_id IN ({$inActorUsers})
    )
");

$db->query("
    CREATE TEMPORARY TABLE tmp_bad_thread (
        id_thread bigint unsigned NOT NULL PRIMARY KEY
    ) ENGINE=Memory
");
$db->query("
    INSERT IGNORE INTO tmp_bad_thread (id_thread)
    SELECT DISTINCT id_thread
    FROM tmp_bad_msg
");

$db->query("
    CREATE TEMPORARY TABLE tmp_bad_att (
        id_attachment bigint unsigned NOT NULL PRIMARY KEY
    ) ENGINE=Memory
");
$db->query("
    INSERT IGNORE INTO tmp_bad_att (id_attachment)
    SELECT DISTINCT a.id_attachment
    FROM msg_attachments a
    INNER JOIN tmp_bad_msg bm ON bm.id_message = a.id_message
");

$counts = [
    'messages' => (int)($db->query("SELECT COUNT(*) AS c FROM tmp_bad_msg")->fetch_assoc()['c'] ?? 0),
    'threads'  => (int)($db->query("SELECT COUNT(*) AS c FROM tmp_bad_thread")->fetch_assoc()['c'] ?? 0),
    'attach'   => (int)($db->query("SELECT COUNT(*) AS c FROM tmp_bad_att")->fetch_assoc()['c'] ?? 0),
    'flags'    => (int)($db->query("
        SELECT COUNT(*) AS c
        FROM msg_user_flags f
        INNER JOIN tmp_bad_msg bm ON bm.id_message = f.id_message
    ")->fetch_assoc()['c'] ?? 0),
    'maps_msg' => (int)($db->query("
        SELECT COUNT(*) AS c
        FROM msg_migration_map mm
        INNER JOIN tmp_bad_msg bm ON bm.id_message = mm.new_id
        WHERE mm.old_table IN ('dap10_message', 'dap10_message_reply')
          AND (mm.map_key LIKE 'USER:%' OR mm.map_key LIKE 'ROLE:%')
    ")->fetch_assoc()['c'] ?? 0),
    'maps_att' => (int)($db->query("
        SELECT COUNT(*) AS c
        FROM msg_migration_map mm
        INNER JOIN tmp_bad_att ba ON ba.id_attachment = mm.new_id
        WHERE mm.old_table = 'dap11_attachments'
    ")->fetch_assoc()['c'] ?? 0),
];

echo "Messaggi candidati   : {$counts['messages']}\n";
echo "Thread candidati     : {$counts['threads']}\n";
echo "Allegati candidati   : {$counts['attach']}\n";
echo "Flags candidati      : {$counts['flags']}\n";
echo "Map msg candidati    : {$counts['maps_msg']}\n";
echo "Map att candidati    : {$counts['maps_att']}\n";

$sampleSql = "
    SELECT m.id_message, m.id_thread, m.sender_user_id, m.recipient_user_id, m.recipient_role, m.root_author_user_id, m.created_at
    FROM msg_messages m
    INNER JOIN tmp_bad_msg bm ON bm.id_message = m.id_message
    ORDER BY m.id_message DESC
    LIMIT 10
";
$res = $db->query($sampleSql);
echo "\nSample messaggi da rimuovere:\n";
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

if (!$apply) {
    echo "\nDry run completato. Nessun dato cancellato.\n";
    echo "Per applicare davvero: php allinea_cleanup_old_migration.php --doctors=" . implode(',', $doctorIds) . " --apply\n";
    exit(0);
}

if ($counts['messages'] === 0) {
    echo "\nNessun record da cancellare.\n";
    exit(0);
}

$db->begin_transaction();

try {
    $db->query("
        DELETE mm
        FROM msg_migration_map mm
        INNER JOIN tmp_bad_att ba ON ba.id_attachment = mm.new_id
        WHERE mm.old_table = 'dap11_attachments'
    ");

    $db->query("
        DELETE mm
        FROM msg_migration_map mm
        INNER JOIN tmp_bad_msg bm ON bm.id_message = mm.new_id
        WHERE mm.old_table IN ('dap10_message', 'dap10_message_reply')
          AND (mm.map_key LIKE 'USER:%' OR mm.map_key LIKE 'ROLE:%')
    ");

    // delete espliciti: anche se molti cascaderanno, qui restiamo chiari e misurabili
    $db->query("
        DELETE f
        FROM msg_user_flags f
        INNER JOIN tmp_bad_msg bm ON bm.id_message = f.id_message
    ");

    $db->query("
        DELETE a
        FROM msg_attachments a
        INNER JOIN tmp_bad_msg bm ON bm.id_message = a.id_message
    ");

    $db->query("
        DELETE m
        FROM msg_messages m
        INNER JOIN tmp_bad_msg bm ON bm.id_message = m.id_message
    ");

    $db->query("
        DELETE t
        FROM msg_threads t
        INNER JOIN tmp_bad_thread bt ON bt.id_thread = t.id_thread
        LEFT JOIN msg_messages m ON m.id_thread = t.id_thread
        WHERE m.id_message IS NULL
    ");

    $db->commit();
    echo "\nCleanup completato.\n";
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
