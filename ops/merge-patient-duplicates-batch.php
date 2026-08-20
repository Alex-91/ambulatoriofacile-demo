<?php

declare(strict_types=1);

/**
 * Fusione batch prudente dei doppioni paziente già approvati.
 *
 * Default: DRY RUN rigorosamente read-only.
 * APPLY richiede contemporaneamente:
 *   --apply
 *   --confirm=<token stampato dal dry-run corrente>
 *
 * Lo script ricalcola l'audit, confronta esattamente target e sorgenti con il
 * piano, blocca tutte le righe coinvolte e applica l'intero lotto in un'unica
 * transazione. Qualsiasi riferimento inatteso causa rollback.
 */

define('AF_DUPLICATE_AUDIT_LIBRARY_ONLY', true);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'audit-patient-duplicates.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function hasCliFlag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function positiveIds(array $values): array
{
    $ids = array_values(array_unique(array_map('intval', $values)));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    sort($ids, SORT_NUMERIC);

    return $ids;
}

function loadPlan(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Piano merge non trovato: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || (int) ($decoded['plan_version'] ?? 0) !== 1) {
        throw new RuntimeException('Piano merge assente o con versione non supportata.');
    }

    return $decoded;
}

function canonicalPlanRows(array $groups): array
{
    $rows = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $targetId = (int) ($group['target_id'] ?? 0);
        $sourceIds = positiveIds((array) ($group['source_ids'] ?? []));
        if ($targetId <= 0 || $sourceIds === []) {
            throw new RuntimeException('Il piano contiene un gruppo senza target o sorgenti validi.');
        }

        $rows[] = [
            'target_id' => $targetId,
            'source_ids' => $sourceIds,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        return $left['target_id'] <=> $right['target_id'];
    });

    return $rows;
}

function confirmationToken(array $plan): string
{
    $canonical = json_encode(
        canonicalPlanRows((array) ($plan['groups'] ?? [])),
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    return strtoupper(substr(hash('sha256', $canonical), 0, 16));
}

function contextFromEnvironment(): array
{
    $auditHost = firstEnv(['AUDIT_DB_HOST']);
    if ($auditHost !== '') {
        $platform = [
            'host' => $auditHost,
            'port' => (int) firstEnv(['AUDIT_DB_PORT'], '3306'),
            'user' => requiredFirstEnv(['AUDIT_DB_USER']),
            'password' => requiredFirstEnv(['AUDIT_DB_PASSWORD']),
        ];

        return [
            'platform_connection' => $platform,
            'tenant_connection' => $platform,
            'platform_database' => requiredFirstEnv(['AUDIT_PLATFORM_DATABASE']),
            'encryption_key' => requiredFirstEnv(['AUDIT_DB_ENCRYPTION_KEY']),
            'encryption_mode' => firstEnv(['AUDIT_DB_ENCRYPTION_MODE'], 'aes-256-cbc'),
            'force_runtime_override' => true,
        ];
    }

    $runtimePasswordRef = requiredFirstEnv([
        'TENANT_PROVISIONING_RUNTIME_PASSWORD_REF',
        'tenant.provisioning.runtimePasswordRef',
    ]);
    $runtimePassword = resolvePasswordReference($runtimePasswordRef);
    if ($runtimePassword === '') {
        throw new RuntimeException('Password runtime tenant non risolta.');
    }

    return [
        'platform_connection' => [
            'host' => requiredFirstEnv(['PLATFORM_DB_HOST', 'database.platform.hostname']),
            'port' => (int) firstEnv(['PLATFORM_DB_PORT', 'database.platform.port'], '3306'),
            'user' => requiredFirstEnv(['PLATFORM_DB_USERNAME', 'database.platform.username']),
            'password' => requiredFirstEnv(['PLATFORM_DB_PASSWORD', 'database.platform.password']),
        ],
        'tenant_connection' => [
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
        ],
        'platform_database' => requiredFirstEnv(['PLATFORM_DB_DATABASE', 'database.platform.database']),
        'encryption_key' => requiredFirstEnv(['DB_ENCRYPTION_KEY', 'database.default.DB_ENCRYPTION_KEY']),
        'encryption_mode' => firstEnv(['DB_ENCRYPTION_MODE'], 'aes-256-cbc'),
        'force_runtime_override' => envFlag([
            'TENANT_PROVISIONING_FORCE_RUNTIME_OVERRIDE',
            'tenant.provisioning.forceRuntimeOverride',
        ]),
    ];
}

function resolveMembership(mysqli $platformDb, string $masterEmail, string $tenantDatabase): array
{
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
           AND t.db_name = ?
           AND t.is_active = 1",
        [$masterEmail, $tenantDatabase]
    );

    if (count($memberships) !== 1) {
        throw new RuntimeException('Il master non risolve in modo univoco il tenant del piano.');
    }

    return $memberships[0];
}

