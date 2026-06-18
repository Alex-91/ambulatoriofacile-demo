<?php

namespace App\Services;

use App\Libraries\DatabaseConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class DoctorDeletionService
{
    private BaseConnection $db;
    private DatabaseConfig $dbConfig;
    private array $doctorFamilyCache = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db, 'utf8mb4');
    }

    public function deleteDoctor(int $doctorPersonaleId, ?int $currentSessionPersonaleId = null, ?int $currentSessionUserId = null): array
    {
        if ($doctorPersonaleId <= 0) {
            throw new \InvalidArgumentException('Dottore non valido.');
        }

        $doctor = $this->db->table('dap03_personale')
            ->select('id_personale, id_user, tipo, COALESCE(legacy_id_dot, 0) AS legacy_id_dot')
            ->where('id_personale', $doctorPersonaleId)
            ->get(1)
            ->getRowArray();

        if (!$doctor) {
            throw new \RuntimeException('Dottore non trovato.');
        }

        if ((int)($doctor['tipo'] ?? 0) !== 1) {
            throw new \RuntimeException('Il record selezionato non e un dottore.');
        }

        $doctorUserId = (int)($doctor['id_user'] ?? 0);
        if (($currentSessionPersonaleId ?? 0) === $doctorPersonaleId || ($doctorUserId > 0 && ($currentSessionUserId ?? 0) === $doctorUserId)) {
            throw new \RuntimeException('Non puoi eliminare l\'account con cui sei loggato.');
        }

        $legacyDoctorId = (int)($doctor['legacy_id_dot'] ?? 0);
        $affectedClientIds = $this->listAffectedClientIds($doctorPersonaleId);
        $agendaScope = $this->collectAgendaScope($legacyDoctorId);
        $legacyMessageScope = $this->collectLegacyMessageScope($doctorPersonaleId);
        $newMessageScope = $this->collectNewMessageScope($doctorUserId);
        $cleanupPlan = $this->buildCleanupPlan($agendaScope, $legacyMessageScope, $newMessageScope);

        $summary = [
            'doctor_id' => $doctorPersonaleId,
            'doctor_user_id' => $doctorUserId,
            'legacy_doctor_id' => $legacyDoctorId,
            'patients_detached' => count($affectedClientIds),
            'agenda_configs_deleted' => count($agendaScope['config_ids']),
            'agenda_slots_deleted' => count($agendaScope['slot_ids']),
            'agenda_backups_deleted' => count($agendaScope['backup_ids']),
            'legacy_messages_deleted' => count($legacyMessageScope['message_ids']),
            'legacy_replies_deleted' => count($legacyMessageScope['reply_ids']),
            'new_threads_deleted' => count($newMessageScope['thread_ids']),
            'new_messages_deleted' => count($newMessageScope['message_ids']),
            'new_drafts_deleted' => count($newMessageScope['draft_ids']),
        ];

        $this->db->transBegin();

        try {
            $this->detachPatients($affectedClientIds, $doctorPersonaleId, $legacyDoctorId);
            $this->deleteDoctorLinks($doctorPersonaleId);
            $this->deleteAgendaData($legacyDoctorId, $agendaScope);
            $this->deleteLegacyMessages($legacyMessageScope);
            $this->deleteNewMessages($newMessageScope);
            $this->deleteUserArtifacts($doctorUserId);

            $this->db->table('dap03_personale')
                ->where('id_personale', $doctorPersonaleId)
                ->delete();

            if ($doctorUserId > 0 && $this->tableExists('dap01_users')) {
                $this->db->table('dap01_users')
                    ->where('id_user', $doctorUserId)
                    ->delete();
            }

            if (!$this->db->transStatus()) {
                throw new \RuntimeException('La cancellazione del dottore non e andata a buon fine.');
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $summary['patient_search_resynced'] = $this->resyncPatientSearchIndex($affectedClientIds);
        $summary['filesystem_cleanup'] = $this->cleanupFilesystem($cleanupPlan);

        return $summary;
    }

    private function detachPatients(array $clientIds, int $doctorPersonaleId = 0, int $legacyDoctorId = 0): void
    {
        $clientIds = $this->uniquePositiveInts($clientIds);
        if ($clientIds === [] && $doctorPersonaleId <= 0 && $legacyDoctorId <= 0) {
            return;
        }

        if ($clientIds !== [] && $doctorPersonaleId > 0 && $this->tableExists('dap09_client_doctor')) {
            foreach (array_chunk($clientIds, 500) as $chunk) {
                $this->db->table('dap09_client_doctor')
                    ->whereIn('id_client', $chunk)
                    ->where('id_dot', $doctorPersonaleId)
                    ->delete();
            }
        }

        if ($clientIds !== [] && $this->tableExists('dap02_clients')) {
            foreach ($clientIds as $clientId) {
                $row = $this->db->table('dap02_clients')
                    ->select('id_client, COALESCE(id_personale, 0) AS id_personale')
                    ->where('id_client', $clientId)
                    ->get(1)
                    ->getRowArray();

                if (!$row) {
                    continue;
                }

                $currentPrimaryDoctorId = (int)($row['id_personale'] ?? 0);
                if ($currentPrimaryDoctorId !== $doctorPersonaleId) {
                    continue;
                }

                $replacementDoctorId = $this->resolveReplacementPrimaryDoctorId(
                    $this->listRemainingDoctorIdsForClient($clientId, $doctorPersonaleId)
                );

                $this->db->table('dap02_clients')
                    ->where('id_client', $clientId)
                    ->update(['id_personale' => $replacementDoctorId > 0 ? $replacementDoctorId : null]);
            }
        }

        if ($this->tableExists('dap26_doctor_patient_search')) {
            if ($legacyDoctorId > 0) {
                $this->db->table('dap26_doctor_patient_search')
                    ->where('id_dot', $legacyDoctorId)
                    ->delete();
            }
        }
    }

    private function listRemainingDoctorIdsForClient(int $clientId, int $excludedDoctorId = 0): array
    {
        if ($clientId <= 0 || !$this->tableExists('dap09_client_doctor')) {
            return [];
        }

        $builder = $this->db->table('dap09_client_doctor')
            ->select('id_dot')
            ->where('id_client', $clientId);

        if ($excludedDoctorId > 0) {
            $builder->where('id_dot <>', $excludedDoctorId);
        }

        $rows = $builder->get()->getResultArray();
        $doctorIds = [];

        foreach ($rows as $row) {
            $doctorId = (int)($row['id_dot'] ?? 0);
            if ($doctorId > 0 && !in_array($doctorId, $doctorIds, true)) {
                $doctorIds[] = $doctorId;
            }
        }

        return $doctorIds;
    }

    private function resolveReplacementPrimaryDoctorId(array $doctorIds): int
    {
        foreach ($doctorIds as $doctorId) {
            if ($this->isFamilyDoctor((int)$doctorId)) {
                return (int)$doctorId;
            }
        }

        return $doctorIds !== [] ? (int)$doctorIds[0] : 0;
    }

    private function isFamilyDoctor(int $doctorId): bool
    {
        if ($doctorId <= 0) {
            return false;
        }

        if (array_key_exists($doctorId, $this->doctorFamilyCache)) {
            return $this->doctorFamilyCache[$doctorId];
        }

        $row = $this->db->table('dap03_personale')
            ->select('COALESCE(legacy_dot_tipo_id, 0) AS legacy_dot_tipo_id, COALESCE(f_dom, 0) AS f_dom')
            ->where('id_personale', $doctorId)
            ->get(1)
            ->getRowArray();

        $legacyTypeId = (int)($row['legacy_dot_tipo_id'] ?? 0);
        $isFamilyDoctor = $legacyTypeId > 0
            ? $legacyTypeId === 1
            : (int)($row['f_dom'] ?? 0) === 1;

        $this->doctorFamilyCache[$doctorId] = $isFamilyDoctor;

        return $isFamilyDoctor;
    }

    private function resyncPatientSearchIndex(array $clientIds): int
    {
        $clientIds = $this->uniquePositiveInts($clientIds);
        if ($clientIds === [] || !$this->tableExists('dap26_doctor_patient_search')) {
            return 0;
        }

        $searchModel = new \App\Models\DoctorPatientSearchModel();
        $resynced = 0;

        foreach ($clientIds as $clientId) {
            try {
                $searchModel->syncClient($clientId);
                $resynced++;
            } catch (\Throwable $e) {
                log_message('warning', 'DoctorDeletionService search sync failed for id_client={idClient}: {error}', [
                    'idClient' => $clientId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $resynced;
    }

    private function deleteDoctorLinks(int $doctorPersonaleId): void
    {
        if ($doctorPersonaleId <= 0) {
            return;
        }

        if ($this->tableExists('dap14_seg_dot')) {
            $this->db->table('dap14_seg_dot')
                ->where('id_dot', $doctorPersonaleId)
                ->delete();
        }

        if ($this->tableExists('dap15_inf_dot')) {
            $this->db->table('dap15_inf_dot')
                ->where('id_dot', $doctorPersonaleId)
                ->delete();
        }

        if ($this->tableExists('dap18_sostituto')) {
            $this->db->table('dap18_sostituto')
                ->groupStart()
                    ->where('id_personale', $doctorPersonaleId)
                    ->orWhere('id_personale_da_sostituire', $doctorPersonaleId)
                ->groupEnd()
                ->delete();
        }
    }

    private function deleteAgendaData(int $legacyDoctorId, array $scope): void
    {
        if ($legacyDoctorId <= 0) {
            return;
        }

        if ($this->tableExists('dap25_agenda_job')) {
            $this->db->table('dap25_agenda_job')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap24_agenda_visibilita')) {
            $this->db->table('dap24_agenda_visibilita')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap21_agenda_giorni_bloccati')) {
            $this->db->table('dap21_agenda_giorni_bloccati')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap37_block_memo')) {
            $this->db->table('dap37_block_memo')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap31_block_dom')) {
            $this->db->table('dap31_block_dom')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap49_dot_spec')) {
            $this->db->table('dap49_dot_spec')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if (!empty($scope['config_day_ids']) && $this->tableExists('dap10_agenda_config_fasce')) {
            foreach (array_chunk($scope['config_day_ids'], 500) as $chunk) {
                $this->db->table('dap10_agenda_config_fasce')
                    ->whereIn('id_config_giorno', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['config_ids']) && $this->tableExists('dap10_agenda_config_giorni')) {
            foreach (array_chunk($scope['config_ids'], 500) as $chunk) {
                $this->db->table('dap10_agenda_config_giorni')
                    ->whereIn('id_config', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['slot_ids']) && $this->tableExists('dap14_agenda_lock')) {
            foreach (array_chunk($scope['slot_ids'], 500) as $chunk) {
                $this->db->table('dap14_agenda_lock')
                    ->whereIn('id_slot', $chunk)
                    ->delete();
            }
        }

        if ($this->tableExists('dap12_agenda_appuntamenti')) {
            $this->db->table('dap12_agenda_appuntamenti')
                ->where('id_dot', $legacyDoctorId)
                ->delete();

            if (!empty($scope['slot_ids'])) {
                foreach (array_chunk($scope['slot_ids'], 500) as $chunk) {
                    $this->db->table('dap12_agenda_appuntamenti')
                        ->whereIn('id_slot', $chunk)
                        ->delete();
                }
            }
        }

        if (!empty($scope['backup_ids']) && $this->tableExists('dap20_agenda_backup_dettaglio')) {
            foreach (array_chunk($scope['backup_ids'], 500) as $chunk) {
                $this->db->table('dap20_agenda_backup_dettaglio')
                    ->whereIn('id_backup', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['backup_ids']) && $this->tableExists('dap19_agenda_backup')) {
            foreach (array_chunk($scope['backup_ids'], 500) as $chunk) {
                $this->db->table('dap19_agenda_backup')
                    ->whereIn('id_backup', $chunk)
                    ->delete();
            }
        }

        if ($this->tableExists('dap15_agenda_note')) {
            $this->db->table('dap15_agenda_note')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap23_agenda_nota_giorno')) {
            $this->db->table('dap23_agenda_nota_giorno')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap13_visite_domiciliari')) {
            $this->db->table('dap13_visite_domiciliari')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if ($this->tableExists('dap11_agenda_slot')) {
            $this->db->table('dap11_agenda_slot')
                ->where('id_dot', $legacyDoctorId)
                ->delete();
        }

        if (!empty($scope['config_ids']) && $this->tableExists('dap10_agenda_config')) {
            foreach (array_chunk($scope['config_ids'], 500) as $chunk) {
                $this->db->table('dap10_agenda_config')
                    ->whereIn('id_config', $chunk)
                    ->delete();
            }
        }
    }

    private function deleteLegacyMessages(array $scope): void
    {
        if (!empty($scope['attachment_ids']) && $this->tableExists('dap11_attachments')) {
            foreach (array_chunk($scope['attachment_ids'], 500) as $chunk) {
                $this->db->table('dap11_attachments')
                    ->whereIn('id_attachments', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['reply_ids']) && $this->tableExists('dap10_message_reply_delete')) {
            foreach (array_chunk($scope['reply_ids'], 500) as $chunk) {
                $this->db->table('dap10_message_reply_delete')
                    ->whereIn('id_message', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['message_ids']) && $this->tableExists('dap10_message_delete')) {
            foreach (array_chunk($scope['message_ids'], 500) as $chunk) {
                $this->db->table('dap10_message_delete')
                    ->whereIn('id_message', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['message_ids']) && $this->tableExists('dap17_inoltro_message')) {
            foreach (array_chunk($scope['message_ids'], 500) as $chunk) {
                $this->db->table('dap17_inoltro_message')
                    ->groupStart()
                        ->whereIn('id_message', $chunk)
                        ->orWhereIn('id_message_new', $chunk)
                    ->groupEnd()
                    ->delete();
            }
        }

        if (!empty($scope['reply_ids']) && $this->tableExists('dap10_message_reply')) {
            foreach (array_chunk($scope['reply_ids'], 500) as $chunk) {
                $this->db->table('dap10_message_reply')
                    ->whereIn('id_message', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['message_ids']) && $this->tableExists('dap10_message')) {
            foreach (array_chunk($scope['message_ids'], 500) as $chunk) {
                $this->db->table('dap10_message')
                    ->whereIn('id_message', $chunk)
                    ->delete();
            }
        }
    }

    private function deleteNewMessages(array $scope): void
    {
        if (!empty($scope['message_ids']) && $this->tableExists('msg_user_flags')) {
            foreach (array_chunk($scope['message_ids'], 500) as $chunk) {
                $this->db->table('msg_user_flags')
                    ->whereIn('id_message', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['attachment_ids']) && $this->tableExists('msg_attachments')) {
            foreach (array_chunk($scope['attachment_ids'], 500) as $chunk) {
                $this->db->table('msg_attachments')
                    ->whereIn('id_attachment', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['draft_ids']) && $this->tableExists('msg_drafts')) {
            foreach (array_chunk($scope['draft_ids'], 500) as $chunk) {
                $this->db->table('msg_drafts')
                    ->whereIn('id_draft', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['message_ids']) && $this->tableExists('msg_messages')) {
            foreach (array_chunk($scope['message_ids'], 500) as $chunk) {
                $this->db->table('msg_messages')
                    ->whereIn('id_message', $chunk)
                    ->delete();
            }
        }

        if (!empty($scope['thread_ids']) && $this->tableExists('msg_threads')) {
            foreach (array_chunk($scope['thread_ids'], 500) as $chunk) {
                $this->db->table('msg_threads')
                    ->whereIn('id_thread', $chunk)
                    ->delete();
            }
        }
    }

    private function deleteUserArtifacts(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        foreach (['push_subscriptions', 'push_outbox', 'push_delivery_logs', 'otp_delivery_logs', 'device_links'] as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $this->db->table($table)
                ->where('user_id', $userId)
                ->delete();
        }
    }

    private function listAffectedClientIds(int $doctorPersonaleId): array
    {
        $ids = [];

        if ($this->tableExists('dap09_client_doctor')) {
            $rows = $this->db->query(
                'SELECT DISTINCT id_client FROM dap09_client_doctor WHERE id_dot = ?',
                [$doctorPersonaleId]
            )->getResultArray();

            foreach ($rows as $row) {
                $id = (int)($row['id_client'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        if ($this->tableExists('dap02_clients')) {
            $rows = $this->db->query(
                'SELECT id_client FROM dap02_clients WHERE id_personale = ?',
                [$doctorPersonaleId]
            )->getResultArray();

            foreach ($rows as $row) {
                $id = (int)($row['id_client'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return $this->uniquePositiveInts($ids);
    }

    private function collectAgendaScope(int $legacyDoctorId): array
    {
        if ($legacyDoctorId <= 0) {
            return [
                'config_ids' => [],
                'config_day_ids' => [],
                'slot_ids' => [],
                'backup_ids' => [],
                'backup_paths' => [],
            ];
        }

        $configIds = $this->tableExists('dap10_agenda_config')
            ? $this->queryIds('SELECT id_config FROM dap10_agenda_config WHERE id_dot = ?', [$legacyDoctorId], 'id_config')
            : [];

        $configDayIds = [];
        if ($configIds !== [] && $this->tableExists('dap10_agenda_config_giorni')) {
            $configDayIds = $this->queryIdsByChunks(
                'SELECT id_config_giorno FROM dap10_agenda_config_giorni WHERE id_config IN (%s)',
                $configIds,
                'id_config_giorno'
            );
        }

        $slotIds = $this->tableExists('dap11_agenda_slot')
            ? $this->queryIds('SELECT id_slot FROM dap11_agenda_slot WHERE id_dot = ?', [$legacyDoctorId], 'id_slot')
            : [];

        $backupIds = $this->tableExists('dap19_agenda_backup')
            ? $this->queryIds('SELECT id_backup FROM dap19_agenda_backup WHERE id_dot = ?', [$legacyDoctorId], 'id_backup')
            : [];

        $backupPaths = [];
        if ($this->tableExists('dap19_agenda_backup')) {
            $rows = $this->db->query(
                'SELECT percorso_file_pdf FROM dap19_agenda_backup WHERE id_dot = ?',
                [$legacyDoctorId]
            )->getResultArray();

            foreach ($rows as $row) {
                $path = $this->normalizePath((string)($row['percorso_file_pdf'] ?? ''));
                if ($path !== '') {
                    $backupPaths[] = $path;
                }
            }
        }

        if ($this->tableExists('dap25_agenda_job')) {
            $rows = $this->db->query(
                'SELECT backup_file_path FROM dap25_agenda_job WHERE id_dot = ?',
                [$legacyDoctorId]
            )->getResultArray();

            foreach ($rows as $row) {
                $path = $this->normalizePath((string)($row['backup_file_path'] ?? ''));
                if ($path !== '') {
                    $backupPaths[] = $path;
                }
            }
        }

        return [
            'config_ids' => $configIds,
            'config_day_ids' => $configDayIds,
            'slot_ids' => $slotIds,
            'backup_ids' => $backupIds,
            'backup_paths' => array_values(array_unique($backupPaths)),
        ];
    }

    private function collectLegacyMessageScope(int $doctorPersonaleId): array
    {
        $messageIds = [];
        $replyIds = [];
        $attachmentIds = [];
        $legacyAttachmentDirs = [];

        if ($doctorPersonaleId <= 0 || !$this->tableExists('dap10_message')) {
            return [
                'message_ids' => [],
                'reply_ids' => [],
                'attachment_ids' => [],
                'legacy_attachment_dirs' => [],
            ];
        }

        $messageIds = $this->queryIds(
            'SELECT id_message
             FROM dap10_message
             WHERE id_mitt = ?
                OR id_dest = ?
                OR dot_seg = ?
                OR dot_inf = ?',
            [$doctorPersonaleId, $doctorPersonaleId, $doctorPersonaleId, $doctorPersonaleId],
            'id_message'
        );

        if ($this->tableExists('dap10_message_reply')) {
            $replyRootIds = $this->queryIds(
                'SELECT DISTINCT id_message_ini
                 FROM dap10_message_reply
                 WHERE id_mitt = ?
                    OR id_dest = ?
                    OR dot_seg = ?
                    OR dot_inf = ?',
                [$doctorPersonaleId, $doctorPersonaleId, $doctorPersonaleId, $doctorPersonaleId],
                'id_message_ini'
            );

            $messageIds = $this->uniquePositiveInts(array_merge($messageIds, $replyRootIds));
        }

        $messageIds = $this->expandLegacyMessageScope($messageIds);

        if ($messageIds !== [] && $this->tableExists('dap10_message_reply')) {
            $replyIds = $this->queryIdsByChunks(
                'SELECT id_message FROM dap10_message_reply WHERE id_message_ini IN (%s)',
                $messageIds,
                'id_message'
            );
        }

        if ($this->tableExists('dap11_attachments') && ($messageIds !== [] || $replyIds !== [])) {
            $attachmentRows = [];

            if ($messageIds !== []) {
                $attachmentRows = array_merge(
                    $attachmentRows,
                    $this->queryRowsByChunks(
                        'SELECT id_attachments, id_message FROM dap11_attachments WHERE id_message IN (%s)',
                        $messageIds
                    )
                );
            }

            if ($replyIds !== []) {
                $attachmentRows = array_merge(
                    $attachmentRows,
                    $this->queryRowsByChunks(
                        'SELECT id_attachments, id_message FROM dap11_attachments WHERE id_message_reply IN (%s)',
                        $replyIds
                    )
                );
            }

            foreach ($attachmentRows as $row) {
                $attachmentId = (int)($row['id_attachments'] ?? 0);
                $folderId = (int)($row['id_message'] ?? 0);
                if ($attachmentId > 0) {
                    $attachmentIds[] = $attachmentId;
                }
                if ($folderId > 0) {
                    $legacyAttachmentDirs[] = $folderId;
                }
            }
        }

        return [
            'message_ids' => $messageIds,
            'reply_ids' => $replyIds,
            'attachment_ids' => $this->uniquePositiveInts($attachmentIds),
            'legacy_attachment_dirs' => $this->uniquePositiveInts($legacyAttachmentDirs),
        ];
    }

    private function collectNewMessageScope(int $doctorUserId): array
    {
        $threadIds = [];
        $messageIds = [];
        $draftIds = [];
        $attachmentIds = [];
        $attachmentPaths = [];

        if ($doctorUserId <= 0) {
            return [
                'thread_ids' => [],
                'message_ids' => [],
                'draft_ids' => [],
                'attachment_ids' => [],
                'attachment_paths' => [],
            ];
        }

        if ($this->tableExists('msg_messages')) {
            $threadIds = $this->queryIds(
                'SELECT DISTINCT id_thread
                 FROM msg_messages
                 WHERE sender_user_id = ?
                    OR recipient_user_id = ?
                    OR root_author_user_id = ?',
                [$doctorUserId, $doctorUserId, $doctorUserId],
                'id_thread'
            );
        }

        if ($this->tableExists('msg_threads')) {
            $rootThreadIds = $this->queryIds(
                'SELECT id_thread
                 FROM msg_threads
                 WHERE root_author_user_id = ?',
                [$doctorUserId],
                'id_thread'
            );

            $threadIds = $this->uniquePositiveInts(array_merge($threadIds, $rootThreadIds));
        }

        if ($threadIds !== [] && $this->tableExists('msg_messages')) {
            $messageIds = $this->queryIdsByChunks(
                'SELECT id_message FROM msg_messages WHERE id_thread IN (%s)',
                $threadIds,
                'id_message'
            );
        }

        if ($this->tableExists('msg_drafts')) {
            $draftIds = $this->queryIds(
                'SELECT id_draft FROM msg_drafts WHERE owner_user_id = ?',
                [$doctorUserId],
                'id_draft'
            );
        }

        if ($this->tableExists('msg_attachments') && ($messageIds !== [] || $draftIds !== [])) {
            $decryptPathSql = $this->attachmentDecryptExpr('storage_path', 'vector_id');
            $attachmentRows = [];

            if ($messageIds !== []) {
                $attachmentRows = array_merge(
                    $attachmentRows,
                    $this->queryRowsByChunks(
                        "SELECT id_attachment, {$decryptPathSql} AS storage_path
                         FROM msg_attachments
                         WHERE id_message IN (%s)",
                        $messageIds
                    )
                );
            }

            if ($draftIds !== []) {
                $attachmentRows = array_merge(
                    $attachmentRows,
                    $this->queryRowsByChunks(
                        "SELECT id_attachment, {$decryptPathSql} AS storage_path
                         FROM msg_attachments
                         WHERE id_draft IN (%s)",
                        $draftIds
                    )
                );
            }

            foreach ($attachmentRows as $row) {
                $attachmentId = (int)($row['id_attachment'] ?? 0);
                $path = $this->normalizePath((string)($row['storage_path'] ?? ''));
                if ($attachmentId > 0) {
                    $attachmentIds[] = $attachmentId;
                }
                if ($path !== '') {
                    $attachmentPaths[] = $path;
                }
            }
        }

        return [
            'thread_ids' => $threadIds,
            'message_ids' => $messageIds,
            'draft_ids' => $draftIds,
            'attachment_ids' => $this->uniquePositiveInts($attachmentIds),
            'attachment_paths' => array_values(array_unique($attachmentPaths)),
        ];
    }

    private function buildCleanupPlan(array $agendaScope, array $legacyMessageScope, array $newMessageScope): array
    {
        $messageDirs = array_map(
            static fn(int $id): string => rtrim(WRITEPATH . 'uploads/messages/' . $id, DIRECTORY_SEPARATOR),
            $newMessageScope['message_ids']
        );

        $draftDirs = array_map(
            static fn(int $id): string => rtrim(WRITEPATH . 'uploads/messages/drafts/' . $id, DIRECTORY_SEPARATOR),
            $newMessageScope['draft_ids']
        );

        $legacyUploadRoot = rtrim(
            (string) (env('LEGACY_UPLOAD_PATH') ?: (dirname(rtrim(ROOTPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'upload')),
            DIRECTORY_SEPARATOR
        );
        $legacyDirs = array_map(
            static fn(int $id): string => rtrim($legacyUploadRoot . DIRECTORY_SEPARATOR . $id, DIRECTORY_SEPARATOR),
            $legacyMessageScope['legacy_attachment_dirs']
        );

        return [
            'new_attachment_paths' => $newMessageScope['attachment_paths'],
            'agenda_backup_paths' => $agendaScope['backup_paths'] ?? [],
            'new_message_dirs' => array_values(array_unique($messageDirs)),
            'new_draft_dirs' => array_values(array_unique($draftDirs)),
            'legacy_dirs' => array_values(array_unique($legacyDirs)),
            'legacy_attachment_dir_ids' => $legacyMessageScope['legacy_attachment_dirs'],
        ];
    }

    private function expandLegacyMessageScope(array $seedIds): array
    {
        $allIds = $this->uniquePositiveInts($seedIds);
        if ($allIds === [] || !$this->tableExists('dap17_inoltro_message')) {
            return $allIds;
        }

        $queue = $allIds;
        while ($queue !== []) {
            $currentChunk = array_splice($queue, 0, 300);
            $rows = $this->queryRowsByChunks(
                'SELECT id_message, id_message_new
                 FROM dap17_inoltro_message
                 WHERE id_message IN (%s)
                    OR id_message_new IN (%s)',
                $currentChunk,
                $currentChunk
            );

            foreach ($rows as $row) {
                foreach ([(int)($row['id_message'] ?? 0), (int)($row['id_message_new'] ?? 0)] as $candidate) {
                    if ($candidate > 0 && !in_array($candidate, $allIds, true)) {
                        $allIds[] = $candidate;
                        $queue[] = $candidate;
                    }
                }
            }
        }

        return $this->uniquePositiveInts($allIds);
    }

    private function cleanupFilesystem(array $plan): array
    {
        $deletedFiles = 0;
        $deletedDirs = 0;
        $skipped = 0;

        foreach ($plan['new_attachment_paths'] ?? [] as $path) {
            if ($this->deleteFileIfAllowed($path)) {
                $deletedFiles++;
            } else {
                $skipped++;
            }
        }

        foreach ($plan['agenda_backup_paths'] ?? [] as $path) {
            if ($this->deleteFileIfAllowed($path)) {
                $deletedFiles++;
            } else {
                $skipped++;
            }
        }

        foreach (['new_message_dirs', 'new_draft_dirs'] as $key) {
            foreach ($plan[$key] ?? [] as $dir) {
                if ($this->removeDirectoryIfAllowed($dir)) {
                    $deletedDirs++;
                } else {
                    $skipped++;
                }
            }
        }

        $legacyIds = $this->uniquePositiveInts($plan['legacy_attachment_dir_ids'] ?? []);
        $legacyDirs = $plan['legacy_dirs'] ?? [];
        foreach ($legacyDirs as $index => $dir) {
            $legacyId = (int)($legacyIds[$index] ?? 0);
            if ($legacyId <= 0 || $this->legacyUploadDirStillReferenced($legacyId)) {
                $skipped++;
                continue;
            }

            if ($this->removeDirectoryIfAllowed($dir)) {
                $deletedDirs++;
            } else {
                $skipped++;
            }
        }

        return [
            'deleted_files' => $deletedFiles,
            'deleted_dirs' => $deletedDirs,
            'skipped_items' => $skipped,
        ];
    }

    private function legacyUploadDirStillReferenced(int $messageId): bool
    {
        if ($messageId <= 0) {
            return false;
        }

        if ($this->tableExists('dap11_attachments')) {
            $row = $this->db->query(
                'SELECT 1 FROM dap11_attachments WHERE id_message = ? LIMIT 1',
                [$messageId]
            )->getRowArray();

            if ($row) {
                return true;
            }
        }

        if ($this->tableExists('dap17_inoltro_message')) {
            $row = $this->db->query(
                'SELECT 1 FROM dap17_inoltro_message WHERE id_message = ? LIMIT 1',
                [$messageId]
            )->getRowArray();

            if ($row) {
                return true;
            }
        }

        return false;
    }

    private function deleteFileIfAllowed(string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === '' || !is_file($path) || !$this->isAllowedFilesystemTarget($path)) {
            return false;
        }

        return @unlink($path);
    }

    private function removeDirectoryIfAllowed(string $dir): bool
    {
        $dir = $this->normalizePath($dir);
        if ($dir === '' || !is_dir($dir) || !$this->isAllowedFilesystemTarget($dir)) {
            return false;
        }

        return $this->removeDirectoryRecursive($dir);
    }

    private function removeDirectoryRecursive(string $dir): bool
    {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                if (!$this->removeDirectoryRecursive($path)) {
                    return false;
                }
                continue;
            }

            if (!@unlink($path)) {
                return false;
            }
        }

        return @rmdir($dir);
    }

    private function isAllowedFilesystemTarget(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            $candidate = realpath(dirname($path));
            if ($candidate === false) {
                return false;
            }

            $real = rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
        }

        $legacyUploadRoot = rtrim(
            (string) (env('LEGACY_UPLOAD_PATH') ?: (dirname(rtrim(ROOTPATH, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'upload')),
            DIRECTORY_SEPARATOR
        );
        $roots = [
            rtrim(realpath($legacyUploadRoot) ?: '', DIRECTORY_SEPARATOR),
            rtrim(realpath(WRITEPATH . 'uploads') ?: '', DIRECTORY_SEPARATOR),
        ];

        foreach ($roots as $root) {
            if ($root !== '' && str_starts_with($real, $root)) {
                return true;
            }
        }

        return false;
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->db->tableExists($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function queryIds(string $sql, array $params, string $column): array
    {
        $rows = $this->db->query($sql, $params)->getResultArray();
        $ids = [];

        foreach ($rows as $row) {
            $id = (int)($row[$column] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $this->uniquePositiveInts($ids);
    }

    private function queryIdsByChunks(string $sqlTemplate, array $values, string $column): array
    {
        $values = $this->uniquePositiveInts($values);
        if ($values === []) {
            return [];
        }

        $ids = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = sprintf($sqlTemplate, $placeholders);
            $rows = $this->db->query($sql, $chunk)->getResultArray();

            foreach ($rows as $row) {
                $id = (int)($row[$column] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return $this->uniquePositiveInts($ids);
    }

    private function queryRowsByChunks(string $sqlTemplate, array $valuesA, ?array $valuesB = null): array
    {
        $valuesA = $this->uniquePositiveInts($valuesA);
        $valuesB = $valuesB === null ? null : $this->uniquePositiveInts($valuesB);

        if ($valuesA === [] && ($valuesB === null || $valuesB === [])) {
            return [];
        }

        $rows = [];

        if ($valuesB === null) {
            foreach (array_chunk($valuesA, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = sprintf($sqlTemplate, $placeholders);
                $rows = array_merge($rows, $this->db->query($sql, $chunk)->getResultArray());
            }

            return $rows;
        }

        $maxChunks = max(
            (int)ceil(count($valuesA) / 300),
            (int)ceil(count($valuesB) / 300)
        );

        for ($i = 0; $i < $maxChunks; $i++) {
            $chunkA = array_slice($valuesA, $i * 300, 300);
            $chunkB = array_slice($valuesB, $i * 300, 300);

            if ($chunkA === [] && $chunkB === []) {
                continue;
            }

            if ($chunkA === []) {
                $chunkA = [0];
            }

            if ($chunkB === []) {
                $chunkB = [0];
            }

            $sql = sprintf(
                $sqlTemplate,
                implode(',', array_fill(0, count($chunkA), '?')),
                implode(',', array_fill(0, count($chunkB), '?'))
            );

            $rows = array_merge($rows, $this->db->query($sql, array_merge($chunkA, $chunkB))->getResultArray());
        }

        return $rows;
    }

    private function attachmentDecryptExpr(string $fieldExpr, string $vectorExpr): string
    {
        return "CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR(2500) CHARACTER SET utf8mb4)";
    }

    private function uniquePositiveInts(array $values): array
    {
        $filtered = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $value): bool => $value > 0
        )));

        sort($filtered);

        return $filtered;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace("\0", '', trim($path));
        return $path === '' ? '' : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
