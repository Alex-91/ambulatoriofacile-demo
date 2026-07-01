<?php

namespace App\Models;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use CodeIgniter\Model;
use Exception;

class AgendaConfigModel extends Model
{
    protected $db;
    protected Crypto_helper $crypto;
    protected $table = 'dap10_agenda_config';
    protected $primaryKey = 'id_config';
    protected ?bool $fasceTableExists = null;
    protected AgendaLocationModel $locationModel;

    private function normalizeLegacyString($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
        (new DatabaseConfig())->setEncryptionConfig($this->db);
        $this->crypto = new Crypto_helper();
        $this->locationModel = new AgendaLocationModel();
    }

    public function getMediciVisibili(int $idUser): array
    {
        $nomeExpr = 'CAST(' . $this->crypto->decrypt_concat('p.nome') . ' AS CHAR)';
        $cognomeExpr = 'CAST(' . $this->crypto->decrypt_concat('p.cognome') . ' AS CHAR)';

        $rows = $this->db->table('dap03_personale p')
            ->select("
                COALESCE(p.legacy_id_dot, 0) AS id_dot,
                {$nomeExpr} AS nome,
                {$cognomeExpr} AS cognome,
                COALESCE(p.f_dom, 0) AS f_dom,
                CONCAT(TRIM({$cognomeExpr}), ' ', TRIM({$nomeExpr})) AS label
            ", false)
            ->whereIn('p.tipo', [1, 2])
            ->where('p.legacy_id_dot >', 0)
            ->orderBy($cognomeExpr, '', false)
            ->orderBy($nomeExpr, '', false)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['nome'] = $this->normalizeLegacyString($row['nome'] ?? null);
            $row['cognome'] = $this->normalizeLegacyString($row['cognome'] ?? null);
            $row['label'] = $this->normalizeLegacyString($row['label'] ?? null);
        }
        unset($row);

        return $rows;
    }

    public function getUltimaConfigByDoctor(int $idDot): ?array
    {
        $config = $this->db->table('dap10_agenda_config')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->orderBy('id_config', 'DESC')
            ->get()
            ->getRowArray();

        if (!$config) {
            return null;
        }

        $config['giorni'] = $this->loadConfigDays((int)$config['id_config']);

        return $config;
    }
    public function saveConfig(array $payload, int $userId): int
    {
        $idDot = (int)($payload['id_dot'] ?? 0);
        $dataInizio = trim((string)($payload['data_inizio'] ?? ''));
        $dataFine = trim((string)($payload['data_fine'] ?? ''));
        $descrizione = trim((string)($payload['descrizione'] ?? ''));

        if ($idDot <= 0) {
            throw new Exception('Seleziona un dottore.');
        }

        if ($dataInizio === '') {
            throw new Exception('La data inizio e obbligatoria.');
        }

        if ($dataFine === '') {
            $dataFine = '2039-12-31';
        }

        if ($dataFine < $dataInizio) {
            throw new Exception('La data fine non puo essere precedente alla data inizio.');
        }

        $giorni = $this->normalizeGiorniPayload($payload['giorni'] ?? []);
        $this->validaGiorni($giorni);

        $now = date('Y-m-d H:i:s');

        $this->db->transStart();

        $this->db->table('dap10_agenda_config')
            ->where('id_dot', $idDot)
            ->update([
                'attiva'     => 0,
                'updated_at' => $now,
            ]);

        $this->db->table('dap10_agenda_config')->insert([
            'id_dot'      => $idDot,
            'data_inizio' => $dataInizio,
            'data_fine'   => $dataFine,
            'descrizione' => $descrizione,
            'attiva'      => 1,
            'created_by'  => $userId,
            'created_at'  => $now,
        ]);

        $idConfig = (int)$this->db->insertID();

        foreach ($giorni as $giorno => $row) {
            $legacy = $this->buildLegacyDayColumns($row);

            $this->db->table('dap10_agenda_config_giorni')->insert([
                'id_config'                  => $idConfig,
                'giorno_settimana'           => (int)$giorno,
                'giorno_libero'              => !empty($row['giorno_libero']) ? 1 : 0,
                'mattina_attiva'             => $legacy['mattina_attiva'],
                'mattina_ora_inizio'         => $legacy['mattina_ora_inizio'],
                'mattina_ora_fine'           => $legacy['mattina_ora_fine'],
                'mattina_durata_slot'        => $legacy['mattina_durata_slot'],
                'pomeriggio_attiva'          => $legacy['pomeriggio_attiva'],
                'pomeriggio_modalita_inizio' => $legacy['pomeriggio_modalita_inizio'],
                'pomeriggio_ora_inizio'      => $legacy['pomeriggio_ora_inizio'],
                'pomeriggio_ora_fine'        => $legacy['pomeriggio_ora_fine'],
                'pomeriggio_durata_slot'     => $legacy['pomeriggio_durata_slot'],
                'created_at'                 => $now,
            ]);

            $idConfigGiorno = (int)$this->db->insertID();

            if ($this->hasFasceTable()) {
                foreach (($row['fasce'] ?? []) as $index => $fascia) {
                    $location = $this->locationModel->resolveSelection($fascia);
                    $insert = [
                        'id_config_giorno' => $idConfigGiorno,
                        'ordine'           => $index + 1,
                        'ora_inizio'       => $fascia['ora_inizio'],
                        'ora_fine'         => $fascia['ora_fine'],
                        'durata_slot'      => (int)$fascia['durata_slot'],
                        'id_amb_legacy'    => $location['id_amb_legacy'],
                        'ambulatorio'      => $location['ambulatorio'],
                        'stanza'           => $location['stanza'],
                        'created_at'       => $now,
                    ];

                    if ($this->locationModel->configFasceHaveRoomColumn()) {
                        $insert['id_stanza'] = $location['id_stanza'];
                    }

                    $this->db->table('dap10_agenda_config_fasce')->insert($insert);
                }
            }
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante il salvataggio della configurazione.');
        }

        return $idConfig;
    }

