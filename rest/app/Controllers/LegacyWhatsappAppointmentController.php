<?php

namespace App\Controllers;

use App\Libraries\WhatsappAppointmentNote;
use App\Models\LegacyWhatsappAppointmentsModel;

class LegacyWhatsappAppointmentController extends BaseController
{
    private const SUPPORT_TEXT = "Se desidera gestire altri appuntamenti, segua queste semplici istruzioni:\nDIGITI 1 per visualizzare e confermare un altro appuntamento (Nel caso in cui esista un altro appuntamento gia' prenotato).\nDIGITI 2 per visualizzare e annullare un altro appuntamento (Nel caso in cui esista un altro appuntamento gia' prenotato). ";

    private LegacyWhatsappAppointmentsModel $legacyWhatsappAppointmentsModel;

    public function __construct()
    {
        $this->legacyWhatsappAppointmentsModel = new LegacyWhatsappAppointmentsModel();
    }

    public function checkMessaggio()
    {
        $payload = $this->getJsonPayload();
        $cellulare = $this->normalizePhone((string)($payload['number'] ?? ''));
        $window = $this->resolveLegacyDateWindow();
        $azione = ((string)($payload['azione'] ?? '') === 'annulla')
            ? ['label' => ' ANNULLAMENTO ', 'verb' => ' ANNULLARE ']
            : ['label' => ' CONFERMA ', 'verb' => ' CONFERMARE '];

        $appointments = $cellulare === ''
            ? []
            : $this->legacyWhatsappAppointmentsModel->findPendingAppointmentsByPhone(
                $cellulare,
                $window['start_date'],
                $window['end_date']
            );

        $appointmentIds = [];
        $message = null;

        if (count($appointments) === 1) {
            $found = 1;
            $appointment = $appointments[0];
            $appointmentDetails = $appointment['appointment_date'];
            $doctorId = (int)($appointment['doctor_id'] ?? 0);
            $appointmentId = (int)($appointment['appointment_id'] ?? 0);
            $responseStatus = 'OK';
            $appointmentIds = [$appointmentId];
        } elseif (count($appointments) === 0) {
            $found = 0;
            $appointmentDetails = null;
            $doctorId = null;
            $appointmentId = null;
            $responseStatus = null;
        } else {
            $found = 2;
            $appointmentDetails = null;
            $doctorId = null;
            $appointmentId = null;
            $responseStatus = null;
            $message = "Sono stati trovati piu' appuntamenti. Digita il numero dell'appuntamento su cui vuoi effettuare l'operazione di{$azione['label']}:\n";

            $this->legacyWhatsappAppointmentsModel->replaceMultipleSelections($cellulare, $appointments);

            foreach ($appointments as $index => $appointment) {
                $message .= 'DIGITARE ' . ($index + 1)
                    . ' per' . $azione['verb']
                    . " l'appuntamento del "
                    . ($appointment['appointment_date_format'] ?? '')
                    . ' con '
                    . $this->formatDoctorMenuLabel($appointment)
                    . "\n";
            }

            $message .= "SI PREGA DI SELEZIONARE UN APPUNTAMENTO ALLA VOLTA.\nDIGITARE 0 per uscire senza effettuare alcuna operazione\n";
        }

        return $this->response->setJSON([
            'found'            => $found,
            'appointment'      => $appointmentDetails,
            'doctor_id'        => $doctorId,
            'sent_date'        => $window['start_date'],
            'sent_date_plus_5' => $window['end_date'],
            'appointment_id'   => $appointmentId,
            'risposta'         => $responseStatus,
            'testo_multipli'   => $message,
            'appointment_ids'  => $appointmentIds,
        ]);
    }

