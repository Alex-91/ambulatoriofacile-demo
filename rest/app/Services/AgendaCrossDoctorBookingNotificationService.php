<?php

namespace App\Services;

use App\Models\AgendaModel;
use App\Models\PersonaleModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

class AgendaCrossDoctorBookingNotificationService
{
    private BaseConnection $db;
    private AgendaModel $agendaModel;
    private PersonaleModel $personaleModel;
    private NotificationService $notificationService;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->agendaModel = new AgendaModel();
        $this->personaleModel = new PersonaleModel();
        $this->notificationService = new NotificationService();
    }

    /**
     * @return array<string, mixed>
     */
    public function notify(int $appointmentId, int $targetLegacyIdDot, int $actorUserId, int $actorLegacyIdDot): array
    {
        if ($appointmentId <= 0 || $targetLegacyIdDot <= 0 || $actorUserId <= 0 || $actorLegacyIdDot <= 0) {
            return ['sent' => false, 'reason' => 'invalid_context'];
        }

        if ($actorLegacyIdDot === $targetLegacyIdDot) {
            return ['sent' => false, 'reason' => 'same_doctor'];
        }

        $appointment = $this->getAppointmentSnapshot($appointmentId);
        if ($appointment === null) {
            return ['sent' => false, 'reason' => 'appointment_not_found'];
        }

        if ((int) ($appointment['id_dot'] ?? 0) !== $targetLegacyIdDot) {
            return ['sent' => false, 'reason' => 'target_mismatch'];
        }

        $actorDoctor = $this->agendaModel->getAgendaProfessionalByLegacyId($actorLegacyIdDot);
        $targetDoctor = $this->agendaModel->getAgendaProfessionalByLegacyId($targetLegacyIdDot);

        if ($actorDoctor === null || $targetDoctor === null) {
            return ['sent' => false, 'reason' => 'doctor_not_found'];
        }

        if ((int) ($targetDoctor['id_user'] ?? 0) === $actorUserId) {
            return ['sent' => false, 'reason' => 'same_user'];
        }

        $actorContact = $this->personaleModel->getPersonaleDecryptedById((int) ($actorDoctor['id_personale'] ?? 0)) ?? [];
        $targetContact = $this->personaleModel->getPersonaleDecryptedById((int) ($targetDoctor['id_personale'] ?? 0)) ?? [];

        $actorLabel = $this->buildDoctorDisplayLabel($actorDoctor, $actorContact);
        $targetLabel = $this->buildDoctorDisplayLabel($targetDoctor, $targetContact);
        $patientLabel = $this->buildPatientLabel($appointment);
        $dateLabel = $this->formatItalianDate((string) ($appointment['data_slot'] ?? ''));
        $timeLabel = $this->formatItalianTime((string) ($appointment['ora_inizio'] ?? ''));
        $appointmentUrl = base_url(
            'agenda?id_dot=' . $targetLegacyIdDot
            . '&data=' . rawurlencode((string) ($appointment['data_slot'] ?? ''))
            . '&view=day'
        );

        $subject = 'Nuovo appuntamento inserito da ' . $actorLabel;
        $body = $this->buildEmailBody(
            $targetLabel,
            $actorLabel,
            $patientLabel,
            $dateLabel,
            $timeLabel,
            $appointment,
            $appointmentUrl
        );

        $result = [
            'sent' => false,
            'email' => [
                'attempted' => false,
                'sent' => false,
                'recipient' => '',
            ],
            'pwa' => [
                'attempted' => false,
                'sent' => false,
                'user_id' => (int) ($targetDoctor['id_user'] ?? 0),
            ],
        ];

        $targetEmail = $this->normalizeEmail((string) ($targetContact['email'] ?? ''));
        if ($targetEmail !== '') {
            $result['email']['attempted'] = true;
            $result['email']['recipient'] = $targetEmail;

            try {
                $this->sendEmail($targetEmail, $subject, $body);
                $result['email']['sent'] = true;
                $result['sent'] = true;
            } catch (\Throwable $e) {
                $result['email']['error'] = $e->getMessage();
                log_message('warning', '[AgendaCrossDoctorBookingNotificationService] Invio email fallito: {message}', [
                    'message' => $e->getMessage(),
                    'appointment_id' => $appointmentId,
                    'recipient' => $targetEmail,
                ]);
            }
        }

        $targetUserId = (int) ($targetDoctor['id_user'] ?? 0);
        if ($targetUserId > 0) {
            $result['pwa']['attempted'] = true;

            try {
                $pushResult = $this->notificationService->sendToUser(
                    $targetUserId,
                    [
                        'type' => 'agenda_cross_booking',
                        'title' => 'Nuovo appuntamento in agenda',
                        'body' => $this->buildPushBody($actorLabel, $patientLabel, $dateLabel, $timeLabel),
                        'tag' => 'agenda-cross-booking-' . $appointmentId,
                        'icon' => NotificationService::notificationIconUrl(),
                        'badge' => NotificationService::notificationBadgeUrl(),
                        'data' => [
                            'url' => $appointmentUrl,
                            'appointmentId' => $appointmentId,
                            'idDot' => $targetLegacyIdDot,
                            'date' => (string) ($appointment['data_slot'] ?? ''),
                        ],
                    ],
                    'agenda_cross_booking',
                    [
                        'TTL' => 900,
                        'urgency' => 'high',
                    ]
                );

                $result['pwa']['result'] = $pushResult;
                $result['pwa']['sent'] = !empty($pushResult['ok']);
                $result['sent'] = $result['sent'] || $result['pwa']['sent'];
            } catch (\Throwable $e) {
                $result['pwa']['error'] = $e->getMessage();
                log_message('warning', '[AgendaCrossDoctorBookingNotificationService] Invio notifica PWA fallito: {message}', [
                    'message' => $e->getMessage(),
                    'appointment_id' => $appointmentId,
                    'target_user_id' => $targetUserId,
                ]);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getAppointmentSnapshot(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            return null;
        }

        $row = $this->db->table('dap12_agenda_appuntamenti a')
            ->select("
                a.id_appuntamento,
                a.id_dot,
                a.id_paziente,
                a.id_client,
                a.cognome,
                a.nome,
                a.note,
                a.motivo_visita,
                s.data_slot,
                s.ora_inizio,
                s.ora_fine
            ")
            ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
            ->where('a.id_appuntamento', $appointmentId)
            ->where('a.stato <>', 'ANNULLATO')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $doctor
     * @param array<string, mixed> $contact
     */
    private function buildDoctorDisplayLabel(array $doctor, array $contact): string
    {
        $qualifica = trim((string) ($contact['qualifica'] ?? ''));
        $nome = trim((string) ($contact['nome'] ?? ($doctor['nome'] ?? '')));
        $cognome = trim((string) ($contact['cognome'] ?? ($doctor['cognome'] ?? '')));

        $pieces = [];
        if ($qualifica !== '') {
            $pieces[] = $qualifica;
        }
        if ($nome !== '') {
            $pieces[] = $nome;
        }
        if ($cognome !== '') {
            $pieces[] = $cognome;
        }

        $label = trim(implode(' ', $pieces));
        if ($label !== '') {
            return $label;
        }

        return trim((string) ($doctor['label'] ?? 'Dottore'));
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function buildPatientLabel(array $appointment): string
    {
        $label = trim(
            implode(' ', array_filter([
                trim((string) ($appointment['cognome'] ?? '')),
                trim((string) ($appointment['nome'] ?? '')),
            ], static fn(string $value): bool => $value !== ''))
        );

        return $label !== '' ? $label : 'Paziente non specificato';
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function buildEmailBody(
        string $targetLabel,
        string $actorLabel,
        string $patientLabel,
        string $dateLabel,
        string $timeLabel,
        array $appointment,
        string $appointmentUrl
    ): string {
        $motivoVisita = trim((string) ($appointment['motivo_visita'] ?? ''));
        $appointmentNotes = trim((string) ($appointment['note'] ?? ''));

        $lines = [
            'Ciao ' . ($targetLabel !== '' ? $targetLabel : 'Dottore') . ',',
            '',
            $actorLabel . ' ha inserito un appuntamento per te.',
            'Giorno: ' . $dateLabel,
            'Ora: ' . $timeLabel,
            'Paziente: ' . $patientLabel,
        ];

        if ($motivoVisita !== '') {
            $lines[] = 'Motivo visita: ' . $motivoVisita;
        }

        $lines[] = 'Note appuntamento: ' . ($appointmentNotes !== '' ? $appointmentNotes : 'Nessuna nota inserita.');
        $lines[] = '';
        $lines[] = 'Apri agenda: ' . $appointmentUrl;

        return implode("\n", $lines);
    }

    private function buildPushBody(string $actorLabel, string $patientLabel, string $dateLabel, string $timeLabel): string
    {
        return $actorLabel . ' ti ha inserito un appuntamento il ' . $dateLabel . ' alle ' . $timeLabel . ' per ' . $patientLabel . '.';
    }

    private function formatItalianDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable) {
            $timestamp = strtotime($value);
            return $timestamp ? date('d/m/Y', $timestamp) : $value;
        }

        $months = [
            1 => 'gennaio',
            2 => 'febbraio',
            3 => 'marzo',
            4 => 'aprile',
            5 => 'maggio',
            6 => 'giugno',
            7 => 'luglio',
            8 => 'agosto',
            9 => 'settembre',
            10 => 'ottobre',
            11 => 'novembre',
            12 => 'dicembre',
        ];

        $month = $months[(int) $date->format('n')] ?? $date->format('m');
        return $date->format('d') . ' ' . $month . ' ' . $date->format('Y');
    }

    private function formatItalianTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('H:i', $timestamp) : $value;
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim(strtolower($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function sendEmail(string $to, string $subject, string $message): void
    {
        $email = Services::email();
        $email->clear(true);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if (!$email->send()) {
            $debug = method_exists($email, 'printDebugger')
                ? trim((string) $email->printDebugger(['headers']))
                : '';

            throw new \RuntimeException(
                'Invio email non riuscito.'
                . ($debug !== '' ? ' ' . strip_tags($debug) : '')
            );
        }
    }
}
