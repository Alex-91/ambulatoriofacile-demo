<?php

declare(strict_types=1);

/**
 * Audit read-only dei possibili pazienti duplicati di uno spazio tenant.
 *
 * Lo script non contiene UPDATE/INSERT/DELETE e apre le connessioni con una
 * transazione READ ONLY. Le credenziali vengono lette esclusivamente da env.
 *
 * Env richieste:
 *   AUDIT_DB_HOST
 *   AUDIT_DB_PORT (default 3306)
 *   AUDIT_DB_USER
 *   AUDIT_DB_PASSWORD
 *   AUDIT_PLATFORM_DATABASE
 *   AUDIT_DB_ENCRYPTION_KEY
 *
 * Uso:
 *   php ops/audit-patient-duplicates.php --master-email=utente@example.test
 */

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function optionValue(array $argv, string $name): string
{
    foreach ($argv as $arg) {
        if (!is_string($arg)) {
            continue;
        }

        if (preg_match('/^--' . preg_quote($name, '/') . '=(.*)$/', $arg, $matches)) {
            return trim((string) $matches[1]);
        }
    }

    return '';
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        throw new RuntimeException("Variabile ambiente mancante: {$name}");
    }

    return trim((string) $value);
}

function firstEnv(array $names, string $default = ''): string
{
    foreach ($names as $name) {
        $value = getenv((string) $name);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return $default;
}

function requiredFirstEnv(array $names): string
{
    $value = firstEnv($names);
    if ($value === '') {
        throw new RuntimeException('Variabile ambiente mancante; attese una tra: ' . implode(', ', $names));
    }

    return $value;
}

function envFlag(array $names): bool
{
    return in_array(strtolower(firstEnv($names)), ['1', 'true', 'yes', 'on'], true);
}

function resolvePasswordReference(string $reference): string
{
    $aliases = [
        'DB_PASSWORD' => ['DB_PASSWORD', 'database.default.password'],
        'database.default.password' => ['database.default.password', 'DB_PASSWORD'],
        'PLATFORM_DB_PASSWORD' => ['PLATFORM_DB_PASSWORD', 'database.platform.password'],
        'database.platform.password' => ['database.platform.password', 'PLATFORM_DB_PASSWORD'],
    ];

    return firstEnv($aliases[$reference] ?? [$reference]);
}

function normalizeText(?string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function normalizeLooseName(?string $value): string
{
    $value = normalizeText($value);
    if ($value === '') {
        return '';
    }

    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($transliterated) && $transliterated !== '') {
        $value = strtolower($transliterated);
    }

    return (string) preg_replace('/[^a-z0-9]+/', '', $value);
}

function normalizeFiscalCode(?string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));
}

function normalizePhone(?string $value): string
{
    $digits = (string) preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) === 12 && str_starts_with($digits, '39')) {
        $digits = substr($digits, 2);
    }

    return $digits;
}

function normalizeBirthDate(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return normalizeText($value);
}

function maskedValue(string $field, ?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if ($field === 'codice_fiscale') {
        $normalized = normalizeFiscalCode($value);
        if (strlen($normalized) <= 6) {
            return str_repeat('*', strlen($normalized));
        }

        return substr($normalized, 0, 3)
            . str_repeat('*', max(1, strlen($normalized) - 6))
            . substr($normalized, -3);
    }

    if ($field === 'cellulare') {
        $normalized = normalizePhone($value);
        if (strlen($normalized) <= 5) {
            return str_repeat('*', strlen($normalized));
        }

        return substr($normalized, 0, 3)
            . str_repeat('*', max(1, strlen($normalized) - 5))
            . substr($normalized, -2);
    }

    if ($field === 'data_nascita') {
        $normalized = normalizeBirthDate($value);
        return preg_match('/^(\d{4})-\d{2}-\d{2}$/', $normalized, $matches)
            ? $matches[1] . '-**-**'
            : '[presente]';
    }

    return '[presente]';
}

function connectReadOnly(array $config, string $database): mysqli
{
    $db = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $database,
        $config['port']
    );
    $db->set_charset('utf8mb4');
    $db->query('SET SESSION TRANSACTION READ ONLY');
    $db->query('START TRANSACTION READ ONLY');

    return $db;
}

