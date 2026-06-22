<?php

namespace App\Models;

use App\Libraries\DatabaseConfig;
use CodeIgniter\Model;
use DateInterval;
use DatePeriod;
use DateTime;
use Exception;

class AgendaSlotModel extends Model
{
    private const CONFIG_INSERT_BATCH_SIZE = 250;

    protected $table = 'dap11_agenda_slot';
    protected $primaryKey = 'id_slot';
    protected $allowedFields = [
        'id_dot',
        'id_config',
        'data_slot',
        'ora_inizio',
        'ora_fine',
        'tipo_slot',
        'stato',
        'titolo_libero',
        'id_amb_legacy',
        'id_stanza',
        'ambulatorio',
        'stanza',
        'note_interne',
        'updated_at'
    ];

    protected $db;
    private ?bool $hasAppointmentClientColumn = null;
    private ?bool $hasAppointmentCreatedByColumn = null;
    private ?bool $hasFasceTable = null;
    private ?bool $hasAmbulatoriTable = null;
    private ?bool $hasSlotRoomColumn = null;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
        // Ensure session-level encryption vars exist on this model-owned connection
        // before running AES_DECRYPT expressions in agenda calendar queries.
        (new DatabaseConfig())->setEncryptionConfig($this->db);
    }

public function getSlotsCalendario(int $idDot, string $date, string $view = 'day'): array
{
    $builder = $this->db->table('dap11_agenda_slot s');
    $hasAppointmentClientColumn = $this->appointmentTableHasClientColumn();
    $hasAppointmentCreatedByColumn = $this->appointmentTableHasCreatedByColumn();
    $hasAmbulatoriTable = $this->ambulatoriTableExists();
    $clientSelect = $hasAppointmentClientColumn
        ? 'a.id_client,'
        : 'NULL AS id_client,';
    $linkedClientSelect = $hasAppointmentClientColumn
        ? 'COALESCE(a.id_client, c_by_id.id_client, c_by_legacy.id_client) AS id_cliente_collegato,'
        : 'c_by_legacy.id_client AS id_cliente_collegato,';
    $createdByUsernameSelect = $hasAppointmentCreatedByColumn
        ? "COALESCE(u_created.username, '') AS created_by_username,"
        : "'' AS created_by_username,";
    $ambulatorioLabelSelect = $hasAmbulatoriTable
        ? "COALESCE(amb.nome, s.ambulatorio, '') AS ambulatorio_label,"
        : "COALESCE(s.ambulatorio, '') AS ambulatorio_label,";
    $pazSpecExpr = $this->buildPazSpecSelectSql($hasAppointmentClientColumn);

    // Keep MySQL session vars like @key_str untouched inside AES_DECRYPT.
    $builder->select("
    s.id_slot,
    s.id_dot,
    s.data_slot,
    s.ora_inizio,
    s.ora_fine,
    s.tipo_slot,
    s.origine_slot,
    s.stato,
    s.titolo_libero,
    s.id_amb_legacy,
    s.ambulatorio,
    {$ambulatorioLabelSelect}
    s.stanza,
    s.note_interne,
    TIMESTAMPDIFF(MINUTE, s.ora_inizio, s.ora_fine) AS durata_slot_minuti,

    a.id_appuntamento,
    a.id_paziente,
    {$clientSelect}
    {$linkedClientSelect}
    a.cognome,
    a.nome,
    a.telefono,
    a.cellulare,
    a.email,
    {$pazSpecExpr}
    a.note,
    {$createdByUsernameSelect}
    a.motivo_visita,
    a.indirizzo_visita,
    a.comune_visita,
    a.stato AS stato_appuntamento
", false);

    $builder->join(
        'dap12_agenda_appuntamenti a',
        'a.id_slot = s.id_slot AND a.stato <> "ANNULLATO"',
        'left'
    );

    if ($hasAppointmentClientColumn) {
        $builder->join(
            'dap02_clients c_by_id',
            'c_by_id.id_client = a.id_client',
            'left'
        );
        $builder->join(
            'dap02_clients c_by_legacy',
            'COALESCE(a.id_client, 0) = 0 AND c_by_legacy.legacy_id_paziente = a.id_paziente',
            'left'
        );
    } else {
        $builder->join(
            'dap02_clients c_by_legacy',
            'c_by_legacy.legacy_id_paziente = a.id_paziente',
            'left'
        );
    }

    if ($hasAppointmentCreatedByColumn) {
        $builder->join(
            'dap01_users u_created',
            'u_created.id_user = a.created_by',
            'left'
        );
    }

    if ($hasAmbulatoriTable) {
        $builder->join(
            'dap42_ambulatori amb',
            'amb.id_amb_legacy = s.id_amb_legacy',
            'left'
        );
    }

    $builder->where('s.id_dot', $idDot);
    $builder->where($this->buildConfiguredOrBookedSlotSql('s'), null, false);

    if ($view === 'week') {
        $start = new \DateTime($date);
        $weekday = (int)$start->format('N');
        $start->modify('-' . ($weekday - 1) . ' days');

        $end = clone $start;
        $end->modify('+6 days');

        $builder->where('s.data_slot >=', $start->format('Y-m-d'));
        $builder->where('s.data_slot <=', $end->format('Y-m-d'));
    } else {
        $builder->where('s.data_slot', $date);
    }

    $builder->orderBy('s.ora_inizio', 'ASC');

    return $builder->get()->getResultArray();
}

public function getAvailabilityDaysForRange(int $idDot, string $fromDate, string $toDate): array
{
    $builder = $this->db->table('dap11_agenda_slot s');

    $builder->select('s.data_slot, COUNT(*) AS slot_liberi', false);
    $builder->join(
        'dap12_agenda_appuntamenti a',
        'a.id_slot = s.id_slot AND a.stato <> "ANNULLATO"',
        'left'
    );
    $builder->join(
        'dap21_agenda_giorni_bloccati gb',
        'gb.id_dot = s.id_dot AND gb.data_agenda = s.data_slot',
        'left'
    );

    $builder->where('s.id_dot', $idDot);
    $builder->where('s.data_slot >=', $fromDate);
    $builder->where('s.data_slot <=', $toDate);
    $builder->where('s.stato', 'LIBERO');
    $builder->where('a.id_appuntamento IS NULL', null, false);
    $builder->where('gb.id_dot IS NULL', null, false);
    $builder->where($this->buildConfiguredOrBookedSlotSql('s'), null, false);
    $builder->groupBy('s.data_slot');
    $builder->orderBy('s.data_slot', 'ASC');

    $rows = $builder->get()->getResultArray();
    $days = [];

    foreach ($rows as $row) {
        $dataSlot = trim((string)($row['data_slot'] ?? ''));
        if ($dataSlot === '') {
            continue;
        }

        $days[] = [
            'data_slot'    => $dataSlot,
            'slot_liberi'  => (int)($row['slot_liberi'] ?? 0),
        ];
    }

    return $days;
}

    private function appointmentTableHasClientColumn(): bool
    {
        if ($this->hasAppointmentClientColumn === null) {
            $this->hasAppointmentClientColumn = $this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti');
        }

        return $this->hasAppointmentClientColumn;
    }

    private function appointmentTableHasCreatedByColumn(): bool
    {
        if ($this->hasAppointmentCreatedByColumn === null) {
            $this->hasAppointmentCreatedByColumn = $this->db->fieldExists('created_by', 'dap12_agenda_appuntamenti');
        }

        return $this->hasAppointmentCreatedByColumn;
    }

    private function configFasceTableExists(): bool
    {
        if ($this->hasFasceTable === null) {
            $this->hasFasceTable = $this->db->tableExists('dap10_agenda_config_fasce');
        }

        return $this->hasFasceTable;
    }

    private function ambulatoriTableExists(): bool
    {
        if ($this->hasAmbulatoriTable === null) {
            $this->hasAmbulatoriTable = $this->db->tableExists('dap42_ambulatori');
        }

        return $this->hasAmbulatoriTable;
    }

    private function buildPazSpecSelectSql(bool $hasAppointmentClientColumn): string
    {
        if (!$hasAppointmentClientColumn) {
            return "COALESCE(CAST(AES_DECRYPT(UNHEX(c_by_legacy.paz_spec), @key_str, c_by_legacy.vector_id) AS CHAR), '') AS paz_spec,";
        }

        return "COALESCE(
            CASE
                WHEN c_by_id.id_client IS NOT NULL THEN CAST(AES_DECRYPT(UNHEX(c_by_id.paz_spec), @key_str, c_by_id.vector_id) AS CHAR)
                ELSE NULL
            END,
            CASE
                WHEN c_by_legacy.id_client IS NOT NULL THEN CAST(AES_DECRYPT(UNHEX(c_by_legacy.paz_spec), @key_str, c_by_legacy.vector_id) AS CHAR)
                ELSE NULL
            END,
            ''
        ) AS paz_spec,";
    }
