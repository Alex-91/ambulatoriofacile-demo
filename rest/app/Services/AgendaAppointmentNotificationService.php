<?php

namespace App\Services;

use App\Models\AgendaModel;
use App\Models\PersonaleModel;
use Config\Database;

class AgendaAppointmentNotificationService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private TenantContextService $tenantContextService;
    private TenantCatalogService $tenantCatalogService;
    private AppointmentNotificationSettingsService $settingsService;
    private AppointmentNotificationChannelService $channelService;
    private AppointmentNotificationLogService $logService;
    private AgendaModel $agendaModel;
    private PersonaleModel $personaleModel;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->tenantContextService = new TenantContextService();
        $this->tenantCatalogService = new TenantCatalogService();
        $this->settingsService = new AppointmentNotificationSettingsService();
        $this->channelService = new AppointmentNotificationChannelService();
        $this->logService = new AppointmentNotificationLogService();
        $this->agendaModel = new AgendaModel();
        $this->personaleModel = new PersonaleModel();
    }

    /**
     * @return array<string, mixed>
     */
    public function handleBookedAppointment(
        int $appointmentId,
        int $targetLegacyIdDot,
        int $actorUserId,
        int $actorLegacyIdDot = 0,
        bool $actorIsDoctor = false
    ): array {
        if ($appointmentId <= 0 || $targetLegacyIdDot <= 0) {
            return ['handled' => false, 'reason' => 'invalid_context'];
        }

        $tenant = $this->resolveCurrentTenant();
        if ($tenant === null) {
            return ['handled' => false, 'reason' => 'tenant_not_available'];
        }

        $settings = $this->settingsService->resolveTenantSettings((int) ($tenant['id_tenant'] ?? 0));
        if (empty($settings['module']['available'])) {
            return ['handled' => false, 'reason' => 'feature_disabled'];
        }

        $appointment = $this->getAppointmentSnapshot($appointmentId);
        if ($appointment === null) {
            return ['handled' => false, 'reason' => 'appointment_not_found'];
        }

        $targetDoctor = $this->agendaModel->getAgendaProfessionalByLegacyId($targetLegacyIdDot);
        if ($targetDoctor === null) {
            return ['handled' => false, 'reason' => 'target_doctor_not_found'];
        }

        $targetContact = $this->personaleModel->getPersonaleDecryptedById((int) ($targetDoctor['id_personale'] ?? 0)) ?? [];
        $doctorLabel = $this->buildDoctorLabel($targetDoctor, $targetContact);
        $patientLabel = $this->buildPatientLabel($appointment);
        $scheduledFor = $this->buildAppointmentDateTime((string) ($appointment['data_slot'] ?? ''), (string) ($appointment['ora_inizio'] ?? ''));
        $notes = trim((string) ($appointment['note'] ?? ''));

        $result = [
            'handled' => true,
            'tenant_id' => (int) ($tenant['id_tenant'] ?? 0),
            'patient_booking' => [],
            'doctor_cross_booking' => [],
        ];

        $patientRecipient = $this->selectAppointmentRecipient($appointment);
        $patientPlan = $this->settingsService->resolveDispatchPlan((int) ($tenant['id_tenant'] ?? 0), AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING);
        if (!empty($patientPlan['enabled']) && !empty($patientPlan['channels'])) {
            $message = $this->buildPatientBookingMessage($patientLabel, $doctorLabel, $scheduledFor, $notes);
            $result['patient_booking'] = $this->dispatchPlan(
                $tenant,
                (array) $patientPlan,
                $patientRecipient,
                $message,
                [
                    'message_type' => AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING,
                    'recipient_role' => 'patient',
                    'appointment_id' => $appointmentId,
                    'doctor_id' => $targetLegacyIdDot,
                    'doctor_label' => $doctorLabel,
                    'actor_user_id' => $actorUserId,
                    'actor_label' => '',
                    'patient_label' => $patientLabel,
                    'scheduled_for' => $scheduledFor,
                    'notes' => $notes,
                    'source' => 'appointment_booking',
                ]
            );
        }

        if ($actorIsDoctor && $actorUserId > 0 && $actorLegacyIdDot > 0 && $actorLegacyIdDot !== $targetLegacyIdDot) {
            $crossPlan = $this->settingsService->resolveDispatchPlan((int) ($tenant['id_tenant'] ?? 0), AppointmentNotificationSettingsService::TYPE_DOCTOR_CROSS_BOOKING);
            if (!empty($crossPlan['enabled']) && !empty($crossPlan['channels'])) {
                $actorDoctor = $this->agendaModel->getAgendaProfessionalByLegacyId($actorLegacyIdDot);
                $actorContact = $actorDoctor !== null
                    ? ($this->personaleModel->getPersonaleDecryptedById((int) ($actorDoctor['id_personale'] ?? 0)) ?? [])
                    : [];
                $actorLabel = $actorDoctor !== null ? $this->buildDoctorLabel($actorDoctor, $actorContact) : 'Un collega';
                $doctorRecipient = $this->channelService->normalizeRecipient((string) ($targetContact['cellulare'] ?? ''));
                $message = $this->buildCrossDoctorMessage($actorLabel, $patientLabel, $scheduledFor, $notes);

                $result['doctor_cross_booking'] = $this->dispatchPlan(
                    $tenant,
                    (array) $crossPlan,
                    $doctorRecipient,
                    $message,
                    [
                        'message_type' => AppointmentNotificationSettingsService::TYPE_DOCTOR_CROSS_BOOKING,
                        'recipient_role' => 'doctor',
                        'appointment_id' => $appointmentId,
                        'doctor_id' => $targetLegacyIdDot,
                        'doctor_label' => $doctorLabel,
                        'actor_user_id' => $actorUserId,
                        'actor_label' => $actorLabel,
                        'patient_label' => $patientLabel,
                        'scheduled_for' => $scheduledFor,
                        'notes' => $notes,
                        'source' => 'appointment_booking',
                    ]
                );
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCurrentTenant(): ?array
    {
        $context = $this->tenantContextService->getCurrentTenant();
        if ($context === null || $context->tenantId <= 0) {
            return null;
        }

        return $this->tenantCatalogService->getTenantById($context->tenantId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getAppointmentSnapshot(int $appointmentId): ?array
    {
        $row = $this->db->table('dap12_agenda_appuntamenti a')
            ->select("
                a.id_appuntamento,
                a.id_dot,
                a.cognome,
                a.nome,
                a.cellulare,
                a.telefono,
                a.note,
                a.motivo_visita,
                s.data_slot,
                s.ora_inizio
            ")
            ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
            ->where('a.id_appuntamento', $appointmentId)
            ->where('a.stato <>', 'ANNULLATO')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function selectAppointmentRecipient(array $appointment): ?string
    {
        $mobile = $this->channelService->normalizeRecipient((string) ($appointment['cellulare'] ?? ''));
        if ($mobile !== null) {
            return $mobile;
        }

        return $this->channelService->normalizeRecipient((string) ($appointment['telefono'] ?? ''));
    }

    /**
     * @param array<string, mixed> $doctor
     * @param array<string, mixed> $contact
     */
    private function buildDoctorLabel(array $doctor, array $contact): string
    {
        $qualifica = trim((string) ($contact['qualifica'] ?? ''));
        $nome = trim((string) ($contact['nome'] ?? ($doctor['nome'] ?? '')));
        $cognome = trim((string) ($contact['cognome'] ?? ($doctor['cognome'] ?? '')));

        $parts = array_values(array_filter([$qualifica, $nome, $cognome], static fn(string $value): bool => $value !== ''));
        $label = trim(implode(' ', $parts));

        return $label !== '' ? $label : trim((string) ($doctor['label'] ?? 'Dottore'));
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

        return $label !== '' ? $label : 'Paziente';
    }

    private function buildAppointmentDateTime(string $date, string $time): string
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '') {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($date . ' ' . ($time !== '' ? $time : '00:00:00'), new \DateTimeZone('Europe/Rome'));
            return $dt->format('d/m/Y H:i');
        } catch (\Throwable $e) {
            return trim($date . ' ' . $time);
        }
    }

    private function buildPatientBookingMessage(string $patientLabel, string $doctorLabel, string $scheduledFor, string $notes): string
    {
        $lines = [
            'Gentile ' . $patientLabel . ',',
            'il suo appuntamento e stato registrato con ' . $doctorLabel . '.',
            'Data e ora: ' . $scheduledFor . '.',
        ];

        if ($notes !== '') {
            $lines[] = 'Note appuntamento: ' . $notes;
        }

        $lines[] = 'AmbulatorioFacile';

        return implode("\n", $lines);
    }

    private function buildCrossDoctorMessage(string $actorLabel, string $patientLabel, string $scheduledFor, string $notes): string
    {
        $lines = [
            $actorLabel . ' ha preso un appuntamento per te.',
            'Data e ora: ' . $scheduledFor . '.',
            'Paziente: ' . $patientLabel . '.',
        ];

        if ($notes !== '') {
            $lines[] = 'Note appuntamento: ' . $notes;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $baseLog
     * @return array<string, mixed>
     */
    private function dispatchPlan(array $tenant, array $plan, ?string $recipient, string $message, array $baseLog): array
    {
        $channels = array_values((array) ($plan['channels'] ?? []));
        $result = [
            'enabled' => !empty($plan['enabled']),
            'channels' => $channels,
            'recipient' => $recipient,
            'results' => [],
        ];

        if (empty($plan['enabled']) || $channels === []) {
            $result['reason'] = 'no_active_channels';
            return $result;
        }

        if ($recipient === null || trim($recipient) === '') {
            $result['reason'] = 'invalid_recipient';
            return $result;
        }

        foreach ($channels as $channel) {
            $sendResult = $this->channelService->send($channel, $recipient, $message);
            $logEntry = $baseLog;
            $logEntry['channel'] = $channel;
            $logEntry['provider'] = (string) ($sendResult['provider'] ?? $this->channelService->providerLabel($channel));
            $logEntry['provider_id'] = (string) ($sendResult['provider_id'] ?? '');
            $logEntry['recipient'] = $recipient;
            $logEntry['status'] = !empty($sendResult['ok']) ? 'sent' : 'failed';
            $logEntry['error'] = (string) ($sendResult['error'] ?? '');
            $logEntry['response'] = $sendResult['response'] ?? null;
            $logEntry['created_at'] = date('c');

            $this->logService->append($tenant, $logEntry);
            $result['results'][] = $sendResult;
        }

        return $result;
    }
}