function fetchAll(mysqli $db, string $sql, array $params = []): array
{
    if ($params === []) {
        return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    return $db->execute_query($sql, $params)->fetch_all(MYSQLI_ASSOC);
}

function tableColumns(mysqli $db, string $database, string $table): array
{
    $rows = fetchAll(
        $db,
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [$database, $table]
    );

    return array_values(array_map(
        static fn (array $row): string => (string) $row['COLUMN_NAME'],
        $rows
    ));
}

function quotedIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function decryptedExpression(string $field): string
{
    $identifier = quotedIdentifier($field);

    return "CONVERT(CAST(AES_DECRYPT(UNHEX(NULLIF(c.{$identifier}, '')), @key_str, c.vector_id)"
        . " AS CHAR CHARACTER SET latin1) USING utf8mb4) AS {$identifier}";
}

function profileFieldsPresent(array $row, array $profileFields): array
{
    $present = [];
    foreach ($profileFields as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            continue;
        }

        // Il modello genera automaticamente "Cognome Nome" come denominazione:
        // non deve far sembrare completo un record composto solo da nome/cognome.
        if ($field === 'denominazione') {
            $denomination = normalizeText($value);
            $surnameName = normalizeText(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? ''));
            $nameSurname = normalizeText(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
            if ($denomination === $surnameName || $denomination === $nameSurname) {
                continue;
            }
        }

        $present[] = $field;
    }

    if ((int) ($row['id_user'] ?? 0) > 0) {
        $present[] = 'account_collegato';
    }

    return $present;
}

function identityConflictFields(array $left, array $right): array
{
    $normalizers = [
        'codice_fiscale' => 'normalizeFiscalCode',
        'data_nascita' => 'normalizeBirthDate',
        'cellulare' => 'normalizePhone',
    ];

    $conflicts = [];
    foreach ($normalizers as $field => $normalizer) {
        $leftValue = $normalizer($left[$field] ?? '');
        $rightValue = $normalizer($right[$field] ?? '');
        if ($leftValue !== '' && $rightValue !== '' && $leftValue !== $rightValue) {
            $conflicts[] = $field;
        }
    }

    return $conflicts;
}

function classifyDuplicateGroup(array $members): array
{
    usort($members, static fn (array $a, array $b): int => ((int) $a['id_client']) <=> ((int) $b['id_client']));

    $bare = array_values(array_filter(
        $members,
        static fn (array $member): bool => ($member['profile_fields_present'] ?? []) === []
    ));
    $rich = array_values(array_filter(
        $members,
        static fn (array $member): bool => ($member['profile_fields_present'] ?? []) !== []
    ));

    $pairConflicts = [];
    for ($i = 0, $count = count($members); $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $fields = identityConflictFields($members[$i], $members[$j]);
            if ($fields !== []) {
                $pairConflicts[] = [
                    'left_id' => (int) $members[$i]['id_client'],
                    'right_id' => (int) $members[$j]['id_client'],
                    'fields' => $fields,
                ];
            }
        }
    }

    if ($rich === []) {
        $target = $members[0];
        return [
            'classification' => 'auto_merge',
            'target_id' => (int) $target['id_client'],
            'source_ids' => array_values(array_map(
                static fn (array $member): int => (int) $member['id_client'],
                array_slice($members, 1)
            )),
            'reason' => 'Tutti i record contengono soltanto nome e cognome.',
            'identity_conflicts' => [],
        ];
    }

    if (count($rich) === 1) {
        $target = $rich[0];
        return [
            'classification' => 'auto_merge',
            'target_id' => (int) $target['id_client'],
            'source_ids' => array_values(array_map(
                static fn (array $member): int => (int) $member['id_client'],
                $bare
            )),
            'reason' => 'Un solo record contiene dati ulteriori; i record solo nome/cognome confluiscono in quello completo.',
            'identity_conflicts' => [],
        ];
    }

    if (count($members) === 2 && $pairConflicts !== []) {
        return [
            'classification' => 'keep_separate',
            'target_id' => null,
            'source_ids' => [],
            'reason' => 'I dati identificativi valorizzati sono diversi: possibile omonimia.',
            'identity_conflicts' => $pairConflicts,
        ];
    }

    return [
        'classification' => 'manual_review',
        'target_id' => null,
        'source_ids' => [],
        'reason' => $pairConflicts !== []
            ? 'Il gruppo contiene più record completi e almeno un conflitto identificativo; i record incompleti non sono attribuibili con certezza.'
            : 'Più record contengono dati ulteriori senza conflitti identificativi espliciti; la regola concordata non autorizza una fusione automatica.',
        'identity_conflicts' => $pairConflicts,
    ];
}

