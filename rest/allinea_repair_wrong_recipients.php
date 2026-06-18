<?php
set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Ripara i messaggi migrati dal legacy legati a un paziente che sono finiti
 * nel mailbox medico/staff sbagliato nel nuovo modulo.
 *
 * Considera sia i root legacy (dap10_message) sia le reply legacy
 * (dap10_message_reply), ma in modalita' automatica corregge solo i casi
 * in cui il destinatario legacy (id_dest) e' un medico reale.
 *
 * I casi dove id_dest punta a segreteria/infermiere vengono esclusi dal
 * repair massivo per evitare riallineamenti ambigui del contesto dottore.
 *
 * Default: DRY RUN
 * Applica davvero solo con: --apply
 *
 * Filtri opzionali:
 *   --wrong-recipients=71,22
 *   --expected-doctors=23,8,33
 *   --patients=93,77
 *   --verbose
 *
 * Esempi:
 *   php allinea_repair_wrong_recipients.php
 *   php allinea_repair_wrong_recipients.php --wrong-recipients=71
 *   php allinea_repair_wrong_recipients.php --wrong-recipients=71 --apply
 */

/*$DB = [
    'host' => '127.0.0.1',
    'user' => 'root',
    'pass' => 'root',
    'db'   => 'mail',
];*/

$DB = [
    'host' => '89.46.111.163',
    'user' => 'Sql1688505',
    'pass' => 'Tira74GL!#',
    'db'   => 'Sql1688505_1',
];

const FLAG_SEG_OFFSET = 100000000;
const FLAG_INF_OFFSET = 200000000;

function hasFlag(array $argv, string $flag): bool
{
    if (PHP_SAPI !== 'cli') {
        $httpKey = ltrim(str_replace('-', '_', $flag), '_');
        if (isset($_GET[$httpKey])) {
            return in_array((string)$_GET[$httpKey], ['1', 'true', 'yes', 'on'], true);
        }
    }

    foreach ($argv as $arg) {
        if ((string)$arg === $flag) {
            return true;
        }
    }

    return false;
}

function optionValue(array $argv, string $name): ?string
{
    $httpKey = str_replace('-', '_', $name);

    if (PHP_SAPI !== 'cli' && isset($_GET[$httpKey])) {
        return trim((string)$_GET[$httpKey]);
    }

    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if (preg_match('/^--' . preg_quote($name, '/') . '=(.+)$/i', $arg, $m)) {
            return trim((string)$m[1]);
        }
    }

    return null;
}

function normalizeIdListOption(array $argv, string $name): array
{
    $raw = optionValue($argv, $name);
    if ($raw === null || $raw === '') {
        return [];
    }

    $values = preg_split('/[\s,;|]+/', trim($raw)) ?: [];
    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    ksort($ids);
    return array_values($ids);
}

function csvInts(array $ids): string
{
    return implode(',', array_map(static fn ($v) => (string)(int)$v, $ids));
}

function sqlNullableString(mysqli $db, ?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $db->real_escape_string($value) . "'";
}