    public function getConfigCompleta(int $idConfig): array
    {
        $config = $this->db->table('dap10_agenda_config')
            ->where('id_config', $idConfig)
            ->get()
            ->getRowArray();

        if (!$config) {
            throw new Exception('Configurazione non trovata.');
        }

        $config['giorni'] = $this->loadConfigDays($idConfig);

        return $config;
    }

    public function getNoAgendaMessageForDate(int $idDot, string $data): string
    {
        $config = $this->db->table('dap10_agenda_config')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('data_inizio <=', $data)
            ->where('data_fine >=', $data)
            ->orderBy('id_config', 'DESC')
            ->get()
            ->getRowArray();

        if (!$config) {
            $nextConfig = $this->db->table('dap10_agenda_config')
                ->select('data_inizio')
                ->where('id_dot', $idDot)
                ->where('attiva', 1)
                ->where('data_inizio >', $data)
                ->orderBy('data_inizio', 'ASC')
                ->get()
                ->getRowArray();

            if ($nextConfig && !empty($nextConfig['data_inizio'])) {
                return 'Nessuna agenda impostata per questo giorno. La prossima configurazione parte dal ' .
                    $this->formatDataItaliana((string)$nextConfig['data_inizio']) . '.';
            }

            $lastConfig = $this->db->table('dap10_agenda_config')
                ->select('data_fine')
                ->where('id_dot', $idDot)
                ->where('attiva', 1)
                ->where('data_fine <', $data)
                ->orderBy('data_fine', 'DESC')
                ->get()
                ->getRowArray();

            if ($lastConfig && !empty($lastConfig['data_fine'])) {
                return 'Nessuna agenda impostata per questo giorno. L\'ultima configurazione terminava il ' .
                    $this->formatDataItaliana((string)$lastConfig['data_fine']) . '.';
            }

            return 'Nessuna agenda impostata per questo giorno.';
        }

        $giorno = $this->getDayRow((int)$config['id_config'], (int)date('N', strtotime($data)));
        if (!$giorno) {
            return 'Nessuna agenda impostata per questo giorno.';
        }

        if ((int)($giorno['giorno_libero'] ?? 0) === 1) {
            return 'Il giorno selezionato è impostato come libero.';
        }

        $fasce = $this->getFasceForDayRow($giorno);
        if (empty($fasce)) {
            return 'Il giorno selezionato non ha fasce attive nella configurazione.';
        }

        return 'La configurazione esiste, ma l\'agenda non e stata ancora generata per questo giorno.';
    }