function countReferencesByClient(mysqli $db, string $database, array $clientIds): array
{
    if ($clientIds === []) {
        return [];
    }

    $idSql = implode(',', array_map('intval', $clientIds));
    $tables = fetchAll(
        $db,
        "SELECT TABLE_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
           AND COLUMN_NAME = 'id_client'
           AND TABLE_NAME <> 'dap02_clients'
         ORDER BY TABLE_NAME",
        [$database]
    );

    $references = [];
    foreach ($tables as $tableRow) {
        $table = (string) $tableRow['TABLE_NAME'];
        $sql = 'SELECT id_client, COUNT(*) AS total FROM '
            . quotedIdentifier($database) . '.' . quotedIdentifier($table)
            . " WHERE id_client IN ({$idSql}) GROUP BY id_client";

        try {
            foreach (fetchAll($db, $sql) as $countRow) {
                $clientId = (int) $countRow['id_client'];
                $references[$clientId][$table] = (int) $countRow['total'];
            }
        } catch (Throwable $e) {
            $references['_errors'][$table] = $e->getMessage();
        }
    }

    return $references;
}

function buildNameDiagnostics(array $patients): array
{
    $looseGroups = [];
    $unorderedGroups = [];

    foreach ($patients as $row) {
        $name = normalizeLooseName($row['nome'] ?? '');
        $surname = normalizeLooseName($row['cognome'] ?? '');
        if ($name === '' || $surname === '') {
            continue;
        }

        $looseGroups[$surname . "\0" . $name][] = $row;
        $unordered = [$surname, $name];
        sort($unordered, SORT_STRING);
        $unorderedGroups[implode("\0", $unordered)][] = $row;
    }

    $visualVariants = [];
    foreach ($looseGroups as $members) {
        if (count($members) < 2) {
            continue;
        }

        $strictKeys = [];
        foreach ($members as $member) {
            $strictKeys[] = normalizeText($member['cognome'] ?? '')
                . "\0"
                . normalizeText($member['nome'] ?? '');
        }
        if (count(array_unique($strictKeys)) < 2) {
            continue;
        }

        $visualVariants[] = array_map(
            static fn (array $member): array => [
                'id_client' => (int) $member['id_client'],
                'nome' => trim((string) ($member['nome'] ?? '')),
                'cognome' => trim((string) ($member['cognome'] ?? '')),
            ],
            $members
        );
    }

    $reversed = [];
    foreach ($unorderedGroups as $members) {
        if (count($members) < 2) {
            continue;
        }

        $orientations = [];
        foreach ($members as $member) {
            $orientations[] = normalizeLooseName($member['cognome'] ?? '')
                . "\0"
                . normalizeLooseName($member['nome'] ?? '');
        }
        if (count(array_unique($orientations)) < 2) {
            continue;
        }

        $reversed[] = array_map(
            static fn (array $member): array => [
                'id_client' => (int) $member['id_client'],
                'nome' => trim((string) ($member['nome'] ?? '')),
                'cognome' => trim((string) ($member['cognome'] ?? '')),
            ],
            $members
        );
    }

    usort($visualVariants, static function (array $a, array $b): int {
        $left = ($a[0]['cognome'] ?? '') . ' ' . ($a[0]['nome'] ?? '');
        $right = ($b[0]['cognome'] ?? '') . ' ' . ($b[0]['nome'] ?? '');
        return strcasecmp($left, $right);
    });
    usort($reversed, static function (array $a, array $b): int {
        $left = ($a[0]['cognome'] ?? '') . ' ' . ($a[0]['nome'] ?? '');
        $right = ($b[0]['cognome'] ?? '') . ' ' . ($b[0]['nome'] ?? '');
        return strcasecmp($left, $right);
    });

    return [
        'visual_name_variant_groups' => $visualVariants,
        'possible_name_surname_reversed_groups' => $reversed,
    ];
}

