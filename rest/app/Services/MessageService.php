<?php namespace App\Services;

use CodeIgniter\Database\ConnectionInterface;
use App\Libraries\DatabaseConfig;
use App\Config\MessageRoles;
use App\Libraries\Crypto_helper;
use App\Libraries\SystemUserMask;
use CodeIgniter\I18n\Time;

class MessageService
{
    private array $clientActorCache = [];
    private array $personaleActorCache = [];
    private array $clientExistsCache = [];
    private array $doctorPersonaleExistsCache = [];
    private array $doctorPatientPairCache = [];
    private array $rootAuthorByThreadCache = [];
    private array $rootParticipantsByThreadCache = [];
    private array $mappedDoctorByClientCache = [];
    private array $mappedDoctorByThreadCache = [];
    private array $anyMappedDoctorByThreadCache = [];
    private array $mappedPatientByThreadDoctorCache = [];
    private array $anyClientByThreadCache = [];
    private array $doctorContextByThreadCache = [];
    private array $patientContextByThreadCache = [];
    private array $actorResolutionCache = [];
    private array $threadCounterpartDisplayCache = [];
    private array $threadDoctorContextHintCache = [];
    private ?bool $windowFunctionsSupported = null;

    public function __construct(
        private ConnectionInterface $db,
        private DatabaseConfig $dbCfg,
    ) {}

    private function ensureCryptoSession(): void
    {
        $this->dbCfg->setEncryptionConfig($this->db, 'utf8mb4');
    }

    private function supportsWindowFunctions(): bool
    {
        if ($this->windowFunctionsSupported !== null) {
            return $this->windowFunctionsSupported;
        }

        try {
            $row = $this->db->query('SELECT VERSION() AS version')->getRowArray();
            $versionString = strtolower(trim((string)($row['version'] ?? '')));
            if ($versionString === '') {
                return $this->windowFunctionsSupported = false;
            }

            if (str_contains($versionString, 'mariadb')) {
                if (preg_match('/(\d+\.\d+\.\d+)/', $versionString, $m) === 1) {
                    return $this->windowFunctionsSupported = version_compare($m[1], '10.2.0', '>=');
                }

                return $this->windowFunctionsSupported = false;
            }

            if (preg_match('/(\d+\.\d+\.\d+)/', $versionString, $m) === 1) {
                return $this->windowFunctionsSupported = version_compare($m[1], '8.0.0', '>=');
            }
        } catch (\Throwable $e) {
            log_message('warning', '[MessageService] Impossibile rilevare supporto window functions: {msg}', [
                'msg' => $e->getMessage(),
            ]);
        }

        return $this->windowFunctionsSupported = false;
    }

    /* =========================
     * RUOLI / SESSIONE
     * ========================= */
    public function getRoleLabelFromSession(): string
    {
        $tipoUser = (int)(session()->get('tipoUser') ?? 0);
        $meObj    = session()->get('utente_sess');
        $tipoPers = (int)(is_object($meObj) ? ($meObj->tipo_pers ?? 0) : 0);
        $tipoPers = $tipoPers === StaffDoctorAccessService::TIPO_ADMIN
            ? StaffDoctorAccessService::TIPO_SEGRETERIA
            : $tipoPers;

        if ($tipoUser === 3) {
            return MessageRoles::ROLE_PATIENT;
        }

        if (is_object($meObj)) {
            if ($tipoPers === StaffDoctorAccessService::TIPO_DOTTORE) return MessageRoles::ROLE_DOCTOR;
            if ($tipoPers === StaffDoctorAccessService::TIPO_INFERMIERE) return MessageRoles::ROLE_INFERM;
            if ($tipoPers === StaffDoctorAccessService::TIPO_SEGRETERIA) return MessageRoles::ROLE_SEGR;

            if ($tipoUser === 2) {
                $ts = strtoupper((string)($meObj->tipo_stringa ?? ''));
                if ($ts === 'P') return MessageRoles::ROLE_DOCTOR;
                if ($ts === 'I') return MessageRoles::ROLE_INFERM;
                if ($ts === 'S') return MessageRoles::ROLE_SEGR;
            }
        }

        if ($tipoUser === 1) {
            return MessageRoles::ROLE_SEGR;
        }

        return 'UNKNOWN';
    }

    /* =========================
     * TIME FORMAT
     * ========================= */
    private function humanTime(?string $dt): string
    {
        $dt = (string)($dt ?? '');
        if ($dt === '') return '';

        try {
            $t = Time::parse($dt, 'Europe/Rome');

            if ($t->toDateString() === Time::now('Europe/Rome')->toDateString()) {
                return $t->format('H:i');
            }

            $weekdays = [
                1 => 'Lun',
                2 => 'Mar',
                3 => 'Mer',
                4 => 'Gio',
                5 => 'Ven',
                6 => 'Sab',
                7 => 'Dom',
            ];

            $months = [
                1 => 'Gennaio',
                2 => 'Febbraio',
                3 => 'Marzo',
                4 => 'Aprile',
                5 => 'Maggio',
                6 => 'Giugno',
                7 => 'Luglio',
                8 => 'Agosto',
                9 => 'Settembre',
                10 => 'Ottobre',
                11 => 'Novembre',
                12 => 'Dicembre',
            ];

            $weekday = $weekdays[(int)$t->format('N')] ?? $t->format('D');
            $month = $months[(int)$t->format('n')] ?? $t->format('F');

            return $weekday . ' ' . (int)$t->format('j') . ' ' . $month . ' ' . $t->format('Y');
        } catch (\Throwable $e) {
            return $dt;
        }
    }

    private function normalizeBodyText(?string $body): string
    {
        $body = (string)($body ?? '');
        $body = str_replace("\0", '', $body);
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return $body;
    }

    private function addHumanTime(array $rows): array
    {
        foreach ($rows as &$r) {
            $r['created_human'] = $this->humanTime($r['created_at'] ?? '');

            if (array_key_exists('body_plain', $r)) {
                $r['body_plain'] = $this->normalizeBodyText($r['body_plain'] ?? '');
            }
        }
        unset($r);

        return $rows;
    }

    private function hydrateInboxPagePreviewData(array &$rows): void
    {
        $messageIds = [];
        foreach ($rows as $row) {
            $messageId = (int)($row['id_message'] ?? 0);
            if ($messageId > 0) {
                $messageIds[] = $messageId;
            }
        }

        $messageIds = array_values(array_unique($messageIds));
        if ($messageIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));

        $previewRows = $this->db->query("
            SELECT
              m.id_message,
              LEFT(
                CAST(AES_DECRYPT(UNHEX(m.body_cipher_hex), @key_str, m.vector_id) AS CHAR(10000) CHARACTER SET utf8mb4),
                500
              ) AS body_plain
            FROM msg_messages m
            WHERE m.id_message IN ({$placeholders})
        ", $messageIds)->getResultArray();

        $bodyByMessage = [];
        foreach ($previewRows as $previewRow) {
            $bodyByMessage[(int)($previewRow['id_message'] ?? 0)] = (string)($previewRow['body_plain'] ?? '');
        }

        $attachmentRows = $this->db->query("
            SELECT DISTINCT a.id_message
            FROM msg_attachments a
            WHERE a.id_message IN ({$placeholders})
        ", $messageIds)->getResultArray();

        $hasAttachments = [];
        foreach ($attachmentRows as $attachmentRow) {
            $attachmentMessageId = (int)($attachmentRow['id_message'] ?? 0);
            if ($attachmentMessageId > 0) {
                $hasAttachments[$attachmentMessageId] = true;
            }
        }

        foreach ($rows as &$row) {
            $messageId = (int)($row['id_message'] ?? 0);
            $row['body_plain'] = $bodyByMessage[$messageId] ?? '';
            $row['has_attachments'] = isset($hasAttachments[$messageId]) ? 1 : 0;
        }
        unset($row);
    }

    /* =========================
     * ID "logici" (actor_id)
     * ========================= */
    private function actorNameJoinSql(string $aliasActorIdCol, string $aliasClient, string $aliasPers): string
    {
        return "
          LEFT JOIN dap02_clients {$aliasClient} ON {$aliasClient}.id_client = {$aliasActorIdCol}
          LEFT JOIN dap03_personale {$aliasPers} ON {$aliasPers}.id_personale = {$aliasActorIdCol}
        ";
    }

    private function actorNameExpr(Crypto_helper $crypto, string $aliasClient, string $aliasPers): array
    {
        $DEC_C_NOME    = $crypto->decrypt_concat("{$aliasClient}.nome");
        $DEC_C_COGNOME = $crypto->decrypt_concat("{$aliasClient}.cognome");
        $DEC_P_NOME    = $crypto->decrypt_concat("{$aliasPers}.nome");
        $DEC_P_COGNOME = $crypto->decrypt_concat("{$aliasPers}.cognome");

        return [
            'nome' => "CASE WHEN {$aliasClient}.id_client IS NOT NULL THEN CAST({$DEC_C_NOME} AS CHAR) ELSE CAST({$DEC_P_NOME} AS CHAR) END",
            'cognome' => "CASE WHEN {$aliasClient}.id_client IS NOT NULL THEN CAST({$DEC_C_COGNOME} AS CHAR) ELSE CAST({$DEC_P_COGNOME} AS CHAR) END",
        ];
    }

    private function actorRoleExpr(string $aliasClient, string $aliasPers): string
    {
        return "
          CASE
            WHEN {$aliasClient}.id_client IS NOT NULL THEN 'PAZIENTE'
            WHEN {$aliasPers}.tipo = 3 THEN 'SEGRETERIA'
            WHEN {$aliasPers}.tipo = 2 THEN 'INFERMIERE'
            WHEN {$aliasPers}.tipo = 1 THEN 'DOTTORE'
            ELSE 'PERSONALE'
          END
        ";
    }

    private function senderDisplayExpr(array $senderExpr, string $senderRoleExpr): string
    {
        return "
          CASE
            WHEN ({$senderRoleExpr}) IN ('SEGRETERIA','INFERMIERE')
              THEN ({$senderRoleExpr})
            ELSE CONCAT(
              TRIM({$senderExpr['cognome']}),
              ' ',
              TRIM({$senderExpr['nome']})
            )
          END
        ";
    }

    private function actorSearchTextExpr(Crypto_helper $crypto, string $aliasClient, string $aliasPers): string
    {
        $cNome = "TRIM(CAST(" . $crypto->decrypt_concat("{$aliasClient}.nome") . " AS CHAR))";
        $cCognome = "TRIM(CAST(" . $crypto->decrypt_concat("{$aliasClient}.cognome") . " AS CHAR))";
        $pNome = "TRIM(CAST(" . $crypto->decrypt_concat("{$aliasPers}.nome") . " AS CHAR))";
        $pCognome = "TRIM(CAST(" . $crypto->decrypt_concat("{$aliasPers}.cognome") . " AS CHAR))";

        return "
          CONCAT_WS(' ',
            {$cNome},
            {$cCognome},
            CONCAT_WS(' ', {$cNome}, {$cCognome}),
            CONCAT_WS(' ', {$cCognome}, {$cNome}),
            {$pNome},
            {$pCognome},
            CONCAT_WS(' ', {$pNome}, {$pCognome}),
            CONCAT_WS(' ', {$pCognome}, {$pNome})
          )
        ";
    }

    private function buildMessageListSearchSql(
        Crypto_helper $crypto,
        string $tableAlias,
        array &$params,
        ?string $q,
        array $actorAliases
    ): string {
        $q = $this->normalizeSearch($q);
        if ($q === '') {
            return '';
        }

        $pieces = [
            "
              CAST(
                AES_DECRYPT(UNHEX({$tableAlias}.body_cipher_hex), @key_str, {$tableAlias}.vector_id)
                AS CHAR(10000) CHARACTER SET utf8mb4
              )
            ",
        ];

        foreach ($actorAliases as $aliases) {
            if (!is_array($aliases) || count($aliases) < 2) {
                continue;
            }

            $pieces[] = $this->actorSearchTextExpr($crypto, (string)$aliases[0], (string)$aliases[1]);
        }

        $params[] = '%' . $q . '%';

        return "
          AND LOWER(
            CONCAT_WS(' ',
              " . implode(",\n              ", $pieces) . "
            )
          ) LIKE LOWER(?)
        ";
    }

    /* =========================
     * ACCESS CONTROL
     * ========================= */
    public function canUserAccessMessage(int $meActorId, string $myRole, int $messageId): bool
    {
        $myRole = strtoupper(trim($myRole));

        $row = $this->db->query(
            "SELECT * FROM msg_messages WHERE id_message=? LIMIT 1",
            [$messageId]
        )->getRowArray();
        if (!$row) {
            return false;
        }

        $threadId = (int)($row['id_thread'] ?? 0);
        if ($threadId <= 0 || !$this->canUserAccessThread($meActorId, $myRole, $threadId)) {
            return false;
        }

        if ($myRole === 'PAZIENTE') {
            $doctorContextId = (int)$this->getDoctorContextIdForThread($threadId);
            $patientContextId = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);

            return $patientContextId > 0
                && $patientContextId === $meActorId
                && $this->isMessageVisibleToPatientContext($row, $threadId, $patientContextId, $doctorContextId);
        }

        if (in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            $doctorContextId = (int)$this->getDoctorContextIdForThread($threadId);

            return $doctorContextId > 0
                && $this->isMessageVisibleToStaffRoleContext($row, $threadId, $doctorContextId, $myRole);
        }

        return true;
    }

    private function selectedDoctorContextIdFromSession(): int
    {
        return (int)(session()->get('selectedDoctorId') ?? 0);
    }

    private function staffHasDoctorContextAccess(int $meActorId, string $myRole, int $doctorContextId): bool
    {
        $myRole = strtoupper(trim($myRole));
        if ($doctorContextId <= 0 || $meActorId <= 0) {
            return false;
        }

        $staffTipo = match ($myRole) {
            'SEGRETERIA' => StaffDoctorAccessService::TIPO_SEGRETERIA,
            'INFERMIERE' => StaffDoctorAccessService::TIPO_INFERMIERE,
            default => 0,
        };

        if ($staffTipo <= 0) {
            return false;
        }

        $access = new StaffDoctorAccessService($this->db);
        return $access->canStaffAccessDoctor($meActorId, $staffTipo, $doctorContextId, 'posta');
    }

    private function isActorIdAmbiguousBetweenClientAndPersonale(int $actorId): bool
    {
        if ($actorId <= 0) {
            return false;
        }

        return $this->isClientActorId($actorId)
            && (bool)$this->db->query(
                "SELECT 1 FROM dap03_personale WHERE id_personale = ? LIMIT 1",
                [$actorId]
            )->getRowArray();
    }

    private function shouldApplyThreadContextScope(int $actorId): bool
    {
        return $actorId > 0 && $this->isActorIdAmbiguousBetweenClientAndPersonale($actorId);
    }

    private function buildDoctorThreadContextScope(string $tableAlias, int $doctorContextId): array
    {
        $doctorContextId = (int)$doctorContextId;
        if ($doctorContextId <= 0) {
            return ['sql' => '1=0', 'params' => []];
        }

        $sql = "
          (
            EXISTS (
              SELECT 1
              FROM dap09_client_doctor cd
              JOIN dap03_personale p
                ON p.id_personale = cd.id_dot
               AND p.tipo = 1
              WHERE cd.id_dot = ?
                AND EXISTS (
                  SELECT 1
                  FROM msg_messages md_patient
                  WHERE md_patient.id_thread = {$tableAlias}.id_thread
                    AND cd.id_client IN (md_patient.sender_user_id, COALESCE(md_patient.recipient_user_id, -1))
                )
                AND EXISTS (
                  SELECT 1
                  FROM msg_messages md_doctor
                  WHERE md_doctor.id_thread = {$tableAlias}.id_thread
                    AND cd.id_dot IN (md_doctor.sender_user_id, COALESCE(md_doctor.recipient_user_id, -1))
                )
            )
        ";
        $params = [$doctorContextId];

        // Se l'id non collide con un paziente, possiamo mantenere un fallback
        // compatibile con i thread storici privi di mapping completo.
        if (!$this->isActorIdAmbiguousBetweenClientAndPersonale($doctorContextId)) {
            $sql .= "
            OR EXISTS (
              SELECT 1
              FROM msg_threads t_ctx
              JOIN dap03_personale p_ctx
                ON p_ctx.id_personale = t_ctx.root_author_user_id
               AND p_ctx.tipo = 1
              WHERE t_ctx.id_thread = {$tableAlias}.id_thread
                AND t_ctx.root_author_user_id = ?
            )
            OR EXISTS (
              SELECT 1
              FROM msg_messages m_ctx
              WHERE m_ctx.id_thread = {$tableAlias}.id_thread
                AND ? IN (m_ctx.sender_user_id, COALESCE(m_ctx.recipient_user_id, -1))
            )
            ";
            $params[] = $doctorContextId;
            $params[] = $doctorContextId;
        }

        $sql .= "
          )
        ";

        return ['sql' => $sql, 'params' => $params];
    }