function tenantConnectionForMembership(array $baseConfig, array $membership, bool $forceOverride): array
{
    if ($forceOverride) {
        return $baseConfig;
    }

    $config = $baseConfig;
    if (trim((string) ($membership['db_host'] ?? '')) !== '') {
        $config['host'] = trim((string) $membership['db_host']);
    }
    if ((int) ($membership['db_port'] ?? 0) > 0) {
        $config['port'] = (int) $membership['db_port'];
    }
    if (trim((string) ($membership['db_username'] ?? '')) !== '') {
        $config['user'] = trim((string) $membership['db_username']);
    }
    if (trim((string) ($membership['db_password_ref'] ?? '')) !== '') {
        $password = resolvePasswordReference((string) $membership['db_password_ref']);
        if ($password !== '') {
            $config['password'] = $password;
        }
    }

    return $config;
}

function reportPlanRows(array $groups): array
{
    $rows = [];
    foreach ($groups as $group) {
        if (($group['decision']['classification'] ?? '') !== 'auto_merge') {
            throw new RuntimeException('L’audit corrente contiene un gruppo non autorizzato alla fusione automatica.');
        }

        $rows[] = [
            'target_id' => (int) ($group['decision']['target_id'] ?? 0),
            'source_ids' => positiveIds((array) ($group['decision']['source_ids'] ?? [])),
        ];
    }

    return canonicalPlanRows(array_map(
        static fn (array $row): array => [
            'target_id' => $row['target_id'],
            'source_ids' => $row['source_ids'],
        ],
        $rows
    ));
}

function assertPlanMatchesAudit(array $plan, array $report): array
{
    $planRows = canonicalPlanRows((array) ($plan['groups'] ?? []));
    $currentRows = reportPlanRows((array) ($report['groups'] ?? []));
    if ($planRows !== $currentRows) {
        throw new RuntimeException('Il live non coincide più con il piano: target o sorgenti sono cambiati.');
    }

    $summary = (array) ($report['summary'] ?? []);
    $expectedGroups = (int) ($plan['expected_duplicate_groups'] ?? 0);
    $expectedRecords = (int) ($plan['expected_duplicate_records'] ?? 0);
    $expectedSources = (int) ($plan['expected_source_records'] ?? 0);
    if ((int) ($summary['duplicate_name_groups'] ?? -1) !== $expectedGroups
        || (int) ($summary['duplicate_records_total'] ?? -1) !== $expectedRecords
        || (int) ($summary['records_auto_mergeable'] ?? -1) !== $expectedSources
        || (int) ($summary['groups_keep_separate'] ?? -1) !== 0
        || (int) ($summary['groups_manual_review'] ?? -1) !== 0) {
        throw new RuntimeException('I conteggi dell’audit corrente non coincidono con il piano approvabile.');
    }

    $allowedReferenceTables = [
        'dap09_client_doctor',
        'dap12_agenda_appuntamenti',
        'dap26_doctor_patient_search',
    ];
    $appointmentCount = 0;
    $unexpected = [];

    foreach ((array) ($report['groups'] ?? []) as $group) {
        $sourceIds = positiveIds((array) ($group['decision']['source_ids'] ?? []));
        foreach ((array) ($group['members'] ?? []) as $member) {
            if (!in_array((int) ($member['id_client'] ?? 0), $sourceIds, true)) {
                continue;
            }

            foreach ((array) ($member['references'] ?? []) as $table => $count) {
                if ($table === 'dap12_agenda_appuntamenti') {
                    $appointmentCount += (int) $count;
                }
                if ((int) $count > 0 && !in_array((string) $table, $allowedReferenceTables, true)) {
                    $unexpected[$table] = ($unexpected[$table] ?? 0) + (int) $count;
                }
            }
        }
    }

    if ($unexpected !== []) {
        throw new RuntimeException(
            'Riferimenti sorgente inattesi: ' . json_encode($unexpected, JSON_UNESCAPED_SLASHES)
        );
    }
    if ((array) ($report['reference_scan_errors'] ?? []) !== []) {
        throw new RuntimeException('La scansione delle relazioni ha restituito errori.');
    }

    return [
        'appointment_rows' => $appointmentCount,
        'source_ids' => positiveIds(array_merge(...array_map(
            static fn (array $row): array => $row['source_ids'],
            $planRows
        ))),
        'all_client_ids' => positiveIds(array_merge(
            array_column($planRows, 'target_id'),
            ...array_map(static fn (array $row): array => $row['source_ids'], $planRows)
        )),
    ];
}