    public function getNoAgendaMessageForRange(int $idDot, string $dataInizio, string $dataFine): string
    {
        $hasConfig = $this->db->table('dap10_agenda_config')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('data_inizio <=', $dataFine)
            ->where('data_fine >=', $dataInizio)
            ->countAllResults() > 0;

        if ($hasConfig) {
            return 'Nel periodo selezionato non risultano slot generati in agenda.';
        }

        $nextConfig = $this->db->table('dap10_agenda_config')
            ->select('data_inizio')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('data_inizio >', $dataFine)
            ->orderBy('data_inizio', 'ASC')
            ->get()
            ->getRowArray();

        if ($nextConfig && !empty($nextConfig['data_inizio'])) {
            return 'Nessuna agenda impostata nel periodo selezionato. La prossima configurazione parte dal ' .
                $this->formatDataItaliana((string)$nextConfig['data_inizio']) . '.';
        }

        return 'Nessuna agenda impostata nel periodo selezionato.';
    }

    public function resolveSlotConfigByDoctorDateTime(int $idDot, string $data, string $oraInizio): array
    {
        $config = $this->db->table('dap10_agenda_config')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('data_inizio <=', $data)
            ->where('data_fine >=', $data)
            ->orderBy('id_config', 'DESC')
            ->get()
            ->getRowArray();

        if (!$config) {
            return [
                'status'    => false,
                'message'   => 'Configurazione agenda non trovata per il giorno selezionato.',
                'durata'    => 0,
                'id_config' => null,
            ];
        }

        $giorno = $this->getDayRow((int)$config['id_config'], (int)date('N', strtotime($data)));
        if (!$giorno) {
            return [
                'status'    => false,
                'message'   => 'Configurazione del giorno non trovata.',
                'durata'    => 0,
                'id_config' => (int)$config['id_config'],
            ];
        }

        if ((int)($giorno['giorno_libero'] ?? 0) === 1) {
            return [
                'status'           => false,
                'message'          => 'Il giorno e configurato come libero.',
                'durata'           => 0,
                'id_config'        => (int)$config['id_config'],
                'id_config_giorno' => (int)($giorno['id_config_giorno'] ?? 0),
            ];
        }

        $ora = $this->normalizeTimeValue($oraInizio);
        if ($ora === '') {
            return [
                'status'           => false,
                'message'          => 'Orario non valido.',
                'durata'           => 0,
                'id_config'        => (int)$config['id_config'],
                'id_config_giorno' => (int)($giorno['id_config_giorno'] ?? 0),
            ];
        }

        foreach ($this->getFasceForDayRow($giorno) as $fascia) {
            if ($ora >= $fascia['ora_inizio'] && $ora < $fascia['ora_fine']) {
                $durata = (int)($fascia['durata_slot'] ?? 0);

                return [
                    'status'            => $durata > 0,
                    'message'           => $durata > 0 ? '' : 'Durata slot non valida.',
                    'durata'            => $durata,
                    'id_config'         => (int)$config['id_config'],
                    'id_config_giorno'  => (int)($giorno['id_config_giorno'] ?? 0),
                    'id_config_fascia'  => (int)($fascia['id_config_fascia'] ?? 0),
                    'fascia'            => $fascia,
                ];
            }
        }

        return [
            'status'           => false,
            'message'          => 'L\'orario non rientra nelle fasce configurate per questo giorno.',
            'durata'           => 0,
            'id_config'        => (int)$config['id_config'],
            'id_config_giorno' => (int)($giorno['id_config_giorno'] ?? 0),
        ];
    }

    public function hasFasceTable(): bool
    {
        if ($this->fasceTableExists === null) {
            $this->fasceTableExists = $this->db->tableExists('dap10_agenda_config_fasce');
        }

        return $this->fasceTableExists;
    }

