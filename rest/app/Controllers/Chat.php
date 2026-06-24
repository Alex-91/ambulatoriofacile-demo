<?php

namespace App\Controllers;

use App\Models\ChatModel;
use CodeIgniter\Controller;

class Chat extends BaseController
{
    private function resolveChatRole(ChatModel $chat, $me): int
    {
        return $chat->resolveChatTipoPers(
            (int)($me->id_user ?? 0),
            (int)($me->tipo_pers ?? 0)
        );
    }

    public function index()
    {
        $chat = new ChatModel();

        $me = session()->get('utente_sess');

        if (!$me || empty($me->id_user)) {
            return $this->sessionExpiredRedirect();
        }

        $meUserId   = (int)$me->id_user;
        $meTipoPers = $this->resolveChatRole($chat, $me);

        // Thread selezionato da querystring
        $threadId = (int)($this->request->getGet('thread') ?? 0);

        // Lista threads visibili in base al ruolo (crea automaticamente quelli per-medico)
        $threads = $chat->getThreadsForRole($meUserId, $meTipoPers);
            $unreadMap = $chat->getUnreadCountsMapForUser($meUserId);

            foreach ($threads as &$t) {
                $tid = (int)($t['id_thread'] ?? 0);
                $t['unread_count'] = (int)($unreadMap[$tid] ?? 0);
            }
            unset($t);

        $selectedThread = null;
        $messages = [];

        if ($threadId > 0 && $chat->canAccessThread($threadId, $meUserId)) {
            $selectedThread = $chat->getThreadInfo($threadId);
            $messages = $chat->getMessages($threadId, 200);
        } else {
            // Se non ho thread selezionato, provo a selezionare il primo disponibile
            if (!empty($threads)) {
                $first = $threads[0];
                $threadId = (int)$first['id_thread'];
                if ($chat->canAccessThread($threadId, $meUserId)) {
                    $selectedThread = $chat->getThreadInfo($threadId);
                    $messages = $chat->getMessages($threadId, 200);
                }
            }
        }

        // Se segreteria/infermiere: elenco medici (per aprire thread)
        $doctors = [];
        if ($meTipoPers === ChatModel::TIPO_SEGRETERIA || $meTipoPers === ChatModel::TIPO_INFERMIERE) {
            $doctors = $chat->getDoctorsList($meUserId, $meTipoPers); // [{id_user, nome_completo}]
        }
         $chat->markThreadRead($threadId, (int)$me->id_user);
         $totUnread = $chat->getTotalUnreadForUser( (int)$me->id_user);
        session()->set('badge_chat_unread', $totUnread);
        return view('chat/index', [
            'me'             => $me,
            'meTipoPers'     => $meTipoPers,
            'threads'        => $threads,
            'selectedThread' => $selectedThread,
            'messages'       => $messages,
            'doctors'        => $doctors,
        ]);
    }

    /**
     * Per segreteria/infermiere: apre (crea se manca) il thread col medico specifico.
     */
    public function startThread(int $doctorUserId)
    {
        $chat = new ChatModel();

         $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return $this->sessionExpiredRedirect();
        }

        $meUserId   = (int)$me->id_user;
        $meTipoPers = $this->resolveChatRole($chat, $me);

        if ($meTipoPers !== ChatModel::TIPO_SEGRETERIA && $meTipoPers !== ChatModel::TIPO_INFERMIERE) {
            // medico non apre via start
            return redirect()->to(base_url('chat'));
        }

        if (!$chat->canStaffAccessDoctor($meUserId, $meTipoPers, $doctorUserId)) {
            return redirect()->to(base_url('chat'))->with('error', 'Non hai accesso a questo medico.');
        }

        // decide baseKey in base al ruolo del mittente
        if ($meTipoPers === ChatModel::TIPO_SEGRETERIA) {
            $threadId = $chat->getOrCreateDoctorGroupThread($doctorUserId, 'segreteria', 'Segreteria');
        } else {
            $threadId = $chat->getOrCreateDoctorGroupThread($doctorUserId, 'infermieri', 'Infermieri');
        }

        // assicurati che io sia membro (dovrei già esserlo perché aggiungo tutto lo staff)
        if (!$chat->canAccessThread($threadId, $meUserId)) {
            return redirect()->to(base_url('chat'))->with('error', 'Non hai accesso a questa chat.');
        }

        $isMobile = ((int)$this->request->getGet('mobile') === 1);
        
        if ($isMobile) {
            return redirect()->to(base_url('chat/thread/' . $threadId));
        }

