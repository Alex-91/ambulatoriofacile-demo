<?php
namespace App\Services;

use App\Models\ContatoreModel;
use App\Models\MessageModel;
use App\Models\MessageReplyModel;
use App\Models\AttachmentTempModel;
use App\Models\AttachmentModel;
use App\Models\ClientsModel;
use App\Models\PersonaleModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use App\Services\NotificaService;

class MessageService
{
    protected ContatoreModel      $contatore;
    protected MessageModel        $msg;
    protected MessageReplyModel   $reply;
    protected AttachmentTempModel $attTemp;
    protected AttachmentModel     $att;
    protected ClientsModel        $clienti;
    protected PersonaleModel      $pers;
    protected NotificaService     $notifiche;

    public function __construct()
    {
        $this->contatore = new ContatoreModel();
        $this->msg       = new MessageModel();
        $this->reply     = new MessageReplyModel();
        $this->attTemp   = new AttachmentTempModel();
        $this->att       = new AttachmentModel();
        $this->clienti   = new ClientsModel();
        $this->pers      = new PersonaleModel();
        $this->notifiche = new NotificaService();

        // Per usare base_url() dentro il service
        helper('url');
    }

    /**
     * Invia un nuovo messaggio (mittente cliente o personale).
     */
    public function inviaNuovo(object $utente, array $data): array
    {
        $subject    = $data['subject']      ?? '';
        $message    = $data['message_text'] ?? '';
        $draft      = (int)($data['draft'] ?? 0);
        $richiesta  = (int)($data['richiesta'] ?? 0); // 1=seg, 2=inf
        $version    = $data['version']      ?? 'desktop';
        $countDiv   = (int)($data['count_div'] ?? 0);
        $stringDest = $data['string_dest']  ?? '';    // usato quando mittente Ã¨ personale

        // id_message pre-generato dal compose (puÃ² essere 0 se arriva da altri flussi)
        $idMessageFromForm = isset($data['id_message']) ? (int)$data['id_message'] : 0;

        // Determina contesto mittente
        // NEL LEGACY: da_dottore==1 => login cliente (!)
        $isCliente = ($utente->da_dottore == 1);

        $db = Database::connect();
        $db->transStart();

        try {
            if ($isCliente) {
                // =======================================================
                // MITTENTE: Cliente -> destinazione: Personale / Segreteria / Inf.
                // =======================================================

                // se ho un id_message giÃ  generato dal compose lo riuso,
                // altrimenti ne creo uno nuovo (per compatibilitÃ  altri flussi)
                $idMessage = $idMessageFromForm > 0
                    ? $idMessageFromForm
                    : $this->contatore->next('dap10_message');

                $idMitt = $utente->id_client;
                $idPers = $utente->id_doctor; // nel legacy usi $obj->id_doctor

                $daDottore = 0;
                $mitt      = 'C';

                // Destinazione e flag
                // Se il controller ti ha giÃ  calcolato dest/seg_flag/inf_flag li puoi usare,
                // altrimenti ricadi sul vecchio meccanismo con $richiesta.
                $segFlag = isset($data['seg_flag'])
                    ? (int)$data['seg_flag']
                    : (($richiesta === 1) ? 1 : 0);

                $infFlag = isset($data['inf_flag'])
                    ? (int)$data['inf_flag']
                    : (($richiesta === 2) ? 1 : 0);

                // Dest di default verso Personale
                $dest = $data['dest'] ?? 'P';
                if ($segFlag) $dest = 'S';
                if ($infFlag) $dest = 'I';

                // Inserisce il record principale in dap10_message
                $this->msg->inserisciNuovo(
                    $idMessage,
                    $idMitt,
                    $idPers,
                    $subject,
                    $message,
                    $mitt,
                    $dest,
                    $segFlag,
                    $infFlag,
                    $daDottore,
                    $draft
                );

                // duplica record delete per mittente e destinatario
                $this->msg->insertDeletePair($idMessage, $idMitt, $idPers);

                // Allegati: sposta da dap11_attachments_temp a definitivi
                // usando id_message e (per il nuovo) id_message_reply = null
                $this->spostaAllegatiTempInDef($idMessage, null);

            } else {
                // =======================================================
                // MITTENTE: Personale
                // =======================================================
                $idMitt    = $utente->id_personale;
                $mitt      = 'P';
                $daDottore = 1;

                // tipo_pers: 1 = Dottore, 2 = Infermiere, 3 = Segreteria
                $tipoPers = (int)($utente->tipo_pers ?? 0);
                $isDoctor = ($tipoPers === 1);

                if ($stringDest === ',0') {
                    // A tutti i contatti del personale
                    $clientIDs = $this->clienti->getIdsByPersonale($idMitt);
                    foreach ($clientIDs as $idClient) {
                        $idMessage = $this->contatore->next('dap10_message');
                        $this->msg->inserisciNuovo(
                            $idMessage,
                            $idMitt,
                            $idClient,
                            $subject,
                            $message,
                            'P',
                            'C',
                            0,
                            0,
                            $daDottore,
                            $draft,
                            true
                        );
                        $this->msg->insertDeletePair($idMessage, $idClient, $idMitt);
                        $this->spostaAllegatiTempInDef($idMessage, null);

                        // PUSH: solo se mittente Ã¨ dottore e non bozza
                        if ($isDoctor && !$draft) {
                            $this->sendPushToClient(
                                $idClient,
                                'Hai ricevuto un nuovo messaggio dal tuo dottore.',
                                $idMessage
                            );
                        }
                    }
                } elseif ($stringDest === ',10540') {
                    // Segreteria diretta (come legacy)
                    $idMessage = $this->contatore->next('dap10_message');
                    // mitt: P, dest: S, dot_seg=1, da_dottore=0 nel legacy (!) lo manteniamo:
                    $this->msg->inserisciSegreteriaDiretta($idMessage, $idMitt, $subject, $message, $draft);
                    $this->msg->insertDelete($idMessage, $idMitt);
                    $this->spostaAllegatiTempInDef($idMessage, null);

                    // Nessuna push: non Ã¨ dottore â†’ paziente
                } else {
                    // elenco specifico di id_client passato tipo ",12,45"
                    log_message('debug', 'SERVICE stringDest=' . $stringDest);
                    $ids = array_values(array_filter(array_map('intval', explode(',', $stringDest))));
                    foreach ($ids as $idClient) {
                        $idMessage = $this->contatore->next('dap10_message');
                        $this->msg->inserisciNuovo(
                            $idMessage,
                            $idMitt,
                            $idClient,
                            $subject,
                            $message,
                            'P',
                            'C',
                            0,
                            0,
                            $daDottore,
                            $draft,
                            true
                        );
                        $this->msg->insertDeletePair($idMessage, $idClient, $idMitt);
                        $this->spostaAllegatiTempInDef($idMessage, null);

                        // PUSH: solo se mittente Ã¨ dottore e non bozza
                        if ($isDoctor && !$draft) {
                            $this->sendPushToClient(
                                $idClient,
                                'Hai ricevuto un nuovo messaggio dal tuo dottore.',
                                $idMessage
                            );
                        }
                    }
                }
            }

            $db->transComplete();
            if ($db->transStatus() === false) {
                throw new DatabaseException('Transazione fallita');
            }

            // risposta JSON minima (come legacy: 'resp'=>"OK")
            return [
                'resp'    => 'OK',
                'version' => $version,
                'tipo'    => $utente->tipo,
            ];
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * Invia una risposta (reply) a un thread esistente.
     * Replica i rami logici legacy per mitt/dest e flag vari.
     */
    public function inviaReply(object $utente, array $data): array
    {
        $idMessage   = (int)$data['id_message'];
        $message     = $data['message_text'] ?? '';
        $draft       = (int)($data['draft'] ?? 0);
        $version     = $data['version'] ?? 'desktop';
        $countDiv    = (int)($data['count_div'] ?? 0);

        $db = Database::connect();
        $db->transStart();
        log_message('debug', "ID_MESSAGE_INI IN REPLY" . $idMessage);

        try {
            // Recupera ultimo reply (se esiste), altrimenti leggi il messaggio originario
            $context = $this->reply->getContextForReply($idMessage);

            if (!$context) {
                throw new \RuntimeException('Thread non trovato');
            }

            // Mappa completa per dedurre mitt/dest del nuovo reply in base a:
            // - chi Ã¨ connesso (cliente o personale)
            // - ultimo mitt/dest nel thread
            $isCliente = ($utente->da_dottore == 1); // coerente con legacy

            $mitt      = '';
            $dest      = '';
            $idMitt    = 0;
            $idDest    = 0;
            $daDottore = 0;
            $dotSeg    = (int)($context['dot_seg'] ?? 0);
            $segFlag   = (int)($context['seg_flag'] ?? 0);
            $infFlag   = (int)($context['inf_flag'] ?? 0);
            $oggetto   = $context['oggetto'] ?? '';

            if (!$isCliente) {
                // CONNESSO PERSONALE (tipo=2 nel legacy)
                // Se ultimo mitt Ã¨ P/S/I e dest Ã¨ C, mantieni coppia (id_mitt,id_dest) coerente con legacy
                if (in_array($context['mitt'], ['P', 'S', 'I'], true)) {
                    if ($context['dest'] === 'C') {
                        $idMitt = $context['id_mitt'];
                        $idDest = $context['id_dest'];
                    } else {
                        $idMitt = $context['id_dest'];
                        $idDest = $context['id_mitt'];
                    }
                } else {
                    // ultimo mitt era C
                    $idMitt = $context['id_dest'];
                    $idDest = $context['id_mitt'];
                }

                log_message('debug', 'Tipo personale:' . $utente->tipo_pers);
                // set lettera mitt personale in base al tipo_pers
                $tipoPers = (int)($utente->tipo_pers ?? 0);

                if ($tipoPers === 1) $mitt = 'P';
                if ($tipoPers === 2) $mitt = 'I';
                if ($tipoPers === 3) $mitt = 'S';

                log_message('debug', 'tipoPers=' . $tipoPers . ' â†’ mitt=' . $mitt);

                $dest      = 'C';
                $daDottore = 0;
            } else {
                // CONNESSO CLIENTE
                if ($context['mitt'] === 'C') {
                    $idMitt = $context['id_mitt'];
                    $idDest = $context['id_dest'];
                    $dest   = $context['dest']; // P/S/I
                } else {
                    $idMitt = $context['id_dest'];
                    $idDest = $context['id_mitt'];
                    $dest   = $context['mitt']; // P/S/I
                }
                $mitt      = 'C';
                $daDottore = 1;

                // set gestita=0 sul messaggio iniziale se scrive il cliente (come legacy)
                $this->msg->setGestita($idMessage, 0);
            }

            // Crea nuovo id reply e inserisci
            $idMessageReply = $this->contatore->next('dap10_message_reply');
            $this->reply->inserisciReply(
                $idMessageReply,
                $context['id_message_ini'],
                $idMitt,
                $idDest,
                $mitt,
                $dest,
                $oggetto,
                $message,
                $segFlag,
                $infFlag,
                $daDottore,
                $draft,
                $dotSeg
            );

            // delete-pair per reply
            $this->reply->insertDeletePair($idMessageReply, $idMitt, $idDest);

            // Allegati temp -> definitivi (con id_message_reply valorizzato)
            $this->spostaAllegatiTempInDef($idMessage, $idMessageReply);

            // Notifica (solo al destinatario, come legacy)
            $this->notifiche->inviaMessaggio($dest, $idDest, ($isCliente ? 'R' : 'R'));

            // PUSH: se Ã¨ il dottore che risponde a un paziente
            $tipoPers = (int)($utente->tipo_pers ?? 0);
            $isDoctor = (!$isCliente && $tipoPers === 1);

            // In questo caso, idDest Ã¨ l'id_client del paziente quando dest = 'C'
            if ($isDoctor && $dest === 'C' && !$draft) {
                $this->sendPushToClient(
                    $idDest,
                    'Hai ricevuto una risposta dal tuo dottore.',
                    $context['id_message_ini'] ?? null
                );
            }

            $db->transComplete();
            if ($db->transStatus() === false) {
                throw new DatabaseException('Transazione fallita');
            }

            // Ritorno HTML snipped (come legacy) + meta
            $html = $this->reply->renderHtmlReply(
                $idMessage,
                $message,
                $version,
                $countDiv
            );

            // Pulisci flag allegati in sessione, se li usi
            session()->set('allegati', 'NO');

            return ['resp' => $html, 'version' => $version, 'tipo' => $utente->tipo];
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }
    }

    /**
     * Sposta i file da dap11_attachments_temp a dap11_attachments creando
     * la directory /upload/{id_message}/ e muovendo i file fisici.
     *
     * @param int      $idMessage       id del messaggio principale
     * @param int|null $idMessageReply  se reply, collega a quella riga
     * @return int numero allegati spostati
     */
    public function spostaAllegatiTempInDef(int $idMessage, ?int $idMessageReply): int
    {
        // Recupero il sessid come nell'upload
        $sessionConfig = config('Session');
        $sessionCookie = $sessionConfig->cookieName ?? 'ci_session';
        $sessid        = $_COOKIE[$sessionCookie] ?? '';

        // Tutti gli allegati temporanei legati alla sessione corrente
        // N.B.: getBySession restituisce giÃ  nome_real e nome_vis DECRITTATI
        $temp  = $this->attTemp->getBySession($sessid);
        $moved = 0;

        foreach ($temp as $row) {

            // $row['nome_real']  = path relativo decrittato (es. "20251123/28/142289/file.pdf")
            // $row['nome_vis']   = nome file "umano" decrittato

            // Inserisco nella tabella definitiva RICRIPTANDO tramite Crypto_helper
            $this->att->insertDefinitivo(
                $row['nome_real'],   // PLAIN TEXT â†’ sarÃ  cifrato in insertDefinitivo
                $row['nome_vis'],    // PLAIN TEXT â†’ sarÃ  cifrato in insertDefinitivo
                $idMessage,
                $idMessageReply,
                $row['vector_id_hex'] // lo passo solo per compatibilitÃ  firma, ma verrÃ  ignorato
            );

            // Cancello il record dalla tabella temporanea
            $this->attTemp->deleteById($row['id_attachments']);
            $moved++;
        }

        log_message(
            'debug',
            'spostaAllegatiTempInDef: spostati {num} allegati dalla sessione {sessid} al messaggio {msg}',
            [
                'num'    => $moved,
                'sessid' => $sessid,
                'msg'    => $idMessage,
            ]
        );

        return $moved;
    }

    /**
     * Invia una push al paziente (cliente) se collegato ad un utente con dispositivi attivi.
     * $idClient Ã¨ l'id della tabella clienti (dap01?).
     */
    protected function sendPushToClient(int $idClient, string $body, ?int $idMessageIni = null): void
    {
        $client = $this->clienti->find($idClient);
        if (!$client) {
            log_message('error', "[sendPushToClient] Cliente {$idClient} non trovato");
            return;
        }

        // âš ï¸ Cambia 'id_user' se nel tuo schema il campo si chiama in altro modo
        $userId = (int)($client['id_user'] ?? 0);
        if ($userId <= 0) {
            log_message('error', "[sendPushToClient] Nessun userId associato al cliente {$idClient}");
            return;
        }

        // URL per aprire la posta; se hai una rotta read specifica usala qui
        $url = base_url('posta');
        if ($idMessageIni !== null) {
            $url = base_url('posta');
        }

        $payload = [
            'title'  => 'AmbulatorioFacile',
            'body'   => $body,
            'sticky' => true,
            'data'   => [
                'url' => $url,
            ],
        ];

        log_message('error', "[sendPushToClient] Invio push a userId={$userId}, clientId={$idClient}");
        service('push')->sendToUser($userId, $payload);
    }
}