function duplicateDoctorLinks(mysqli $db): array
{
    if (tableColumns($db, (string) $db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'], 'dap09_client_doctor') === []) {
        return [];
    }

    return array_map(
        static fn (array $row): array => [
            'id_client' => (int) $row['id_client'],
            'id_dot' => (int) $row['id_dot'],
            'rows' => (int) $row['total'],
        ],
        fetchAll(
            $db,
            'SELECT id_client, id_dot, COUNT(*) AS total
             FROM dap09_client_doctor
             GROUP BY id_client, id_dot
             HAVING COUNT(*) > 1
             ORDER BY id_client, id_dot'
        )
    );
}

function emitAuditResult(array $result): void
{
    $plain = (string) json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $keyHex = firstEnv(['AF_AUDIT_KEY_HEX']);
    $ivHex = firstEnv(['AF_AUDIT_IV_HEX']);
    $runId = firstEnv(['AF_AUDIT_RUN_ID']);
    if ($keyHex === '' || $ivHex === '' || $runId === '') {
        echo $plain . PHP_EOL;
        return;
    }

    $compressed = gzencode($plain, 9);
    if ($compressed === false) {
        throw new RuntimeException('Compressione output audit non riuscita.');
    }

    $tag = '';
    $cipher = openssl_encrypt(
        $compressed,
        'aes-256-gcm',
        hex2bin($keyHex),
        OPENSSL_RAW_DATA,
        hex2bin($ivHex),
        $tag,
        'AF_PATIENT_AUDIT'
    );
    if ($cipher === false) {
        throw new RuntimeException('Cifratura output audit non riuscita.');
    }

    echo "AF_PATIENT_AUDIT_BEGIN:{$runId}\n";
    foreach (str_split(base64_encode($tag . $cipher), 3000) as $index => $chunk) {
        echo "AF_PATIENT_AUDIT_DATA:{$runId}:{$index}:{$chunk}\n";
    }
    echo "AF_PATIENT_AUDIT_END:{$runId}\n";
}

function auditTenant(
    mysqli $platformDb,
    array $connectionConfig,
    array $membership,
    string $encryptionKey,
    string $encryptionMode
): array
{
    $database = trim((string) ($membership['db_name'] ?? ''));
    if ($database === '') {
        throw new RuntimeException('Database tenant non configurato per ' . ($membership['tenant_key'] ?? 'tenant'));
    }

    $db = connectReadOnly($connectionConfig, $database);
    try {
        if (!preg_match('/^aes-(128|192|256)-(cbc|ecb)$/', $encryptionMode)) {
            throw new RuntimeException("Modalita di cifratura DB non ammessa: {$encryptionMode}");
        }

        // Replica l'inizializzazione usata dal gestionale. La passphrase non
        // viene utilizzata direttamente da AES: prima viene derivata SHA2-512.
        $db->query("SET block_encryption_mode = '" . $db->real_escape_string($encryptionMode) . "'");
        $db->execute_query('SET @key_str = SHA2(?, 512)', [$encryptionKey]);

        $columns = tableColumns($db, $database, 'dap02_clients');
        if ($columns === []) {
            throw new RuntimeException("Tabella dap02_clients non trovata in {$database}");
        }

        $encryptedCandidates = [
            'nome', 'cognome', 'denominazione', 'data_nascita', 'codice_fiscale',
            'partita_iva', 'comune_nascita', 'provincia_nascita', 'indirizzo',
            'nr_civico', 'citta', 'cap', 'provincia', 'indirizzo_secondario',
            'nr_civico_secondario', 'comune_secondario', 'cap_secondario',
            'provincia_secondaria', 'residenza_indirizzo', 'residenza_comune',
            'residenza_cap', 'residenza_provincia', 'telefono', 'cellulare',
            'email', 'email_pec', 'banca', 'condizioni_pagamento',
            'codice_destinatario', 'note_cliente', 'paz_spec',
        ];
        $encryptedFields = array_values(array_intersect($encryptedCandidates, $columns));
        $technicalCandidates = ['id_client', 'id_user', 'id_personale', 'legacy_id_paziente'];
        $technicalFields = array_values(array_intersect($technicalCandidates, $columns));

        $select = array_map(
            static fn (string $field): string => 'c.' . quotedIdentifier($field),
            $technicalFields
        );
        foreach ($encryptedFields as $field) {
            $select[] = decryptedExpression($field);
        }

        $patients = fetchAll(
            $db,
            'SELECT ' . implode(', ', $select) . ' FROM dap02_clients c ORDER BY c.id_client ASC'
        );

        $decodedNames = count(array_filter(
            $patients,
            static fn (array $row): bool => trim((string) ($row['nome'] ?? '')) !== ''
                || trim((string) ($row['cognome'] ?? '')) !== ''
        ));
        if ($patients !== [] && $decodedNames === 0) {
            throw new RuntimeException('La chiave di cifratura non decodifica alcun nominativo del tenant.');
        }

        $nameDiagnostics = buildNameDiagnostics($patients);
        $duplicateDoctorLinks = duplicateDoctorLinks($db);

        $profileFields = array_values(array_diff($encryptedFields, ['nome', 'cognome']));
        $groups = [];
        foreach ($patients as $row) {
            $normalizedName = normalizeText($row['nome'] ?? '');
            $normalizedSurname = normalizeText($row['cognome'] ?? '');
            if ($normalizedName === '' || $normalizedSurname === '') {
                continue;
            }

            $row['profile_fields_present'] = profileFieldsPresent($row, $profileFields);
            $key = $normalizedSurname . "\0" . $normalizedName;
            $groups[$key][] = $row;
        }
        $groups = array_filter($groups, static fn (array $members): bool => count($members) > 1);

        $duplicateClientIds = [];
        foreach ($groups as $members) {
            foreach ($members as $member) {
                $duplicateClientIds[] = (int) $member['id_client'];
            }
        }
        $duplicateClientIds = array_values(array_unique($duplicateClientIds));
        sort($duplicateClientIds);

        $references = countReferencesByClient($db, $database, $duplicateClientIds);
        $reportGroups = [];
        $autoMergeSources = 0;
        $keepSeparateGroups = 0;
        $manualReviewGroups = 0;

        foreach ($groups as $members) {
            $classification = classifyDuplicateGroup($members);
            if ($classification['classification'] === 'auto_merge') {
                $autoMergeSources += count($classification['source_ids']);
            } elseif ($classification['classification'] === 'keep_separate') {
                $keepSeparateGroups++;
            } else {
                $manualReviewGroups++;
            }

            $safeMembers = [];
            foreach ($members as $member) {
                $clientId = (int) $member['id_client'];
                $identity = [];
                foreach (['codice_fiscale', 'data_nascita', 'cellulare'] as $field) {
                    if (trim((string) ($member[$field] ?? '')) !== '') {
                        $identity[$field] = maskedValue($field, (string) $member[$field]);
                    }
                }

                $safeMembers[] = [
                    'id_client' => $clientId,
                    'nome' => trim((string) ($member['nome'] ?? '')),
                    'cognome' => trim((string) ($member['cognome'] ?? '')),
                    'profile_fields_present' => $member['profile_fields_present'],
                    'identity_masked' => $identity,
                    'references' => $references[$clientId] ?? [],
                ];
            }

            $reportGroups[] = [
                'display_name' => trim((string) ($members[0]['cognome'] ?? '') . ' ' . (string) ($members[0]['nome'] ?? '')),
                'members' => $safeMembers,
                'decision' => $classification,
            ];
        }

        usort($reportGroups, static fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));

        return [
            'tenant' => [
                'id_tenant' => (int) ($membership['id_tenant'] ?? 0),
                'tenant_key' => (string) ($membership['tenant_key'] ?? ''),
                'tenant_name' => (string) ($membership['tenant_name'] ?? ''),
                'database' => $database,
                'tenant_role' => (string) ($membership['tenant_role'] ?? ''),
                'is_owner' => (int) ($membership['is_owner'] ?? 0) === 1,
            ],
            'summary' => [
                'patients_total' => count($patients),
                'duplicate_name_groups' => count($reportGroups),
                'duplicate_records_total' => count($duplicateClientIds),
                'records_auto_mergeable' => $autoMergeSources,
                'groups_keep_separate' => $keepSeparateGroups,
                'groups_manual_review' => $manualReviewGroups,
                'visual_name_variant_groups' => count($nameDiagnostics['visual_name_variant_groups']),
                'possible_name_surname_reversed_groups' => count($nameDiagnostics['possible_name_surname_reversed_groups']),
                'duplicate_doctor_links' => count($duplicateDoctorLinks),
            ],
            'groups' => $reportGroups,
            'diagnostics' => [
                'visual_name_variant_groups' => $nameDiagnostics['visual_name_variant_groups'],
                'possible_name_surname_reversed_groups' => $nameDiagnostics['possible_name_surname_reversed_groups'],
                'duplicate_doctor_links' => $duplicateDoctorLinks,
            ],
            'reference_scan_errors' => $references['_errors'] ?? [],
        ];
    } finally {
        try {
            $db->rollback();
        } catch (Throwable) {
        }
        $db->close();
    }
}