        return redirect()->to(base_url('chat?thread=' . $threadId));

    }

    public function thread($idThread = null)
{
    $chat = new \App\Models\ChatModel();
     $me = session()->get('utente_sess');
     
    if (!$me || empty($me->id_user)) {
        return $this->sessionExpiredRedirect();
    }
    $meTipoPers = $this->resolveChatRole($chat, $me);
    $threadId = (int)$idThread;

    if ($threadId <= 0 || !$chat->canAccessThread($threadId, (int)$me->id_user)) {
        return redirect()->to(base_url('chat'));
    }

    $selectedThread = $chat->getThreadInfo($threadId);
    $messages = $chat->getMessages($threadId);
    $threads = $chat->getThreadsForRole((int)$me->id_user, $meTipoPers);

    // lista dottori serve per titolo (se segreteria/infermiere)
    $doctors = [];
    if ($meTipoPers !== \App\Models\ChatModel::TIPO_DOTTORE) {
        $doctors = $chat->getDoctorsList((int)$me->id_user, $meTipoPers);
    }
        $chat->markThreadRead($threadId, (int)$me->id_user);
        $totUnread = $chat->getTotalUnreadForUser( (int)$me->id_user);
            session()->set('badge_chat_unread', $totUnread);

    return view('chat/thread', [
        'me' => $me,
        'meTipoPers' => $meTipoPers,
        'threads' => $threads,
        'selectedThread' => $selectedThread,
          'thread' => $selectedThread,  
        'messages' => $messages,
        'doctors' => $doctors,
    ]);
}

public function clear()
{
    $chat = new ChatModel();

    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->sessionExpiredRedirect();
    }

    $meUserId = (int)$me->id_user;

    $threadId   = (int)($this->request->getPost('thread_id') ?? 0);
    $returnUrl  = (string)($this->request->getPost('return_url') ?? base_url('chat'));

    if ($threadId <= 0) {
        return redirect()->to($returnUrl)->with('error', 'Thread non valido.');
    }

    if (!$chat->canAccessThread($threadId, (int)$me->id_user)) {
        return redirect()->to(base_url('chat'))->with('error', 'Non hai accesso a questa chat.');
    }

    // >>> QUI SVUOTO SOLO I MESSAGGI DEL THREAD
    $chat->clearThreadMessages($threadId,(int)$me->id_user);

    return redirect()->to($returnUrl)->with('success', 'Chat svuotata.');
}
public function clearAll()
{
    $chat = new ChatModel();

    $me = session()->get('utente_sess');
    if (!$me || empty($me->id_user)) {
        return $this->sessionExpiredRedirect();
    }

    $meUserId  = (int)$me->id_user;
    $returnUrl = (string)($this->request->getPost('return_url') ?? base_url('chat'));

    // ✅ svuota SOLO per questo utente (non cancella i messaggi dal DB)
    $chat->clearAllThreadsForUser($meUserId);

    return redirect()->to($returnUrl)->with('success', 'Tutte le chat sono state svuotate (solo per te).');
}




   public function send()
{
    $chat = new \App\Models\ChatModel();
    $db   = \Config\Database::connect();
    $me   = session()->get('utente_sess');

    if (!$me || empty($me->id_user)) {
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Unauthorized']);
        }
        return $this->sessionExpiredRedirect();
    }

    $threadId = (int)($this->request->getPost('thread_id') ?? $this->request->getPost('id_thread') ?? 0);
    $body     = trim((string)($this->request->getPost('message') ?? $this->request->getPost('body') ?? ''));
    $file     = $this->request->getFile('attachment');

    $hasFile = false;
    if ($file && (int)$file->getError() !== UPLOAD_ERR_NO_FILE) {
        try {
            $this->validateUploadedFilesMaxSize([$file]);
            $hasFile = true;
        } catch (\RuntimeException $e) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(400)->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
            }

            return redirect()->to(base_url('chat'))->with('error', $e->getMessage());
        }
    }

    // consenti invio se c'è testo O file
    if ($threadId <= 0 || ($body === '' && !$hasFile)) {
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Dati non validi']);
        }
        return redirect()->to(base_url('chat'))->with('error', 'Dati non validi');
    }

    if (!$chat->canAccessThread($threadId, (int)$me->id_user)) {
        if ($this->request->isAJAX()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Forbidden']);
        }
        return redirect()->to(base_url('chat'));
    }

    // se invii solo allegato, metto body vuoto gestito
    $textToSave = $body !== '' ? $body : '[Allegato]';

    $idMessage = $chat->sendMessage($threadId, (int)$me->id_user, $textToSave);

    $attachment = null;