    protected function formatDataItaliana(string $data): string
    {
        $timestamp = strtotime($data);
        if (!$timestamp) {
            return $data;
        }

        return date('d/m/Y', $timestamp);
    }

    protected function validaGiorni(array $giorni): void
    {
        foreach ($giorni as $num => $row) {
            if (!empty($row['giorno_libero'])) {
                continue;
            }

            $fasce = $row['fasce'] ?? [];
            if (empty($fasce)) {
                throw new Exception("Il giorno {$num} non e libero ma non ha fasce configurate.");
            }

            $lastOraFine = null;

            foreach ($fasce as $fascia) {
                $oraInizio = $fascia['ora_inizio'] ?? '';
                $oraFine = $fascia['ora_fine'] ?? '';
                $durataSlot = (int)($fascia['durata_slot'] ?? 0);

                if ($oraInizio === '' || $oraFine === '' || $durataSlot <= 0) {
                    throw new Exception("Compila tutti i campi della fascia per il giorno {$num}.");
                }

                if ($oraFine <= $oraInizio) {
                    throw new Exception("L'orario fine deve essere successivo all'inizio per il giorno {$num}.");
                }

                $minutiDisponibili = (int)((strtotime('1970-01-01 ' . $oraFine) - strtotime('1970-01-01 ' . $oraInizio)) / 60);
                if ($minutiDisponibili < $durataSlot) {
                    throw new Exception("La durata slot supera l'intervallo configurato per il giorno {$num}.");
                }

                if ($lastOraFine !== null && $oraInizio < $lastOraFine) {
                    throw new Exception("Le fasce del giorno {$num} si sovrappongono.");
                }

                $lastOraFine = $oraFine;
            }
        }
    }

    protected function loadConfigDays(int $idConfig): array
    {
        $giorni = $this->db->table('dap10_agenda_config_giorni')
            ->where('id_config', $idConfig)
            ->orderBy('giorno_settimana', 'ASC')
            ->get()
            ->getResultArray();

        if (empty($giorni)) {
            return [];
        }

        $fasceMap = $this->loadFasceMapForDays(array_column($giorni, 'id_config_giorno'));

        foreach ($giorni as &$giorno) {
            $idConfigGiorno = (int)($giorno['id_config_giorno'] ?? 0);
            $giorno['fasce'] = $fasceMap[$idConfigGiorno] ?? $this->buildLegacyFasceFromDayRow($giorno);
        }
        unset($giorno);

        return $giorni;
    }

    protected function loadFasceMapForDays(array $dayIds): array
    {
        $map = [];

        if (!$this->hasFasceTable()) {
            return $map;
        }

        $dayIds = array_values(array_filter(array_map('intval', $dayIds)));
        if (empty($dayIds)) {
            return $map;
        }

        $rows = $this->db->table('dap10_agenda_config_fasce')
            ->whereIn('id_config_giorno', $dayIds)
            ->orderBy('id_config_giorno', 'ASC')
            ->orderBy('ordine', 'ASC')
            ->orderBy('ora_inizio', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $row['ora_inizio'] = $this->normalizeTimeValue($row['ora_inizio'] ?? '');
            $row['ora_fine'] = $this->normalizeTimeValue($row['ora_fine'] ?? '');
            $row['durata_slot'] = (int)($row['durata_slot'] ?? 0);
            $row['id_amb_legacy'] = (int)($row['id_amb_legacy'] ?? 0);
            $row['id_stanza'] = (int)($row['id_stanza'] ?? 0);
            $row['ambulatorio'] = trim((string)($row['ambulatorio'] ?? ''));
            $row['stanza'] = trim((string)($row['stanza'] ?? ''));
            $map[(int)$row['id_config_giorno']][] = $row;
        }

        return $map;
    }

    protected function getDayRow(int $idConfig, int $giornoSettimana): ?array
    {
        $giorno = $this->db->table('dap10_agenda_config_giorni')
            ->where('id_config', $idConfig)
            ->where('giorno_settimana', $giornoSettimana)
            ->get()
            ->getRowArray();

        if (!$giorno) {
            return null;
        }

        $giorno['fasce'] = $this->getFasceForDayRow($giorno);

        return $giorno;
    }