public function generateFromWeeklyConfig(array $config, int $idDot, ?callable $progressCallback = null): int
{
    $idConfig = (int)($config['id_config'] ?? 0);
    $dataInizio = $config['data_inizio'];
    $dataFine   = $config['data_fine'];
    $giorniMap  = [];

    foreach (($config['giorni'] ?? []) as $g) {
        $giorniMap[(int)$g['giorno_settimana']] = $g;
    }

    $inizio = new \DateTime($dataInizio);
    $fine   = new \DateTime($dataFine);
    $fine->modify('+1 day');

    $period = new \DatePeriod($inizio, new \DateInterval('P1D'), $fine);
    $totalAgendaDays = 0;

    foreach ($period as $day) {
        $weekday = (int)$day->format('N');
        $cfg = $giorniMap[$weekday] ?? null;

        if (!$cfg || !empty($cfg['giorno_libero']) || empty($cfg['fasce'])) {
            continue;
        }

        $totalAgendaDays++;
    }

    $period = new \DatePeriod($inizio, new \DateInterval('P1D'), $fine);

    $inserted = 0;
    $processedAgendaDays = 0;

    foreach ($period as $day) {
        $weekday = (int)$day->format('N');

        if (!isset($giorniMap[$weekday])) {
            continue;
        }

        $cfg = $giorniMap[$weekday];

        if (!empty($cfg['giorno_libero'])) {
            continue;
        }

        foreach (($cfg['fasce'] ?? []) as $fascia) {
            $inserted += $this->generaFascia(
                $idDot,
                $idConfig,
                $day->format('Y-m-d'),
                (string)($fascia['ora_inizio'] ?? ''),
                (string)($fascia['ora_fine'] ?? ''),
                (int)($fascia['durata_slot'] ?? 0),
                'AMBULATORIO',
                $fascia
            );
        }

        $processedAgendaDays++;
        if ($progressCallback !== null) {
            $progressCallback($processedAgendaDays, $totalAgendaDays, $day->format('Y-m-d'));
        }
    }

    return $inserted;
    }
    private function slotTableHasRoomColumn(): bool
    {
        if ($this->hasSlotRoomColumn === null) {
            $this->hasSlotRoomColumn = $this->db->fieldExists('id_stanza', 'dap11_agenda_slot');
        }

        return $this->hasSlotRoomColumn;
    }
