-- Pulizia manuale di un dottore in pensione.
-- Uso consigliato:
-- 1) imposta @doctor_personale_id
-- 2) esegui il file a blocchi, non tutto insieme
-- 3) fermati dopo le SELECT di preview
-- 4) se i numeri sono corretti continua con i DELETE
-- 5) COMMIT solo alla fine, altrimenti ROLLBACK
--
-- Nota: questo script pulisce il database.
-- Non elimina i file fisici in upload/ e writable/uploads/.

SET @doctor_personale_id := 123;
SET @doctor_user_id := 0;
SET @legacy_doctor_id := 0;

SELECT
    p.id_personale,
    p.id_user,
    p.tipo,
    COALESCE(p.legacy_id_dot, 0) AS legacy_id_dot
FROM dap03_personale p
WHERE p.id_personale = @doctor_personale_id
  AND p.tipo = 1
LIMIT 1;

SELECT
    @doctor_user_id := COALESCE(p.id_user, 0),
    @legacy_doctor_id := COALESCE(p.legacy_id_dot, 0)
FROM dap03_personale p
WHERE p.id_personale = @doctor_personale_id
  AND p.tipo = 1
LIMIT 1;

SELECT
    @doctor_personale_id AS doctor_personale_id,
    @doctor_user_id AS doctor_user_id,
    @legacy_doctor_id AS legacy_doctor_id;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_affected_clients;
