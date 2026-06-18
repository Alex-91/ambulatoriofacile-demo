-- Ripristino slot CONFIG mancanti di Gabrielli senza shell PHP.
-- Uso: apri phpMyAdmin/Adminer sul DB `mail`, importa/esegui questo file.
-- Sicuro da rieseguire: inserisce solo gli slot mancanti.

START TRANSACTION;

SET @doctor_id := 63;
SET @date_from := '2026-06-01';
SET @date_to   := '2027-12-31';
SET @patch_note := 'Patch Gabrielli recurring extra esportata da locale';
SET @repair_note := 'Ripristinato CONFIG dopo patch Gabrielli recurring extra';

DROP TEMPORARY TABLE IF EXISTS tmp_gabrielli_numbers;
CREATE TEMPORARY TABLE tmp_gabrielli_numbers (
    n INT NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

INSERT INTO tmp_gabrielli_numbers (n)
VALUES
    (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),
    (10),(11),(12),(13),(14),(15),(16),(17),(18),(19),
    (20),(21),(22),(23),(24),(25),(26),(27),(28),(29),
    (30),(31),(32),(33),(34),(35),(36),(37),(38),(39),
    (40),(41),(42),(43),(44),(45),(46),(47),(48),(49),
    (50),(51),(52),(53),(54),(55),(56),(57),(58),(59),
    (60),(61),(62),(63);

DROP TEMPORARY TABLE IF EXISTS tmp_gabrielli_affected_windows;
CREATE TEMPORARY TABLE tmp_gabrielli_affected_windows (
    id_dot INT NOT NULL,
    data_slot DATE NOT NULL,
    extra_start TIME NOT NULL,
    extra_end TIME NOT NULL,
    PRIMARY KEY (id_dot, data_slot, extra_start, extra_end)
) ENGINE=InnoDB;

INSERT INTO tmp_gabrielli_affected_windows (id_dot, data_slot, extra_start, extra_end)
SELECT
    s.id_dot,
    s.data_slot,
    TIME(s.ora_inizio) AS extra_start,
    TIME(s.ora_fine) AS extra_end
FROM dap11_agenda_slot s
WHERE s.id_dot = @doctor_id
  AND s.origine_slot = 'EXTRA'
  AND s.note_interne = @patch_note
  AND s.data_slot >= @date_from
  AND s.data_slot <= @date_to;

DROP TEMPORARY TABLE IF EXISTS tmp_gabrielli_candidate_config_slots;
CREATE TEMPORARY TABLE tmp_gabrielli_candidate_config_slots (
    id_dot INT NOT NULL,
    id_config BIGINT NOT NULL,
    data_slot DATE NOT NULL,
    ora_inizio DATETIME NOT NULL,
    ora_fine DATETIME NOT NULL,
    id_amb_legacy INT NULL,
    ambulatorio VARCHAR(150) NULL,
    stanza VARCHAR(100) NULL,
    PRIMARY KEY (id_dot, data_slot, ora_inizio, ora_fine)
) ENGINE=InnoDB;

INSERT INTO tmp_gabrielli_candidate_config_slots (
    id_dot,
    id_config,
    data_slot,
    ora_inizio,
    ora_fine,
    id_amb_legacy,
    ambulatorio,
    stanza
)
SELECT DISTINCT
    aw.id_dot,
    c.id_config,
    aw.data_slot,
    TIMESTAMP(
        aw.data_slot,
        ADDTIME(cf.ora_inizio, SEC_TO_TIME(nums.n * cf.durata_slot * 60))
    ) AS ora_inizio,
    TIMESTAMP(
        aw.data_slot,
        ADDTIME(cf.ora_inizio, SEC_TO_TIME((nums.n + 1) * cf.durata_slot * 60))
    ) AS ora_fine,
    cf.id_amb_legacy,
    NULLIF(cf.ambulatorio, '') AS ambulatorio,
    NULLIF(cf.stanza, '') AS stanza
FROM tmp_gabrielli_affected_windows aw
INNER JOIN dap10_agenda_config c
    ON c.id_config = (
        SELECT c2.id_config
        FROM dap10_agenda_config c2
        WHERE c2.id_dot = aw.id_dot
          AND c2.attiva = 1
          AND c2.data_inizio <= aw.data_slot
          AND c2.data_fine >= aw.data_slot
        ORDER BY c2.id_config DESC
        LIMIT 1
    )
INNER JOIN dap10_agenda_config_giorni cg
    ON cg.id_config = c.id_config
   AND cg.giorno_settimana = ((DAYOFWEEK(aw.data_slot) + 5) % 7) + 1
   AND COALESCE(cg.giorno_libero, 0) = 0
INNER JOIN dap10_agenda_config_fasce cf
    ON cf.id_config_giorno = cg.id_config_giorno
INNER JOIN tmp_gabrielli_numbers nums
    ON TIMESTAMP(
           aw.data_slot,
           ADDTIME(cf.ora_inizio, SEC_TO_TIME((nums.n + 1) * cf.durata_slot * 60))
       ) <= TIMESTAMP(aw.data_slot, cf.ora_fine)
WHERE TIMESTAMP(
          aw.data_slot,
          ADDTIME(cf.ora_inizio, SEC_TO_TIME(nums.n * cf.durata_slot * 60))
      ) < TIMESTAMP(aw.data_slot, aw.extra_end)
  AND TIMESTAMP(
          aw.data_slot,
          ADDTIME(cf.ora_inizio, SEC_TO_TIME((nums.n + 1) * cf.durata_slot * 60))
      ) > TIMESTAMP(aw.data_slot, aw.extra_start);

-- Anteprima: slot CONFIG attesi nei giorni toccati dalla patch.
SELECT
    COUNT(*) AS expected_overlapping_config_slots
FROM tmp_gabrielli_candidate_config_slots;

-- Inserisce solo gli slot mancanti.
INSERT INTO dap11_agenda_slot (
    id_dot,
    id_config,
    data_slot,
    ora_inizio,
    ora_fine,
    tipo_slot,
    stato,
    titolo_libero,
    id_amb_legacy,
    ambulatorio,
    stanza,
    origine_slot,
    note_interne,
    created_at,
    updated_at
)
SELECT
    candidate.id_dot,
    candidate.id_config,
    candidate.data_slot,
    candidate.ora_inizio,
    candidate.ora_fine,
    'AMBULATORIO' AS tipo_slot,
    CASE
        WHEN blocked.id_blocco IS NOT NULL THEN 'CHIUSO'
        ELSE 'LIBERO'
    END AS stato,
    NULL AS titolo_libero,
    candidate.id_amb_legacy,
    candidate.ambulatorio,
    candidate.stanza,
    'CONFIG' AS origine_slot,
    @repair_note AS note_interne,
    NOW() AS created_at,
    NOW() AS updated_at
FROM tmp_gabrielli_candidate_config_slots candidate
LEFT JOIN dap11_agenda_slot existing
    ON existing.id_dot = candidate.id_dot
   AND existing.data_slot = candidate.data_slot
   AND existing.ora_inizio = candidate.ora_inizio
   AND existing.ora_fine = candidate.ora_fine
LEFT JOIN dap21_agenda_giorni_bloccati blocked
    ON blocked.id_dot = candidate.id_dot
   AND blocked.data_agenda = candidate.data_slot
WHERE existing.id_slot IS NULL;

-- Verifica finale: se torna 0, il ripristino e completo.
SELECT
    COUNT(*) AS still_missing_exact_slots
FROM tmp_gabrielli_candidate_config_slots candidate
LEFT JOIN dap11_agenda_slot existing
    ON existing.id_dot = candidate.id_dot
   AND existing.data_slot = candidate.data_slot
   AND existing.ora_inizio = candidate.ora_inizio
   AND existing.ora_fine = candidate.ora_fine
WHERE existing.id_slot IS NULL;

SELECT
    s.data_slot,
    TIME(s.ora_inizio) AS ora_inizio,
    TIME(s.ora_fine) AS ora_fine,
    s.origine_slot,
    s.stato,
    s.note_interne
FROM dap11_agenda_slot s
WHERE s.id_dot = @doctor_id
  AND s.data_slot >= @date_from
  AND s.data_slot <= @date_to
  AND (
      (TIME(s.ora_inizio) = '12:30:00' AND TIME(s.ora_fine) = '13:15:00')
      OR (TIME(s.ora_inizio) = '13:00:00' AND TIME(s.ora_fine) = '13:30:00')
      OR (TIME(s.ora_inizio) = '18:30:00' AND TIME(s.ora_fine) = '19:15:00')
      OR (TIME(s.ora_inizio) = '19:00:00' AND TIME(s.ora_fine) = '19:30:00')
  )
ORDER BY s.data_slot ASC, s.ora_inizio ASC, s.origine_slot ASC;

COMMIT;