function runtimeLockPlan(array $plan, array $report): array
{
    $membersByTarget = [];
    foreach ((array) ($report['groups'] ?? []) as $group) {
        $targetId = (int) ($group['decision']['target_id'] ?? 0);
        if ($targetId <= 0) {
            throw new RuntimeException('L’audit corrente contiene un gruppo senza target valido.');
        }
        $membersByTarget[$targetId] = (array) ($group['members'] ?? []);
    }

    $lockPlan = $plan;
    foreach ($lockPlan['groups'] as &$group) {
        $targetId = (int) ($group['target_id'] ?? 0);
        if (!isset($membersByTarget[$targetId])) {
            throw new RuntimeException("Membri correnti non trovati per target id_client={$targetId}");
        }
        $group['members'] = $membersByTarget[$targetId];
    }
    unset($group);

    return $lockPlan;
}

function connectWritable(array $config, string $database, string $encryptionKey, string $encryptionMode): mysqli
{
    if (!preg_match('/^aes-(128|192|256)-(cbc|ecb)$/', $encryptionMode)) {
        throw new RuntimeException("Modalita cifratura non ammessa: {$encryptionMode}");
    }

    $db = new mysqli(
        $config['host'],
        $config['user'],
        $config['password'],
        $database,
        (int) $config['port']
    );
    $db->set_charset('utf8mb4');
    $db->query("SET block_encryption_mode = '" . $db->real_escape_string($encryptionMode) . "'");
    $db->execute_query('SET @key_str = SHA2(?, 512)', [$encryptionKey]);

    return $db;
}