if ($hasFile) {
    $uploadPath = WRITEPATH . 'uploads/chat/';
    if (!is_dir($uploadPath)) {
        mkdir($uploadPath, 0777, true);
    }

    $originalName = $file->getClientName();
    $mimeType     = $file->getMimeType();
    $fileSize     = $file->getSize();
    $newName      = $file->getRandomName();

    $file->move($uploadPath, $newName);

    $db->query(
        "INSERT INTO dap_chat_attachments
         (id_message, original_name, stored_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?)",
        [
            $idMessage,
            $originalName,
            $newName,
            $mimeType,
            $fileSize
        ]
    );

    $attachment = [
        'original_name' => $originalName,
        'stored_name'   => $newName,
    ];
}

    $message = [
        'id_message'   => $idMessage,
        'id_thread'    => $threadId,
        'sender_id'    => (int)$me->id_user,
        'sender_name'  => 'Tu',
        'body'         => $textToSave,
        'created_at'   => date('Y-m-d H:i:s'),
        'attachment'   => $attachment,
    ];

    if ($this->request->isAJAX()) {
        return $this->response->setJSON(['ok' => true, 'message' => $message]);
    }

    return redirect()->to(base_url('chat?thread=' . $threadId));
}

public function attachment(int $idMessage)
{
    $db = \Config\Database::connect();
    $chat = new \App\Models\ChatModel();
    $me = session()->get('utente_sess');

    if (!$me || empty($me->id_user)) {
        return $this->sessionExpiredRedirect();
    }

    $row = $db->query("
        SELECT 
            a.id_attachment,
            a.id_message,
            a.original_name,
            a.stored_name,
            a.mime_type,
            a.file_size,
            m.id_thread
        FROM dap_chat_attachments a
        INNER JOIN dap_chat_message m 
            ON m.id_message = a.id_message
        WHERE a.id_message = ?
        LIMIT 1
    ", [$idMessage])->getRowArray();

    if (!$row) {
        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Allegato non trovato');
    }

    if (!$chat->canAccessThread((int)$row['id_thread'], (int)$me->id_user)) {
        return redirect()->to(base_url('chat'))->with('error', 'Accesso negato.');
    }

    $path = WRITEPATH . 'uploads/chat/' . $row['stored_name'];

    if (!is_file($path)) {
        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('File non trovato nel filesystem');
    }

    return $this->response
        ->download($path, null)
        ->setFileName($row['original_name']);
}
    /**
     * Poll AJAX: ritorna i messaggi nuovi
     * GET /chat/poll?thread=ID&after=LAST_ID
     */
    public function poll()
    {
        $chat = new ChatModel();

         $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Sessione scaduta.']);
        }

        $meUserId = (int)$me->id_user;

        $threadId = (int)($this->request->getGet('thread') ?? 0);
        $afterId  = (int)($this->request->getGet('after') ?? 0);

        $meTipoPers = $this->resolveChatRole($chat, $me);

// se thread=0 => vogliamo SOLO aggiornare lista e badge
        if ($threadId <= 0) {
            $totUnread = $chat->getTotalUnreadForUser($meUserId);
            session()->set('badge_chat_unread', $totUnread);

            $unreadMap  = $chat->getUnreadCountsMapForUser($meUserId);
            $previewMap = $chat->getLastPreviewMapForUser($meUserId);

            // lista aggiornata threads (importantissima per mostrare nuovi thread senza refresh)
            $threads = $chat->getThreadsForRole($meUserId, $meTipoPers);

            // aggiungo unread_count a ogni thread (così lato JS non deve incrociare)
            foreach ($threads as &$t) {
                $tid = (int)($t['id_thread'] ?? 0);
                $t['unread_count'] = (int)($unreadMap[$tid] ?? 0);
            }
            unset($t);

            return $this->response->setJSON([
                'ok' => true,
                'mode' => 'list',
                'messages' => [],
                'total_unread' => $totUnread,
                'unread_map' => $unreadMap,
                'last_preview_map' => $previewMap,
                'threads' => $threads,
            ]);
        }
        /*if ($threadId <= 0) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Thread non valido.']);
        }*/

        if (!$chat->canAccessThread($threadId, $meUserId)) {
            return $this->response->setJSON(['ok' => false, 'error' => 'Accesso negato.']);
        }

       $new = $chat->getMessagesAfter($threadId, $afterId, 200);

$totUnread = $chat->getTotalUnreadForUser($meUserId);
session()->set('badge_chat_unread', $totUnread);

$unreadMap  = $chat->getUnreadCountsMapForUser($meUserId);
$previewMap = $chat->getLastPreviewMapForUser($meUserId);

// [NUOVO] lista thread aggiornata anche mentre ho una chat aperta
$threads = $chat->getThreadsForRole($meUserId, $meTipoPers);
foreach ($threads as &$t) {
    $tid = (int)($t['id_thread'] ?? 0);
    $t['unread_count'] = (int)($unreadMap[$tid] ?? 0);
}
unset($t);

return $this->response->setJSON([
    'ok' => true,
    'mode' => 'thread',
    'messages' => $new,
    'total_unread' => $totUnread,
    'unread_map' => $unreadMap,
    'last_preview_map' => $previewMap,
    'threads' => $threads, // <-- FONDAMENTALE
]);

    }
}