// Consente allo script operativo batch di riutilizzare in sicurezza tutte le
// funzioni di audit senza avviare automaticamente una seconda esecuzione CLI.
if (defined('AF_DUPLICATE_AUDIT_LIBRARY_ONLY') && AF_DUPLICATE_AUDIT_LIBRARY_ONLY === true) {
    return;
}

$argv = $_SERVER['argv'] ?? [];
$masterEmail = strtolower(optionValue($argv, 'master-email'));
if ($masterEmail === '' || filter_var($masterEmail, FILTER_VALIDATE_EMAIL) === false) {
    throw new RuntimeException('Specificare un indirizzo valido con --master-email=...');
}

$auditHost = firstEnv(['AUDIT_DB_HOST']);
if ($auditHost !== '') {
    // Esecuzione locale sul clone test: una singola utenza amministrativa
    // read-only di sessione può leggere catalogo piattaforma e tenant.
    $platformConnectionConfig = [
        'host' => $auditHost,
        'port' => (int) firstEnv(['AUDIT_DB_PORT'], '3306'),
        'user' => requiredFirstEnv(['AUDIT_DB_USER']),
        'password' => requiredFirstEnv(['AUDIT_DB_PASSWORD']),
    ];
    $tenantConnectionConfig = $platformConnectionConfig;
    $platformDatabase = requiredFirstEnv(['AUDIT_PLATFORM_DATABASE']);
    $encryptionKey = requiredFirstEnv(['AUDIT_DB_ENCRYPTION_KEY']);
    $encryptionMode = firstEnv(['AUDIT_DB_ENCRYPTION_MODE'], 'aes-256-cbc');
} else {
    // Esecuzione dentro il container login di produzione. Si usano soltanto
    // le env runtime già presenti: nessun segreto viene passato come argomento.
    $platformConnectionConfig = [
        'host' => requiredFirstEnv(['PLATFORM_DB_HOST', 'database.platform.hostname']),
        'port' => (int) firstEnv(['PLATFORM_DB_PORT', 'database.platform.port'], '3306'),
        'user' => requiredFirstEnv(['PLATFORM_DB_USERNAME', 'database.platform.username']),
        'password' => requiredFirstEnv(['PLATFORM_DB_PASSWORD', 'database.platform.password']),
    ];
    $runtimePasswordRef = requiredFirstEnv([
        'TENANT_PROVISIONING_RUNTIME_PASSWORD_REF',
        'tenant.provisioning.runtimePasswordRef',
    ]);
    $runtimePassword = resolvePasswordReference($runtimePasswordRef);
    if ($runtimePassword === '') {
        throw new RuntimeException('Password runtime tenant non risolta dalla variabile di riferimento.');
    }

    $tenantConnectionConfig = [
        'host' => requiredFirstEnv([
            'TENANT_PROVISIONING_RUNTIME_HOST',
            'tenant.provisioning.runtimeHost',
            'PLATFORM_DB_HOST',
        ]),
        'port' => (int) firstEnv([
            'TENANT_PROVISIONING_RUNTIME_PORT',
            'tenant.provisioning.runtimePort',
            'PLATFORM_DB_PORT',
        ], '3306'),
        'user' => requiredFirstEnv([
            'TENANT_PROVISIONING_RUNTIME_USERNAME',
            'tenant.provisioning.runtimeUsername',
            'PLATFORM_DB_USERNAME',
        ]),
        'password' => $runtimePassword,
    ];
    $platformDatabase = requiredFirstEnv(['PLATFORM_DB_DATABASE', 'database.platform.database']);
    $encryptionKey = requiredFirstEnv(['DB_ENCRYPTION_KEY', 'database.default.DB_ENCRYPTION_KEY']);
    $encryptionMode = firstEnv(['DB_ENCRYPTION_MODE'], 'aes-256-cbc');
}

