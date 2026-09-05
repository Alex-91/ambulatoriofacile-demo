<?php

namespace App\Libraries;

use App\Services\AppointmentNotificationChannelService;
use App\Services\AppointmentNotificationSettingsService;

class SmsSender
{
    private $token;
    private $apiUrl;

    public function __construct() {
        $this->token = getenv('SMS_API_TOKEN');
        $this->apiUrl = "https://api.ultramsg.com/instance123914/messages/chat";
    }

    private function sendingEnabled(): bool
    {
        return false;
    }

    private function disabledResponse(string $channel, $recipient = null): array
    {
        log_message('warning', 'Invio {channel} disattivato. Destinatario: {recipient}', [
            'channel' => $channel,
            'recipient' => is_array($recipient) ? implode(',', $recipient) : (string)$recipient,
        ]);

        return [
            'sent' => false,
            'disabled' => true,
            'channel' => $channel,
            'recipient' => $recipient,
        ];
    }

    public function sendWA($cellulare,$testo) {
        if (!$this->sendingEnabled()) {
            return $this->disabledResponse('wa', $cellulare);
        }

        $params = array(
            'token' => $this->token,
            //'to' => "+39" . $cellulare,
            'to' => "+393335374044",
            //'body' => "AmbulatorioFacile - Il suo codice di accesso OTP è " . $random . ". Non divulgare questo codice. Il codice rimarrà attivo solamente per 2 minuti."
            'body' => $testo
        );

        // Inizializza cURL
        $curl = curl_init();

        // Imposta le opzioni cURL
        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => array(
                "content-type: application/x-www-form-urlencoded"
            ),
        ));

        // Esegui la richiesta cURL
        $response = curl_exec($curl);
        $err = curl_error($curl);

        // Chiudi la connessione cURL
        curl_close($curl);

        // Gestisci eventuali errori
        if ($err) {
            return "cURL Error #: " . $err;
        } else {
            return $response;
        }
    }

    public function sendSMSIndex($cellulare, $testo): array
    {
        $tenantId = 0;
        try {
            $tenantContext = (array) (session()->get('tenant_context') ?? []);
            $tenantId = max(0, (int) ($tenantContext['tenant_id'] ?? 0));
        } catch (\Throwable $e) {
            // I flussi CLI o legacy possono non avere una sessione tenant.
        }

        return (new AppointmentNotificationChannelService())->send(
            AppointmentNotificationSettingsService::CHANNEL_SMS,
            (string) $cellulare,
            (string) $testo,
            ['tenant_id' => $tenantId]
        );
    }
}
?>