    protected function getFasceForDayRow(array $giorno): array
    {
        $idConfigGiorno = (int)($giorno['id_config_giorno'] ?? 0);

        if ($this->hasFasceTable() && $idConfigGiorno > 0) {
            $rows = $this->db->table('dap10_agenda_config_fasce')
                ->where('id_config_giorno', $idConfigGiorno)
                ->orderBy('ordine', 'ASC')
                ->orderBy('ora_inizio', 'ASC')
                ->get()
                ->getResultArray();

            if (!empty($rows)) {
                foreach ($rows as &$row) {
                    $row['ora_inizio'] = $this->normalizeTimeValue($row['ora_inizio'] ?? '');
                    $row['ora_fine'] = $this->normalizeTimeValue($row['ora_fine'] ?? '');
                    $row['durata_slot'] = (int)($row['durata_slot'] ?? 0);
                    $row['id_amb_legacy'] = (int)($row['id_amb_legacy'] ?? 0);
                    $row['id_stanza'] = (int)($row['id_stanza'] ?? 0);
                    $row['ambulatorio'] = trim((string)($row['ambulatorio'] ?? ''));
                    $row['stanza'] = trim((string)($row['stanza'] ?? ''));
                }
                unset($row);

                return $rows;
            }
        }

        return $this->buildLegacyFasceFromDayRow($giorno);
    }

    protected function buildLegacyFasceFromDayRow(array $row): array
    {
        $fasce = [];

        $mattinaInizio = $this->normalizeTimeValue($row['mattina_ora_inizio'] ?? '');
        $mattinaFine = $this->normalizeTimeValue($row['mattina_ora_fine'] ?? '');
        $mattinaDurata = (int)($row['mattina_durata_slot'] ?? 0);

        if ((int)($row['mattina_attiva'] ?? 0) === 1 && $mattinaInizio !== '' && $mattinaFine !== '' && $mattinaDurata > 0) {
            $fasce[] = [
                'ora_inizio'  => $mattinaInizio,
                'ora_fine'    => $mattinaFine,
                'durata_slot' => $mattinaDurata,
                'id_amb_legacy' => 0,
                'id_stanza' => 0,
                'ambulatorio' => '',
                'stanza' => '',
            ];
        }

        $pomeriggioInizio = $this->resolveLegacyAfternoonStart($row);
        $pomeriggioFine = $this->normalizeTimeValue($row['pomeriggio_ora_fine'] ?? '');
        $pomeriggioDurata = (int)($row['pomeriggio_durata_slot'] ?? 0);

        if ((int)($row['pomeriggio_attiva'] ?? 0) === 1 && $pomeriggioInizio !== '' && $pomeriggioFine !== '' && $pomeriggioDurata > 0) {
            $fasce[] = [
                'ora_inizio'  => $pomeriggioInizio,
                'ora_fine'    => $pomeriggioFine,
                'durata_slot' => $pomeriggioDurata,
                'id_amb_legacy' => 0,
                'id_stanza' => 0,
                'ambulatorio' => '',
                'stanza' => '',
            ];
        }

        usort($fasce, static function (array $left, array $right): int {
            return strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
        });

        return $fasce;
    }

    protected function resolveLegacyAfternoonStart(array $row): string
    {
        $mode = (string)($row['pomeriggio_modalita_inizio'] ?? 'FINE_MATTINA');

        if ($mode === 'ORE_14') {
            return '14:00:00';
        }

        if ($mode === 'MANUALE') {
            return $this->normalizeTimeValue($row['pomeriggio_ora_inizio'] ?? '');
        }

        return $this->normalizeTimeValue($row['mattina_ora_fine'] ?? '');
    }