    public function aggiornaNoteApp()
    {
        $payload = $this->getJsonPayload();

        if (!isset($payload['id'])) {
            return $this->response->setJSON([
                'errore' => "Parametro 'id' mancante.",
            ]);
        }

        $idAppuntamento = (int)$payload['id'];
        $cellulare = $this->normalizePhone((string)($payload['cellulare'] ?? ''));
        $azione = strtolower(trim((string)($payload['esito'] ?? '')));
        $appointment = $this->legacyWhatsappAppointmentsModel->findPendingAppointmentById($idAppuntamento);

        if (!$appointment) {
            return $this->response->setJSON([
                'errore' => "Appuntamento non trovato o gia' gestito.",
            ]);
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
        $testoRisposta = $this->buildLegacyAppointmentResultText($appointment, $azione);

        if ($azione === 'conferma') {
            $noteAppend = WhatsappAppointmentNote::buildConfirmationNote($occurredAt);
            $ok = $this->legacyWhatsappAppointmentsModel->markAppointmentConfirmed($idAppuntamento, $noteAppend);

            if (!$ok) {
                return $this->response->setJSON([
                    'errore' => "Errore nell'aggiornamento.",
                ]);
            }

            return $this->response->setJSON([
                'confermato' => '1',
                'risposta'   => 'OK',
                'id'         => $idAppuntamento,
                'testo'      => $testoRisposta,
            ]);
        }

        if ($azione === 'annulla') {
            $specialPatient = $this->legacyWhatsappAppointmentsModel->getSpecialDotPatient();
            if (!$specialPatient) {
                return $this->response->setJSON([
                    'errore' => "Paziente tecnico DOT non trovato.",
                ]);
            }

            $noteAppend = WhatsappAppointmentNote::buildCancellationNote($occurredAt);
            $ok = $this->legacyWhatsappAppointmentsModel->markAppointmentCancelled($idAppuntamento, $noteAppend, $specialPatient);

            if (!$ok) {
                return $this->response->setJSON([
                    'errore' => "Errore nell'aggiornamento.",
                ]);
            }

            $this->sendLegacyCancellationMail($appointment, $noteAppend, $occurredAt);

            return $this->response->setJSON([
                'annullato' => '1',
                'risposta'  => 'OK',
                'id'        => $idAppuntamento,
                'testo'     => $testoRisposta,
            ]);
        }

        return $this->response->setJSON([
            'errore' => "Errore nell'aggiornamento.",
        ]);
    }

    public function checkAppMultiplo()
    {
        $payload = $this->getJsonPayload();
        $cellulare = $this->normalizePhone((string)($payload['number'] ?? ''));
        $appDaConfermare = (int)($payload['app_da_confermare'] ?? 0);
        $idAppuntamento = $this->legacyWhatsappAppointmentsModel->findPendingMultipleSelection($cellulare, $appDaConfermare);

        return $this->response->setJSON([
            'id_controllo_appuntamento' => $idAppuntamento > 0 ? $idAppuntamento : null,
            'esiste_numero'             => $idAppuntamento > 0 ? 1 : 0,
            'risposta'                  => $idAppuntamento > 0 ? 'OK' : null,
        ]);
    }

    private function getJsonPayload(): array
    {
        $body = (string)$this->request->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePhone(string $cellulare): string
    {
        $cellulare = trim($cellulare);

        if (substr($cellulare, 0, 2) === '39') {
            return substr($cellulare, 2);
        }

        return $cellulare;
    }

    private function resolveLegacyDateWindow(): array
    {
        date_default_timezone_set('Europe/Rome');
        $oraCorrente = (int)date('H');
        $dataInizio = $oraCorrente < 9
            ? date('Y-m-d', strtotime('-1 day'))
            : date('Y-m-d');
        $dataFine = date('Y-m-d', strtotime($dataInizio . ' +6 days'));

        return [
            'start_date' => $dataInizio,
            'end_date'   => $dataFine,
        ];
    }

    private function formatDoctorMenuLabel(array $appointment): string
    {
        return trim(implode(' ', array_filter([
            trim((string)($appointment['doctor_title'] ?? '')),
            trim((string)($appointment['doctor_surname'] ?? '')),
            trim((string)($appointment['doctor_name'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));
    }

    private function buildLegacyAppointmentResultText(array $appointment, string $azione): string
    {
        $date = new \DateTimeImmutable((string)($appointment['appointment_date'] ?? 'now'), new \DateTimeZone('Europe/Rome'));
        $giorni = [
            1 => 'Lunedi',
            2 => 'Martedi',
            3 => 'Mercoledi',
            4 => 'Giovedi',
            5 => 'Venerdi',
            6 => 'Sabato',
            7 => 'Domenica',
        ];
        $mesi = [
            '01' => 'Gennaio',
            '02' => 'Febbraio',
            '03' => 'Marzo',
            '04' => 'Aprile',
            '05' => 'Maggio',
            '06' => 'Giugno',
            '07' => 'Luglio',
            '08' => 'Agosto',
            '09' => 'Settembre',
            '10' => 'Ottobre',
            '11' => 'Novembre',
            '12' => 'Dicembre',
        ];

        $prefissoDottore = trim((string)($appointment['doctor_title'] ?? '')) === 'Dr.ssa'
            ? 'la Dr.ssa '
            : ' il Dott. ';

        $doctorLabel = $prefissoDottore
            . trim((string)($appointment['doctor_surname'] ?? ''))
            . ' '
            . trim((string)($appointment['doctor_name'] ?? ''));

        $dayName = $giorni[(int)$date->format('N')] ?? '';
        $monthName = $mesi[$date->format('m')] ?? '';
        $dateLabel = $dayName
            . ' '
            . $date->format('d')
            . ' '
            . $monthName
            . ' '
            . $date->format('Y')
            . ' alle ore '
            . $date->format('H:i');

        if ($azione === 'annulla') {
            return "L'appuntamento con {$doctorLabel} per {$dateLabel} E' STATO ANNULLATO. Per eventuali comunicazioni o disdette si prega di contattare il numero seguente 05571898. \n" . self::SUPPORT_TEXT;
        }

        return "Gentile utente,\nLa conferma del suo appuntamento con {$doctorLabel} per {$dateLabel} e' avvenuta con successo. Per eventuali comunicazioni o disdette si prega di contattare il numero seguente 05571898. \n" . self::SUPPORT_TEXT;
    }

    private function sendLegacyCancellationMail(array $appointment, string $noteAppend, \DateTimeInterface $occurredAt): void
    {
        try {
            $mailer = \Config\Services::email(null, false);
            $config = config('Email');
            $fromEmail = trim((string)($config->fromEmail ?? ''));
            $fromName = trim((string)($config->fromName ?? ''));
            $recipient = 'info@ambulatoridirimaggio.it';

            if ($fromEmail === '') {
                $fromEmail = (string)(env('email.fromEmail') ?: 'noreply@ambulatori.cloud');
            }

            if ($fromName === '') {
                $fromName = (string)(env('email.fromName') ?: 'AMBULATORI.Cloud');
            }

            $doctorLabel = trim(implode(' ', array_filter([
                trim((string)($appointment['doctor_title'] ?? '')),
                trim((string)($appointment['doctor_surname'] ?? '')),
                trim((string)($appointment['doctor_name'] ?? '')),
            ], static fn($value): bool => $value !== '')));

            $patientLabel = trim(implode(' ', array_filter([
                trim((string)($appointment['patient_surname'] ?? ($appointment['appointment_surname'] ?? ''))),
                trim((string)($appointment['patient_name'] ?? ($appointment['appointment_name'] ?? ''))),
            ], static fn($value): bool => $value !== '')));

            $appointmentDate = new \DateTimeImmutable((string)($appointment['appointment_date'] ?? 'now'), new \DateTimeZone('Europe/Rome'));
            $subject = 'Appuntamento annullato via WA - ' . ($doctorLabel !== '' ? $doctorLabel : ('Dottore #' . (int)($appointment['id_dot'] ?? 0)));
            $message = implode("\n", [
                'E stato annullato un appuntamento via WhatsApp.',
                '',
                'Data/ora appuntamento: ' . $appointmentDate->format('d-m-Y H:i'),
                'Dottore: ' . ($doctorLabel !== '' ? $doctorLabel : ('ID ' . (int)($appointment['id_dot'] ?? 0))),
                'Paziente originale: ' . ($patientLabel !== '' ? $patientLabel : 'N/D'),
                'Cellulare paziente: ' . trim((string)($appointment['patient_cellulare'] ?? ($appointment['appointment_cellulare'] ?? ''))),
                'Telefono paziente: ' . trim((string)($appointment['patient_telefono'] ?? ($appointment['appointment_telefono'] ?? ''))),
                'Email paziente: ' . trim((string)($appointment['patient_email'] ?? ($appointment['appointment_email'] ?? ''))),
                'ID appuntamento: ' . (int)($appointment['id_appuntamento'] ?? 0),
                'ID slot: ' . (int)($appointment['id_slot'] ?? 0),
                'Annullato il: ' . $occurredAt->format('d-m-Y H:i:s'),
                'Nota inserita: ' . $noteAppend,
            ]);

            $mailer->clear(true);
            $mailer->setFrom($fromEmail, $fromName);
            $mailer->setTo($recipient);
            $mailer->setSubject($subject);
            $mailer->setMailType('text');
            $mailer->setMessage($message);
            $mailer->send();
        } catch (\Throwable $e) {
            log_message('warning', '[LegacyWhatsappAppointmentController] Invio mail annullamento fallito: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