    private function buildPatientThreadContextScope(string $tableAlias, int $patientContextId): array
    {
        $patientContextId = (int)$patientContextId;
        if ($patientContextId <= 0) {
            return ['sql' => '1=0', 'params' => []];
        }

        $sql = "
          (
            EXISTS (
              SELECT 1
              FROM dap09_client_doctor cd
              JOIN dap03_personale p
                ON p.id_personale = cd.id_dot
               AND p.tipo = 1
              WHERE cd.id_client = ?
                AND EXISTS (
                  SELECT 1
                  FROM msg_messages md_patient
                  WHERE md_patient.id_thread = {$tableAlias}.id_thread
                    AND cd.id_client IN (md_patient.sender_user_id, COALESCE(md_patient.recipient_user_id, -1))
                )
                AND EXISTS (
                  SELECT 1
                  FROM msg_messages md_doctor
                  WHERE md_doctor.id_thread = {$tableAlias}.id_thread
                    AND cd.id_dot IN (md_doctor.sender_user_id, COALESCE(md_doctor.recipient_user_id, -1))
                )
            )
        ";
        $params = [$patientContextId];

        if (!$this->isActorIdAmbiguousBetweenClientAndPersonale($patientContextId)) {
            $sql .= "
            OR EXISTS (
              SELECT 1
              FROM msg_threads t_ctx
              JOIN dap02_clients c_ctx
                ON c_ctx.id_client = t_ctx.root_author_user_id
              WHERE t_ctx.id_thread = {$tableAlias}.id_thread
                AND t_ctx.root_author_user_id = ?
            )
            OR EXISTS (
              SELECT 1
              FROM msg_messages m_ctx
              WHERE m_ctx.id_thread = {$tableAlias}.id_thread
                AND ? IN (m_ctx.sender_user_id, COALESCE(m_ctx.recipient_user_id, -1))
            )
            ";
            $params[] = $patientContextId;
            $params[] = $patientContextId;
        }

        $sql .= "
          )
        ";

        return ['sql' => $sql, 'params' => $params];
    }