function normalizeNullableString($value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function recipientRoleFromLegacyDest(string $destCode): ?string
{
    $destCode = strtoupper(trim($destCode));
    if ($destCode === 'S') {
        return 'SEGRETERIA';
    }
    if ($destCode === 'I') {
        return 'INFERMIERE';
    }

    return null;
}

function mailboxFlagUserIdsForDoctor(int $doctorId): array
{
    if ($doctorId <= 0) {
        return [];
    }

    return [
        $doctorId,
        FLAG_SEG_OFFSET + $doctorId,
        FLAG_INF_OFFSET + $doctorId,
    ];
}

function expectedMailboxFlagUserIds(int $doctorId, array $doctorScope): array
{
    if ($doctorId <= 0) {
        return [];
    }

    $targets = [$doctorId => $doctorId];

    if (!empty($doctorScope['has_seg'][$doctorId])) {
        $targets[FLAG_SEG_OFFSET + $doctorId] = FLAG_SEG_OFFSET + $doctorId;
    }
    if (!empty($doctorScope['has_inf'][$doctorId])) {
        $targets[FLAG_INF_OFFSET + $doctorId] = FLAG_INF_OFFSET + $doctorId;
    }

    ksort($targets);
    return array_values($targets);
}

function earlierDate(?string $current, ?string $candidate): ?string
{
    if ($candidate === null || $candidate === '') {
        return $current;
    }
    if ($current === null || $current === '') {
        return $candidate;
    }

    return strcmp($candidate, $current) < 0 ? $candidate : $current;
}

function loadDoctorRoleScope(mysqli $db, array $doctorIds): array
{
    $scope = [
        'has_seg' => [],
        'has_inf' => [],
    ];

    if (!$doctorIds) {
        return $scope;
    }

    $inDoctors = csvInts($doctorIds);

    $res = $db->query("
        SELECT DISTINCT id_dot
        FROM dap14_seg_dot
        WHERE id_dot IN ({$inDoctors})
    ");
    while ($row = $res->fetch_assoc()) {
        $doctorId = (int)$row['id_dot'];
        if ($doctorId > 0) {
            $scope['has_seg'][$doctorId] = true;
        }
    }

    $res = $db->query("
        SELECT DISTINCT id_dot
        FROM dap15_inf_dot
        WHERE id_dot IN ({$inDoctors})
    ");
    while ($row = $res->fetch_assoc()) {
        $doctorId = (int)$row['id_dot'];
        if ($doctorId > 0) {
            $scope['has_inf'][$doctorId] = true;
        }
    }

    return $scope;
}

function loadRepairCandidates(mysqli $db, array $filters): array
{
    $expectedRoleExpr = "
        CASE
            WHEN UPPER(TRIM(l.dest)) = 'S' THEN 'SEGRETERIA'
            WHEN UPPER(TRIM(l.dest)) = 'I' THEN 'INFERMIERE'
            ELSE NULL
        END
    ";

    $where = [
        "mm.old_table IN ('dap10_message', 'dap10_message_reply')",
        "mm.map_key = 'MESSAGE:CANON'",
        "UPPER(TRIM(l.dest)) IN ('P', 'D', 'M', 'S', 'I')",
        "pd.tipo = 1",
        "m.recipient_type = 'USER'",
        "m.recipient_user_id IS NOT NULL",
        "m.recipient_user_id > 0",
        "(m.recipient_user_id <> l.id_dest OR COALESCE(m.recipient_role, '') <> COALESCE({$expectedRoleExpr}, ''))",
    ];

    if (!empty($filters['wrong_recipients'])) {
        $where[] = "m.recipient_user_id IN (" . csvInts($filters['wrong_recipients']) . ")";
    }
    if (!empty($filters['expected_doctors'])) {
        $where[] = "l.id_dest IN (" . csvInts($filters['expected_doctors']) . ")";
    }
    if (!empty($filters['patients'])) {
        $where[] = "l.id_mitt IN (" . csvInts($filters['patients']) . ")";
    }

    $sql = "
        SELECT
            m.id_message AS new_message_id,
            m.id_thread,
            mm.old_table AS old_table,
            mm.old_id AS old_message_id,
            l.id_mitt AS patient_id,
            CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR(255) CHARACTER SET utf8mb4) AS patient_cognome,
            CAST(AES_DECRYPT(UNHEX(c.nome),    @key_str, c.vector_id) AS CHAR(255) CHARACTER SET utf8mb4) AS patient_nome,
            l.id_dest AS expected_doctor_id,
            CAST(AES_DECRYPT(UNHEX(pd.cognome), @key_str, pd.vector_id) AS CHAR(255) CHARACTER SET utf8mb4) AS expected_doctor_cognome,
            CAST(AES_DECRYPT(UNHEX(pd.nome),    @key_str, pd.vector_id) AS CHAR(255) CHARACTER SET utf8mb4) AS expected_doctor_nome,
            m.recipient_user_id AS current_recipient_user_id,
            CAST(AES_DECRYPT(UNHEX(cd.cognome), @key_str, cd.vector_id) AS CHAR(255) CHARACTER SET utf8mb4) AS current_recipient_cognome,
            CAST(AES_DECRYPT(UNHEX(cd.nome),    @key_str, cd.vector_id) AS CHAR(255) CHARACTER SET utf8mb4) AS current_recipient_nome,
            UPPER(TRIM(l.dest)) AS legacy_dest_code,
            m.recipient_role AS current_recipient_role,
            {$expectedRoleExpr} AS expected_recipient_role,
            l.dataora AS legacy_created_at
        FROM msg_migration_map mm
        INNER JOIN msg_messages m
            ON m.id_message = mm.new_id
        INNER JOIN (
            SELECT
                'dap10_message' AS old_table,
                id_message,
                id_mitt,
                id_dest,
                mitt,
                dest,
                dataora
            FROM dap10_message
            UNION ALL
            SELECT
                'dap10_message_reply' AS old_table,
                id_message,
                id_mitt,
                id_dest,
                mitt,
                dest,
                dataora
            FROM dap10_message_reply
        ) l
            ON l.old_table = mm.old_table
           AND l.id_message = mm.old_id
        INNER JOIN dap02_clients c
            ON c.id_client = l.id_mitt
        INNER JOIN dap03_personale pd
            ON pd.id_personale = l.id_dest
        LEFT JOIN dap03_personale cd
            ON cd.id_personale = m.recipient_user_id
        WHERE " . implode("\n          AND ", $where) . "
        ORDER BY
            m.recipient_user_id ASC,
            l.id_dest ASC,
            l.id_mitt ASC,
            m.id_message ASC
    ";

    $rows = [];
    $res = $db->query($sql);
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'new_message_id'             => (int)$row['new_message_id'],
            'id_thread'                  => (int)$row['id_thread'],
            'old_table'                  => (string)($row['old_table'] ?? ''),
            'old_message_id'             => (int)$row['old_message_id'],
            'patient_id'                 => (int)$row['patient_id'],
            'patient_cognome'            => trim((string)($row['patient_cognome'] ?? '')),
            'patient_nome'               => trim((string)($row['patient_nome'] ?? '')),
            'expected_doctor_id'         => (int)$row['expected_doctor_id'],
            'expected_doctor_cognome'    => trim((string)($row['expected_doctor_cognome'] ?? '')),
            'expected_doctor_nome'       => trim((string)($row['expected_doctor_nome'] ?? '')),
            'current_recipient_user_id'  => (int)$row['current_recipient_user_id'],
            'current_recipient_cognome'  => trim((string)($row['current_recipient_cognome'] ?? '')),
            'current_recipient_nome'     => trim((string)($row['current_recipient_nome'] ?? '')),
            'legacy_dest_code'           => strtoupper(trim((string)($row['legacy_dest_code'] ?? ''))),
            'current_recipient_role'     => normalizeNullableString($row['current_recipient_role'] ?? null),
            'expected_recipient_role'    => normalizeNullableString($row['expected_recipient_role'] ?? null),
            'legacy_created_at'          => normalizeNullableString($row['legacy_created_at'] ?? null),
        ];
    }

    return $rows;
}