CREATE TEMPORARY TABLE tmp_affected_clients (
    id_client INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_affected_clients (id_client)
SELECT DISTINCT cd.id_client
FROM dap09_client_doctor cd
WHERE @doctor_personale_id > 0
  AND cd.id_dot = @doctor_personale_id;

INSERT IGNORE INTO tmp_affected_clients (id_client)
SELECT c.id_client
FROM dap02_clients c
WHERE @doctor_personale_id > 0
  AND c.id_personale = @doctor_personale_id;

DROP TEMPORARY TABLE IF EXISTS tmp_client_replacements;
CREATE TEMPORARY TABLE tmp_client_replacements AS
SELECT
    t.id_client,
    COALESCE(
        MIN(
            CASE
                WHEN COALESCE(p.legacy_dot_tipo_id, 0) = 1 OR COALESCE(p.f_dom, 0) = 1
                    THEN cd.id_dot
                ELSE NULL
            END
        ),
        MIN(cd.id_dot),
        0
    ) AS new_primary_doctor_id
FROM tmp_affected_clients t
LEFT JOIN dap09_client_doctor cd
    ON cd.id_client = t.id_client
   AND cd.id_dot <> @doctor_personale_id
LEFT JOIN dap03_personale p
    ON p.id_personale = cd.id_dot
GROUP BY t.id_client;

DROP TEMPORARY TABLE IF EXISTS tmp_agenda_configs;
CREATE TEMPORARY TABLE tmp_agenda_configs (
    id_config INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_agenda_configs (id_config)
SELECT ac.id_config
FROM dap10_agenda_config ac
WHERE @legacy_doctor_id > 0
  AND ac.id_dot = @legacy_doctor_id;

DROP TEMPORARY TABLE IF EXISTS tmp_agenda_config_days;
CREATE TEMPORARY TABLE tmp_agenda_config_days (
    id_config_giorno INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_agenda_config_days (id_config_giorno)
SELECT acg.id_config_giorno
FROM dap10_agenda_config_giorni acg
JOIN tmp_agenda_configs tac
  ON tac.id_config = acg.id_config;

DROP TEMPORARY TABLE IF EXISTS tmp_agenda_slots;
CREATE TEMPORARY TABLE tmp_agenda_slots (
    id_slot INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_agenda_slots (id_slot)
SELECT s.id_slot
FROM dap11_agenda_slot s
WHERE @legacy_doctor_id > 0
  AND s.id_dot = @legacy_doctor_id;

DROP TEMPORARY TABLE IF EXISTS tmp_agenda_backups;
CREATE TEMPORARY TABLE tmp_agenda_backups (
    id_backup INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_agenda_backups (id_backup)
SELECT b.id_backup
FROM dap19_agenda_backup b
WHERE @legacy_doctor_id > 0
  AND b.id_dot = @legacy_doctor_id;

DROP TEMPORARY TABLE IF EXISTS tmp_legacy_messages;
CREATE TEMPORARY TABLE tmp_legacy_messages (
    id_message INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT m.id_message
FROM dap10_message m
WHERE @doctor_personale_id > 0
  AND (
        m.id_mitt = @doctor_personale_id
     OR m.id_dest = @doctor_personale_id
     OR m.dot_seg = @doctor_personale_id
     OR m.dot_inf = @doctor_personale_id
  );

INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT DISTINCT r.id_message_ini
FROM dap10_message_reply r
WHERE @doctor_personale_id > 0
  AND (
        r.id_mitt = @doctor_personale_id
     OR r.id_dest = @doctor_personale_id
     OR r.dot_seg = @doctor_personale_id
      OR r.dot_inf = @doctor_personale_id
  );

-- Compatibile con MySQL 5.7 / MariaDB senza WITH RECURSIVE e senza
-- "Can't reopen table" su tabelle temporanee.
DROP TEMPORARY TABLE IF EXISTS tmp_legacy_frontier;
CREATE TEMPORARY TABLE tmp_legacy_frontier (
    id_message INT NOT NULL PRIMARY KEY
);

DROP TEMPORARY TABLE IF EXISTS tmp_legacy_next;
CREATE TEMPORARY TABLE tmp_legacy_next (
    id_message INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message
FROM tmp_legacy_messages;

-- round 1
TRUNCATE TABLE tmp_legacy_next;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message_new;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message_new
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message;
INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT id_message FROM tmp_legacy_next;
TRUNCATE TABLE tmp_legacy_frontier;
INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message FROM tmp_legacy_next;

-- round 2
TRUNCATE TABLE tmp_legacy_next;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message_new;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message_new
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message;
INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT id_message FROM tmp_legacy_next;
TRUNCATE TABLE tmp_legacy_frontier;
INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message FROM tmp_legacy_next;

-- round 3
TRUNCATE TABLE tmp_legacy_next;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message_new;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message_new
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message;
INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT id_message FROM tmp_legacy_next;
TRUNCATE TABLE tmp_legacy_frontier;
INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message FROM tmp_legacy_next;

-- round 4
TRUNCATE TABLE tmp_legacy_next;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message_new;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message_new
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message;
INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT id_message FROM tmp_legacy_next;
TRUNCATE TABLE tmp_legacy_frontier;
INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message FROM tmp_legacy_next;

-- round 5
TRUNCATE TABLE tmp_legacy_next;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message_new;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message_new
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message;
INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT id_message FROM tmp_legacy_next;
TRUNCATE TABLE tmp_legacy_frontier;
INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message FROM tmp_legacy_next;

-- round 6
TRUNCATE TABLE tmp_legacy_next;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message_new;
INSERT IGNORE INTO tmp_legacy_next (id_message)
SELECT im.id_message_new
FROM dap17_inoltro_message im
JOIN tmp_legacy_frontier f ON f.id_message = im.id_message;
INSERT IGNORE INTO tmp_legacy_messages (id_message)
SELECT id_message FROM tmp_legacy_next;
TRUNCATE TABLE tmp_legacy_frontier;
INSERT IGNORE INTO tmp_legacy_frontier (id_message)
SELECT id_message FROM tmp_legacy_next;

DROP TEMPORARY TABLE IF EXISTS tmp_legacy_replies;
CREATE TEMPORARY TABLE tmp_legacy_replies (
    id_message INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_legacy_replies (id_message)
SELECT r.id_message
FROM dap10_message_reply r
JOIN tmp_legacy_messages tlm
  ON tlm.id_message = r.id_message_ini;

DROP TEMPORARY TABLE IF EXISTS tmp_legacy_attachments;
CREATE TEMPORARY TABLE tmp_legacy_attachments (
    id_attachments INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_legacy_attachments (id_attachments)
SELECT a.id_attachments
FROM dap11_attachments a
JOIN tmp_legacy_messages tlm
  ON tlm.id_message = a.id_message;

INSERT IGNORE INTO tmp_legacy_attachments (id_attachments)
SELECT a.id_attachments
FROM dap11_attachments a
JOIN tmp_legacy_replies tlr
  ON tlr.id_message = a.id_message_reply;

DROP TEMPORARY TABLE IF EXISTS tmp_new_threads;
CREATE TEMPORARY TABLE tmp_new_threads (
    id_thread INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_new_threads (id_thread)
SELECT DISTINCT m.id_thread
FROM msg_messages m
WHERE @doctor_user_id > 0
  AND (
        m.sender_user_id = @doctor_user_id
     OR m.recipient_user_id = @doctor_user_id
     OR m.root_author_user_id = @doctor_user_id
  );

INSERT IGNORE INTO tmp_new_threads (id_thread)
SELECT t.id_thread
FROM msg_threads t
WHERE @doctor_user_id > 0
  AND t.root_author_user_id = @doctor_user_id;

DROP TEMPORARY TABLE IF EXISTS tmp_new_messages;
CREATE TEMPORARY TABLE tmp_new_messages (
    id_message INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_new_messages (id_message)
SELECT m.id_message
FROM msg_messages m
JOIN tmp_new_threads tnt
  ON tnt.id_thread = m.id_thread;

DROP TEMPORARY TABLE IF EXISTS tmp_new_drafts;
CREATE TEMPORARY TABLE tmp_new_drafts (
    id_draft INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_new_drafts (id_draft)
SELECT d.id_draft
FROM msg_drafts d
WHERE @doctor_user_id > 0
  AND d.owner_user_id = @doctor_user_id;

DROP TEMPORARY TABLE IF EXISTS tmp_new_attachments;
CREATE TEMPORARY TABLE tmp_new_attachments (
    id_attachment INT NOT NULL PRIMARY KEY
);

INSERT IGNORE INTO tmp_new_attachments (id_attachment)
SELECT a.id_attachment
FROM msg_attachments a
JOIN tmp_new_messages tnm
  ON tnm.id_message = a.id_message;

INSERT IGNORE INTO tmp_new_attachments (id_attachment)
SELECT a.id_attachment
FROM msg_attachments a
JOIN tmp_new_drafts tnd
  ON tnd.id_draft = a.id_draft;

SELECT
    (SELECT COUNT(*) FROM tmp_affected_clients) AS patients_to_detach,
    (SELECT COUNT(*) FROM tmp_agenda_configs) AS agenda_configs_to_delete,
    (SELECT COUNT(*) FROM tmp_agenda_slots) AS agenda_slots_to_delete,
    (SELECT COUNT(*) FROM tmp_agenda_backups) AS agenda_backups_to_delete,
    (SELECT COUNT(*) FROM tmp_legacy_messages) AS legacy_messages_to_delete,
    (SELECT COUNT(*) FROM tmp_legacy_replies) AS legacy_replies_to_delete,
    (SELECT COUNT(*) FROM tmp_new_threads) AS new_threads_to_delete,
    (SELECT COUNT(*) FROM tmp_new_messages) AS new_messages_to_delete,
    (SELECT COUNT(*) FROM tmp_new_drafts) AS new_drafts_to_delete;

SELECT
    c.id_client,
    COALESCE(c.id_personale, 0) AS current_primary_doctor_id,
    COALESCE(r.new_primary_doctor_id, 0) AS replacement_primary_doctor_id
FROM dap02_clients c
JOIN tmp_affected_clients t
  ON t.id_client = c.id_client
LEFT JOIN tmp_client_replacements r
  ON r.id_client = c.id_client
ORDER BY c.id_client;

-- Fermati qui.
-- Se i numeri o i pazienti non ti convincono: ROLLBACK;
-- Se tutto torna, continua con i DELETE qui sotto.

DELETE cd
FROM dap09_client_doctor cd
JOIN tmp_affected_clients t
  ON t.id_client = cd.id_client
WHERE cd.id_dot = @doctor_personale_id;

UPDATE dap02_clients c
JOIN tmp_client_replacements r
  ON r.id_client = c.id_client
SET c.id_personale = NULLIF(r.new_primary_doctor_id, 0)
WHERE c.id_personale = @doctor_personale_id;

DELETE FROM dap26_doctor_patient_search
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap14_seg_dot
WHERE id_dot = @doctor_personale_id;

DELETE FROM dap15_inf_dot
WHERE id_dot = @doctor_personale_id;

DELETE FROM dap18_sostituto
WHERE id_personale = @doctor_personale_id
   OR id_personale_da_sostituire = @doctor_personale_id;

DELETE FROM dap25_agenda_job
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap24_agenda_visibilita
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap21_agenda_giorni_bloccati
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap37_block_memo
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap31_block_dom
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap49_dot_spec
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE alf
FROM dap10_agenda_config_fasce alf
JOIN tmp_agenda_config_days tacd
  ON tacd.id_config_giorno = alf.id_config_giorno;

DELETE alg
FROM dap10_agenda_config_giorni alg
JOIN tmp_agenda_configs tac
  ON tac.id_config = alg.id_config;

DELETE al
FROM dap14_agenda_lock al
JOIN tmp_agenda_slots tas
  ON tas.id_slot = al.id_slot;

DELETE aa
FROM dap12_agenda_appuntamenti aa
WHERE @legacy_doctor_id > 0
  AND aa.id_dot = @legacy_doctor_id;

DELETE aa
FROM dap12_agenda_appuntamenti aa
JOIN tmp_agenda_slots tas
  ON tas.id_slot = aa.id_slot;

DELETE abd
FROM dap20_agenda_backup_dettaglio abd
JOIN tmp_agenda_backups tab
  ON tab.id_backup = abd.id_backup;

DELETE ab
FROM dap19_agenda_backup ab
JOIN tmp_agenda_backups tab
  ON tab.id_backup = ab.id_backup;

DELETE FROM dap15_agenda_note
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap23_agenda_nota_giorno
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap13_visite_domiciliari
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE FROM dap11_agenda_slot
WHERE @legacy_doctor_id > 0
  AND id_dot = @legacy_doctor_id;

DELETE ac
FROM dap10_agenda_config ac
JOIN tmp_agenda_configs tac
  ON tac.id_config = ac.id_config;

DELETE ad
FROM dap11_attachments ad
JOIN tmp_legacy_attachments tla
  ON tla.id_attachments = ad.id_attachments;

DELETE mrd
FROM dap10_message_reply_delete mrd
JOIN tmp_legacy_replies tlr
  ON tlr.id_message = mrd.id_message;

DELETE md
FROM dap10_message_delete md
JOIN tmp_legacy_messages tlm
  ON tlm.id_message = md.id_message;

DELETE im
FROM dap17_inoltro_message im
LEFT JOIN tmp_legacy_messages t1
  ON t1.id_message = im.id_message
LEFT JOIN tmp_legacy_messages t2
  ON t2.id_message = im.id_message_new
WHERE t1.id_message IS NOT NULL
   OR t2.id_message IS NOT NULL;

DELETE mr
FROM dap10_message_reply mr
JOIN tmp_legacy_replies tlr
  ON tlr.id_message = mr.id_message;

DELETE mm
FROM dap10_message mm
JOIN tmp_legacy_messages tlm
  ON tlm.id_message = mm.id_message;

DELETE muf
FROM msg_user_flags muf
JOIN tmp_new_messages tnm
  ON tnm.id_message = muf.id_message;

DELETE ma
FROM msg_attachments ma
JOIN tmp_new_attachments tna
  ON tna.id_attachment = ma.id_attachment;

DELETE md
FROM msg_drafts md
JOIN tmp_new_drafts tnd
  ON tnd.id_draft = md.id_draft;

DELETE mm
FROM msg_messages mm
JOIN tmp_new_messages tnm
  ON tnm.id_message = mm.id_message;

DELETE mt
FROM msg_threads mt
JOIN tmp_new_threads tnt
  ON tnt.id_thread = mt.id_thread;

DELETE FROM push_subscriptions
WHERE @doctor_user_id > 0
  AND user_id = @doctor_user_id;

DELETE FROM push_outbox
WHERE @doctor_user_id > 0
  AND user_id = @doctor_user_id;

DELETE FROM push_delivery_logs
WHERE @doctor_user_id > 0
  AND user_id = @doctor_user_id;

DELETE FROM otp_delivery_logs
WHERE @doctor_user_id > 0
  AND user_id = @doctor_user_id;

DELETE FROM device_links
WHERE @doctor_user_id > 0
  AND user_id = @doctor_user_id;

DELETE FROM dap03_personale
WHERE id_personale = @doctor_personale_id;

DELETE FROM dap01_users
WHERE @doctor_user_id > 0
  AND id_user = @doctor_user_id;

SELECT
    @doctor_personale_id AS deleted_doctor_personale_id,
    @doctor_user_id AS deleted_doctor_user_id,
    @legacy_doctor_id AS deleted_legacy_doctor_id;

-- Se tutto e corretto:
-- COMMIT;
--
-- Se vuoi annullare:
-- ROLLBACK;