$platformDb = connectReadOnly($platformConnectionConfig, $platformDatabase);
try {
    $memberships = fetchAll(
        $platformDb,
        "SELECT
            pu.id_platform_user,
            pu.email,
            put.id_tenant,
            put.tenant_role,
            put.is_owner,
            put.is_default,
            put.invitation_status,
            put.app_user_id,
            t.tenant_key,
            t.tenant_name,
            t.status AS tenant_status,
            t.db_name,
            t.db_host,
            t.db_port,
            t.db_username,
            t.db_password_ref
         FROM platform_users pu
         INNER JOIN platform_user_tenants put ON put.id_platform_user = pu.id_platform_user
         INNER JOIN platform_tenants t ON t.id_tenant = put.id_tenant
         WHERE LOWER(pu.email) = ?
           AND t.is_active = 1
         ORDER BY put.is_default DESC, put.id_platform_user_tenant ASC",
        [$masterEmail]
    );

    if ($memberships === []) {
        throw new RuntimeException("Utente master non trovato nel catalogo piattaforma: {$masterEmail}");
    }

    $tenantReports = [];
    foreach ($memberships as $membership) {
        $connectionConfig = $tenantConnectionConfig;
        if ($auditHost === '' && !envFlag([
            'TENANT_PROVISIONING_FORCE_RUNTIME_OVERRIDE',
            'tenant.provisioning.forceRuntimeOverride',
        ])) {
            if (trim((string) ($membership['db_host'] ?? '')) !== '') {
                $connectionConfig['host'] = trim((string) $membership['db_host']);
            }
            if ((int) ($membership['db_port'] ?? 0) > 0) {
                $connectionConfig['port'] = (int) $membership['db_port'];
            }
            if (trim((string) ($membership['db_username'] ?? '')) !== '') {
                $connectionConfig['user'] = trim((string) $membership['db_username']);
            }
            if (trim((string) ($membership['db_password_ref'] ?? '')) !== '') {
                $resolvedPassword = resolvePasswordReference((string) $membership['db_password_ref']);
                if ($resolvedPassword !== '') {
                    $connectionConfig['password'] = $resolvedPassword;
                }
            }
        }

        $tenantReports[] = auditTenant(
            $platformDb,
            $connectionConfig,
            $membership,
            $encryptionKey,
            $encryptionMode
        );
    }

    $result = [
        'mode' => 'DRY_RUN_READ_ONLY',
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format(DateTimeInterface::ATOM),
        'master_email' => $masterEmail,
        'platform_user_found' => true,
        'memberships_found' => count($memberships),
        'tenants' => $tenantReports,
    ];

    emitAuditResult($result);
} finally {
    try {
        $platformDb->rollback();
    } catch (Throwable) {
    }
    $platformDb->close();
}
