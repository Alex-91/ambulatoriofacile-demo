-- Rebuild completo della tabella dap26_doctor_patient_search
-- Sostituisci i due placeholder qui sotto con i valori della produzione:
--   1) DB_ENCRYPTION_KEY
--   2) DB_ENCRYPTION_MODE
--
-- Se la tabella e' appena stata creata ed e' vuota, puoi saltare il TRUNCATE.
-- Se non hai permessi TRUNCATE, usa: DELETE FROM dap26_doctor_patient_search;

SET @key_str = SHA2('PartitaIVA22', 512);
SET NAMES latin1;
SET block_encryption_mode = 'aes-256-cbc';

ALTER TABLE dap26_doctor_patient_search
    ADD COLUMN IF NOT EXISTS paz_spec_norm varchar(191) DEFAULT NULL AFTER email_norm,
    ADD INDEX IF NOT EXISTS idx_dps_paz_spec (id_dot, paz_spec_norm, id_client);

TRUNCATE TABLE dap26_doctor_patient_search;

INSERT IGNORE INTO dap26_doctor_patient_search (
    id_dot,
    id_client,
    cognome_norm,
    nome_norm,
    full_norm,
    cf_norm,
    tel_norm,
    cell_norm,
    email_norm,
    paz_spec_norm,
    updated_at
)
SELECT DISTINCT
    p.legacy_id_dot AS id_dot,
    c.id_client,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS cognome_norm,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS nome_norm,
    LEFT(
        TRIM(CONCAT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            ' ',
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), '')))
        )),
        191
    ) AS full_norm,
    NULLIF(
        LEFT(
            REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ',
                ''
            ),
            32
        ),
        ''
    ) AS cf_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS tel_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS cell_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS email_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS paz_spec_norm,
    NOW()
FROM dap02_clients c
INNER JOIN dap03_personale p
    ON p.id_personale = c.id_personale
WHERE p.legacy_id_dot > 0;

INSERT IGNORE INTO dap26_doctor_patient_search (
    id_dot,
    id_client,
    cognome_norm,
    nome_norm,
    full_norm,
    cf_norm,
    tel_norm,
    cell_norm,
    email_norm,
    paz_spec_norm,
    updated_at
)
SELECT DISTINCT
    p.legacy_id_dot AS id_dot,
    c.id_client,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS cognome_norm,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS nome_norm,
    LEFT(
        TRIM(CONCAT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            ' ',
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), '')))
        )),
        191
    ) AS full_norm,
    NULLIF(
        LEFT(
            REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ',
                ''
            ),
            32
        ),
        ''
    ) AS cf_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS tel_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS cell_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS email_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS paz_spec_norm,
    NOW()
FROM dap02_clients c
INNER JOIN dap09_client_doctor cd
    ON cd.id_client = c.id_client
INNER JOIN dap03_personale p
    ON p.id_personale = cd.id_dot
WHERE p.legacy_id_dot > 0;

INSERT IGNORE INTO dap26_doctor_patient_search (
    id_dot,
    id_client,
    cognome_norm,
    nome_norm,
    full_norm,
    cf_norm,
    tel_norm,
    cell_norm,
    email_norm,
    paz_spec_norm,
    updated_at
)
SELECT DISTINCT
    a.id_dot,
    c.id_client,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS cognome_norm,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS nome_norm,
    LEFT(
        TRIM(CONCAT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            ' ',
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), '')))
        )),
        191
    ) AS full_norm,
    NULLIF(
        LEFT(
            REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ',
                ''
            ),
            32
        ),
        ''
    ) AS cf_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS tel_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS cell_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS email_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS paz_spec_norm,
    NOW()
FROM dap12_agenda_appuntamenti a
INNER JOIN dap02_clients c
    ON c.id_client = a.id_client
WHERE a.id_dot > 0
  AND a.stato <> 'ANNULLATO'
  AND a.id_client IS NOT NULL
  AND a.id_client > 0;