    protected function normalizeGiorniPayload($giorni): array
    {
        if (!is_array($giorni) || empty($giorni)) {
            throw new Exception('Configurazione giorni non valida.');
        }

        $normalized = [];

        for ($day = 1; $day <= 7; $day++) {
            $hasRow = array_key_exists($day, $giorni);
            $row = $giorni[$day] ?? [];
            $row = is_array($row) ? $row : [];

            $normalized[$day] = [
                'giorno_libero' => !$hasRow || !empty($row['giorno_libero']) ? 1 : 0,
                'fasce'         => $this->normalizeFascePayload($row),
            ];
        }

        return $normalized;
    }

    protected function normalizeFascePayload(array $row): array
    {
        $fasce = $row['fasce'] ?? null;
        if (!is_array($fasce) || empty($fasce)) {
            $fasce = $this->buildLegacyFasceFromPayload($row);
        }

        $normalized = [];

        foreach ($fasce as $fascia) {
            if (!is_array($fascia)) {
                continue;
            }

            $oraInizio = $this->normalizeTimeValue($fascia['ora_inizio'] ?? '');
            $oraFine = $this->normalizeTimeValue($fascia['ora_fine'] ?? '');
            $durataRaw = trim((string)($fascia['durata_slot'] ?? ''));

            if ($oraInizio === '' && $oraFine === '' && $durataRaw === '') {
                continue;
            }

            $normalized[] = [
                'ora_inizio'  => $oraInizio,
                'ora_fine'    => $oraFine,
                'durata_slot' => $durataRaw === '' ? 0 : (int)$durataRaw,
                'id_amb_legacy' => !empty($fascia['id_amb_legacy']) ? (int)$fascia['id_amb_legacy'] : 0,
                'id_stanza' => !empty($fascia['id_stanza']) ? (int)$fascia['id_stanza'] : 0,
                'ambulatorio' => trim((string)($fascia['ambulatorio'] ?? '')),
                'stanza' => trim((string)($fascia['stanza'] ?? '')),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
        });

        return array_values($normalized);
    }

    protected function buildLegacyFasceFromPayload(array $row): array
    {
        $legacyRow = [
            'mattina_attiva'             => !empty($row['mattina_attiva']) ? 1 : 0,
            'mattina_ora_inizio'         => $row['mattina_ora_inizio'] ?? '',
            'mattina_ora_fine'           => $row['mattina_ora_fine'] ?? '',
            'mattina_durata_slot'        => $row['mattina_durata_slot'] ?? '',
            'pomeriggio_attiva'          => !empty($row['pomeriggio_attiva']) ? 1 : 0,
            'pomeriggio_modalita_inizio' => $row['pomeriggio_modalita_inizio'] ?? 'FINE_MATTINA',
            'pomeriggio_ora_inizio'      => $row['pomeriggio_ora_inizio'] ?? '',
            'pomeriggio_ora_fine'        => $row['pomeriggio_ora_fine'] ?? '',
            'pomeriggio_durata_slot'     => $row['pomeriggio_durata_slot'] ?? '',
        ];

        return $this->buildLegacyFasceFromDayRow($legacyRow);
    }

    protected function buildLegacyDayColumns(array $row): array
    {
        $fasce = array_values($row['fasce'] ?? []);
        $prima = $fasce[0] ?? null;
        $seconda = $fasce[1] ?? null;

        return [
            'mattina_attiva'             => $prima ? 1 : 0,
            'mattina_ora_inizio'         => $prima['ora_inizio'] ?? null,
            'mattina_ora_fine'           => $prima['ora_fine'] ?? null,
            'mattina_durata_slot'        => $prima ? (int)$prima['durata_slot'] : null,
            'pomeriggio_attiva'          => $seconda ? 1 : 0,
            'pomeriggio_modalita_inizio' => $seconda ? 'MANUALE' : 'FINE_MATTINA',
            'pomeriggio_ora_inizio'      => $seconda['ora_inizio'] ?? null,
            'pomeriggio_ora_fine'        => $seconda['ora_fine'] ?? null,
            'pomeriggio_durata_slot'     => $seconda ? (int)$seconda['durata_slot'] : null,
        ];
    }

    protected function normalizeTimeValue($value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        foreach (['H:i:s', 'H:i'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('H:i:s');
            }
        }

        return $value;
    }
}