function loadFlagsByMessage(mysqli $db, array $messageIds): array
{
    $rowsByMessage = [];
    if (!$messageIds) {
        return $rowsByMessage;
    }

    $res = $db->query("
        SELECT
            id_message,
            user_id,
            is_deleted,
            deleted_at,
            is_read,
            read_at,
            is_handled,
            handled_at
        FROM msg_user_flags
        WHERE id_message IN (" . csvInts($messageIds) . ")
        ORDER BY id_message ASC, user_id ASC
    ");

    while ($row = $res->fetch_assoc()) {
        $messageId = (int)$row['id_message'];
        $rowsByMessage[$messageId][] = [
            'user_id'     => (int)$row['user_id'],
            'is_deleted'  => (int)$row['is_deleted'],
            'deleted_at'  => normalizeNullableString($row['deleted_at'] ?? null),
            'is_read'     => (int)$row['is_read'],
            'read_at'     => normalizeNullableString($row['read_at'] ?? null),
            'is_handled'  => (int)$row['is_handled'],
            'handled_at'  => normalizeNullableString($row['handled_at'] ?? null),
        ];
    }

    return $rowsByMessage;
}

function aggregateRecipientFlagSummary(array $flagRows, int $currentDoctorId): array
{
    $summary = [
        'row_count'    => 0,
        'user_rows'    => [],
        'is_deleted'   => 0,
        'deleted_at'   => null,
        'is_read'      => 0,
        'read_at'      => null,
        'is_handled'   => 0,
        'handled_at'   => null,
        'has_any_state'=> false,
    ];

    $allowed = array_flip(mailboxFlagUserIdsForDoctor($currentDoctorId));
    foreach ($flagRows as $row) {
        $userId = (int)($row['user_id'] ?? 0);
        if (!isset($allowed[$userId])) {
            continue;
        }

        $summary['row_count']++;
        $summary['user_rows'][$userId] = $row;

        if ((int)($row['is_deleted'] ?? 0) === 1) {
            $summary['is_deleted'] = 1;
            $summary['deleted_at'] = earlierDate($summary['deleted_at'], normalizeNullableString($row['deleted_at'] ?? null));
            $summary['has_any_state'] = true;
        }
        if ((int)($row['is_read'] ?? 0) === 1) {
            $summary['is_read'] = 1;
            $summary['read_at'] = earlierDate($summary['read_at'], normalizeNullableString($row['read_at'] ?? null));
            $summary['has_any_state'] = true;
        }
        if ((int)($row['is_handled'] ?? 0) === 1) {
            $summary['is_handled'] = 1;
            $summary['handled_at'] = earlierDate($summary['handled_at'], normalizeNullableString($row['handled_at'] ?? null));
            $summary['has_any_state'] = true;
        }
    }

    return $summary;
}

function fullName(string $cognome, string $nome, int $fallbackId): string
{
    $label = trim(trim($cognome) . ' ' . trim($nome));
    if ($label !== '') {
        return $label;
    }

    return '#' . $fallbackId;
}

function printFilters(array $filters): void
{
    $parts = [];
    if (!empty($filters['wrong_recipients'])) {
        $parts[] = 'wrong_recipients=' . implode(',', $filters['wrong_recipients']);
    }
    if (!empty($filters['expected_doctors'])) {
        $parts[] = 'expected_doctors=' . implode(',', $filters['expected_doctors']);
    }
    if (!empty($filters['patients'])) {
        $parts[] = 'patients=' . implode(',', $filters['patients']);
    }

    echo 'Filtri                : ' . ($parts ? implode(' | ', $parts) : 'nessuno') . "\n";
}

function printSummary(array $candidates, array $flagSummaries, array $filters, bool $apply, bool $verbose): void
{
    $threadIds = [];
    $patientIds = [];
    $wrongRecipientIds = [];
    $expectedDoctorIds = [];
    $destCodes = [];
    $patientGroups = [];
    $recipientGroups = [];
    $totalRecipientFlagRows = 0;

    foreach ($candidates as $candidate) {
        $messageId = (int)$candidate['new_message_id'];
        $threadIds[(int)$candidate['id_thread']] = true;
        $patientIds[(int)$candidate['patient_id']] = true;
        $wrongRecipientIds[(int)$candidate['current_recipient_user_id']] = true;
        $expectedDoctorIds[(int)$candidate['expected_doctor_id']] = true;
        $destCodes[$candidate['legacy_dest_code']] = true;

        $flagRows = (int)($flagSummaries[$messageId]['row_count'] ?? 0);
        $totalRecipientFlagRows += $flagRows;

        $groupKey = implode('|', [
            (int)$candidate['patient_id'],
            (int)$candidate['current_recipient_user_id'],
            (int)$candidate['expected_doctor_id'],
        ]);
        if (!isset($patientGroups[$groupKey])) {
            $patientGroups[$groupKey] = [
                'patient_id'               => (int)$candidate['patient_id'],
                'patient_name'             => fullName($candidate['patient_cognome'], $candidate['patient_nome'], (int)$candidate['patient_id']),
                'wrong_recipient_id'       => (int)$candidate['current_recipient_user_id'],
                'wrong_recipient_name'     => fullName($candidate['current_recipient_cognome'], $candidate['current_recipient_nome'], (int)$candidate['current_recipient_user_id']),
                'expected_doctor_id'       => (int)$candidate['expected_doctor_id'],
                'expected_doctor_name'     => fullName($candidate['expected_doctor_cognome'], $candidate['expected_doctor_nome'], (int)$candidate['expected_doctor_id']),
                'messages'                 => 0,
                'recipient_flag_rows'      => 0,
                'dest_codes'               => [],
            ];
        }

        $patientGroups[$groupKey]['messages']++;
        $patientGroups[$groupKey]['recipient_flag_rows'] += $flagRows;
        $patientGroups[$groupKey]['dest_codes'][$candidate['legacy_dest_code']] = true;

        $recipientKey = (int)$candidate['current_recipient_user_id'];
        if (!isset($recipientGroups[$recipientKey])) {
            $recipientGroups[$recipientKey] = [
                'id'                    => (int)$candidate['current_recipient_user_id'],
                'name'                  => fullName($candidate['current_recipient_cognome'], $candidate['current_recipient_nome'], (int)$candidate['current_recipient_user_id']),
                'messages'              => 0,
                'patients'              => [],
                'recipient_flag_rows'   => 0,
            ];
        }
        $recipientGroups[$recipientKey]['messages']++;
        $recipientGroups[$recipientKey]['patients'][(int)$candidate['patient_id']] = true;
        $recipientGroups[$recipientKey]['recipient_flag_rows'] += $flagRows;
    }

    usort($patientGroups, static function (array $a, array $b): int {
        if ($a['messages'] === $b['messages']) {
            return $a['patient_id'] <=> $b['patient_id'];
        }
        return $b['messages'] <=> $a['messages'];
    });

    usort($recipientGroups, static function (array $a, array $b): int {
        if ($a['messages'] === $b['messages']) {
            return $a['id'] <=> $b['id'];
        }
        return $b['messages'] <=> $a['messages'];
    });

    echo "== REPAIR WRONG RECIPIENTS ==\n";
    echo 'Modalita              : ' . ($apply ? 'APPLY' : 'DRY RUN') . "\n";
    printFilters($filters);
    echo 'Messaggi candidati    : ' . count($candidates) . "\n";
    echo 'Thread coinvolti      : ' . count($threadIds) . "\n";
    echo 'Pazienti coinvolti    : ' . count($patientIds) . "\n";
    echo 'Mailbox errate        : ' . count($wrongRecipientIds) . "\n";
    echo 'Mailbox corrette      : ' . count($expectedDoctorIds) . "\n";
    echo 'Codici legacy         : ' . implode(',', array_keys($destCodes)) . "\n";
    echo 'Flag recipient-side   : ' . $totalRecipientFlagRows . "\n";

    echo "\nDettaglio per mailbox errata:\n";
    foreach ($recipientGroups as $group) {
        echo '- ' . $group['id'] . ' ' . $group['name']
            . ' | messaggi=' . $group['messages']
            . ' | pazienti=' . count($group['patients'])
            . ' | flag_recipient=' . $group['recipient_flag_rows']
            . "\n";
    }

    echo "\nDettaglio per paziente:\n";
    foreach ($patientGroups as $group) {
        $destCodesList = array_keys($group['dest_codes']);
        sort($destCodesList);
        echo '- [' . $group['patient_id'] . '] ' . $group['patient_name']
            . ' | messaggi=' . $group['messages']
            . ' | legacy=' . implode(',', $destCodesList)
            . ' | ' . $group['wrong_recipient_id'] . ' ' . $group['wrong_recipient_name']
            . ' -> ' . $group['expected_doctor_id'] . ' ' . $group['expected_doctor_name']
            . ' | flag_recipient=' . $group['recipient_flag_rows']
            . "\n";
    }

    if ($verbose) {
        echo "\nDettaglio per messaggio:\n";
        foreach ($candidates as $candidate) {
            $messageId = (int)$candidate['new_message_id'];
            $flagRows = (int)($flagSummaries[$messageId]['row_count'] ?? 0);
            echo '- msg=' . $messageId
                . ' | thread=' . (int)$candidate['id_thread']
                . ' | old_table=' . ($candidate['old_table'] ?? '')
                . ' | old=' . (int)$candidate['old_message_id']
                . ' | patient=' . (int)$candidate['patient_id']
                . ' | legacy=' . $candidate['legacy_dest_code']
                . ' | current=' . (int)$candidate['current_recipient_user_id']
                . '/' . ($candidate['current_recipient_role'] ?? 'NULL')
                . ' | expected=' . (int)$candidate['expected_doctor_id']
                . '/' . ($candidate['expected_recipient_role'] ?? 'NULL')
                . ' | flag_recipient=' . $flagRows
                . "\n";
        }
    }
}

function upsertFlag(mysqli $db, int $messageId, int $userId, array $flagSummary): void
{
    $sql = "
        INSERT INTO msg_user_flags (
            id_message,
            user_id,
            is_deleted,
            deleted_at,
            is_read,
            read_at,
            is_handled,
            handled_at
        ) VALUES (
            {$messageId},
            {$userId},
            " . (int)$flagSummary['is_deleted'] . ",
            " . sqlNullableString($db, $flagSummary['deleted_at']) . ",
            " . (int)$flagSummary['is_read'] . ",
            " . sqlNullableString($db, $flagSummary['read_at']) . ",
            " . (int)$flagSummary['is_handled'] . ",
            " . sqlNullableString($db, $flagSummary['handled_at']) . "
        )
        ON DUPLICATE KEY UPDATE
            is_deleted = GREATEST(is_deleted, VALUES(is_deleted)),
            deleted_at = CASE
                WHEN VALUES(deleted_at) IS NULL THEN deleted_at
                WHEN deleted_at IS NULL THEN VALUES(deleted_at)
                ELSE LEAST(deleted_at, VALUES(deleted_at))
            END,
            is_read = GREATEST(is_read, VALUES(is_read)),
            read_at = CASE
                WHEN VALUES(read_at) IS NULL THEN read_at
                WHEN read_at IS NULL THEN VALUES(read_at)
                ELSE LEAST(read_at, VALUES(read_at))
            END,
            is_handled = GREATEST(is_handled, VALUES(is_handled)),
            handled_at = CASE
                WHEN VALUES(handled_at) IS NULL THEN handled_at
                WHEN handled_at IS NULL THEN VALUES(handled_at)
                ELSE LEAST(handled_at, VALUES(handled_at))
            END
    ";

    $db->query($sql);
}

$argv = $_SERVER['argv'] ?? [];

$filters = [
    'wrong_recipients' => normalizeIdListOption($argv, 'wrong-recipients'),
    'expected_doctors' => normalizeIdListOption($argv, 'expected-doctors'),
    'patients'         => normalizeIdListOption($argv, 'patients'),
];

$apply   = hasFlag($argv, '--apply');
$verbose = hasFlag($argv, '--verbose');

$db = new mysqli($DB['host'], $DB['user'], $DB['pass'], $DB['db']);
$db->set_charset('utf8mb4');
$db->query("SET NAMES utf8mb4");
$db->query("SET lc_time_names = 'it_IT'");
$db->query("SET SESSION block_encryption_mode = 'aes-256-cbc'");
$db->query("SET @key_str = SHA2('PartitaIVA22', 512)");

$candidates = loadRepairCandidates($db, $filters);
$messageIds = array_values(array_map(static fn (array $row): int => (int)$row['new_message_id'], $candidates));
$flagsByMessage = loadFlagsByMessage($db, $messageIds);

$doctorIds = [];
$flagSummaries = [];
foreach ($candidates as $candidate) {
    $doctorIds[(int)$candidate['current_recipient_user_id']] = (int)$candidate['current_recipient_user_id'];
    $doctorIds[(int)$candidate['expected_doctor_id']] = (int)$candidate['expected_doctor_id'];
    $messageId = (int)$candidate['new_message_id'];
    $flagSummaries[$messageId] = aggregateRecipientFlagSummary($flagsByMessage[$messageId] ?? [], (int)$candidate['current_recipient_user_id']);
}

$doctorScope = loadDoctorRoleScope($db, array_values($doctorIds));

printSummary($candidates, $flagSummaries, $filters, $apply, $verbose);

if (!$candidates) {
    echo "\nNessun candidato trovato.\n";
    exit(0);
}

if (!$apply) {
    echo "\nDRY RUN completato. Nessuna modifica applicata.\n";
    exit(0);
}

$metrics = [
    'messages_updated'          => 0,
    'flag_rows_upserted'        => 0,
    'flag_rows_deleted'         => 0,
    'messages_with_flag_repair' => 0,
];

$db->begin_transaction();

try {
    foreach ($candidates as $candidate) {
        $messageId = (int)$candidate['new_message_id'];
        $expectedDoctorId = (int)$candidate['expected_doctor_id'];
        $expectedRole = $candidate['expected_recipient_role'];
        $currentDoctorId = (int)$candidate['current_recipient_user_id'];
        $flagSummary = $flagSummaries[$messageId] ?? [
            'row_count' => 0,
            'user_rows' => [],
            'is_deleted' => 0,
            'deleted_at' => null,
            'is_read' => 0,
            'read_at' => null,
            'is_handled' => 0,
            'handled_at' => null,
            'has_any_state' => false,
        ];

        $db->query("
            UPDATE msg_messages
            SET recipient_user_id = {$expectedDoctorId},
                recipient_role    = " . sqlNullableString($db, $expectedRole) . "
            WHERE id_message = {$messageId}
            LIMIT 1
        ");
        $metrics['messages_updated']++;

        $oldFlagUserIds = mailboxFlagUserIdsForDoctor($currentDoctorId);
        $newFlagUserIds = expectedMailboxFlagUserIds($expectedDoctorId, $doctorScope);

        if (!empty($flagSummary['user_rows'])) {
            $metrics['messages_with_flag_repair']++;
        }

        if (!empty($flagSummary['user_rows']) && !empty($newFlagUserIds)) {
            foreach ($newFlagUserIds as $targetUserId) {
                upsertFlag($db, $messageId, (int)$targetUserId, $flagSummary);
                $metrics['flag_rows_upserted']++;
            }
        }

        $deleteUserIds = array_values(array_diff($oldFlagUserIds, $newFlagUserIds));
        if ($deleteUserIds) {
            $existingDeleteIds = [];
            foreach (array_keys($flagSummary['user_rows']) as $existingUserId) {
                if (in_array((int)$existingUserId, $deleteUserIds, true)) {
                    $existingDeleteIds[] = (int)$existingUserId;
                }
            }

            if ($existingDeleteIds) {
                $db->query("
                    DELETE FROM msg_user_flags
                    WHERE id_message = {$messageId}
                      AND user_id IN (" . csvInts($existingDeleteIds) . ")
                ");
                $metrics['flag_rows_deleted'] += count($existingDeleteIds);
            }
        }
    }

    $remaining = loadRepairCandidates($db, $filters);
    if ($remaining) {
        throw new RuntimeException('Il repair non ha azzerato i candidati nello scope selezionato');
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

echo "\nAPPLY completato.\n";
echo 'Messaggi aggiornati    : ' . $metrics['messages_updated'] . "\n";
echo 'Messaggi con flag move : ' . $metrics['messages_with_flag_repair'] . "\n";
echo 'Flag upsert eseguiti   : ' . $metrics['flag_rows_upserted'] . "\n";
echo 'Flag vecchi rimossi    : ' . $metrics['flag_rows_deleted'] . "\n";
