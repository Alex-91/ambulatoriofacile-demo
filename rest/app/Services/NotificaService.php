<?php

namespace App\Services;

use App\Models\ClientsModel;
use App\Models\PersonaleModel;

class NotificaService
{
    protected ClientsModel $clienti;
    protected PersonaleModel $pers;

    public function __construct()
    {
        $this->clienti = new ClientsModel();
        $this->pers    = new PersonaleModel();
    }

    /**
     * $dest: 'C' oppure 'P'/'S'/'I'
     * $tipo: 'N' (nuovo) | 'R' (reply)
     * NB: qui mantengo stub/placeholder del tuo invio WhatsApp/SMS/email
     */
    public function inviaMessaggio(string $dest, int $idDest, string $tipo = 'N'): void
    {
        if ($dest === 'C') {
            $info = $this->clienti->getInfoNotificaCliente($idDest);
            if (!$info) {
                return;
            }

            // Componi messaggio come nel legacy
            $mittente = $info['mittente'] ?? '';
            $qualifica = $info['qualifica'] ?? '';
            if ($qualifica === 'Dott.') {
                $mittente = 'il ' . $mittente;
            } else {
                $mittente = 'la ' . $mittente;
            }

            $testo1 = ($tipo === 'N') ? ' ti ha inviato un nuovo messaggio' : ' ha risposto al tuo messaggio';
            $platformUrl = rtrim((string) (env('APP_CANONICAL_URL', '') ?: env('app.baseURL', '')), '/');
            $body = "Ciao {$info['nome']} {$mittente}{$testo1}.";
            if ($platformUrl !== '') {
                $body .= "\n\nPer leggere, accedi alla piattaforma: {$platformUrl}";
            }

            // TODO: integrare il tuo canale reale (WhatsApp/SMS/Push) qui.
            log_message('info', '[NOTIFICA] A cliente {id} -> {body}', ['id' => $idDest, 'body' => $body]);
        } else {
            // Notifica verso personale (se serve)
            // TODO eventuale
        }
    }
}