protected function generaFascia(
    int $idDot,
    int $idConfig,
    string $dataSlot,
    string $oraInizio,
    string $oraFine,
    int $durataMinuti,
    string $tipoSlot = 'AMBULATORIO',
    array $meta = []
): int {
    $inserted = 0;
    $batch = [];

    if (!$oraInizio || !$oraFine || $durataMinuti <= 0) {
        return 0;
    }

    $idAmbLegacy = !empty($meta['id_amb_legacy']) ? (int)$meta['id_amb_legacy'] : null;
    $idStanza = !empty($meta['id_stanza']) ? (int)$meta['id_stanza'] : null;
    $ambulatorio = trim((string)($meta['ambulatorio'] ?? ''));
    $stanza = trim((string)($meta['stanza'] ?? ''));

    $cursor = new \DateTime($dataSlot . ' ' . $oraInizio);
    $limit  = new \DateTime($dataSlot . ' ' . $oraFine);

    while ($cursor < $limit) {
        $slotStart = clone $cursor;
        $slotEnd   = (clone $cursor)->modify('+' . $durataMinuti . ' minutes');

        if ($slotEnd > $limit) {
            break;
        }

        $batch[] = [
            'id_dot'       => $idDot,
            'id_config'    => $idConfig > 0 ? $idConfig : null,
            'data_slot'    => $dataSlot,
            'ora_inizio'   => $slotStart->format('Y-m-d H:i:s'),
            'ora_fine'     => $slotEnd->format('Y-m-d H:i:s'),
            'tipo_slot'    => $tipoSlot,
            'stato'        => 'LIBERO',
            'origine_slot' => 'CONFIG',
            'id_amb_legacy'=> $idAmbLegacy,
            'ambulatorio'  => $ambulatorio,
            'stanza'       => $stanza,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        if ($this->slotTableHasRoomColumn()) {
            $batch[count($batch) - 1]['id_stanza'] = $idStanza;
        }

        if (count($batch) >= self::CONFIG_INSERT_BATCH_SIZE) {
            $this->db->table('dap11_agenda_slot')->insertBatch($batch);
            $inserted += count($batch);
            $batch = [];
        }

        $cursor->modify('+' . $durataMinuti . ' minutes');
    }

    if (!empty($batch)) {
        $this->db->table('dap11_agenda_slot')->insertBatch($batch);
        $inserted += count($batch);
    }

    return $inserted;
}
    protected function buildConfiguredDayExistsSql(string $slotTableAlias): string
    {
        $hasFasceTable = $this->configFasceTableExists();
        $fasceCondition = $hasFasceTable
            ? '(EXISTS (SELECT 1 FROM dap10_agenda_config_fasce cf WHERE cf.id_config_giorno = cg.id_config_giorno) OR cg.mattina_attiva = 1 OR cg.pomeriggio_attiva = 1)'
            : '(cg.mattina_attiva = 1 OR cg.pomeriggio_attiva = 1)';

        return "EXISTS (
            SELECT 1
            FROM dap10_agenda_config c
            INNER JOIN dap10_agenda_config_giorni cg
                ON cg.id_config = c.id_config
            WHERE c.id_dot = {$slotTableAlias}.id_dot
              AND c.data_inizio <= {$slotTableAlias}.data_slot
              AND (c.data_fine IS NULL OR c.data_fine >= {$slotTableAlias}.data_slot)
              AND cg.giorno_settimana = ((DAYOFWEEK({$slotTableAlias}.data_slot) + 5) % 7) + 1
              AND c.id_config = (
                  SELECT MAX(c2.id_config)
                  FROM dap10_agenda_config c2
                  WHERE c2.id_dot = {$slotTableAlias}.id_dot
                    AND c2.data_inizio <= {$slotTableAlias}.data_slot
                    AND (c2.data_fine IS NULL OR c2.data_fine >= {$slotTableAlias}.data_slot)
              )
              AND cg.giorno_libero = 0
              AND {$fasceCondition}
        )";
    }

    protected function buildConfiguredOrBookedSlotSql(string $slotTableAlias): string
    {
        $configuredSql = $this->buildConfiguredDayExistsSql($slotTableAlias);

        return "(
            {$configuredSql}
            OR UPPER(COALESCE({$slotTableAlias}.origine_slot, '')) = 'EXTRA'
            OR EXISTS (
                SELECT 1
                FROM dap12_agenda_appuntamenti a_vis
                WHERE a_vis.id_slot = {$slotTableAlias}.id_slot
                  AND a_vis.stato <> 'ANNULLATO'
            )
        )";
    }

    public function getDomiciliari(int $idDot, string $date): array
    {
        return $this->db->table('dap11_agenda_slot s')
            ->select("
                s.*,
                a.id_appuntamento,
                a.cognome,
                a.nome,
                a.indirizzo_visita,
                a.comune_visita,
                a.motivo_visita,
                a.note
            ")
            ->join(
                'dap12_agenda_appuntamenti a',
                'a.id_slot = s.id_slot AND a.stato <> "ANNULLATO"',
                'left'
            )
            ->where('s.id_dot', $idDot)
            ->where('s.data_slot', $date)
            ->where('s.tipo_slot', 'DOMICILIARE')
            ->orderBy('s.ora_inizio', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function generateFromConfig(array $payload): int
    {
        $idDot    = (int)($payload['id_dot'] ?? 0);
        $dStart   = (string)($payload['data_inizio'] ?? '');
        $dEnd     = (string)($payload['data_fine'] ?? '');
        $tStart   = (string)($payload['ora_inizio'] ?? '08:00');
        $tEnd     = (string)($payload['ora_fine'] ?? '14:00');
        $duration = (int)($payload['durata_slot_minuti'] ?? 15);
        $weekdays = $payload['giorni_settimana'] ?? '1,2,3,4,5';
        $tipoSlot = $payload['tipo_slot'] ?? 'AMBULATORIO';

        if (!$idDot || !$dStart || !$dEnd) {
            throw new Exception('Parametri configurazione mancanti.');
        }

        $validDays = array_map('intval', array_filter(explode(',', (string)$weekdays)));

        $period = new DatePeriod(
            new DateTime($dStart),
            new DateInterval('P1D'),
            (new DateTime($dEnd))->modify('+1 day')
        );

        $inserted = 0;

        foreach ($period as $day) {
            if (!in_array((int)$day->format('N'), $validDays, true)) {
                continue;
            }

            $cursor = new DateTime($day->format('Y-m-d') . ' ' . $tStart);
            $limit  = new DateTime($day->format('Y-m-d') . ' ' . $tEnd);

            while ($cursor < $limit) {
                $slotStart = clone $cursor;
                $slotEnd   = (clone $cursor)->modify('+' . $duration . ' minutes');

                if ($slotEnd > $limit) {
                    break;
                }

                $exists = $this->db->table('dap11_agenda_slot')
                    ->where('id_dot', $idDot)
                    ->where('ora_inizio', $slotStart->format('Y-m-d H:i:s'))
                    ->where('ora_fine', $slotEnd->format('Y-m-d H:i:s'))
                    ->where('tipo_slot', $tipoSlot)
                    ->countAllResults();

                if ((int)$exists === 0) {
                    $this->db->table('dap11_agenda_slot')->insert([
                        'id_dot'      => $idDot,
                        'data_slot'   => $day->format('Y-m-d'),
                        'ora_inizio'  => $slotStart->format('Y-m-d H:i:s'),
                        'ora_fine'    => $slotEnd->format('Y-m-d H:i:s'),
                        'tipo_slot'   => $tipoSlot,
                        'stato'       => 'LIBERO',
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);

                    $inserted++;
                }

                $cursor->modify('+' . $duration . ' minutes');
            }
        }

        return $inserted;
    }

    public function setSlotState(int $idSlot, string $state): bool
    {
        return (bool)$this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->update([
                'stato'      => $state,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
