<?php

namespace App\Services;

use App\Models\AgendaModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class SmsReminderDashboardService
{
    private BaseConnection $db;
    private AgendaModel $agendaModel;
    private string $stateDir;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->agendaModel = new AgendaModel();
        $this->stateDir = WRITEPATH . 'reminder_state' . DIRECTORY_SEPARATOR;
    }

    /**
     * @param array<int, int|string> $allowedDoctorIds
     * @return array<string, mixed>
     */
    public function buildDashboard(array $allowedDoctorIds, int $selectedDot = 0, int $recentDays = 30, int $recentLimit = 40): array
    {
        $doctorIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => (int) $value,
            $allowedDoctorIds
        ), static fn(int $id): bool => $id > 0)));

        sort($doctorIds);

        $selectedDot = in_array($selectedDot, $doctorIds, true) ? $selectedDot : 0;

        $entries = $this->loadSentEntries();
        if ($entries === []) {
            return [
                'available' => is_dir($this->stateDir),
                'provider' => $this->buildProviderSummary(),
                'space' => $this->emptyScopeSummary(),
                'selected' => $this->emptyScopeSummary(),
                'recent_rows' => [],
                'selected_dot' => $selectedDot,
            ];
        }

        $appointmentMap = $this->getAppointmentMap(array_map(
            static fn(array $entry): int => (int) ($entry['appointment_id'] ?? 0),
            $entries
        ));

        $doctorMap = $this->agendaModel->getAgendaProfessionalMapByLegacyIds(array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_dot'] ?? 0),
            $appointmentMap
        )))));

        $normalizedEntries = [];
        foreach ($entries as $entry) {
            $appointmentId = (int) ($entry['appointment_id'] ?? 0);
            if ($appointmentId <= 0) {
                continue;
            }

            $appointment = $appointmentMap[$appointmentId] ?? null;
            $idDot = (int) ($appointment['id_dot'] ?? 0);
            if ($idDot <= 0 || !in_array($idDot, $doctorIds, true)) {
                continue;
            }

            $doctor = $doctorMap[$idDot] ?? [];
            $normalizedEntries[] = [
                'appointment_id' => $appointmentId,
                'id_dot' => $idDot,
                'doctor_label' => trim((string) ($doctor['label'] ?? ('Dottore #' . $idDot))),
                'patient_label' => $this->buildPatientLabel($appointment),
                'recipient' => trim((string) ($entry['recipient'] ?? '')),
                'sent_at' => trim((string) ($entry['sent_at'] ?? '')),
                'sent_day' => $this->extractDayKey((string) ($entry['sent_at'] ?? '')),
                'target_date' => trim((string) (($appointment['data_slot'] ?? '') ?: ($entry['target_date'] ?? ''))),
                'target_time' => trim((string) ($appointment['time_label'] ?? '')),
                'provider_id' => trim((string) ($entry['provider_id'] ?? '')),
                'state_file' => trim((string) ($entry['state_file'] ?? '')),
            ];
        }

        usort($normalizedEntries, static function (array $left, array $right): int {
            return strcmp((string) ($right['sent_at'] ?? ''), (string) ($left['sent_at'] ?? ''));
        });

        $spaceSummary = $this->buildScopeSummary($normalizedEntries, $doctorIds, $recentDays);
        $selectedSummary = $selectedDot > 0
            ? $this->buildScopeSummary($normalizedEntries, [$selectedDot], $recentDays)
            : $spaceSummary;

        $recentRows = array_values(array_filter(
            $normalizedEntries,
            static fn(array $row): bool => $selectedDot <= 0 || (int) ($row['id_dot'] ?? 0) === $selectedDot
        ));
        $recentRows = array_slice($recentRows, 0, max(1, $recentLimit));

        return [
            'available' => true,
            'provider' => $this->buildProviderSummary(),
            'space' => $spaceSummary,
            'selected' => $selectedSummary,
            'recent_rows' => $recentRows,
            'selected_dot' => $selectedDot,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSentEntries(): array
    {
        if (!is_dir($this->stateDir)) {
            return [];
        }

        $files = glob($this->stateDir . 'appointment_reminders_sms_*.json') ?: [];
        $entries = [];

        foreach ($files as $file) {
            $json = @file_get_contents($file);
            $decoded = is_string($json) ? json_decode($json, true) : null;
            $sentItems = is_array($decoded['sent'] ?? null) ? $decoded['sent'] : [];

            $targetDate = '';
            if (preg_match('/appointment_reminders_sms_(\d{4}-\d{2}-\d{2})\.json$/', basename($file), $matches) === 1) {
                $targetDate = (string) ($matches[1] ?? '');
            }

            foreach ($sentItems as $appointmentId => $payload) {
                if (!is_array($payload)) {
                    continue;
                }

                $entries[] = [
                    'appointment_id' => (int) $appointmentId,
                    'recipient' => (string) ($payload['recipient'] ?? ''),
                    'sent_at' => (string) ($payload['sent_at'] ?? ''),
                    'channel' => (string) ($payload['channel'] ?? 'sms'),
                    'provider_id' => (string) ($payload['provider_id'] ?? ''),
                    'target_date' => $targetDate,
                    'state_file' => basename($file),
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array<int, int> $appointmentIds
     * @return array<int, array<string, mixed>>
     */
    private function getAppointmentMap(array $appointmentIds): array
    {
        $appointmentIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => (int) $value,
            $appointmentIds
        ), static fn(int $id): bool => $id > 0)));

        if ($appointmentIds === []) {
            return [];
        }

        $map = [];
        foreach (array_chunk($appointmentIds, 500) as $chunk) {
            $rows = $this->db->table('dap12_agenda_appuntamenti a')
                ->select("
                    a.id_appuntamento,
                    a.id_dot,
                    a.cognome,
                    a.nome,
                    s.data_slot,
                    TIME_FORMAT(s.ora_inizio, '%H:%i') AS time_label
                ", false)
                ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'left')
                ->whereIn('a.id_appuntamento', $chunk)
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $appointmentId = (int) ($row['id_appuntamento'] ?? 0);
                if ($appointmentId > 0) {
                    $map[$appointmentId] = $row;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<int, int> $doctorIds
     * @return array<string, mixed>
     */
    private function buildScopeSummary(array $entries, array $doctorIds, int $recentDays): array
    {
        $doctorIds = array_values(array_unique(array_filter(array_map('intval', $doctorIds), static fn(int $id): bool => $id > 0)));
        if ($doctorIds === []) {
            return $this->emptyScopeSummary();
        }

        $threshold = new \DateTimeImmutable('-' . max(1, $recentDays) . ' days');
        $filtered = array_values(array_filter($entries, static function (array $entry) use ($doctorIds): bool {
            return in_array((int) ($entry['id_dot'] ?? 0), $doctorIds, true);
        }));

        if ($filtered === []) {
            return $this->emptyScopeSummary();
        }

        $lastSentAt = '';
        $recentCount = 0;
        $byDay = [];
        $byDoctor = [];

        foreach ($filtered as $entry) {
            $sentAt = trim((string) ($entry['sent_at'] ?? ''));
            if ($lastSentAt === '' || $sentAt > $lastSentAt) {
                $lastSentAt = $sentAt;
            }

            $dayKey = (string) ($entry['sent_day'] ?? '');
            if ($dayKey !== '') {
                $byDay[$dayKey] = ($byDay[$dayKey] ?? 0) + 1;
            }

            $doctorLabel = trim((string) ($entry['doctor_label'] ?? 'Dottore'));
            if (!isset($byDoctor[$doctorLabel])) {
                $byDoctor[$doctorLabel] = [
                    'doctor_label' => $doctorLabel,
                    'id_dot' => (int) ($entry['id_dot'] ?? 0),
                    'count' => 0,
                ];
            }
            $byDoctor[$doctorLabel]['count']++;

            if ($sentAt !== '') {
                try {
                    $sentAtDate = new \DateTimeImmutable($sentAt);
                    if ($sentAtDate >= $threshold) {
                        $recentCount++;
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        krsort($byDay);
        usort($byDoctor, static fn(array $left, array $right): int => $right['count'] <=> $left['count']);

        $dailyRows = [];
        foreach (array_slice($byDay, 0, 14, true) as $day => $count) {
            $dailyRows[] = [
                'day' => $day,
                'count' => $count,
            ];
        }

        return [
            'total_sent' => count($filtered),
            'sent_recent_days' => $recentCount,
            'last_sent_at' => $lastSentAt,
            'active_doctors' => count($byDoctor),
            'daily_rows' => $dailyRows,
            'by_doctor' => array_slice($byDoctor, 0, 20),
        ];
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function buildPatientLabel(array $appointment): string
    {
        $label = trim(implode(' ', array_filter([
            trim((string) ($appointment['cognome'] ?? '')),
            trim((string) ($appointment['nome'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));

        return $label !== '' ? $label : 'Paziente non disponibile';
    }

    private function extractDayKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable $e) {
            $timestamp = strtotime($value);
            return $timestamp ? date('Y-m-d', $timestamp) : '';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProviderSummary(): array
    {
        $channel = strtolower(trim((string) (env('REMINDER_CHANNEL') ?: 'wa')));
        $isSmsChannel = $channel === 'sms';

        return [
            'channel' => $channel !== '' ? $channel : 'wa',
            'channel_label' => $isSmsChannel ? 'SMS' : 'WhatsApp',
            'provider_label' => $isSmsChannel ? 'Aruba SMS' : 'UltraMsg',
            'sms_sender' => trim((string) (env('SMS_SENDER') ?: '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyScopeSummary(): array
    {
        return [
            'total_sent' => 0,
            'sent_recent_days' => 0,
            'last_sent_at' => '',
            'active_doctors' => 0,
            'daily_rows' => [],
            'by_doctor' => [],
        ];
    }
}
