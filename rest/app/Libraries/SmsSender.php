<?php

namespace App\Libraries;
define("BASEURL", "https://adminsms.aruba.it/API/v1.0/REST/");

define("MESSAGE_HIGH_QUALITY", "N");
define("MESSAGE_MEDIUM_QUALITY", "L");
class SmsSender {
    private $token;
    private $apiUrl;
private $smsUsername;
private $smsPassword;
    public function __construct() {
        $this->token = getenv('SMS_API_TOKEN');  // Prende il token dal file .env
          $this->smsUsername = "Sms64060";
           $this->smsPassword = getenv('SMS_PASSWORD');
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

    public function sendSMSIndex($cellulare,$testo) {
        if (!$this->sendingEnabled()) {
            return $this->disabledResponse('sms', $cellulare);
        }

        try
        {
         /*   $smsUsername = getenv('SMS_USERNAME');
            $smsPassword = getenv('SMS_PASSWORD');*/
            
            $auth = $this->login( $this->smsUsername,  $this->smsPassword);
            //var_dump($auth);
        $smsSent =  $this->sendSMS($auth, array(
          //"message" => "AmbulatorioFacile - Il suo codice di accesso OTP è ".$random.". Non divulgare questo codice. Il codice rimarrà attivo solamente per 2 minuti.  ",
            "message" => $testo,
            "message_type" => MESSAGE_HIGH_QUALITY,
            "returnCredits" => false,
            "recipient" => ["+393335374044"], // <-- array!
          //"recipient" => array("+39".$cellulare),
            "sender" => "AmbRIMAGGIO", // postpone by 5 minutes
        ));
       
        }
        catch (Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
        }
    }
    function login($username, $password) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, BASEURL .
                    'login?username=' . $username .
                    '&password=' . $password);
    
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        if ($info['http_code'] != 200) {
            return null;
        }
   // die("RESP".$info['http_code']);
        return explode(";", $response);
    }
    
    /**
     * Sends an SMS message
     */
    function sendSMS($auth, $sendSMS) {
        if (!$this->sendingEnabled()) {
            return $this->disabledResponse('sms', $sendSMS['recipient'] ?? null);
        }

        $ch = curl_init();
       
        curl_setopt($ch, CURLOPT_URL, BASEURL . 'sms');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json',
            'user_key: ' . $auth[0],
            'Session_key: ' . $auth[1]
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sendSMS));
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
      //  die($response);
        if ($info['http_code'] != 201) {
            return null;
        }
    
        return json_decode($response);
    }
}
?>