function countRows(mysqli $db, string $sql): int
{
    $row = $db->query($sql)->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

function appointmentPatientLinkStats(mysqli $db): array
{
    $row = $db->query(
        'SELECT
            COUNT(*) AS total,
            SUM(
                CASE
                    WHEN COALESCE(a.id_client, 0) > 0 OR COALESCE(a.id_paziente, 0) > 0
                    THEN 1 ELSE 0
                END
            ) AS with_patient_reference,
            SUM(
                CASE
                    WHEN (
                        COALESCE(a.id_client, 0) > 0 OR COALESCE(a.id_paziente, 0) > 0
                    ) AND NOT EXISTS (
                        SELECT 1
                        FROM dap02_clients c
                        WHERE (
                            COALESCE(a.id_client, 0) > 0
                            AND c.id_client = a.id_client
                        ) OR (
                            COALESCE(a.id_client, 0) = 0
                            AND COALESCE(a.id_paziente, 0) > 0
                            AND (
                                c.id_client = a.id_paziente
                                OR COALESCE(c.legacy_id_paziente, 0) = a.id_paziente
                            )
                        )
                    )
                    THEN 1 ELSE 0
                END
            ) AS without_valid_patient
         FROM dap12_agenda_appuntamenti a'
    )->fetch_assoc();

    $total = (int) ($row['total'] ?? 0);
    $withReference = (int) ($row['with_patient_reference'] ?? 0);
    $withoutValidPatient = (int) ($row['without_valid_patient'] ?? 0);

    return [
        'total' => $total,
        'with_patient_reference' => $withReference,
        'with_valid_patient' => $withReference - $withoutValidPatient,
        'without_valid_patient' => $withoutValidPatient,
    ];
}

function tableExistsInCurrentDatabase(mysqli $db, string $table): bool
{
    $row = $db->execute_query(
        'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    )->fetch_assoc();

    return (int) ($row['total'] ?? 0) === 1;
}

function assertNoLegacyMessageReferences(mysqli $db, array $sourceIds): void
{
    $ids = implode(',', $sourceIds);
    if (tableExistsInCurrentDatabase($db, 'dap10_message')) {
        $total = countRows(
            $db,
            "SELECT COUNT(*) AS total FROM dap10_message
             WHERE (mitt = 'C' AND id_mitt IN ({$ids})) OR (dest = 'C' AND id_dest IN ({$ids}))"
        );
        if ($total > 0) {
            throw new RuntimeException("Messaggi legacy collegati alle sorgenti: {$total}");
        }
    }
    if (tableExistsInCurrentDatabase($db, 'dap10_message_reply')) {
        $total = countRows(
            $db,
            "SELECT COUNT(*) AS total FROM dap10_message_reply
             WHERE (mitt = 'C' AND id_mitt IN ({$ids})) OR (dest = 'C' AND id_dest IN ({$ids}))"
        );
        if ($total > 0) {
            throw new RuntimeException("Risposte legacy collegate alle sorgenti: {$total}");
        }
    }
}

function readOnlyOperationalChecks(
    array $connectionConfig,
    string $database,
    array $sourceIds
): array {
    $db = connectReadOnly($connectionConfig, $database);
    try {
        assertNoLegacyMessageReferences($db, $sourceIds);
        $idSql = implode(',', $sourceIds);
        $legacyAppointments = countRows(
            $db,
            "SELECT COUNT(*) AS total
             FROM dap12_agenda_appuntamenti a
             INNER JOIN dap02_clients c
                ON c.id_client IN ({$idSql})
               AND (
                    a.id_paziente = c.id_client
                    OR (
                        COALESCE(c.legacy_id_paziente, 0) > 0
                        AND a.id_paziente = c.legacy_id_paziente
                    )
               )
             WHERE COALESCE(a.id_client, 0) = 0"
        );

        $appointmentStats = appointmentPatientLinkStats($db);
        if ($appointmentStats['without_valid_patient'] !== 0) {
            throw new RuntimeException(
                'Sono presenti appuntamenti con riferimento paziente non valido prima della fusione: '
                . $appointmentStats['without_valid_patient']
            );
        }

        return [
            'legacy_appointment_rows' => $legacyAppointments,
            'appointment_patient_links' => $appointmentStats,
        ];
    } finally {
        try {
            $db->rollback();
        } catch (Throwable) {
        }
        $db->close();
    }
}

function assertLockedRowsStillMatchPlan(mysqli $db, array $plan): void
{
    $databaseRow = $db->query('SELECT DATABASE() AS database_name')->fetch_assoc();
    $database = (string) ($databaseRow['database_name'] ?? '');
    $columns = tableColumns($db, $database, 'dap02_clients');
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
    $profileFields = array_values(array_diff($encryptedFields, ['nome', 'cognome']));

    foreach ((array) ($plan['groups'] ?? []) as $group) {
        $expectedById = [];
        foreach ((array) ($group['members'] ?? []) as $member) {
            $expectedFields = array_values(array_map(
                'strval',
                (array) ($member['profile_fields_present'] ?? [])
            ));
            sort($expectedFields, SORT_STRING);
            $expectedById[(int) ($member['id_client'] ?? 0)] = [
                'nome' => normalizeText((string) ($member['nome'] ?? '')),
                'cognome' => normalizeText((string) ($member['cognome'] ?? '')),
                'profile_fields_present' => $expectedFields,
            ];
        }

        $ids = positiveIds(array_merge(
            [(int) ($group['target_id'] ?? 0)],
            (array) ($group['source_ids'] ?? [])
        ));
        $idSql = implode(',', $ids);
        $select = ['c.id_client', 'c.id_user', 'c.id_personale'];
        foreach ($encryptedFields as $field) {
            $select[] = decryptedExpression($field);
        }
        $rows = fetchAll(
            $db,
            'SELECT ' . implode(', ', $select)
            . " FROM dap02_clients c WHERE c.id_client IN ({$idSql}) FOR UPDATE"
        );

        if (count($rows) !== count($ids)) {
            throw new RuntimeException('Una scheda del piano non esiste più durante il lock transazionale.');
        }
        foreach ($rows as $row) {
            $id = (int) $row['id_client'];
            $expected = $expectedById[$id] ?? null;
            if ($expected === null
                || normalizeText((string) $row['nome']) !== $expected['nome']
                || normalizeText((string) $row['cognome']) !== $expected['cognome']) {
                throw new RuntimeException("Identita cambiata durante il lock per id_client={$id}");
            }
            $actualFields = profileFieldsPresent($row, $profileFields);
            sort($actualFields, SORT_STRING);
            if ($actualFields !== $expected['profile_fields_present']) {
                throw new RuntimeException("Profilo cambiato durante il lock per id_client={$id}");
            }
        }
    }
}

function remainingSourceReferences(mysqli $db, string $database, array $sourceIds): array
{
    $references = countReferencesByClient($db, $database, $sourceIds);
    $remaining = [];
    foreach ($references as $clientId => $tables) {
        if ($clientId === '_errors') {
            continue;
        }
        foreach ((array) $tables as $table => $count) {
            if ((int) $count > 0) {
                $remaining[$table] = ($remaining[$table] ?? 0) + (int) $count;
            }
        }
    }
    if ((array) ($references['_errors'] ?? []) !== []) {
        throw new RuntimeException('Errore nel controllo finale delle relazioni sorgente.');
    }

    return $remaining;
}

function applyBatch(
    mysqli $db,
    array $plan,
    array $validated,
    string $database
): array {
    $movedAppointments = 0;

    $db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $db->begin_transaction();
    try {
        $patientCountBefore = countRows($db, 'SELECT COUNT(*) AS total FROM dap02_clients');
        $appointmentStatsBefore = appointmentPatientLinkStats($db);
        if ($appointmentStatsBefore['without_valid_patient'] !== 0) {
            throw new RuntimeException(
                'Fusione annullata: appuntamenti con riferimento paziente non valido prima delle modifiche: '
                . $appointmentStatsBefore['without_valid_patient']
            );
        }

        assertLockedRowsStillMatchPlan($db, $plan);
        assertNoLegacyMessageReferences($db, $validated['source_ids']);

        $unexpectedBefore = remainingSourceReferences($db, $database, $validated['source_ids']);
        $allowed = [
            'dap09_client_doctor',
            'dap12_agenda_appuntamenti',
            'dap26_doctor_patient_search',
        ];
        foreach (array_keys($unexpectedBefore) as $table) {
            if (!in_array($table, $allowed, true)) {
                throw new RuntimeException("Relazione sorgente non gestita: {$table}");
            }
        }

        foreach ((array) ($plan['groups'] ?? []) as $group) {
            $targetId = (int) $group['target_id'];
            foreach (positiveIds((array) $group['source_ids']) as $sourceId) {
                $source = $db->execute_query(
                    'SELECT COALESCE(legacy_id_paziente, 0) AS legacy_id_paziente
                     FROM dap02_clients WHERE id_client = ? LIMIT 1',
                    [$sourceId]
                )->fetch_assoc();
                $legacyId = (int) ($source['legacy_id_paziente'] ?? 0);

                $db->execute_query(
                    'INSERT IGNORE INTO dap09_client_doctor (id_client, id_dot)
                     SELECT ?, source.id_dot
                     FROM dap09_client_doctor source
                     WHERE source.id_client = ?
                       AND NOT EXISTS (
                           SELECT 1
                           FROM dap09_client_doctor target
                           WHERE target.id_client = ?
                             AND target.id_dot = source.id_dot
                       )',
                    [$targetId, $sourceId, $targetId]
                );
                $db->execute_query(
                    'DELETE FROM dap09_client_doctor WHERE id_client = ?',
                    [$sourceId]
                );

                $db->execute_query(
                    'UPDATE dap12_agenda_appuntamenti
                     SET id_client = ?, id_paziente = ?
                     WHERE id_client = ?',
                    [$targetId, $targetId, $sourceId]
                );
                $movedAppointments += $db->affected_rows;

                $legacyIds = positiveIds([$sourceId, $legacyId]);
                if ($legacyIds !== []) {
                    $legacySql = implode(',', $legacyIds);
                    $db->query(
                        "UPDATE dap12_agenda_appuntamenti
                         SET id_client = {$targetId}, id_paziente = {$targetId}
                         WHERE COALESCE(id_client, 0) = 0
                           AND id_paziente IN ({$legacySql})"
                    );
                    $movedAppointments += $db->affected_rows;
                }

                if (tableExistsInCurrentDatabase($db, 'dap26_doctor_patient_search')) {
                    $db->execute_query(
                        'DELETE FROM dap26_doctor_patient_search WHERE id_client = ?',
                        [$sourceId]
                    );
                }

                $remaining = remainingSourceReferences($db, $database, [$sourceId]);
                if ($remaining !== []) {
                    throw new RuntimeException(
                        "Riferimenti residui per id_client={$sourceId}: "
                        . json_encode($remaining, JSON_UNESCAPED_SLASHES)
                    );
                }

                $db->execute_query(
                    'DELETE FROM dap02_clients WHERE id_client = ? LIMIT 1',
                    [$sourceId]
                );
                if ($db->affected_rows !== 1) {
                    throw new RuntimeException("Eliminazione sorgente fallita per id_client={$sourceId}");
                }
            }
        }

        $sourceSql = implode(',', $validated['source_ids']);
        $remainingSources = countRows(
            $db,
            "SELECT COUNT(*) AS total FROM dap02_clients WHERE id_client IN ({$sourceSql})"
        );
        $patientCountAfter = countRows($db, 'SELECT COUNT(*) AS total FROM dap02_clients');
        $appointmentStatsAfter = appointmentPatientLinkStats($db);

        if ($remainingSources !== 0
            || $patientCountAfter !== $patientCountBefore - count($validated['source_ids'])
            || $appointmentStatsAfter['total'] !== $appointmentStatsBefore['total']
            || $appointmentStatsAfter['without_valid_patient'] !== 0
            || $appointmentStatsAfter['with_patient_reference'] !== $appointmentStatsBefore['with_patient_reference']) {
            throw new RuntimeException('I conteggi finali transazionali non coincidono con il piano.');
        }

        $db->commit();

        return [
            'patients_before' => $patientCountBefore,
            'patients_after' => $patientCountAfter,
            'appointments_before' => $appointmentStatsBefore['total'],
            'appointments_after' => $appointmentStatsAfter['total'],
            'appointment_patient_links_before' => $appointmentStatsBefore,
            'appointment_patient_links_after' => $appointmentStatsAfter,
            'appointment_rows_relinked' => $movedAppointments,
            'sources_deleted' => count($validated['source_ids']),
        ];
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function verifyCompletedBatch(
    array $connectionConfig,
    string $database,
    array $plan,
    array $report,
    int $expectedAppointments
): array {
    $planRows = canonicalPlanRows((array) ($plan['groups'] ?? []));
    $sourceIds = positiveIds(array_merge(...array_map(
        static fn (array $row): array => $row['source_ids'],
        $planRows
    )));
    $sourceSql = implode(',', $sourceIds);
    $expectedPatients = (int) ($plan['expected_live_patients_total'] ?? 0) - count($sourceIds);

    $db = connectReadOnly($connectionConfig, $database);
    try {
        $patients = countRows($db, 'SELECT COUNT(*) AS total FROM dap02_clients');
        $remainingSources = countRows(
            $db,
            "SELECT COUNT(*) AS total FROM dap02_clients WHERE id_client IN ({$sourceSql})"
        );
        $sourceAppointmentRows = countRows(
            $db,
            "SELECT COUNT(*) AS total
             FROM dap12_agenda_appuntamenti
             WHERE id_client IN ({$sourceSql})"
        );
        $appointmentStats = appointmentPatientLinkStats($db);
        $remainingReferences = remainingSourceReferences($db, $database, $sourceIds);

        if ($patients !== $expectedPatients
            || $remainingSources !== 0
            || $sourceAppointmentRows !== 0
            || $remainingReferences !== []
            || $appointmentStats['without_valid_patient'] !== 0
            || ($expectedAppointments >= 0 && $appointmentStats['total'] !== $expectedAppointments)
            || (int) ($report['summary']['duplicate_name_groups'] ?? -1) !== 0) {
            throw new RuntimeException('La verifica indipendente successiva alla fusione non coincide con il risultato atteso.');
        }

        return [
            'patients_total' => $patients,
            'remaining_source_patients' => $remainingSources,
            'remaining_source_appointment_rows' => $sourceAppointmentRows,
            'remaining_source_references' => $remainingReferences,
            'duplicate_name_groups' => (int) $report['summary']['duplicate_name_groups'],
            'appointment_patient_links' => $appointmentStats,
        ];
    } finally {
        try {
            $db->rollback();
        } catch (Throwable) {
        }
        $db->close();
    }
}

$argv = $_SERVER['argv'] ?? [];
$apply = hasCliFlag($argv, '--apply');
$verifyCompleted = hasCliFlag($argv, '--verify-completed');
$masterEmail = strtolower(optionValue($argv, 'master-email'));
$planPath = optionValue($argv, 'plan');
$confirm = strtoupper((string) optionValue($argv, 'confirm'));

if ($masterEmail === '' || filter_var($masterEmail, FILTER_VALIDATE_EMAIL) === false) {
    throw new RuntimeException('Specificare --master-email con un indirizzo valido.');
}
if ($planPath === '') {
    throw new RuntimeException('Specificare --plan con il percorso del piano approvato.');
}

$plan = loadPlan($planPath);
if ($masterEmail !== strtolower((string) ($plan['master_email'] ?? ''))) {
    throw new RuntimeException('Il master indicato non coincide con il piano.');
}

$context = contextFromEnvironment();
$verification = null;
$platformDb = connectReadOnly($context['platform_connection'], $context['platform_database']);
try {
    $membership = resolveMembership(
        $platformDb,
        $masterEmail,
        (string) ($plan['tenant_database'] ?? '')
    );
    $tenantConnection = tenantConnectionForMembership(
        $context['tenant_connection'],
        $membership,
        (bool) $context['force_runtime_override']
    );
    $report = auditTenant(
        $platformDb,
        $tenantConnection,
        $membership,
        $context['encryption_key'],
        $context['encryption_mode']
    );

    if ($verifyCompleted) {
        $expectedAppointmentsOption = optionValue($argv, 'expected-appointments');
        $expectedAppointments = $expectedAppointmentsOption === ''
            ? -1
            : (int) $expectedAppointmentsOption;
        $verification = verifyCompletedBatch(
            $tenantConnection,
            (string) $plan['tenant_database'],
            $plan,
            $report,
            $expectedAppointments
        );
    } else {
        $validated = assertPlanMatchesAudit($plan, $report);
        $lockPlan = runtimeLockPlan($plan, $report);
    }
} finally {
    try {
        $platformDb->rollback();
    } catch (Throwable) {
    }
    $platformDb->close();
}

if ($verifyCompleted) {
    echo json_encode([
        'mode' => 'POST_APPLY_READ_ONLY_VERIFICATION',
        'master_email' => $masterEmail,
        'tenant_database' => (string) $plan['tenant_database'],
        'database_changes' => 0,
        'status' => 'VERIFIED',
        'result' => $verification,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$operationalChecks = readOnlyOperationalChecks(
    $tenantConnection,
    (string) $plan['tenant_database'],
    $validated['source_ids']
);
$validated['legacy_appointment_rows'] = (int) $operationalChecks['legacy_appointment_rows'];

$token = confirmationToken($plan);
$baseResult = [
    'mode' => $apply ? 'APPLY' : 'DRY_RUN_READ_ONLY',
    'master_email' => $masterEmail,
    'tenant_database' => (string) $plan['tenant_database'],
    'current_patients_total' => (int) $report['summary']['patients_total'],
    'duplicate_groups' => (int) $report['summary']['duplicate_name_groups'],
    'duplicate_records' => (int) $report['summary']['duplicate_records_total'],
    'source_records' => count($validated['source_ids']),
    'appointment_rows_detected' => (int) $validated['appointment_rows']
        + (int) $validated['legacy_appointment_rows'],
    'legacy_only_appointment_rows' => (int) $validated['legacy_appointment_rows'],
    'appointment_patient_links' => $operationalChecks['appointment_patient_links'],
    'confirmation_token' => $token,
];

if (!$apply) {
    $baseResult['database_changes'] = 0;
    $baseResult['apply_command_requires'] = "--apply --confirm={$token}";
    echo json_encode($baseResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if (!hash_equals($token, $confirm)) {
    throw new RuntimeException('Token --confirm assente o non corrispondente al piano corrente.');
}

$writeDb = connectWritable(
    $tenantConnection,
    (string) $plan['tenant_database'],
    $context['encryption_key'],
    $context['encryption_mode']
);
try {
    $baseResult['result'] = applyBatch(
        $writeDb,
        $lockPlan,
        $validated,
        (string) $plan['tenant_database']
    );
    $baseResult['status'] = 'COMPLETED';
    echo json_encode($baseResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $writeDb->close();
}