INSERT IGNORE INTO dap26_doctor_patient_search (
    id_dot,
    id_client,
    cognome_norm,
    nome_norm,
    full_norm,
    cf_norm,
    tel_norm,
    cell_norm,
    email_norm,
    paz_spec_norm,
    updated_at
)
SELECT DISTINCT
    a.id_dot,
    c.id_client,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS cognome_norm,
    LEFT(
        LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
        191
    ) AS nome_norm,
    LEFT(
        TRIM(CONCAT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            ' ',
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), '')))
        )),
        191
    ) AS full_norm,
    NULLIF(
        LEFT(
            REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ',
                ''
            ),
            32
        ),
        ''
    ) AS cf_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS tel_norm,
    NULLIF(
        LEFT(
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ', ''
            ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
            32
        ),
        ''
    ) AS cell_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS email_norm,
    NULLIF(
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ),
        ''
    ) AS paz_spec_norm,
    NOW()
FROM dap12_agenda_appuntamenti a
INNER JOIN dap02_clients c
    ON COALESCE(c.legacy_id_paziente, 0) = a.id_paziente
WHERE a.id_dot > 0
  AND a.stato <> 'ANNULLATO'
  AND COALESCE(a.id_client, 0) = 0
  AND COALESCE(c.legacy_id_paziente, 0) > 0;

INSERT IGNORE INTO dap26_doctor_patient_search (
    id_dot,
    id_client,
    cognome_norm,
    nome_norm,
    full_norm,
    cf_norm,
    tel_norm,
    cell_norm,
    email_norm,
    paz_spec_norm,
    updated_at
)
SELECT DISTINCT
    p.legacy_id_dot AS id_dot,
    s.id_client,
    s.cognome_norm,
    s.nome_norm,
    s.full_norm,
    s.cf_norm,
    s.tel_norm,
    s.cell_norm,
    s.email_norm,
    s.paz_spec_norm,
    NOW()
FROM dap03_personale p
INNER JOIN (
    SELECT
        c.id_client,
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ) AS cognome_norm,
        LEFT(
            LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
            191
        ) AS nome_norm,
        LEFT(
            TRIM(CONCAT(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                ' ',
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), '')))
            )),
            191
        ) AS full_norm,
        NULLIF(
            LEFT(
                REPLACE(
                    LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.codice_fiscale), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                    ' ',
                    ''
                ),
                32
            ),
            ''
        ) AS cf_norm,
        NULLIF(
            LEFT(
                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.telefono), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                    ' ', ''
                ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
                32
            ),
            ''
        ) AS tel_norm,
        NULLIF(
            LEFT(
                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cellulare), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                    ' ', ''
                ), '-', ''), '+', ''), '/', ''), '(', ''), ')', ''), '.', ''),
                32
            ),
            ''
        ) AS cell_norm,
        NULLIF(
            LEFT(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.email), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                191
            ),
            ''
        ) AS email_norm,
        NULLIF(
            LEFT(
                LOWER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))),
                191
            ),
            ''
        ) AS paz_spec_norm
    FROM dap02_clients c
    WHERE COALESCE(TRIM(CONVERT(CAST(AES_DECRYPT(UNHEX(c.paz_spec), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)), '') <> ''
       OR UPPER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))) IN ('DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT')
       OR UPPER(TRIM(COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''))) IN ('DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT')
       OR UPPER(TRIM(CONCAT(
            COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.cognome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), ''),
            ' ',
            COALESCE(CONVERT(CAST(AES_DECRYPT(UNHEX(c.nome), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4), '')
       ))) REGEXP '^(DDD|STOP|INFO|INF|URG|CER|DOT) '
) s
    ON 1 = 1
WHERE p.legacy_id_dot > 0;

ANALYZE TABLE dap26_doctor_patient_search;

SELECT COUNT(*) AS righe_totali
FROM dap26_doctor_patient_search;