    private function preloadInboxThreadCounterparts(array $rows, string $viewerRole, int $doctorContextId): void
    {
        $viewerRole = strtoupper(trim($viewerRole));
        if (!in_array($viewerRole, ['DOTTORE', 'SEGRETERIA', 'INFERMIERE'], true)) {
            return;
        }

        $doctorContextId = (int)$doctorContextId;
        if ($doctorContextId <= 0) {
            return;
        }

        $threadIds = [];
        foreach ($rows as $row) {
            $threadId = (int)($row['id_thread'] ?? 0);
            if ($threadId <= 0) {
                continue;
            }
            $threadIds[$threadId] = $threadId;
            $this->threadDoctorContextHintCache[$threadId] = $doctorContextId;
        }

        if ($threadIds === []) {
            return;
        }

        $this->ensureCryptoSession();
        $crypto = new Crypto_helper();
        $DEC_NOME = $crypto->decrypt_concat('c.nome');
        $DEC_COGN = $crypto->decrypt_concat('c.cognome');

        $threadIds = array_values($threadIds);
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));

        $sql = "
            SELECT
                u.id_thread,
                u.actor_id AS id_client,
                TRIM(CAST({$DEC_NOME} AS CHAR)) AS nome,
                TRIM(CAST({$DEC_COGN} AS CHAR)) AS cognome,
                CASE WHEN cd.id_client IS NULL THEN 0 ELSE 1 END AS is_mapped,
                u.first_at
            FROM (
                SELECT
                    m.id_thread,
                    m.sender_user_id AS actor_id,
                    MIN(m.created_at) AS first_at
                FROM msg_messages m
                WHERE m.id_thread IN ({$placeholders})
                  AND m.sender_user_id > 0
                GROUP BY m.id_thread, m.sender_user_id

                UNION ALL

                SELECT
                    m.id_thread,
                    m.recipient_user_id AS actor_id,
                    MIN(m.created_at) AS first_at
                FROM msg_messages m
                WHERE m.id_thread IN ({$placeholders})
                  AND m.recipient_type = 'USER'
                  AND m.recipient_user_id IS NOT NULL
                  AND m.recipient_user_id > 0
                GROUP BY m.id_thread, m.recipient_user_id
            ) u
            JOIN dap02_clients c
              ON c.id_client = u.actor_id
            LEFT JOIN dap09_client_doctor cd
              ON cd.id_client = u.actor_id
             AND cd.id_dot = ?
            ORDER BY u.id_thread ASC, is_mapped DESC, u.first_at ASC, u.actor_id ASC
        ";

        $params = array_merge($threadIds, $threadIds, [$doctorContextId]);
        $result = $this->db->query($sql, $params)->getResultArray();

        foreach ($result as $row) {
            $threadId = (int)($row['id_thread'] ?? 0);
            if ($threadId <= 0 || isset($this->threadCounterpartDisplayCache[$threadId])) {
                continue;
            }

            $clientId = (int)($row['id_client'] ?? 0);
            if ($clientId <= 0) {
                continue;
            }

            if (SystemUserMask::isMaskedClientId($clientId)) {
                $display = SystemUserMask::SYSTEM_USER_LABEL;
                $this->clientActorCache[$clientId] = [
                    'kind'     => 'CLIENT',
                    'id'       => $clientId,
                    'nome'     => SystemUserMask::SYSTEM_USER_LABEL,
                    'cognome'  => '',
                    'role'     => 'PAZIENTE',
                    'display'  => SystemUserMask::SYSTEM_USER_LABEL,
                ];
            } else {
                $nome = trim((string)($row['nome'] ?? ''));
                $cognome = trim((string)($row['cognome'] ?? ''));
                $display = trim($cognome . ' ' . $nome);
                $this->clientActorCache[$clientId] = [
                    'kind'     => 'CLIENT',
                    'id'       => $clientId,
                    'nome'     => $nome,
                    'cognome'  => $cognome,
                    'role'     => 'PAZIENTE',
                    'display'  => $display,
                ];
            }

            $this->threadCounterpartDisplayCache[$threadId] = $display;
            $this->patientContextByThreadCache[$threadId . ':' . $doctorContextId] = $clientId;
        }
    }

    private function threadMatchesDoctorContext(int $threadId, int $doctorContextId): bool
    {
        if ($threadId <= 0 || $doctorContextId <= 0) {
            return false;
        }

        $scope = $this->buildDoctorThreadContextScope('m', $doctorContextId);
        $row = $this->db->query("
            SELECT 1
            FROM msg_messages m
            WHERE m.id_thread = ?
              AND {$scope['sql']}
            LIMIT 1
        ", array_merge([$threadId], $scope['params']))->getRowArray();

        return !empty($row);
    }

    private function buildInboxVisibilityScope(string $tableAlias, int $mailboxActorId, string $viewerRole): array
    {
        $viewerRole = strtoupper(trim($viewerRole));

        $sql = "{$tableAlias}.recipient_type='USER' AND {$tableAlias}.recipient_user_id=?";
        $params = [$mailboxActorId];

        if ($viewerRole === 'DOTTORE') {
            $sql .= " AND {$tableAlias}.recipient_role IS NULL";
        } elseif (in_array($viewerRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            $sql .= " AND {$tableAlias}.recipient_role=?";
            $params[] = $viewerRole;
        }

        return [
            'sql'    => $sql,
            'params' => $params,
        ];
    }

    private function canStaffAccessThreadForRoleMailbox(int $threadId, int $doctorContextId, string $staffRole): bool
    {
        if ($threadId <= 0 || $doctorContextId <= 0) {
            return false;
        }

        $staffRole = strtoupper(trim($staffRole));
        if (!in_array($staffRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            return false;
        }

        $row = $this->db->query("
            SELECT 1
            FROM msg_messages m
            WHERE m.id_thread = ?
              AND m.recipient_type = 'USER'
              AND m.recipient_user_id = ?
              AND m.recipient_role = ?
            LIMIT 1
        ", [$threadId, $doctorContextId, $staffRole])->getRowArray();

        return !empty($row);
    }

    private function canUserAccessThread(int $meActorId, string $myRole, int $threadId): bool
    {
        $myRole = strtoupper(trim($myRole));

        if ($myRole === 'PAZIENTE') {
            $doctorContextId = $this->getDoctorContextIdForThread($threadId);
            $patientContextId = $this->getPatientContextIdForThread($threadId, $doctorContextId);

            return $patientContextId > 0 && $patientContextId === $meActorId;
        }

        if ($myRole === 'DOTTORE') {
            return $this->threadMatchesDoctorContext($threadId, $meActorId);
        }

        if (in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            $doctorContextId = $this->getDoctorContextIdForThread($threadId);
            if (
                $doctorContextId > 0
                && $this->threadMatchesDoctorContext($threadId, $doctorContextId)
                && $this->staffHasDoctorContextAccess($meActorId, $myRole, $doctorContextId)
            ) {
                if ($this->canStaffAccessThreadForRoleMailbox($threadId, $doctorContextId, $myRole)) {
                    return true;
                }

                $row = $this->db->query("
                    SELECT 1
                    FROM msg_messages m
                    WHERE m.id_thread = ?
                      AND m.sender_user_id = ?
                    LIMIT 1
                ", [$threadId, $meActorId])->getRowArray();

                if (!empty($row)) {
                    return true;
                }
            }
        }

        return false;
    }

    /* =========================
     * Destinatari paziente
     * ========================= */
    public function resolvePatientTargetRecipient(int $patientClientId, string $targetCode): array
    {
        $targetCode = strtoupper($targetCode);

        $primaryRow = $this->db->query(
            "SELECT COALESCE(id_personale, 0) AS id_personale FROM dap02_clients WHERE id_client = ? LIMIT 1",
            [$patientClientId]
        )->getRowArray();
        $preferredDoctorId = (int)($primaryRow['id_personale'] ?? 0);

        $doctorLink = (new \App\Models\ClientDoctorModel())->getPreferredDoctorLinkForClient(
            $patientClientId,
            $preferredDoctorId
        );
        $doctorPersonaleId = (int)($doctorLink['id_dot'] ?? 0);

        if ($doctorPersonaleId <= 0) {
            throw new \RuntimeException('Nessun medico assegnato al paziente');
        }

        if ($targetCode === 'SEGRETERIA') {
            return ['type' => 'USER', 'role' => 'SEGRETERIA', 'actor_id' => $doctorPersonaleId];
        }

        if ($targetCode === 'INFERMIERE') {
            return ['type' => 'USER', 'role' => 'INFERMIERE', 'actor_id' => $doctorPersonaleId];
        }

        return ['type' => 'USER', 'role' => null, 'actor_id' => $doctorPersonaleId];
    }

    public function assertDoctorCanMessagePatient(int $doctorPersonaleId, int $patientClientId): void
    {
        $sql = "SELECT 1 FROM dap09_client_doctor WHERE id_client=? AND id_dot=? LIMIT 1";
        $ok  = $this->db->query($sql, [$patientClientId, $doctorPersonaleId])->getRowArray();

        if (!$ok) {
            throw new \RuntimeException('Paziente non assegnato al dottore');
        }
    }

    /* =========================
     * LISTE
     * ========================= */
    public function listInbox(
        int $meActorId,
        string $myRole,
        int $doctorPersonaleId = 0,
        ?string $q = null,
        string $status = 'all',
        int $page = 1,
        int $perPage = 25
    ): array {
        $this->ensureCryptoSession();

        $allowed = [5,10,25,50,100];
        if (!in_array($perPage, $allowed, true)) $perPage = 25;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $perPage;

        $myRole = strtoupper(trim($myRole));
        $doctorPersonaleId = (int)$doctorPersonaleId;

        $crypto = new Crypto_helper();

        $whereVis  = '';
        $paramsVis = [];

        $mailboxActorId = $meActorId;

        if (in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            if ($doctorPersonaleId <= 0 || !$this->staffHasDoctorContextAccess($meActorId, $myRole, $doctorPersonaleId)) {
                return [
                    'rows'  => [],
                    'pager' => [
                        'page'    => $page,
                        'perPage' => $perPage,
                        'total'   => 0,
                        'pages'   => 0,
                    ],
                ];
            }
            $mailboxActorId = $doctorPersonaleId;
        }

        $visibility = $this->buildInboxVisibilityScope('m2', $mailboxActorId, $myRole);
        $whereVis   = $visibility['sql'];
        $paramsVis  = $visibility['params'];

        $q = trim((string)$q);
        if (strlen($q) > 200) $q = substr($q, 0, 200);

        $searchSql = '';
        $paramsSearch = [];
        $searchSql = $this->buildMessageListSearchSql($crypto, 'm', $paramsSearch, $q, [
            ['cS', 'pS'],
            ['cD', 'pD'],
            ['cR', 'pR'],
        ]);

        $threadContextSql = '';
        $threadContextParams = [];
        if ($myRole === 'PAZIENTE' && $this->shouldApplyThreadContextScope($meActorId)) {
            $scope = $this->buildPatientThreadContextScope('m', $meActorId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        } elseif ($myRole === 'DOTTORE' && $this->shouldApplyThreadContextScope($meActorId)) {
            $scope = $this->buildDoctorThreadContextScope('m', $meActorId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        } elseif (
            in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)
            && $this->shouldApplyThreadContextScope($mailboxActorId)
        ) {
            $scope = $this->buildDoctorThreadContextScope('m', $mailboxActorId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        }

        $flagsUserId = $this->getFlagsUserIdForContext($meActorId, $myRole, $doctorPersonaleId);
        $status = strtoupper(trim((string)$status));
        if ($q !== '') {
            $status = 'ALL';
        }
        $threadStatusJoinSql = '';
        $threadStatusWhereSql = '';
        $threadStatusParams = [];
        if ($status === 'HANDLED' || $status === 'UNHANDLED') {
            $threadStatusJoinSql = "
              JOIN msg_threads t2
                ON t2.id_thread = m2.id_thread
              LEFT JOIN msg_user_flags fh2
                ON fh2.id_message = t2.root_message_id
               AND fh2.user_id = ?
            ";
            $threadStatusWhereSql = ($status === 'HANDLED')
                ? " AND COALESCE(fh2.is_handled,0)=1 "
                : " AND COALESCE(fh2.is_handled,0)=0 ";
            $threadStatusParams[] = $flagsUserId;
        }

        $searchActorJoins = '';
        if ($searchSql !== '') {
            $searchActorJoins = "
              {$this->actorNameJoinSql('m.sender_user_id', 'cS', 'pS')}
              {$this->actorNameJoinSql('m.recipient_user_id', 'cD', 'pD')}
              {$this->actorNameJoinSql('m.root_author_user_id', 'cR', 'pR')}
            ";
        }

        $selectSql = "
          SELECT
            m.id_thread,
            m.id_message,
            t.root_message_id,
            m.message_type,
            m.sender_user_id,
            m.recipient_type,
            m.recipient_user_id,
            m.recipient_role,
            m.root_author_user_id,
            m.created_at,

            '' AS body_plain,

            COALESCE(fd.is_read, 0)    AS is_read,
            COALESCE(fd.is_deleted, 0) AS is_deleted,
            COALESCE(fh.is_handled, 0) AS is_handled,

            0 AS has_attachments,

            '' AS sender_nome,
            '' AS sender_cognome,
            '' AS root_nome,
            '' AS root_cognome,
            '' AS sender_role,
            '' AS sender_display

          FROM msg_messages m
          JOIN msg_threads t
            ON t.id_thread = m.id_thread
          INNER JOIN (
              SELECT MAX(m2.id_message) AS last_id
              FROM msg_messages m2
              {$threadStatusJoinSql}
              WHERE {$whereVis}
              {$threadStatusWhereSql}
              GROUP BY m2.id_thread
          ) x ON x.last_id = m.id_message

          LEFT JOIN msg_user_flags fd
            ON fd.id_message = m.id_message
           AND fd.user_id = ?
          LEFT JOIN msg_user_flags fh
            ON fh.id_message = t.root_message_id
           AND fh.user_id = ?

          {$searchActorJoins}

          WHERE COALESCE(fd.is_deleted, 0) = 0
          {$threadContextSql}
          {$searchSql}
        ";

        $params = array_merge($threadStatusParams, $paramsVis, [$flagsUserId, $flagsUserId], $threadContextParams, $paramsSearch);

        /*if ($this->supportsWindowFunctions()) {
            $pagedSql = "
              SELECT listed_rows.*, COUNT(*) OVER() AS full_total_count
              FROM (
                {$selectSql}
              ) listed_rows
              ORDER BY listed_rows.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}
            ";

            $rows = $this->db->query($pagedSql, $params)->getResultArray();

            $total = !empty($rows)
                ? (int)($rows[0]['full_total_count'] ?? count($rows))
                : 0;

            foreach ($rows as &$rowMeta) {
                unset($rowMeta['full_total_count']);
            }
            unset($rowMeta);
        } else {
            $countSql = "
              SELECT COUNT(*) AS cnt
              FROM (
                SELECT m.id_message
                FROM msg_messages m
                JOIN msg_threads t
                  ON t.id_thread = m.id_thread
                INNER JOIN (
                  SELECT MAX(m2.id_message) AS last_id
                  FROM msg_messages m2
                  {$threadStatusJoinSql}
                  WHERE {$whereVis}
                  {$threadStatusWhereSql}
                  GROUP BY m2.id_thread
                ) x ON x.last_id = m.id_message
                LEFT JOIN msg_user_flags fd
                  ON fd.id_message = m.id_message
                 AND fd.user_id = ?
                LEFT JOIN msg_user_flags fh
                  ON fh.id_message = t.root_message_id
                 AND fh.user_id = ?
                {$searchActorJoins}
                WHERE COALESCE(fd.is_deleted, 0) = 0
                {$threadContextSql}
                {$searchSql}
              ) counted_rows
            ";

            $countParams = array_merge($threadStatusParams, $paramsVis, [$flagsUserId, $flagsUserId], $threadContextParams, $paramsSearch);
            $total = (int)($this->db->query($countSql, $countParams)->getRowArray()['cnt'] ?? 0);

            $rows = $this->db->query(
                $selectSql . "
                  ORDER BY m.created_at DESC
                  LIMIT {$perPage} OFFSET {$offset}
                ",
                $params
            )->getResultArray();
        }

        $pages = (int)ceil($total / max(1, $perPage));*/
        $limitPlusOne = $perPage + 1;

        $rows = $this->db->query(
            $selectSql . "
            ORDER BY m.created_at DESC
            LIMIT {$limitPlusOne} OFFSET {$offset}
            ",
            $params
        )->getResultArray();

        $hasMore = count($rows) > $perPage;

        if ($hasMore) {
            $rows = array_slice($rows, 0, $perPage);
        }

        // totale stimato, evita COUNT pesante
        $total = $offset + count($rows) + ($hasMore ? 1 : 0);
        $pages = (int)ceil($total / max(1, $perPage));

        $this->hydrateInboxPagePreviewData($rows);
        $this->preloadInboxThreadCounterparts($rows, $myRole, $mailboxActorId);

        foreach ($rows as &$r) {
            $r = $this->enrichThreadListRow($r, $myRole);
        }
        unset($r);

        $rows = $this->addHumanTime($rows);

        return [
            'rows'  => $rows,
            'pager' => [
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
            ],
        ];
    }

    public function countUnreadInboxThreads(int $meActorId, string $myRole, int $doctorPersonaleId = 0): int
    {
        $this->ensureCryptoSession();

        $myRole = strtoupper(trim($myRole));
        $doctorPersonaleId = (int)$doctorPersonaleId;
        $mailboxActorId = $meActorId;

        if (in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            if ($doctorPersonaleId <= 0 || !$this->staffHasDoctorContextAccess($meActorId, $myRole, $doctorPersonaleId)) {
                return 0;
            }
            $mailboxActorId = $doctorPersonaleId;
        }

        $visibility = $this->buildInboxVisibilityScope('m2', $mailboxActorId, $myRole);
        $threadContextSql = '';
        $threadContextParams = [];

        if ($myRole === 'PAZIENTE' && $this->shouldApplyThreadContextScope($meActorId)) {
            $scope = $this->buildPatientThreadContextScope('m', $meActorId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        } elseif ($myRole === 'DOTTORE' && $this->shouldApplyThreadContextScope($meActorId)) {
            $scope = $this->buildDoctorThreadContextScope('m', $meActorId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        } elseif (
            in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)
            && $this->shouldApplyThreadContextScope($mailboxActorId)
        ) {
            $scope = $this->buildDoctorThreadContextScope('m', $mailboxActorId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        }

        $flagsUserId = $this->getFlagsUserIdForContext($meActorId, $myRole, $doctorPersonaleId);
        $handledWhereSql = '';
        if ($myRole !== 'PAZIENTE') {
            $handledWhereSql = ' AND COALESCE(fh.is_handled, 0) = 0 ';
        }

        $sql = "
          SELECT COUNT(*) AS cnt
          FROM msg_messages m
          JOIN msg_threads t
            ON t.id_thread = m.id_thread
          INNER JOIN (
              SELECT MAX(m2.id_message) AS last_id
              FROM msg_messages m2
              WHERE {$visibility['sql']}
              GROUP BY m2.id_thread
          ) x ON x.last_id = m.id_message
          LEFT JOIN msg_user_flags fd
            ON fd.id_message = m.id_message
           AND fd.user_id = ?
          LEFT JOIN msg_user_flags fh
            ON fh.id_message = t.root_message_id
           AND fh.user_id = ?
          WHERE COALESCE(fd.is_deleted, 0) = 0
            AND COALESCE(fd.is_read, 0) = 0
            {$handledWhereSql}
            {$threadContextSql}
        ";

        $params = array_merge($visibility['params'], [$flagsUserId, $flagsUserId], $threadContextParams);
        $row = $this->db->query($sql, $params)->getRowArray();

        return (int)($row['cnt'] ?? 0);
    }

    public function countUnreadInboxThreadsByDoctorForStaff(string $staffRole, array $doctorPersonaleIds): array
    {
        $this->ensureCryptoSession();

        $staffRole = strtoupper(trim($staffRole));
        if (!in_array($staffRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            return [];
        }

        $doctorPersonaleIds = array_values(array_unique(array_filter(
            array_map('intval', $doctorPersonaleIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($doctorPersonaleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($doctorPersonaleIds), '?'));
        $flagsOffset = $staffRole === 'SEGRETERIA' ? 100000000 : 200000000;

        $sql = "
          SELECT
            m.recipient_user_id AS doctor_id,
            COUNT(*) AS cnt
          FROM msg_messages m
          JOIN msg_threads t
            ON t.id_thread = m.id_thread
          INNER JOIN (
              SELECT
                MAX(m2.id_message) AS last_id,
                m2.recipient_user_id AS doctor_id
              FROM msg_messages m2
              WHERE m2.recipient_type = 'USER'
                AND m2.recipient_user_id IN ({$placeholders})
                AND m2.recipient_role = ?
              GROUP BY m2.id_thread, m2.recipient_user_id
          ) x
            ON x.last_id = m.id_message
           AND x.doctor_id = m.recipient_user_id
          LEFT JOIN msg_user_flags fd
            ON fd.id_message = m.id_message
           AND fd.user_id = ({$flagsOffset} + m.recipient_user_id)
          LEFT JOIN msg_user_flags fh
            ON fh.id_message = t.root_message_id
           AND fh.user_id = ({$flagsOffset} + m.recipient_user_id)
          WHERE COALESCE(fd.is_deleted, 0) = 0
            AND COALESCE(fd.is_read, 0) = 0
            AND COALESCE(fh.is_handled, 0) = 0
          GROUP BY m.recipient_user_id
        ";

        $params = array_merge($doctorPersonaleIds, [$staffRole]);
        $rows = $this->db->query($sql, $params)->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)($row['doctor_id'] ?? 0)] = (int)($row['cnt'] ?? 0);
        }

        return $counts;
    }

    public function listSent(int $meActorId, string $myRole, ?string $q = null, int $page = 1, int $perPage = 25, int $doctorMailboxActorId = 0): array
    {
        $this->ensureCryptoSession();

        $allowed = [5,10,25,50,100];
        if (!in_array($perPage, $allowed, true)) $perPage = 25;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $perPage;
        $myRole = strtoupper(trim($myRole));

        $senderMailboxId = $meActorId;
        if (in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            if ($doctorMailboxActorId <= 0 || !$this->staffHasDoctorContextAccess($meActorId, $myRole, $doctorMailboxActorId)) {
                return [
                    'rows'  => [],
                    'pager' => [
                        'page'    => $page,
                        'perPage' => $perPage,
                        'total'   => 0,
                        'pages'   => 0,
                    ],
                ];
            }
            $senderMailboxId = $doctorMailboxActorId;
        }

        $crypto = new Crypto_helper();

        $senderExpr = $this->actorNameExpr($crypto, 'cS', 'pS');
        $rootExpr   = $this->actorNameExpr($crypto, 'cR', 'pR');

        $senderRoleExpr = $this->actorRoleExpr('cS','pS');
        $senderDisplay  = $this->senderDisplayExpr($senderExpr, $senderRoleExpr);

        $q = trim((string)$q);
        if (strlen($q) > 200) $q = substr($q, 0, 200);

        $searchSql = '';
        $paramsSearch = [];
        $searchSql = $this->buildMessageListSearchSql($crypto, 'm', $paramsSearch, $q, [
            ['cS', 'pS'],
            ['cD', 'pD'],
            ['cR', 'pR'],
        ]);

        $threadContextSql = '';
        $threadContextParams = [];
        if ($myRole === 'DOTTORE' && $this->shouldApplyThreadContextScope($senderMailboxId)) {
            $scope = $this->buildDoctorThreadContextScope('m', $senderMailboxId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        } elseif (
            in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)
            && $this->shouldApplyThreadContextScope($senderMailboxId)
        ) {
            $scope = $this->buildDoctorThreadContextScope('m', $senderMailboxId);
            $threadContextSql = " AND {$scope['sql']} ";
            $threadContextParams = $scope['params'];
        }

        $selectSql = "
          SELECT
            m.id_thread,
            m.id_message,
            m.message_type,
            m.sender_user_id,
            m.recipient_type,
            m.recipient_user_id,
            m.recipient_role,
            m.root_author_user_id,
            m.created_at,

            CAST(AES_DECRYPT(UNHEX(m.body_cipher_hex), @key_str, m.vector_id) AS CHAR(10000) CHARACTER SET utf8mb4) AS body_plain,

            1 AS is_read,
            0 AS is_deleted,

            EXISTS (
              SELECT 1
              FROM msg_attachments a
              WHERE a.id_message = m.id_message
              LIMIT 1
            ) AS has_attachments,

            {$senderExpr['nome']}    AS sender_nome,
            {$senderExpr['cognome']} AS sender_cognome,

            {$rootExpr['nome']}      AS root_nome,
            {$rootExpr['cognome']}   AS root_cognome,

            {$senderRoleExpr} AS sender_role,
            {$senderDisplay}  AS sender_display

          FROM msg_messages m
          INNER JOIN (
            SELECT MAX(m2.id_message) AS last_id
            FROM msg_messages m2
            WHERE m2.sender_user_id = ?
            GROUP BY m2.id_thread
          ) x ON x.last_id = m.id_message

          {$this->actorNameJoinSql('m.sender_user_id', 'cS', 'pS')}
          {$this->actorNameJoinSql('m.recipient_user_id', 'cD', 'pD')}
          {$this->actorNameJoinSql('m.root_author_user_id', 'cR', 'pR')}

          WHERE 1=1
          {$threadContextSql}
          {$searchSql}
        ";

        $params = array_merge([$senderMailboxId], $threadContextParams, $paramsSearch);

        if ($myRole === 'PAZIENTE') {
            $allRows = $this->db->query(
                $selectSql . "
                  ORDER BY m.created_at DESC
                ",
                $params
            )->getResultArray();

            $visibleRows = [];
            foreach ($allRows as $row) {
                $threadId = (int)($row['id_thread'] ?? 0);
                if ($threadId <= 0) {
                    continue;
                }

                $doctorContextId = (int)$this->getDoctorContextIdForThread($threadId);
                $patientContextId = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);
                if ($patientContextId !== $meActorId) {
                    continue;
                }

                if (!$this->isMessageVisibleToPatientContext($row, $threadId, $patientContextId, $doctorContextId)) {
                    continue;
                }

                $visibleRows[] = $row;
            }

            $total = count($visibleRows);
            $pages = (int)ceil($total / max(1, $perPage));
            $rows = array_slice($visibleRows, $offset, $perPage);
        } else {
            $countSql = "
              SELECT COUNT(*) AS cnt
              FROM (
                SELECT m.id_message
                FROM msg_messages m
                INNER JOIN (
                  SELECT MAX(m2.id_message) AS last_id
                  FROM msg_messages m2
                  WHERE m2.sender_user_id = ?
                  GROUP BY m2.id_thread
                ) x ON x.last_id = m.id_message
                {$this->actorNameJoinSql('m.sender_user_id', 'cS', 'pS')}
                {$this->actorNameJoinSql('m.recipient_user_id', 'cD', 'pD')}
                {$this->actorNameJoinSql('m.root_author_user_id', 'cR', 'pR')}
                WHERE 1=1
                {$threadContextSql}
                {$searchSql}
              ) t
            ";

            $countParams = array_merge([$senderMailboxId], $threadContextParams, $paramsSearch);
            $total = (int)($this->db->query($countSql, $countParams)->getRowArray()['cnt'] ?? 0);

            $rows = $this->db->query(
                $selectSql . "
                  ORDER BY m.created_at DESC
                  LIMIT {$perPage} OFFSET {$offset}
                ",
                $params
            )->getResultArray();

            $pages = (int)ceil($total / max(1, $perPage));
        }

        foreach ($rows as &$r) {
            $r = $this->enrichThreadListRow($r, $myRole);
        }
        unset($r);

        $rows = $this->addHumanTime($rows);

        return [
            'rows'  => $rows,
            'pager' => [
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
            ],
        ];
    }

    private function buildDraftOwnershipScope(string $tableAlias, string $viewerRole): string
    {
        $viewerRole = strtoupper(trim($viewerRole));

        if ($viewerRole === 'PAZIENTE') {
            return "{$tableAlias}.recipient_type = 'PATIENT_TARGET'";
        }

        if (in_array($viewerRole, ['DOTTORE', 'SEGRETERIA', 'INFERMIERE'], true)) {
            return "COALESCE({$tableAlias}.recipient_type, '') <> 'PATIENT_TARGET'";
        }

        return '1=0';
    }

    public function listDrafts(int $meActorId, string $myRole, ?string $q = null, int $page = 1, int $perPage = 25): array
    {
        $this->ensureCryptoSession();

        $allowed = [5,10,25,50,100];
        if (!in_array($perPage, $allowed, true)) $perPage = 25;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $perPage;
        $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);

        $q = trim((string)$q);
        if (strlen($q) > 200) $q = substr($q, 0, 200);

        $searchSql = '';
        $params = [$meActorId];

        if ($q !== '') {
            $searchSql = "
              AND LOWER(
                CAST(
                  AES_DECRYPT(UNHEX(d.body_cipher_hex), @key_str, d.vector_id)
                  AS CHAR(10000) CHARACTER SET utf8mb4
                )
              ) LIKE LOWER(?)
            ";
            $params[] = '%' . $q . '%';
        }

        $countSql = "
          SELECT COUNT(*) AS cnt
          FROM msg_drafts d
          WHERE d.owner_user_id = ?
            AND {$draftScopeSql}
          {$searchSql}
        ";

        $total = (int)($this->db->query($countSql, $params)->getRowArray()['cnt'] ?? 0);

        $sql = "
          SELECT
            d.id_draft,
            d.updated_at AS created_at,
            CAST(AES_DECRYPT(UNHEX(d.body_cipher_hex), @key_str, d.vector_id) AS CHAR(10000) CHARACTER SET utf8mb4) AS body_plain
          FROM msg_drafts d
          WHERE d.owner_user_id = ?
            AND {$draftScopeSql}
          {$searchSql}
          ORDER BY d.updated_at DESC
          LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->db->query($sql, $params)->getResultArray();
        $rows = $this->addHumanTime($rows);

        $pages = (int)ceil($total / max(1, $perPage));

        return [
            'rows'  => $rows,
            'pager' => [
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
            ],
        ];
    }

    public function setHandledMessage(int $messageId, int $meActorId, string $myRole, int $flagsUserId, int $handled = 1): void
    {
        $threadId = $this->getThreadIdByMessage($messageId);
        if ($threadId <= 0) {
            throw new \RuntimeException('Thread non trovato');
        }

        if (!$this->canUserAccessThread($meActorId, $myRole, $threadId)) {
            throw new \RuntimeException('Accesso negato (thread)');
        }

        $handled = $handled ? 1 : 0;

        $threadMessageIds = $this->db->query(
            "SELECT id_message FROM msg_messages WHERE id_thread=?",
            [$threadId]
        )->getResultArray();

        foreach ($threadMessageIds as $row) {
            $targetMessageId = (int)($row['id_message'] ?? 0);
            if ($targetMessageId <= 0) {
                continue;
            }

            $this->db->query("
              INSERT INTO msg_user_flags (id_message, user_id, is_handled, handled_at)
              VALUES (?,?,?,NOW())
              ON DUPLICATE KEY UPDATE is_handled=VALUES(is_handled), handled_at=NOW()
            ", [$targetMessageId, $flagsUserId, $handled]);
        }
    }

    private function flagsUserIdForRecipientContext(int $recipientUserId, ?string $recipientRole = null): int
    {
        $recipientRole = strtoupper(trim((string)($recipientRole ?? '')));

        if ($recipientRole === 'SEGRETERIA') {
            return 100000000 + $recipientUserId;
        }

        if ($recipientRole === 'INFERMIERE') {
            return 200000000 + $recipientUserId;
        }

        return $recipientUserId;
    }

    private function markThreadUnhandledForFlagsUser(int $threadId, int $flagsUserId): void
    {
        if ($threadId <= 0 || $flagsUserId <= 0) {
            return;
        }

        $threadMessageIds = $this->db->query(
            "SELECT id_message FROM msg_messages WHERE id_thread=?",
            [$threadId]
        )->getResultArray();

        foreach ($threadMessageIds as $row) {
            $targetMessageId = (int)($row['id_message'] ?? 0);
            if ($targetMessageId <= 0) {
                continue;
            }

            $this->db->query("
              INSERT INTO msg_user_flags (id_message, user_id, is_handled, handled_at)
              VALUES (?,?,0,NULL)
              ON DUPLICATE KEY UPDATE is_handled=0, handled_at=NULL
            ", [$targetMessageId, $flagsUserId]);
        }
    }

    private function reopenThreadForReplyRecipients(
        int $threadId,
        int $recipientUserId,
        ?string $recipientRole
    ): void {
        $flagsUserIds = [];

        if ($recipientUserId > 0) {
            $flagsUserIds[$this->flagsUserIdForRecipientContext($recipientUserId, $recipientRole)] = true;
        }

        foreach (array_keys($flagsUserIds) as $flagsUserId) {
            $this->markThreadUnhandledForFlagsUser($threadId, (int)$flagsUserId);
        }
    }

    private function reopenThreadForForwardRecipient(
        int $threadId,
        int $recipientUserId,
        ?string $recipientRole
    ): void {
        if ($threadId <= 0 || $recipientUserId <= 0) {
            return;
        }

        $flagsUserId = $this->flagsUserIdForRecipientContext($recipientUserId, $recipientRole);
        $this->markThreadUnhandledForFlagsUser($threadId, $flagsUserId);
    }

    public function setHandledBulk(array $ids, int $meActorId, string $myRole, int $flagsUserId, int $handled): int
    {
        $handled = $handled ? 1 : 0;
        $done = 0;

        foreach ($ids as $id) {
            $mid = (int)$id;
            if ($mid <= 0) continue;

            $this->setHandledMessage($mid, $meActorId, $myRole, $flagsUserId, $handled);
            $done++;
        }

        return $done;
    }

    /* =========================
     * DRAFTS
     * ========================= */
    public function saveDraft(array $payload, int $meActorId, string $myRole): array
    {
        $this->ensureCryptoSession();

        $draftId   = (int)($payload['id_draft'] ?? 0);
        $bodyPlain = $this->normalizeBodyText((string)($payload['body'] ?? ''));

        $myRole = strtoupper(trim($myRole));
        $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);

        $recipientType     = strtoupper((string)($payload['recipient_type'] ?? ''));
        $recipientUserId   = array_key_exists('recipient_user_id', $payload) ? (int)$payload['recipient_user_id'] : null;
        $recipientRole     = isset($payload['recipient_role']) ? strtoupper((string)$payload['recipient_role']) : null;
        $patientTargetCode = isset($payload['patient_target_code']) ? strtoupper((string)$payload['patient_target_code']) : null;

        if ($recipientType === '') {
            if ($myRole === 'PAZIENTE') {
                $recipientType     = 'PATIENT_TARGET';
                if ($patientTargetCode === null || trim((string)$patientTargetCode) === '') {
                    $patientTargetCode = strtoupper((string)($payload['patient_target'] ?? ''));
                }
            } elseif ($myRole === 'DOTTORE') {
                $recipientType   = 'USER';
                $recipientUserId = (int)($payload['to_patient'] ?? 0);
            } else {
                $dest = strtoupper((string)($payload['staff_role_dest'] ?? 'ROLE:SEGRETERIA'));
                if (str_starts_with($dest, 'ROLE:')) {
                    $recipientType = 'ROLE';
                    $recipientRole = substr($dest, 5);
                }
            }
        }

        if ($myRole === 'PAZIENTE') {
            $recipientType = 'PATIENT_TARGET';
            $patientTargetCode = strtoupper(trim((string)($patientTargetCode ?? '')));
            if ($patientTargetCode === '') {
                $patientTargetCode = null;
            } elseif (!in_array($patientTargetCode, ['MEDICO', 'SEGRETERIA', 'INFERMIERE'], true)) {
                throw new \RuntimeException('Tipo di messaggio non valido');
            }
        }

        if ($myRole === 'DOTTORE') {
            if ($recipientType !== 'USER' || !$recipientUserId) {
                throw new \RuntimeException('Seleziona un paziente');
            }

            $this->assertDoctorCanMessagePatient($meActorId, (int)$recipientUserId);
        }

        if ($recipientType === 'ROLE') {
            if (!in_array($recipientRole, ['SEGRETERIA','INFERMIERE'], true)) {
                $recipientRole = 'SEGRETERIA';
            }
            $recipientUserId = null;
        }

        $vector = random_bytes(16);

        if ($draftId > 0) {
            $row = $this->db->query(
                "SELECT d.id_draft FROM msg_drafts d WHERE d.id_draft=? AND d.owner_user_id=? AND {$draftScopeSql} LIMIT 1",
                [$draftId, $meActorId]
            )->getRowArray();

            if (!$row) {
                throw new \RuntimeException('Bozza non trovata');
            }

            $this->db->query("
              UPDATE msg_drafts
              SET recipient_type=?,
                  recipient_user_id=?,
                  recipient_role=?,
                  patient_target_code=?,
                  body_cipher_hex=HEX(AES_ENCRYPT(?, @key_str, ?)),
                  vector_id=?,
                  updated_at=NOW()
              WHERE id_draft=? AND owner_user_id=?
            ", [
                $recipientType,
                $recipientUserId,
                $recipientRole,
                $patientTargetCode,
                $bodyPlain,
                $vector,
                $vector,
                $draftId,
                $meActorId
            ]);
        } else {
            $this->db->query("
              INSERT INTO msg_drafts
                (owner_user_id, recipient_type, recipient_user_id, recipient_role, patient_target_code,
                 body_cipher_hex, vector_id, draft_kind, ref_message_id, ref_thread_id)
              VALUES
                (?,?,?,?,?,
                 HEX(AES_ENCRYPT(?, @key_str, ?)), ?, ?, ?, ?)
            ", [
                $meActorId,
                $recipientType,
                $recipientUserId,
                $recipientRole,
                $patientTargetCode,
                $bodyPlain,
                $vector,
                $vector,
                $payload['draft_kind'] ?? 'NEW',
                $payload['ref_message_id'] ?? null,
                $payload['ref_thread_id'] ?? null
            ]);

            $draftId = (int)$this->db->insertID();
        }

        return ['ok' => true, 'id_draft' => $draftId];
    }

    public function loadDraft(int $draftId, int $meActorId, string $myRole): array
    {
        $this->ensureCryptoSession();
        $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);

        $sql = "
          SELECT
            d.id_draft,
            d.owner_user_id,
            d.recipient_type,
            d.recipient_user_id,
            d.recipient_role,
            d.patient_target_code,
            d.draft_kind,
            d.ref_message_id,
            d.ref_thread_id,
            d.created_at,
            d.updated_at,
            d.body_cipher_hex,
            d.vector_id,
            CAST(
              AES_DECRYPT(UNHEX(d.body_cipher_hex), @key_str, d.vector_id)
              AS CHAR(10000) CHARACTER SET utf8mb4
            ) AS body_plain
          FROM msg_drafts d
          WHERE d.id_draft = ? AND d.owner_user_id = ?
            AND {$draftScopeSql}
          LIMIT 1
        ";

        $row = $this->db->query($sql, [$draftId, $meActorId])->getRowArray();

        if (!$row) {
            throw new \RuntimeException('Bozza non trovata');
        }

        $row['body_plain'] = $this->normalizeBodyText($row['body_plain'] ?? '');

        if (method_exists($this, 'listDraftAttachments')) {
            $row['attachments'] = $this->listDraftAttachments($draftId, $meActorId, $myRole);
        } else {
            $row['attachments'] = [];
        }

        return $row;
    }

    /* =========================
     * Allegati bozza
     * ========================= */
    private function insertAttachmentRecord(?int $draftId, ?int $messageId, int $meActorId, array $meta): void
    {
        $vector = random_bytes(16);

        $this->db->query("
          INSERT INTO msg_attachments
            (id_draft, id_message, uploaded_by_user_id, original_name, stored_name, mime_type, file_size, storage_path, vector_id)
          VALUES (
            ?, ?, ?,
            HEX(AES_ENCRYPT(?, @key_str, ?)),
            HEX(AES_ENCRYPT(?, @key_str, ?)),
            ?, ?,
            HEX(AES_ENCRYPT(?, @key_str, ?)),
            ?
          )
        ", [
            $draftId,
            $messageId,
            $meActorId,

            (string)$meta['original_name'],
            $vector,

            (string)$meta['stored_name'],
            $vector,

            (string)$meta['mime_type'],
            (int)$meta['file_size'],

            (string)$meta['storage_path'],
            $vector,

            $vector,
        ]);
    }

   public function addDraftAttachment(int $draftId, int $meActorId, string $myRole, array $meta): void
{
    $this->ensureCryptoSession();
    $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);

    $row = $this->db->query(
        "SELECT 1 FROM msg_drafts d WHERE d.id_draft=? AND d.owner_user_id=? AND {$draftScopeSql} LIMIT 1",
        [$draftId, $meActorId]
    )->getRowArray();

    if (!$row) {
        throw new \RuntimeException('Bozza non trovata');
    }

    $this->insertAttachmentRecord($draftId, null, $meActorId, $meta);
}

    public function addMessageAttachment(int $messageId, int $meActorId, array $meta): void
    {
        $this->ensureCryptoSession();

        $row = $this->db->query(
            "SELECT 1 FROM msg_messages WHERE id_message=? LIMIT 1",
            [$messageId]
        )->getRowArray();

        if (!$row) {
            throw new \RuntimeException('Messaggio non trovato');
        }

        $this->insertAttachmentRecord(null, $messageId, $meActorId, $meta);
    }

  public function listDraftAttachments(int $draftId, int $meActorId, string $myRole): array
{
    $this->ensureCryptoSession();
    $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);

    $sql = "
      SELECT
        a.id_attachment,
        a.mime_type,
        a.file_size,
        a.created_at,

        {$this->attachmentDecryptExpr('a.original_name', 'a.vector_id')} AS original_name,
        {$this->attachmentDecryptExpr('a.stored_name', 'a.vector_id')}   AS stored_name

      FROM msg_attachments a
      JOIN msg_drafts d
        ON d.id_draft = a.id_draft
      WHERE a.id_draft=?
        AND d.owner_user_id=?
        AND {$draftScopeSql}
      ORDER BY a.created_at ASC
    ";

    return $this->db->query($sql, [$draftId, $meActorId])->getResultArray();
}
public function deleteDraftAttachment(int $attachmentId, int $meActorId, string $myRole): void
{
    $this->ensureCryptoSession();
    $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);

    $row = $this->db->query("
      SELECT
        a.id_attachment,
        {$this->attachmentDecryptExpr('a.storage_path', 'a.vector_id')} AS storage_path
      FROM msg_attachments a
      JOIN msg_drafts d
        ON d.id_draft = a.id_draft
      WHERE a.id_attachment=?
        AND d.owner_user_id=?
        AND {$draftScopeSql}
        AND a.id_draft IS NOT NULL
      LIMIT 1
    ", [$attachmentId, $meActorId])->getRowArray();

    if (!$row) {
        throw new \RuntimeException('Allegato non trovato');
    }

    $this->db->query(
        "DELETE FROM msg_attachments WHERE id_attachment=? LIMIT 1",
        [$attachmentId]
    );

    $path = (string)($row['storage_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

    /* =========================
     * Resolve attori/thread
     * ========================= */
    private function decryptClientNameById(int $clientId): ?array
    {
        if ($clientId <= 0) return null;

        if (array_key_exists($clientId, $this->clientActorCache)) {
            return $this->clientActorCache[$clientId];
        }

        $crypto = new Crypto_helper();
        $DEC_NOME = $crypto->decrypt_concat('c.nome');
        $DEC_COGN = $crypto->decrypt_concat('c.cognome');

        $row = $this->db->query("
            SELECT
              c.id_client,
              TRIM(CAST({$DEC_NOME} AS CHAR)) AS nome,
              TRIM(CAST({$DEC_COGN} AS CHAR)) AS cognome
            FROM dap02_clients c
            WHERE c.id_client = ?
            LIMIT 1
        ", [$clientId])->getRowArray();

        if (!$row) {
            $this->clientActorCache[$clientId] = null;
            return null;
        }

        if (SystemUserMask::isMaskedClientId((int)$row['id_client'])) {
            return $this->clientActorCache[$clientId] = [
                'kind'     => 'CLIENT',
                'id'       => (int)$row['id_client'],
                'nome'     => SystemUserMask::SYSTEM_USER_LABEL,
                'cognome'  => '',
                'role'     => 'PAZIENTE',
                'display'  => SystemUserMask::SYSTEM_USER_LABEL,
            ];
        }

        return $this->clientActorCache[$clientId] = [
            'kind'     => 'CLIENT',
            'id'       => (int)$row['id_client'],
            'nome'     => (string)($row['nome'] ?? ''),
            'cognome'  => (string)($row['cognome'] ?? ''),
            'role'     => 'PAZIENTE',
            'display'  => trim(((string)($row['cognome'] ?? '')) . ' ' . ((string)($row['nome'] ?? ''))),
        ];
    }

    private function getPersonaleLabelFromTipo(int $tipo): string
    {
        return match ($tipo) {
            1 => 'DOTTORE',
            2 => 'INFERMIERE',
            3 => 'SEGRETERIA',
            default => 'PERSONALE',
        };
    }

    private function getPersonaleDisplayLabel(string $role, string $nome, string $cognome): string
    {
        $role = strtoupper(trim($role));
        if ($role === 'SEGRETERIA') return 'SEGRETERIA';
        if ($role === 'INFERMIERE') return 'INFERMIERE';
        return trim($cognome . ' ' . $nome);
    }

    private function getPersonaleById(int $personaleId): ?array
    {
        if ($personaleId <= 0) return null;

        if (array_key_exists($personaleId, $this->personaleActorCache)) {
            return $this->personaleActorCache[$personaleId];
        }

        $this->ensureCryptoSession();
        $crypto = new Crypto_helper();
        $DEC_NOME = $crypto->decrypt_concat('p.nome');
        $DEC_COGN = $crypto->decrypt_concat('p.cognome');

        $row = $this->db->query("
            SELECT
              p.id_personale,
              p.tipo,
              TRIM(CAST({$DEC_NOME} AS CHAR)) AS nome,
              TRIM(CAST({$DEC_COGN} AS CHAR)) AS cognome
            FROM dap03_personale p
            WHERE p.id_personale = ?
            LIMIT 1
        ", [$personaleId])->getRowArray();

        if (!$row) {
            $this->personaleActorCache[$personaleId] = null;
            return null;
        }

        $role = $this->getPersonaleLabelFromTipo((int)($row['tipo'] ?? 0));
        $nome = trim((string)($row['nome'] ?? ''));
        $cognome = trim((string)($row['cognome'] ?? ''));

        return $this->personaleActorCache[$personaleId] = [
            'kind'     => 'PERSONALE',
            'id'       => (int)$row['id_personale'],
            'nome'     => $nome,
            'cognome'  => $cognome,
            'role'     => $role,
            'display'  => $this->getPersonaleDisplayLabel($role, $nome, $cognome),
        ];
    }

    private function getRootAuthorActorIdForThread(int $threadId): int
    {
        if (array_key_exists($threadId, $this->rootAuthorByThreadCache)) {
            return $this->rootAuthorByThreadCache[$threadId];
        }

        $row = $this->db->query("
            SELECT t.root_author_user_id
            FROM msg_threads t
            WHERE t.id_thread = ?
            LIMIT 1
        ", [$threadId])->getRowArray();

        return $this->rootAuthorByThreadCache[$threadId] = (int)($row['root_author_user_id'] ?? 0);
    }

    private function isClientActorId(int $actorId): bool
    {
        if ($actorId <= 0) {
            return false;
        }

        if (array_key_exists($actorId, $this->clientExistsCache)) {
            return $this->clientExistsCache[$actorId];
        }

        return $this->clientExistsCache[$actorId] = (bool)$this->db->query(
            "SELECT 1 FROM dap02_clients WHERE id_client = ? LIMIT 1",
            [$actorId]
        )->getRowArray();
    }

    private function isDoctorPersonaleActorId(int $actorId): bool
    {
        if ($actorId <= 0) {
            return false;
        }

        if (array_key_exists($actorId, $this->doctorPersonaleExistsCache)) {
            return $this->doctorPersonaleExistsCache[$actorId];
        }

        return $this->doctorPersonaleExistsCache[$actorId] = (bool)$this->db->query("
            SELECT 1
            FROM dap03_personale
            WHERE id_personale = ?
              AND tipo = 1
            LIMIT 1
        ", [$actorId])->getRowArray();
    }

    private function getMappedDoctorIdForClient(int $clientId): int
    {
        if ($clientId <= 0) {
            return 0;
        }

        if (array_key_exists($clientId, $this->mappedDoctorByClientCache)) {
            return $this->mappedDoctorByClientCache[$clientId];
        }

        $row = $this->db->query("
            SELECT cd.id_dot AS doctor_personale_id
            FROM dap09_client_doctor cd
            JOIN dap03_personale p
              ON p.id_personale = cd.id_dot
             AND p.tipo = 1
            WHERE cd.id_client = ?
            LIMIT 1
        ", [$clientId])->getRowArray();

        return $this->mappedDoctorByClientCache[$clientId] = (int)($row['doctor_personale_id'] ?? 0);
    }

    private function isDoctorPatientPair(int $doctorActorId, int $clientActorId): bool
    {
        if ($doctorActorId <= 0 || $clientActorId <= 0) {
            return false;
        }

        $cacheKey = $doctorActorId . ':' . $clientActorId;
        if (array_key_exists($cacheKey, $this->doctorPatientPairCache)) {
            return $this->doctorPatientPairCache[$cacheKey];
        }

        if (!$this->isDoctorPersonaleActorId($doctorActorId)) {
            return $this->doctorPatientPairCache[$cacheKey] = false;
        }

        $row = $this->db->query(
            "SELECT 1 FROM dap09_client_doctor WHERE id_client = ? AND id_dot = ? LIMIT 1",
            [$clientActorId, $doctorActorId]
        )->getRowArray();

        return $this->doctorPatientPairCache[$cacheKey] = !empty($row);
    }

    private function getRootParticipantsForThread(int $threadId): ?array
    {
        if ($threadId <= 0) {
            return null;
        }

        if (array_key_exists($threadId, $this->rootParticipantsByThreadCache)) {
            return $this->rootParticipantsByThreadCache[$threadId];
        }

        $row = $this->db->query("
            SELECT
              t.root_author_user_id,
              m.sender_user_id,
              m.recipient_type,
              m.recipient_user_id,
              m.recipient_role
            FROM msg_threads t
            LEFT JOIN msg_messages m
              ON m.id_message = t.root_message_id
            WHERE t.id_thread = ?
            LIMIT 1
        ", [$threadId])->getRowArray();

        if (!$row || empty($row['sender_user_id'])) {
            $row = $this->db->query("
                SELECT
                  t.root_author_user_id,
                  m.sender_user_id,
                  m.recipient_type,
                  m.recipient_user_id,
                  m.recipient_role
                FROM msg_threads t
                JOIN msg_messages m
                  ON m.id_thread = t.id_thread
                WHERE t.id_thread = ?
                ORDER BY m.created_at ASC, m.id_message ASC
                LIMIT 1
            ", [$threadId])->getRowArray();
        }

        if (!$row) {
            return $this->rootParticipantsByThreadCache[$threadId] = null;
        }

        return $this->rootParticipantsByThreadCache[$threadId] = [
            'root_author_user_id' => (int)($row['root_author_user_id'] ?? 0),
            'sender_user_id'      => (int)($row['sender_user_id'] ?? 0),
            'recipient_type'      => strtoupper(trim((string)($row['recipient_type'] ?? ''))),
            'recipient_user_id'   => (int)($row['recipient_user_id'] ?? 0),
            'recipient_role'      => strtoupper(trim((string)($row['recipient_role'] ?? ''))),
        ];
    }

    private function getDoctorContextIdFromRootParticipants(int $threadId, int $rootAuthorActorId = 0): int
    {
        $root = $this->getRootParticipantsForThread($threadId);
        if (!$root) {
            return 0;
        }

        $senderId = (int)($root['sender_user_id'] ?? 0);
        $recipientType = (string)($root['recipient_type'] ?? '');
        $recipientId = (int)($root['recipient_user_id'] ?? 0);
        $recipientRole = (string)($root['recipient_role'] ?? '');

        if ($recipientType === 'USER' && $recipientId > 0) {
            if ($this->isDoctorPatientPair($senderId, $recipientId)) {
                return $senderId;
            }

            if ($this->isDoctorPatientPair($recipientId, $senderId)) {
                return $recipientId;
            }
        }

        if ($senderId > 0 && $recipientRole !== '' && $this->isDoctorPersonaleActorId($senderId)) {
            return $senderId;
        }

        if ($rootAuthorActorId > 0 && $rootAuthorActorId === $senderId && !$this->isActorIdAmbiguousBetweenClientAndPersonale($rootAuthorActorId)) {
            if ($this->isDoctorPersonaleActorId($rootAuthorActorId)) {
                return $rootAuthorActorId;
            }
        }

        return 0;
    }

    private function getMappedDoctorParticipantIdForThread(int $threadId): int
    {
        if (array_key_exists($threadId, $this->mappedDoctorByThreadCache)) {
            return $this->mappedDoctorByThreadCache[$threadId];
        }

        $row = $this->db->query("
            SELECT cd.id_dot AS doctor_personale_id
            FROM msg_messages m
            JOIN dap09_client_doctor cd
              ON cd.id_client IN (m.sender_user_id, COALESCE(m.recipient_user_id, -1))
            JOIN dap03_personale p
              ON p.id_personale = cd.id_dot
             AND p.tipo = 1
            WHERE m.id_thread = ?
              AND EXISTS (
                SELECT 1
                FROM msg_messages md
                WHERE md.id_thread = m.id_thread
                  AND cd.id_dot IN (md.sender_user_id, COALESCE(md.recipient_user_id, -1))
              )
            GROUP BY cd.id_dot
            ORDER BY MIN(m.created_at) ASC, cd.id_dot ASC
            LIMIT 1
        ", [$threadId])->getRowArray();

        return $this->mappedDoctorByThreadCache[$threadId] = (int)($row['doctor_personale_id'] ?? 0);
    }

    private function getAnyMappedDoctorIdForThread(int $threadId): int
    {
        if (array_key_exists($threadId, $this->anyMappedDoctorByThreadCache)) {
            return $this->anyMappedDoctorByThreadCache[$threadId];
        }

        $row = $this->db->query("
            SELECT cd.id_dot AS doctor_personale_id
            FROM msg_messages m
            JOIN dap09_client_doctor cd
              ON cd.id_client IN (m.sender_user_id, COALESCE(m.recipient_user_id, -1))
            JOIN dap03_personale p
              ON p.id_personale = cd.id_dot
             AND p.tipo = 1
            WHERE m.id_thread = ?
            GROUP BY cd.id_dot
            ORDER BY MIN(m.created_at) ASC, cd.id_dot ASC
            LIMIT 1
        ", [$threadId])->getRowArray();

        return $this->anyMappedDoctorByThreadCache[$threadId] = (int)($row['doctor_personale_id'] ?? 0);
    }

    private function getMappedPatientParticipantIdForThread(int $threadId, int $doctorContextId): int
    {
        if ($threadId <= 0 || $doctorContextId <= 0) {
            return 0;
        }

        $cacheKey = $threadId . ':' . $doctorContextId;
        if (array_key_exists($cacheKey, $this->mappedPatientByThreadDoctorCache)) {
            return $this->mappedPatientByThreadDoctorCache[$cacheKey];
        }

        $row = $this->db->query("
            SELECT cd.id_client
            FROM msg_messages m
            JOIN dap09_client_doctor cd
              ON cd.id_client IN (m.sender_user_id, COALESCE(m.recipient_user_id, -1))
            WHERE m.id_thread = ?
              AND cd.id_dot = ?
            GROUP BY cd.id_client
            ORDER BY MIN(m.created_at) ASC, cd.id_client ASC
            LIMIT 1
        ", [$threadId, $doctorContextId])->getRowArray();

        return $this->mappedPatientByThreadDoctorCache[$cacheKey] = (int)($row['id_client'] ?? 0);
    }

    private function getAnyClientParticipantIdForThread(int $threadId, int $excludeActorId = 0): int
    {
        if ($threadId <= 0) {
            return 0;
        }

        $cacheKey = $threadId . ':' . $excludeActorId;
        if (array_key_exists($cacheKey, $this->anyClientByThreadCache)) {
            return $this->anyClientByThreadCache[$cacheKey];
        }

        $row = $this->db->query("
            SELECT x.actor_id
            FROM (
                SELECT m.sender_user_id AS actor_id, m.created_at
                FROM msg_messages m
                WHERE m.id_thread = ?
                  AND m.sender_user_id > 0

                UNION ALL

                SELECT m.recipient_user_id AS actor_id, m.created_at
                FROM msg_messages m
                WHERE m.id_thread = ?
                  AND m.recipient_type = 'USER'
                  AND m.recipient_user_id IS NOT NULL
                  AND m.recipient_user_id > 0
            ) x
            JOIN dap02_clients c
              ON c.id_client = x.actor_id
            WHERE (? <= 0 OR x.actor_id <> ?)
            GROUP BY x.actor_id
            ORDER BY MIN(x.created_at) ASC, x.actor_id ASC
            LIMIT 1
        ", [$threadId, $threadId, $excludeActorId, $excludeActorId])->getRowArray();

        return $this->anyClientByThreadCache[$cacheKey] = (int)($row['actor_id'] ?? 0);
    }

    private function getPatientContextIdForThread(int $threadId, int $doctorContextId = 0): int
    {
        $cacheKey = $threadId . ':' . $doctorContextId;
        if (array_key_exists($cacheKey, $this->patientContextByThreadCache)) {
            return $this->patientContextByThreadCache[$cacheKey];
        }

        if ($doctorContextId > 0) {
            $clientId = $this->getMappedPatientParticipantIdForThread($threadId, $doctorContextId);
            if ($clientId > 0) {
                return $this->patientContextByThreadCache[$cacheKey] = $clientId;
            }

            $clientId = $this->getAnyClientParticipantIdForThread($threadId, $doctorContextId);
            if ($clientId > 0) {
                return $this->patientContextByThreadCache[$cacheKey] = $clientId;
            }
        }

        $rootAuthor = $this->getRootAuthorActorIdForThread($threadId);
        if ($rootAuthor > 0 && $rootAuthor !== $doctorContextId && $this->isClientActorId($rootAuthor)) {
            return $this->patientContextByThreadCache[$cacheKey] = $rootAuthor;
        }

        return $this->patientContextByThreadCache[$cacheKey] = 0;
    }

    private function enrichThreadListRow(array $row, string $viewerRole): array
    {
        $threadId = (int)($row['id_thread'] ?? 0);
        if ($threadId <= 0) {
            return $row;
        }

        $viewerRole = strtoupper(trim($viewerRole));
        $isForward = strtoupper((string)($row['message_type'] ?? '')) === 'FORWARD';
        $row['thread_counterpart_display'] = '';
        $doctorContextId = 0;
        $patientContextId = 0;

        if (in_array($viewerRole, ['DOTTORE', 'SEGRETERIA', 'INFERMIERE'], true)) {
            $doctorContextId = (int)$this->getDoctorContextIdForThread($threadId);
            $patientDisplay = trim((string)($this->threadCounterpartDisplayCache[$threadId] ?? ''));

            if ($patientDisplay === '' && $doctorContextId > 0) {
                $patientContextId = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);
                if ($patientContextId > 0) {
                    $patient = $this->decryptClientNameById($patientContextId) ?? [
                        'display' => '',
                        'cognome' => '',
                        'nome'    => '',
                    ];

                    $patientDisplay = trim((string)($patient['display'] ?? ''));
                    if ($patientDisplay === '') {
                        $patientDisplay = trim(
                            trim((string)($patient['cognome'] ?? '')) . ' ' . trim((string)($patient['nome'] ?? ''))
                        );
                    }
                    $this->threadCounterpartDisplayCache[$threadId] = $patientDisplay;
                }
            } elseif ($doctorContextId > 0) {
                $patientContextId = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);
            }

            $row['thread_counterpart_display'] = $patientDisplay;
        } else {
            $doctorContextId  = (int)$this->getDoctorContextIdForThread($threadId);
            $patientContextId = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);
        }

        $needsSenderDetails = ($viewerRole === 'PAZIENTE') || $isForward || $row['thread_counterpart_display'] === '';
        if (!$needsSenderDetails) {
            $row['sender_nome'] = '';
            $row['sender_cognome'] = '';
            $row['sender_role'] = '';
            $row['sender_display'] = '';
            $row['root_nome'] = '';
            $row['root_cognome'] = '';
            return $row;
        }

        $sender = $this->resolveActorForThread(
            (int)($row['sender_user_id'] ?? 0),
            $threadId,
            $doctorContextId,
            $patientContextId,
            false
        );

        $root = $this->resolveActorForThread(
            (int)($row['root_author_user_id'] ?? 0),
            $threadId,
            $doctorContextId,
            $patientContextId,
            false
        );

        $row['sender_nome']    = $sender['nome'];
        $row['sender_cognome'] = $sender['cognome'];
        $row['sender_role']    = $sender['role'];
        $row['sender_display'] = $sender['display'] !== '' ? $sender['display'] : 'Mittente';

        $row['root_nome']      = $root['nome'];
        $row['root_cognome']   = $root['cognome'];

        return $row;
    }

    private function resolveRecipientActorForMessage(
        array $message,
        int $threadId,
        int $doctorContextId,
        int $patientContextId
    ): array {
        $preferRecipientPersonale = (
            strtoupper((string)($message['recipient_type'] ?? '')) === 'USER'
            && (
                !empty($message['recipient_role'])
                || (int)($message['recipient_user_id'] ?? 0) === $doctorContextId
                || (int)($message['sender_user_id'] ?? 0) === $patientContextId
            )
        );

        return $this->resolveActorForThread(
            (int)($message['recipient_user_id'] ?? 0),
            $threadId,
            $doctorContextId,
            $patientContextId,
            $preferRecipientPersonale
        );
    }

    private function isMessageVisibleToPatientContext(
        array $message,
        int $threadId,
        int $patientContextId,
        int $doctorContextId = 0
    ): bool {
        if ($threadId <= 0 || $patientContextId <= 0) {
            return false;
        }

        if ($doctorContextId <= 0) {
            $doctorContextId = $this->getDoctorContextIdForThread($threadId);
        }

        $sender = $this->resolveActorForThread(
            (int)($message['sender_user_id'] ?? 0),
            $threadId,
            $doctorContextId,
            $patientContextId,
            false
        );

        $recipient = $this->resolveRecipientActorForMessage(
            $message,
            $threadId,
            $doctorContextId,
            $patientContextId
        );

        $senderIsPatient = (($sender['kind'] ?? '') === 'CLIENT')
            && (int)($sender['id'] ?? 0) === $patientContextId;

        $recipientIsPatient = (
            strtoupper((string)($message['recipient_type'] ?? '')) === 'USER'
            && (($recipient['kind'] ?? '') === 'CLIENT')
            && (int)($recipient['id'] ?? 0) === $patientContextId
        );

        return $senderIsPatient || $recipientIsPatient;
    }

    private function isMessageVisibleToStaffRoleContext(
        array $message,
        int $threadId,
        int $doctorContextId,
        string $staffRole,
        array $messageIndex = [],
        array &$memo = [],
        array $trail = []
    ): bool {
        $messageId = (int)($message['id_message'] ?? 0);
        if ($messageId <= 0 || $threadId <= 0 || $doctorContextId <= 0) {
            return false;
        }

        $staffRole = strtoupper(trim($staffRole));
        if (!in_array($staffRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
            return false;
        }

        if (array_key_exists($messageId, $memo)) {
            return (bool)$memo[$messageId];
        }

        if (isset($trail[$messageId])) {
            $memo[$messageId] = false;
            return false;
        }
        $trail[$messageId] = true;

        $recipientType = strtoupper(trim((string)($message['recipient_type'] ?? '')));
        $recipientRole = strtoupper(trim((string)($message['recipient_role'] ?? '')));
        $recipientUserId = (int)($message['recipient_user_id'] ?? 0);

        if (
            $recipientType === 'USER'
            && $recipientUserId === $doctorContextId
            && $recipientRole === $staffRole
        ) {
            $memo[$messageId] = true;
            return true;
        }

        $parentMessageId = (int)($message['parent_message_id'] ?? 0);
        if ($parentMessageId <= 0) {
            $memo[$messageId] = false;
            return false;
        }

        $parent = $messageIndex[$parentMessageId] ?? null;
        if (!is_array($parent)) {
            $parent = $this->db->query(
                "SELECT id_message, id_thread, parent_message_id, recipient_type, recipient_user_id, recipient_role
                 FROM msg_messages
                 WHERE id_message=? LIMIT 1",
                [$parentMessageId]
            )->getRowArray();
        }

        if (!$parent || (int)($parent['id_thread'] ?? 0) !== $threadId) {
            $memo[$messageId] = false;
            return false;
        }

        $parentVisible = $this->isMessageVisibleToStaffRoleContext(
            $parent,
            $threadId,
            $doctorContextId,
            $staffRole,
            $messageIndex,
            $memo,
            $trail
        );

        if (!$parentVisible) {
            $memo[$messageId] = false;
            return false;
        }

        $senderUserId = (int)($message['sender_user_id'] ?? 0);
        $memo[$messageId] = $senderUserId > 0
            && $this->staffHasDoctorContextAccess($senderUserId, $staffRole, $doctorContextId);

        return (bool)$memo[$messageId];
    }

    private function resolveActorForThread(
        int $actorId,
        int $threadId,
        int $doctorContextId = 0,
        int $patientContextId = 0,
        bool $preferPersonale = false
    ): array {
        if ($actorId <= 0) {
            return ['kind'=>null,'id'=>0,'nome'=>'','cognome'=>'','role'=>'','display'=>''];
        }

        $cacheKey = implode(':', [$actorId, $threadId, $doctorContextId, $patientContextId, $preferPersonale ? 1 : 0]);
        if (array_key_exists($cacheKey, $this->actorResolutionCache)) {
            return $this->actorResolutionCache[$cacheKey];
        }

        if ($doctorContextId > 0 && $actorId === $doctorContextId) {
            $p = $this->getPersonaleById($actorId);
            if ($p) return $this->actorResolutionCache[$cacheKey] = $p;
        }

        if ($patientContextId > 0 && $actorId === $patientContextId) {
            $c = $this->decryptClientNameById($actorId);
            if ($c) return $this->actorResolutionCache[$cacheKey] = $c;
        }

        $personale = $this->getPersonaleById($actorId);
        $client    = $this->decryptClientNameById($actorId);

        if ($personale && !$client) return $this->actorResolutionCache[$cacheKey] = $personale;
        if ($client && !$personale) return $this->actorResolutionCache[$cacheKey] = $client;

        if ($personale && $client) {
            if ($preferPersonale) return $this->actorResolutionCache[$cacheKey] = $personale;

            if ($doctorContextId > 0) {
                $row = $this->db->query("
                    SELECT 1
                    FROM dap09_client_doctor
                    WHERE id_client = ? AND id_dot = ?
                    LIMIT 1
                ", [$actorId, $doctorContextId])->getRowArray();

                if ($row) return $this->actorResolutionCache[$cacheKey] = $client;
            }

            return $this->actorResolutionCache[$cacheKey] = $personale;
        }

        return $this->actorResolutionCache[$cacheKey] = ['kind'=>null,'id'=>$actorId,'nome'=>'','cognome'=>'','role'=>'','display'=>''];
    }

    public function getDoctorContextIdForThread(int $threadId): int
    {
        if (array_key_exists($threadId, $this->threadDoctorContextHintCache)) {
            return $this->threadDoctorContextHintCache[$threadId];
        }

        if (array_key_exists($threadId, $this->doctorContextByThreadCache)) {
            return $this->doctorContextByThreadCache[$threadId];
        }

        $rootAuthor = $this->getRootAuthorActorIdForThread($threadId);

        $doctorId = $this->getDoctorContextIdFromRootParticipants($threadId, $rootAuthor);
        if ($doctorId > 0) {
            return $this->doctorContextByThreadCache[$threadId] = $doctorId;
        }

        if (
            $rootAuthor > 0
            && !$this->isActorIdAmbiguousBetweenClientAndPersonale($rootAuthor)
            && $this->isDoctorPersonaleActorId($rootAuthor)
        ) {
            return $this->doctorContextByThreadCache[$threadId] = $rootAuthor;
        }

        if ($rootAuthor > 0 && $this->isClientActorId($rootAuthor)) {
            $doctorId = $this->getMappedDoctorIdForClient($rootAuthor);
            if ($doctorId > 0) {
                return $this->doctorContextByThreadCache[$threadId] = $doctorId;
            }
        }

        $doctorId = $this->getMappedDoctorParticipantIdForThread($threadId);
        if ($doctorId > 0) {
            return $this->doctorContextByThreadCache[$threadId] = $doctorId;
        }

        if ($rootAuthor > 0 && $this->isDoctorPersonaleActorId($rootAuthor)) {
            return $this->doctorContextByThreadCache[$threadId] = $rootAuthor;
        }

        $doctorId = $this->getAnyMappedDoctorIdForThread($threadId);
        if ($doctorId > 0) {
            return $this->doctorContextByThreadCache[$threadId] = $doctorId;
        }

        return $this->doctorContextByThreadCache[$threadId] = 0;
    }

    private function resolveDoctorContextId(int $threadId, int $rootAuthorActorId): int
    {
        $doctorId = $this->getDoctorContextIdFromRootParticipants($threadId, $rootAuthorActorId);
        if ($doctorId > 0) {
            return $doctorId;
        }

        if (
            $rootAuthorActorId > 0
            && !$this->isActorIdAmbiguousBetweenClientAndPersonale($rootAuthorActorId)
            && $this->isDoctorPersonaleActorId($rootAuthorActorId)
        ) {
            return $rootAuthorActorId;
        }

        if ($rootAuthorActorId > 0 && $this->isClientActorId($rootAuthorActorId)) {
            $doctorId = $this->getMappedDoctorIdForClient($rootAuthorActorId);
            if ($doctorId > 0) {
                return $doctorId;
            }
        }

        $doctorId = $this->getMappedDoctorParticipantIdForThread($threadId);
        if ($doctorId > 0) {
            return $doctorId;
        }

        if ($rootAuthorActorId > 0 && $this->isDoctorPersonaleActorId($rootAuthorActorId)) {
            return $rootAuthorActorId;
        }

        $doctorId = $this->getAnyMappedDoctorIdForThread($threadId);
        if ($doctorId > 0) {
            return $doctorId;
        }

        throw new \RuntimeException('Impossibile determinare il dottore contesto del thread');
    }

    /* =========================
     * THREAD DETAIL
     * ========================= */
    public function getThreadMessages(int $meActorId, string $myRole, int $threadId): array
    {
        if (!$this->canUserAccessThread($meActorId, $myRole, $threadId)) {
            throw new \RuntimeException('Accesso negato (thread)');
        }

        $this->ensureCryptoSession();

        $crypto = new Crypto_helper();

        $senderExpr = $this->actorNameExpr($crypto, 'cS', 'pS');
        $rootExpr   = $this->actorNameExpr($crypto, 'cR', 'pR');
        $destExpr   = $this->actorNameExpr($crypto, 'cD', 'pD');

        $senderRoleExpr = $this->actorRoleExpr('cS','pS');
        $senderDisplay  = $this->senderDisplayExpr($senderExpr, $senderRoleExpr);

        $myRole = strtoupper(trim($myRole));
        $threadVisibilitySql = '';

        if ($myRole === 'PAZIENTE') {
            $threadVisibilitySql = "
              AND (
                m.sender_user_id = {$meActorId}
                OR (
                  m.recipient_type = 'USER'
                  AND m.recipient_user_id = {$meActorId}
                )
              )
            ";
        }

        $sql = "
          SELECT
            m.*,

            CAST(AES_DECRYPT(UNHEX(m.body_cipher_hex), @key_str, m.vector_id) AS CHAR(10000) CHARACTER SET utf8mb4) AS body_plain,

            {$senderExpr['nome']}    AS sender_nome,
            {$senderExpr['cognome']} AS sender_cognome,

            {$rootExpr['nome']}      AS root_nome,
            {$rootExpr['cognome']}   AS root_cognome,

            CASE WHEN m.recipient_type='USER' THEN {$destExpr['nome']} ELSE NULL END AS recipient_nome,
            CASE WHEN m.recipient_type='USER' THEN {$destExpr['cognome']} ELSE NULL END AS recipient_cognome,

            {$senderRoleExpr} AS sender_role,
            {$senderDisplay}  AS sender_display

          FROM msg_messages m

          {$this->actorNameJoinSql('m.sender_user_id', 'cS', 'pS')}
          {$this->actorNameJoinSql('m.root_author_user_id', 'cR', 'pR')}
          {$this->actorNameJoinSql('m.recipient_user_id', 'cD', 'pD')}

          WHERE m.id_thread = ?
          {$threadVisibilitySql}
          ORDER BY m.created_at desc
        ";

        $doctorContextId  = (int)$this->getDoctorContextIdForThread($threadId);
        $patientContextId = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);
        $rows = $this->db->query($sql, [$threadId])->getResultArray();
        $messageIndex = [];
        foreach ($rows as $row) {
            $messageIndex[(int)($row['id_message'] ?? 0)] = $row;
        }
        $staffVisibilityMemo = [];
        $attachmentsByMessage = $this->listAttachmentsForMessages(array_column($rows, 'id_message'));

        $visibleRows = [];
        foreach ($rows as $r) {
            $sender = $this->resolveActorForThread(
                (int)($r['sender_user_id'] ?? 0),
                $threadId,
                $doctorContextId,
                $patientContextId,
                false
            );

            $root = $this->resolveActorForThread(
                (int)($r['root_author_user_id'] ?? 0),
                $threadId,
                $doctorContextId,
                $patientContextId,
                false
            );

            $dest = $this->resolveRecipientActorForMessage(
                $r,
                $threadId,
                $doctorContextId,
                $patientContextId
            );

            $senderIsPatient = (($sender['kind'] ?? '') === 'CLIENT')
                && (int)($sender['id'] ?? 0) === $patientContextId;

            $recipientIsPatient = (
                strtoupper((string)($r['recipient_type'] ?? '')) === 'USER'
                && (($dest['kind'] ?? '') === 'CLIENT')
                && (int)($dest['id'] ?? 0) === $patientContextId
            );

            if (
                $myRole === 'PAZIENTE'
                && !($senderIsPatient || $recipientIsPatient)
            ) {
                continue;
            }

            if (
                in_array($myRole, ['SEGRETERIA', 'INFERMIERE'], true)
                && !$this->isMessageVisibleToStaffRoleContext(
                    $r,
                    $threadId,
                    $doctorContextId,
                    $myRole,
                    $messageIndex,
                    $staffVisibilityMemo
                )
            ) {
                continue;
            }

            $r['sender_nome']       = $sender['nome'];
            $r['sender_cognome']    = $sender['cognome'];
            $r['sender_role']       = $sender['role'];
            $r['sender_display']    = $sender['display'] !== '' ? $sender['display'] : 'Mittente';

            $r['root_nome']         = $root['nome'];
            $r['root_cognome']      = $root['cognome'];

            $r['recipient_nome']    = $dest['nome'];
            $r['recipient_cognome'] = $dest['cognome'];

            $r['body_plain']    = $this->normalizeBodyText($r['body_plain'] ?? '');
            $r['created_human'] = $this->humanTime($r['created_at'] ?? '');
            $r['attachments']   = $attachmentsByMessage[(int)$r['id_message']] ?? [];

            $visibleRows[] = $r;
        }

        $flagsUserId = $this->getFlagsUserIdForContext($meActorId, $myRole, $doctorContextId);
        $this->markThreadRead($threadId, $flagsUserId);

        return $visibleRows;
    }

    private function listMessageAttachments(int $messageId): array
{
    $grouped = $this->listAttachmentsForMessages([$messageId]);
    return $grouped[$messageId] ?? [];
}

    private function listMessageAttachmentCopyRows(int $messageId): array
{
    if ($messageId <= 0) {
        return [];
    }

    $this->ensureCryptoSession();

    $rows = $this->db->query("
      SELECT
        a.id_attachment,
        a.id_message,
        a.mime_type,
        a.file_size,
        a.created_at,

        {$this->attachmentDecryptExpr('a.original_name', 'a.vector_id')} AS original_name,
        {$this->attachmentDecryptExpr('a.stored_name', 'a.vector_id')}   AS stored_name,
        {$this->attachmentDecryptExpr('a.storage_path', 'a.vector_id')}  AS storage_path

      FROM msg_attachments a
      WHERE a.id_message = ?
      ORDER BY a.created_at ASC, a.id_attachment ASC
    ", [$messageId])->getResultArray();

    foreach ($rows as &$row) {
        $row = $this->normalizeAttachmentRow($row);
    }
    unset($row);

    return $rows;
}

    private function buildPreparedAttachmentsFromMessage(int $messageId, bool $ignoreInvalidRows = false): array
{
    $rows = $this->listMessageAttachmentCopyRows($messageId);
    $prepared = [];

    foreach ($rows as $row) {
        try {
            $storedName = $this->normalizeAttachmentText($row['stored_name'] ?? '');
            if ($storedName === '') {
                throw new \RuntimeException('stored_name mancante');
            }

            $path = $this->resolveAttachmentPath($row);
            $cipherBytes = @file_get_contents($path);
            if (!is_string($cipherBytes)) {
                throw new \RuntimeException('Impossibile leggere il file allegato');
            }

            $originalName = $this->normalizeAttachmentText($row['original_name'] ?? '');
            if ($originalName === '') {
                $originalName = preg_replace('/\.crypto$/i', '', basename($storedName));
            }

            $prepared[] = [
                'original_name' => $originalName !== '' ? $originalName : 'allegato',
                'stored_name'   => $storedName,
                'mime_type'     => (string)($row['mime_type'] ?? 'application/octet-stream'),
                'file_size'     => max((int)($row['file_size'] ?? 0), 0),
                'cipher_bytes'  => $cipherBytes,
            ];
        } catch (\Throwable $e) {
            if (!$ignoreInvalidRows) {
                throw new \RuntimeException(
                    'Impossibile preparare gli allegati del messaggio da inoltrare: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    return $prepared;
}

    private function mergePreparedAttachments(array $base, array $incoming): array
{
    $seen = [];

    foreach ($base as $attachment) {
        $key = strtolower((string)($attachment['stored_name'] ?? ''))
            . '|'
            . strtolower((string)($attachment['original_name'] ?? ''))
            . '|'
            . strtolower((string)($attachment['mime_type'] ?? ''))
            . '|'
            . (int)($attachment['file_size'] ?? 0);
        $seen[$key] = true;
    }

    foreach ($incoming as $attachment) {
        $key = strtolower((string)($attachment['stored_name'] ?? ''))
            . '|'
            . strtolower((string)($attachment['original_name'] ?? ''))
            . '|'
            . strtolower((string)($attachment['mime_type'] ?? ''))
            . '|'
            . (int)($attachment['file_size'] ?? 0);

        if (isset($seen[$key])) {
            continue;
        }

        $base[] = $attachment;
        $seen[$key] = true;
    }

    return $base;
}

    private function buildPreparedForwardAttachments(array $message): array
{
    $messageId = (int)($message['id_message'] ?? 0);
    if ($messageId <= 0) {
        return [];
    }

    $isForward = strtoupper((string)($message['message_type'] ?? '')) === 'FORWARD';
    $prepared = $this->buildPreparedAttachmentsFromMessage($messageId, $isForward);

    if (!$isForward) {
        return $prepared;
    }

    $parentMessageId = (int)($message['parent_message_id'] ?? 0);
    if ($parentMessageId > 0) {
        $prepared = $this->mergePreparedAttachments(
            $prepared,
            $this->buildPreparedAttachmentsFromMessage($parentMessageId)
        );
    }

    return $prepared;
}

    private function listAttachmentsForMessages(array $messageIds): array
{
    $messageIds = array_values(array_unique(array_map('intval', $messageIds)));
    $messageIds = array_values(array_filter($messageIds, static fn (int $id): bool => $id > 0));
    if ($messageIds === []) {
        return [];
    }

    $this->ensureCryptoSession();

    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));

    $sql = "
      SELECT
        id_message,
        id_attachment,
        mime_type,
        file_size,
        created_at,

        {$this->attachmentDecryptExpr('original_name', 'vector_id')} AS original_name,
        {$this->attachmentDecryptExpr('stored_name', 'vector_id')}   AS stored_name

      FROM msg_attachments
      WHERE id_message IN ({$placeholders})
      ORDER BY id_message ASC, created_at ASC
    ";

    $rows = $this->db->query($sql, $messageIds)->getResultArray();
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['id_message']][] = $row;
    }

    return $grouped;
}

private function markThreadRead(int $threadId, int $flagsUserId): void
{
    $sql = "
        INSERT INTO msg_user_flags (
            id_message,
            user_id,
            is_read,
            read_at
        )
        SELECT
            m.id_message,
            ?,
            1,
            NOW()
        FROM msg_messages m
        LEFT JOIN msg_user_flags f
            ON f.id_message = m.id_message
           AND f.user_id = ?
        WHERE m.id_thread = ?
          AND (
                f.id_message IS NULL
                OR f.is_read = 0
              )

        ON DUPLICATE KEY UPDATE
            is_read = 1,
            read_at = NOW()
    ";

    $this->db->query($sql, [
        $flagsUserId,
        $flagsUserId,
        $threadId
    ]);
}
    /*private function markThreadRead(int $threadId, int $flagsUserId): void
    {
        $this->db->query("
          INSERT INTO msg_user_flags (id_message, user_id, is_read, read_at)
          SELECT id_message, ?, 1, NOW()
          FROM msg_messages
          WHERE id_thread = ?
          ON DUPLICATE KEY UPDATE is_read=1, read_at=NOW()
        ", [$flagsUserId, $threadId]);
    }*/

    public function getThreadIdByMessage(int $messageId): int
    {
        $row = $this->db->query(
            "SELECT id_thread FROM msg_messages WHERE id_message=? LIMIT 1",
            [$messageId]
        )->getRowArray();

        return (int)($row['id_thread'] ?? 0);
    }

    /* =========================
     * Delete
     * ========================= */
    public function softDeleteMessage(int $messageId, int $meActorId, string $myRole, ?int $flagsUserId = null): void
    {
        $threadId = $this->getThreadIdByMessage($messageId);
        if ($threadId <= 0) {
            throw new \RuntimeException('Thread non trovato');
        }

        if (!$this->canUserAccessThread($meActorId, $myRole, $threadId)) {
            throw new \RuntimeException('Accesso negato (thread)');
        }

        $flagsUserId = (int)($flagsUserId ?? $meActorId);
        if ($flagsUserId <= 0) $flagsUserId = $meActorId;

        $this->db->query("
          INSERT INTO msg_user_flags (id_message, user_id, is_deleted, deleted_at)
          VALUES (?,?,1,NOW())
          ON DUPLICATE KEY UPDATE is_deleted=1, deleted_at=NOW()
        ", [$messageId, $flagsUserId]);
    }

private function attachmentDecryptExpr(string $fieldExpr, string $vectorExpr): string
{
    return "CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR(4096) CHARACTER SET utf8mb4)";
}

private function normalizeAttachmentText(?string $value): string
{
    $value = (string)($value ?? '');
    $value = str_replace("\0", '', $value);
    return trim($value);
}

private function normalizeAttachmentRow(array $row): array
{
    $row['original_name'] = $this->normalizeAttachmentText($row['original_name_plain'] ?? $row['original_name'] ?? '');
    $row['stored_name']   = $this->normalizeAttachmentText($row['stored_name_plain'] ?? $row['stored_name'] ?? '');
    $row['storage_path']  = $this->normalizeAttachmentText($row['storage_path_plain'] ?? $row['storage_path'] ?? '');
    $row['mime_type']     = $this->normalizeAttachmentText($row['mime_type'] ?? 'application/octet-stream');
    if ($row['mime_type'] === '') {
        $row['mime_type'] = 'application/octet-stream';
    }
    $row['file_size']     = (int)($row['file_size'] ?? 0);

    return $row;
}

    /* =========================
     * Allegati
     * ========================= */
    public function getAttachmentForUser(int $attachmentId, int $meActorId, string $myRole): array
{
    $this->ensureCryptoSession();

    $myRole = strtoupper(trim($myRole));

    $att = $this->db->query("
        SELECT
            a.id_attachment,
            a.id_message,
            a.id_draft,
            a.uploaded_by_user_id,
            a.original_name,
            a.stored_name,
            a.mime_type,
            a.file_size,
            a.storage_path,
            a.vector_id,
            a.created_at,

            {$this->attachmentDecryptExpr('a.original_name', 'a.vector_id')} AS original_name_plain,
            {$this->attachmentDecryptExpr('a.stored_name', 'a.vector_id')}   AS stored_name_plain,
            {$this->attachmentDecryptExpr('a.storage_path', 'a.vector_id')}  AS storage_path_plain

        FROM msg_attachments a
        WHERE a.id_attachment = ?
        LIMIT 1
    ", [$attachmentId])->getRowArray();

    if (!$att) {
        throw new \RuntimeException('Allegato non trovato');
    }

    $att = $this->normalizeAttachmentRow($att);

    if (!empty($att['id_draft'])) {
        $draftScopeSql = $this->buildDraftOwnershipScope('d', $myRole);
        $ok = (bool)$this->db->query("
            SELECT 1
            FROM msg_drafts d
            WHERE d.id_draft = ?
              AND d.owner_user_id = ?
              AND {$draftScopeSql}
            LIMIT 1
        ", [(int)$att['id_draft'], $meActorId])->getRowArray();
        if (!$ok) {
            throw new \RuntimeException('Accesso negato (draft attachment)');
        }
        return $att;
    }

    $msgId = (int)($att['id_message'] ?? 0);
    if ($msgId <= 0) {
        throw new \RuntimeException('Allegato non associato a messaggio');
    }

    if (!$this->canUserAccessMessage($meActorId, $myRole, $msgId)) {
        throw new \RuntimeException('Accesso negato (message attachment)');
    }

    return $att;
}

private function attachmentCopyNameVariants(string $stored): array
{
    $variants = [];
    $patterns = [
        '/_([1-9]\d{0,2})(?=\.crypto$)/i',
        '/_([1-9]\d{0,2})(?=\.[^.]+(?:\.crypto)?$)/i',
    ];

    foreach ($patterns as $pattern) {
        $candidate = preg_replace($pattern, '', $stored, 1);
        if (is_string($candidate) && $candidate !== '' && $candidate !== $stored) {
            $variants[] = $candidate;
        }
    }

    return array_values(array_unique($variants));
}

private function buildStoredNameCandidates(string $stored): array
{
    $queue = [$stored];
    $seen = [];

    while ($queue !== []) {
        $current = $this->normalizeAttachmentText((string) array_shift($queue));
        if ($current === '' || isset($seen[$current])) {
            continue;
        }

        $seen[$current] = true;

        if (preg_match('/\.crypto$/i', $current)) {
            $withoutCrypto = preg_replace('/\.crypto$/i', '', $current);
            if (is_string($withoutCrypto) && $withoutCrypto !== '') {
                $queue[] = $withoutCrypto;
            }
        } else {
            $queue[] = $current . '.crypto';
        }

        foreach ($this->attachmentCopyNameVariants($current) as $variant) {
            $queue[] = $variant;
        }
    }

    return array_keys($seen);
}

   public function resolveAttachmentPath(array $att): string
{
    $storagePath = $this->normalizeAttachmentText($att['storage_path'] ?? '');
    $stored = $this->normalizeAttachmentText($att['stored_name'] ?? '');

    log_message('error', '[resolveAttachmentPath] att={att}', [
        'att' => json_encode($att, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    log_message('error', '[resolveAttachmentPath] storage_path={storage_path} stored_name={stored_name}', [
        'storage_path' => $storagePath,
        'stored_name'  => $stored,
    ]);

    if ($stored === '') {
        throw new \RuntimeException('stored_name mancante');
    }

    $storedCandidates = $this->buildStoredNameCandidates($stored);

    $candidates = [];

    if ($storagePath !== '') {
        $candidates[] = $storagePath;

        foreach ($storedCandidates as $storedCandidate) {
            $looksLikeDir = preg_match('#[\\\\/]$#', $storagePath) || is_dir($storagePath);
            if ($looksLikeDir) {
                $candidates[] = rtrim($storagePath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $storedCandidate;
                continue;
            }

            if (basename(str_replace('\\', '/', $storagePath)) !== $storedCandidate) {
                $dir = dirname($storagePath);
                if ($dir !== '' && $dir !== '.') {
                    $candidates[] = rtrim($dir, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $storedCandidate;
                }

                $candidates[] = rtrim($storagePath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $storedCandidate;
            }
        }
    }

    if (!empty($att['id_message'])) {
        $dir = rtrim(WRITEPATH . 'uploads/messages/' . (int)$att['id_message'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($storedCandidates as $storedCandidate) {
            $candidates[] = $dir . $storedCandidate;
        }
    }

    if (!empty($att['id_draft'])) {
        $dir = rtrim(WRITEPATH . 'uploads/messages/drafts/' . (int)$att['id_draft'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($storedCandidates as $storedCandidate) {
            $candidates[] = $dir . $storedCandidate;
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates, static fn ($v) => $v !== '')));

    foreach ($candidates as $idx => $candidate) {
        log_message('error', '[resolveAttachmentPath] candidate{n}={path} exists={exists} is_file={is_file}', [
            'n'       => $idx + 1,
            'path'    => $candidate,
            'exists'  => file_exists($candidate) ? '1' : '0',
            'is_file' => is_file($candidate) ? '1' : '0',
        ]);

        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new \RuntimeException('File non trovato su disco');
}

    /* =========================
     * INVIO DA BOZZA
     * ========================= */
    public function sendFromDraft(int $draftId, int $meActorId, string $myRole): array
    {
        $myRole = strtoupper(trim($myRole));
        $draft = $this->loadDraft($draftId, $meActorId, $myRole);

        $recipientType = $draft['recipient_type'];
        $recipientUserId = $draft['recipient_user_id'] !== null ? (int)$draft['recipient_user_id'] : null;
        $recipientRole = $draft['recipient_role'] !== null ? (string)$draft['recipient_role'] : null;

        if ($recipientType === 'PATIENT_TARGET') {
            $targetCode = strtoupper(trim((string)($draft['patient_target_code'] ?? '')));
            if ($targetCode === '') {
                throw new \RuntimeException('Seleziona oggetto mail.');
            }
            if (!in_array($targetCode, ['MEDICO', 'SEGRETERIA', 'INFERMIERE'], true)) {
                throw new \RuntimeException('Tipo di messaggio non valido.');
            }

            $res = $this->resolvePatientTargetRecipient($meActorId, $targetCode);
            $recipientType = $res['type'] === 'ROLE' ? 'ROLE' : 'USER';
            $recipientRole = $res['role'];
            $recipientUserId = $res['actor_id'];
        }

        $this->db->query("INSERT INTO msg_threads (root_author_user_id) VALUES (?)", [$meActorId]);
        $threadId = (int)$this->db->insertID();

        $this->db->query("
          INSERT INTO msg_messages
            (id_thread, message_type, sender_user_id, recipient_type, recipient_user_id, recipient_role,
             body_cipher_hex, vector_id, root_author_user_id, created_at)
          VALUES (?,?,?,?,?,?,?,?,?,NOW())
        ", [
            $threadId,
            'ROOT',
            $meActorId,
            $recipientType,
            $recipientUserId,
            $recipientRole,
            $draft['body_cipher_hex'],
            $draft['vector_id'],
            $meActorId,
        ]);
        $rootMessageId = (int)$this->db->insertID();

        $this->db->query("UPDATE msg_threads SET root_message_id=? WHERE id_thread=?", [$rootMessageId, $threadId]);

        $this->moveDraftAttachmentsToMessage($draftId, $rootMessageId);
        $this->db->query("UPDATE msg_attachments SET id_message=?, id_draft=NULL WHERE id_draft=?", [$rootMessageId, $draftId]);

        $this->db->query("DELETE FROM msg_drafts WHERE id_draft=? AND owner_user_id=? LIMIT 1", [$draftId, $meActorId]);

        $shouldPush = $this->shouldSendPushFromDoctorToPatient($myRole, (string)$recipientType, $recipientUserId, $recipientRole);
        log_message('debug', '[sendFromDraft] push_check role={role} recipient_type={rtype} recipient_user_id={ruid} recipient_role={rrole} should_push={sp}', [
            'role'  => (string)$myRole,
            'rtype' => (string)$recipientType,
            'ruid'  => (int)($recipientUserId ?? 0),
            'rrole' => (string)($recipientRole ?? ''),
            'sp'    => $shouldPush ? 1 : 0,
        ]);

        if ($shouldPush) {
            log_message('debug', '[sendFromDraft] push_send client_id={cid} thread_id={tid}', [
                'cid' => (int)$recipientUserId,
                'tid' => (int)$threadId,
            ]);

            $this->sendPushToClient(
                (int)$recipientUserId,
                'Hai ricevuto un nuovo messaggio dal tuo dottore.',
                $threadId
            );
        }

        return ['ok' => true, 'thread_id' => $threadId, 'message_id' => $rootMessageId];
    }

    private function shouldSendPushFromDoctorToPatient(
        string $myRole,
        string $recipientType,
        ?int $recipientUserId,
        ?string $recipientRole
    ): bool {
        $role = strtoupper(trim($myRole));
        $type = strtoupper(trim($recipientType));
        $rRole = strtoupper(trim((string)($recipientRole ?? '')));
        $clientId = (int)($recipientUserId ?? 0);

        if (!($role === 'DOTTORE'
            && $type === 'USER'
            && $clientId > 0
            && $rRole === '')) {
            return false;
        }

        return $this->isPatientClientId($clientId);
    }

    private function isPatientClientId(int $idClient): bool
    {
        $row = $this->db->query(
            "SELECT 1 AS ok FROM dap02_clients WHERE id_client=? LIMIT 1",
            [$idClient]
        )->getRowArray();

        return !empty($row);
    }

    /**
     * Invia push al cliente se associato a un utente con dispositivi attivi.
     */
    private function sendPushToClient(int $idClient, string $body, ?int $threadId = null): void
    {
        log_message('error', '[sendPushToClient] start client_id={cid} thread_id={tid}', [
            'cid' => $idClient,
            'tid' => (int)($threadId ?? 0),
        ]);

        $client = $this->db->query(
            "SELECT id_user FROM dap02_clients WHERE id_client=? LIMIT 1",
            [$idClient]
        )->getRowArray();

        if (!$client) {
            log_message('error', "[sendPushToClient] Cliente {id} non trovato", ['id' => $idClient]);
            return;
        }

        $userId = (int)($client['id_user'] ?? 0);
        if ($userId <= 0) {
            log_message('error', "[sendPushToClient] Nessun userId associato al cliente {id}", ['id' => $idClient]);
            return;
        }

        $url = base_url('messaggi/inbox');
        if ($threadId !== null && $threadId > 0) {
            $url = base_url('messaggi/thread/' . (int)$threadId);
        }

        $payload = [
            'title'  => 'AmbulatoriCLOUD',
            'body'   => $body,
            'sticky' => true,
            'data'   => [
                'url' => $url,
            ],
        ];

        log_message('error', '[sendPushToClient] sending user_id={uid} url={url}', [
            'uid' => $userId,
            'url' => $url,
        ]);

        service('push')->sendToUser($userId, $payload);

        log_message('error', '[sendPushToClient] sent user_id={uid}', ['uid' => $userId]);
    }

     private function sendPushToService(int $idClient, string $body, ?int $threadId = null): void
    {
        log_message('debug', '[sendPushToClient] start client_id={cid} thread_id={tid}', [
            'cid' => $idClient,
            'tid' => (int)($threadId ?? 0),
        ]);

      /*  $client = $this->db->query(
            "SELECT id_user FROM dap02_clients WHERE id_client=? LIMIT 1",
            [$idClient]
        )->getRowArray();

        if (!$client) {
            log_message('error', "[sendPushToClient] Cliente {id} non trovato", ['id' => $idClient]);
            return;
        }*/

        $userId = $idClient;
        if ($userId <= 0) {
            log_message('error', "[sendPushToClient] Nessun userId associato al cliente {id}", ['id' => $idClient]);
            return;
        }

        $url = base_url('messaggi/inbox');
        if ($threadId !== null && $threadId > 0) {
            $url = base_url('messaggi/thread/' . (int)$threadId);
        }

        $payload = [
            'title'  => 'AmbulatoriCLOUD',
            'body'   => $body,
            'sticky' => true,
            'data'   => [
                'url' => $url,
            ],
        ];

        log_message('debug', '[sendPushToClient] sending user_id={uid} url={url}', [
            'uid' => $userId,
            'url' => $url,
        ]);

        service('push')->sendToUser($userId, $payload);

        log_message('debug', '[sendPushToClient] sent user_id={uid}', ['uid' => $userId]);
    }
private function moveDraftAttachmentsToMessage(int $draftId, int $messageId): void
{
    $this->ensureCryptoSession();

    $rows = $this->db->query("
        SELECT
            id_attachment,
            vector_id,
            {$this->attachmentDecryptExpr('original_name', 'vector_id')} AS original_name,
            {$this->attachmentDecryptExpr('stored_name', 'vector_id')}  AS stored_name,
            {$this->attachmentDecryptExpr('storage_path', 'vector_id')} AS storage_path
        FROM msg_attachments
        WHERE id_draft = ?
        ORDER BY id_attachment ASC
    ", [$draftId])->getResultArray();

    if (!$rows) {
        return;
    }

    $targetDir = WRITEPATH . 'uploads/messages/' . $messageId . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0770, true);
    }

    foreach ($rows as $r) {
        $attId       = (int)$r['id_attachment'];
        $displayName = (string)($r['original_name'] ?? '');
        $oldPath     = (string)($r['storage_path'] ?? '');
        $stored      = (string)($r['stored_name'] ?? '');

        if ($oldPath === '' || $stored === '') {
            continue;
        }

        if (!is_file($oldPath)) {
            $candidate = rtrim($oldPath, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . $stored;
            if (is_file($candidate)) {
                $oldPath = $candidate;
            }
        }

        $newPath = $targetDir . $stored;

        if (is_file($newPath)) {
            $newPath = $targetDir . bin2hex(random_bytes(8)) . '_' . $stored;
        }

        $ok = false;
        if (is_file($oldPath)) {
            $ok = @rename($oldPath, $newPath);
            if (!$ok) {
                $ok = @copy($oldPath, $newPath);
                if ($ok) {
                    @unlink($oldPath);
                }
            }
        }

        if ($ok) {
            $vector = random_bytes(16);

            $this->db->query("
                UPDATE msg_attachments
                SET original_name = HEX(AES_ENCRYPT(?, @key_str, ?)),
                    stored_name  = HEX(AES_ENCRYPT(?, @key_str, ?)),
                    storage_path = HEX(AES_ENCRYPT(?, @key_str, ?)),
                    vector_id    = ?
                WHERE id_attachment = ?
                LIMIT 1
            ", [
                $displayName,
                $vector,
                basename($newPath),
                $vector,
                $newPath,
                $vector,
                $vector,
                $attId
            ]);
        }
    }
}

    private function storePreparedAttachmentsForMessage(int $messageId, int $meActorId, array $attachments): void
    {
        if (empty($attachments)) {
            return;
        }

        $targetDir = WRITEPATH . 'uploads/messages/' . $messageId . DIRECTORY_SEPARATOR;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossibile creare la cartella degli allegati');
        }

        foreach ($attachments as $attachment) {
            $storedName = trim((string)($attachment['stored_name'] ?? ''));
            if ($storedName === '') {
                throw new \RuntimeException('Nome allegato non valido');
            }

            $cipherBytes = $attachment['cipher_bytes'] ?? null;
            if (!is_string($cipherBytes)) {
                throw new \RuntimeException('Contenuto allegato non valido');
            }

            $finalPath = $targetDir . $storedName;
            if (@file_put_contents($finalPath, $cipherBytes) === false) {
                throw new \RuntimeException('Errore durante il salvataggio dell\'allegato');
            }

            try {
                $this->addMessageAttachment($messageId, $meActorId, [
                    'original_name' => (string)($attachment['original_name'] ?? 'allegato'),
                    'stored_name'   => $storedName,
                    'mime_type'     => (string)($attachment['mime_type'] ?? 'application/octet-stream'),
                    'file_size'     => (int)($attachment['file_size'] ?? strlen($cipherBytes)),
                    'storage_path'  => $finalPath,
                ]);
            } catch (\Throwable $e) {
                if (is_file($finalPath)) {
                    @unlink($finalPath);
                }
                throw $e;
            }
        }
    }

    private function deleteMessageAttachments(int $messageId): void
    {
        $this->ensureCryptoSession();

        $rows = $this->db->query("
          SELECT {$this->attachmentDecryptExpr('storage_path', 'vector_id')} AS storage_path
          FROM msg_attachments
          WHERE id_message=?
        ", [$messageId])->getResultArray();

        foreach ($rows as $row) {
            $path = $this->normalizeAttachmentText($row['storage_path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        $this->db->query("DELETE FROM msg_attachments WHERE id_message=?", [$messageId]);
    }

    /* =========================
     * REPLY / FORWARD
     * ========================= */
    public function reply(int $parentMessageId, int $meActorId, string $myRole, string $bodyPlain, array $attachments = []): array
    {
        if (!$this->canUserAccessMessage($meActorId, $myRole, $parentMessageId)) {
            throw new \RuntimeException('Accesso negato (reply)');
        }

        $this->ensureCryptoSession();

        $bodyPlain = $this->normalizeBodyText($bodyPlain);
        if (trim($bodyPlain) === '' && empty($attachments)) {
            throw new \RuntimeException('Scrivi una risposta o allega almeno un file');
        }

        $parent = $this->db->query(
            "SELECT * FROM msg_messages WHERE id_message=? LIMIT 1",
            [$parentMessageId]
        )->getRowArray();

        if (!$parent) {
            throw new \RuntimeException('Messaggio non trovato');
        }

        $threadId   = (int)$parent['id_thread'];
        $rootAuthor = (int)$parent['root_author_user_id'];
        $rootMsgId  = $parent['root_message_id'] !== null ? (int)$parent['root_message_id'] : null;

        $myRole = strtoupper(trim($myRole));

        $isForwardToStaff =
            ($parent['message_type'] ?? '') === 'FORWARD'
            && in_array((string)($parent['recipient_role'] ?? ''), ['SEGRETERIA', 'INFERMIERE'], true);

        if ($isForwardToStaff) {
            $replyTo = (int)$parent['sender_user_id'];
            if ($replyTo <= 0) {
                throw new \RuntimeException('Reply forward: sender non valido');
            }
        } else {
            if ($meActorId === $rootAuthor) {
                if ($myRole === 'PAZIENTE') {
                    $replyTo = (int)$this->getDoctorContextIdForThread($threadId);
                    if ($replyTo <= 0) {
                        $replyTo = (int)($parent['recipient_user_id'] ?? 0);
                    }
                } else {
                    $doctorContextId = (int)$this->getDoctorContextIdForThread($threadId);
                    $replyTo = (int)$this->getPatientContextIdForThread($threadId, $doctorContextId);
                    if ($replyTo <= 0) {
                        $replyTo = (int)($parent['recipient_user_id'] ?? 0);
                    }
                }
            } else {
                $replyTo = $rootAuthor;
            }

            if ($replyTo <= 0) {
                throw new \RuntimeException('Reply: destinatario non valido');
            }
        }

        $vector = random_bytes(16);

        $msgId = 0;

        try {
            $this->db->query("
              INSERT INTO msg_messages
                (id_thread, message_type, parent_message_id, reply_to_user_id,
                 sender_user_id, recipient_type, recipient_user_id,
                 body_cipher_hex, vector_id,
                 root_message_id, root_author_user_id, created_at)
              VALUES
                (?,?,?,?,?,'USER',?,
                 HEX(AES_ENCRYPT(?, @key_str, ?)), ?,
                 ?, ?, NOW())
            ", [
                $threadId,
                'REPLY',
                $parentMessageId,
                $replyTo,
                $meActorId,
                $replyTo,
                $bodyPlain,
                $vector,
                $vector,
                $rootMsgId,
                $rootAuthor,
            ]);

            $msgId = (int)$this->db->insertID();
            $this->storePreparedAttachmentsForMessage($msgId, $meActorId, $attachments);
        } catch (\Throwable $e) {
            if ($msgId > 0) {
                $this->deleteMessageAttachments($msgId);
                $this->db->query("DELETE FROM msg_messages WHERE id_message=? LIMIT 1", [$msgId]);
            }
            throw $e;
        }

        $this->db->query("UPDATE msg_threads SET updated_at=NOW() WHERE id_thread=?", [$threadId]);
        $this->reopenThreadForReplyRecipients($threadId, $replyTo, null);

        $shouldPushReply = $this->shouldSendPushFromDoctorToPatient($myRole, 'USER', $replyTo, null);
        log_message('error', '[reply] push_check role={role} reply_to={reply_to} should_push={sp}', [
            'role'     => (string)$myRole,
            'reply_to' => (int)$replyTo,
            'sp'       => $shouldPushReply ? 1 : 0,
        ]);

        if ($shouldPushReply) {
            log_message('error', '[reply] push_send client_id={cid} thread_id={tid}', [
                'cid' => (int)$replyTo,
                'tid' => (int)$threadId,
            ]);

            $this->sendPushToClient(
                (int)$replyTo,
                'Hai ricevuto una risposta dal tuo dottore.',
                $threadId
            );
        }

        return ['ok' => true, 'thread_id' => $threadId, 'message_id' => $msgId];
    }

    public function forward(int $messageId, int $meActorId, string $myRole, string $dest, string $note): array
    {
        if (!$this->canUserAccessMessage($meActorId, $myRole, $messageId)) {
            throw new \RuntimeException('Accesso negato (forward)');
        }

        $this->ensureCryptoSession();

        $orig = $this->db->query("SELECT * FROM msg_messages WHERE id_message=? LIMIT 1", [$messageId])->getRowArray();
        if (!$orig) {
            throw new \RuntimeException('Messaggio non trovato');
        }

        $threadId      = (int)$orig['id_thread'];
        $rootAuthor    = (int)$orig['root_author_user_id'];
        $rootMessageId = $orig['root_message_id'] !== null ? (int)$orig['root_message_id'] : null;

        $doctorContextId = $this->getDoctorContextIdForThread($threadId);
        if ($doctorContextId <= 0) {
            $doctorContextId = $this->resolveDoctorContextId($threadId, $rootAuthor);
        }

        $dest = strtoupper(trim($dest));

        $recipientType   = null;
        $recipientRole   = null;
        $recipientUserId = null;

        if (str_starts_with($dest, 'ROLE:')) {
            $recipientRole = substr($dest, 5);
            if (!in_array($recipientRole, ['SEGRETERIA', 'INFERMIERE'], true)) {
                throw new \RuntimeException('Destinatario ruolo non valido');
            }

            $recipientType   = 'USER';
            $recipientUserId = $doctorContextId;
        } elseif (str_starts_with($dest, 'USER:')) {
            $recipientType = 'USER';
            $recipientUserId = (int)substr($dest, 5);
            if ($recipientUserId <= 0) {
                throw new \RuntimeException('Destinatario non valido');
            }
        } else {
            throw new \RuntimeException('Destinatario non valido');
        }

        $origPlain = $this->db->query("
          SELECT CAST(AES_DECRYPT(UNHEX(body_cipher_hex), @key_str, vector_id) AS CHAR(10000) CHARACTER SET utf8mb4) AS body_plain
          FROM msg_messages
          WHERE id_message=? LIMIT 1
        ", [$messageId])->getRowArray();

        $plain = trim((string)$note);
        $plain = $this->normalizeBodyText($plain);

        if ($plain !== '') $plain .= "\n\n---\n";
        $plain .= $this->normalizeBodyText((string)($origPlain['body_plain'] ?? ''));

        $forwardAttachments = $this->buildPreparedForwardAttachments($orig);
        $vector = random_bytes(16);
        $newId = 0;

        try {
            $this->db->query("
              INSERT INTO msg_messages
                (id_thread, message_type, parent_message_id,
                 sender_user_id, recipient_type, recipient_user_id, recipient_role,
                 body_cipher_hex, vector_id,
                 root_message_id, root_author_user_id, created_at)
              VALUES
                (?,?,?,?,?,?,?,
                 HEX(AES_ENCRYPT(?, @key_str, ?)), ?,
                 ?, ?, NOW())
            ", [
                $threadId,
                'FORWARD',
                $messageId,
                $meActorId,
                $recipientType,
                $recipientUserId,
                $recipientRole,
                $plain,
                $vector,
                $vector,
                $rootMessageId,
                $rootAuthor,
            ]);

            $newId = (int)$this->db->insertID();

            if (!empty($forwardAttachments)) {
                // The legacy DB trigger may pre-create broken attachment rows for forwards.
                $this->db->query("DELETE FROM msg_attachments WHERE id_message=?", [$newId]);
                $this->storePreparedAttachmentsForMessage($newId, $meActorId, $forwardAttachments);
            }
        } catch (\Throwable $e) {
            if ($newId > 0) {
                $this->deleteMessageAttachments($newId);
                $this->db->query("DELETE FROM msg_messages WHERE id_message=? LIMIT 1", [$newId]);
            }
            throw $e;
        }

        $this->db->query("UPDATE msg_threads SET updated_at=NOW() WHERE id_thread=?", [$threadId]);
        $this->reopenThreadForForwardRecipient($threadId, (int)$recipientUserId, $recipientRole);

        return ['ok' => true, 'thread_id' => $threadId, 'message_id' => $newId];
    }

    /* =========================
     * SEARCH helpers
     * ========================= */
    private function normalizeSearch(?string $q): string
    {
        $q = trim((string)$q);
        if (strlen($q) > 200) $q = substr($q, 0, 200);
        return $q;
    }

    private function buildBodySearchSql(string $tableAlias, array &$params, ?string $q): string
    {
        $q = $this->normalizeSearch($q);
        if ($q === '') return '';

        $params[] = '%' . $q . '%';

        return "
          AND LOWER(
            CAST(
              AES_DECRYPT(UNHEX({$tableAlias}.body_cipher_hex), @key_str, {$tableAlias}.vector_id)
              AS CHAR(10000) CHARACTER SET utf8mb4
            )
          ) LIKE LOWER(?)
        ";
    }

    /* =========================
     * Autocomplete pazienti
     * ========================= */
    public function autocompletePatientsForDoctor(int $doctorPersonaleId, string $term): array
    {
        $this->ensureCryptoSession();
        $crypto = new Crypto_helper();

        $term = trim($term);
        $like = '%' . $term . '%';

        $DEC_NOME = $crypto->decrypt_concat('c.nome');
        $DEC_COGN = $crypto->decrypt_concat('c.cognome');

        $sql = "
          SELECT
            c.id_client AS id,
            CONCAT(
              TRIM(CAST($DEC_COGN AS CHAR)),
              ' ',
              TRIM(CAST($DEC_NOME AS CHAR))
            ) AS text
          FROM dap09_client_doctor cd
          JOIN dap02_clients c ON c.id_client = cd.id_client
          WHERE cd.id_dot = ?
            AND (
              CAST($DEC_NOME AS CHAR) LIKE ?
              OR CAST($DEC_COGN AS CHAR) LIKE ?
              OR CONCAT(CAST($DEC_COGN AS CHAR), ' ', CAST($DEC_NOME AS CHAR)) LIKE ?
            )
          ORDER BY text ASC
          LIMIT 30
        ";

        return $this->db->query($sql, [$doctorPersonaleId, $like, $like, $like])->getResultArray();
    }

    public function resolveFlagsUserIdForContext(int $meActorId, string $myRole, int $doctorContextId = 0): int
    {
        return $this->getFlagsUserIdForContext($meActorId, $myRole, $doctorContextId);
    }

    private function getFlagsUserIdForContext(int $meActorId, string $myRole, int $doctorContextId = 0): int
    {
        $myRole = strtoupper(trim($myRole));

        // paziente e dottore usano il proprio id reale
        if (in_array($myRole, ['PAZIENTE', 'DOTTORE'], true)) {
            return $meActorId;
        }

        // segreteria/infermiere: separo i flag per ruolo sul contesto del medico
        if ($doctorContextId > 0) {
            if ($myRole === 'SEGRETERIA') {
                return 100000000 + $doctorContextId;
            }
            if ($myRole === 'INFERMIERE') {
                return 200000000 + $doctorContextId;
            }
        }

        return $meActorId;
    }
}

