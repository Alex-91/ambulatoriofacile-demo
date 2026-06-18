<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\DraftModel;

class DraftController extends BaseController
{
    /**
     * ✅ LISTA BOZZE (pagina tipo inbox)
     * URL: /bozze
     */
    public function index()
    {
        $utente = session()->get('utente_sess');
        if (!$utente) return redirect()->to(site_url('logout'));

        [$mitt, $id_mitt] = $this->resolveMitt($utente);

        $q       = (string)($this->request->getGet('q') ?? '');
        $page    = max(1, (int)($this->request->getGet('page') ?? 1));
        $perPage = 25;

        $draftModel = new DraftModel();
        $res = $draftModel->getDraftsLatest($id_mitt, $mitt, [
            'q' => $q,
        ], $perPage, $page);

        // stesse variabili della inbox view
        $data = [
            'messages'   => $res['rows'],
            'total'      => $res['total'],
            'page'       => $page,
            'perPage'    => $perPage,
            'hasPrev'    => $page > 1,
            'hasNext'    => ($page * $perPage) < $res['total'],
            'rangeStart' => $res['total'] ? (($page-1)*$perPage + 1) : 0,
            'rangeEnd'   => min($page*$perPage, $res['total']),
            'q'          => $q,

            // ✅ set folder drafts
            'folder'     => 'drafts',

            // compat per la view (segreteria/dottori)
            'requireDoctorSelection' => false,
            'selectedDoctorId'       => null,
        ];

        // riuso ESATTAMENTE la tua mailbox
        return view('posta', $data);
    }

    public function create()
    {
    $utente = session()->get('utente_sess');
        if (!$utente) return $this->response->setStatusCode(401)->setJSON(['ok'=>false]);

        [$mitt, $id_mitt] = $this->resolveMitt($utente);

        $payload = $this->request->getPost() ?? [];
        if (!$payload) $payload = $this->request->getJSON(true) ?? [];

        $sessid  = (string)($payload['sessid'] ?? session_id());

        $draftModel = new DraftModel();

        $id = $draftModel->createDraft([
            'id_mitt'   => $id_mitt,
            'mitt'      => $mitt,
            'id_dest'   => null,
            'dest'      => null,
            'oggetto'   => '',
            'testo'     => '',
            'seg_flag'  => (int)($payload['seg_flag'] ?? 0),
            'inf_flag'  => (int)($payload['inf_flag'] ?? 0),
            'dot_seg'   => (int)($payload['dot_seg'] ?? 0),
            'dot_inf'   => (int)($payload['dot_inf'] ?? 0),
            'da_dottore'=> (int)($payload['da_dottore'] ?? 1),
        ]);

        $draftModel->linkTempAttachmentsToDraft($sessid, $id);

        return $this->response->setJSON(['ok'=>true, 'draftId'=>$id]);
    }

    public function save()
    {
    $utente = session()->get('utente_sess');
        if (!$utente) return $this->response->setStatusCode(401)->setJSON(['ok'=>false]);

        [$mitt, $id_mitt] = $this->resolveMitt($utente);

        $payload = $this->request->getPost() ?? [];
        if (!$payload) $payload = $this->request->getJSON(true) ?? [];

        $draftId = (int)($payload['draftId'] ?? 0);
        if ($draftId <= 0) return $this->response->setStatusCode(400)->setJSON(['ok'=>false]);

        // qui salvo testo e meta (JSON) in inoltrato, come ti ho impostato in compose
        $testo = (string)($payload['testo'] ?? '');
        $meta  = (string)($payload['meta'] ?? '');

        $data = [
            'testo'     => $testo,
            'inoltrato' => $meta,  // ✅ usato come meta draft
        ];

        $draftModel = new DraftModel();
        $ok = $draftModel->saveDraft($draftId, $id_mitt, $mitt, $data);

        return $this->response->setJSON(['ok'=>$ok, 'draftId'=>$draftId]);
    }

    public function delete()
    {
    $utente = session()->get('utente_sess');
        if (!$utente) return $this->response->setStatusCode(401)->setJSON(['ok'=>false]);

        [$mitt, $id_mitt] = $this->resolveMitt($utente);

        $payload = $this->request->getPost() ?? [];
        if (!$payload) $payload = $this->request->getJSON(true) ?? [];

        $draftId = (int)($payload['draftId'] ?? 0);
        if ($draftId <= 0) return $this->response->setStatusCode(400)->setJSON(['ok'=>false]);

        $draftModel = new DraftModel();

        // ✅ ADATTA QUI il path allegati reali
        $fileDeleter = function(string $nome_real) {
            $base = WRITEPATH . 'uploads/'; // CAMBIA se diverso
            $path = (strpos($nome_real, DIRECTORY_SEPARATOR) !== false) ? $nome_real : ($base . $nome_real);
            if (is_file($path)) @unlink($path);
        };

        $ok = $draftModel->deleteDraftCascade($draftId, $id_mitt, $mitt, $fileDeleter);

        return $this->response->setJSON(['ok'=>$ok]);
    }

    public function get($draftId)
    {
    $utente = session()->get('utente_sess');
        if (!$utente) return $this->response->setStatusCode(401)->setJSON(['ok'=>false]);

        [$mitt, $id_mitt] = $this->resolveMitt($utente);

        $draftModel = new DraftModel();
        $draft = $draftModel->getDraft((int)$draftId, $id_mitt, $mitt);
        if (!$draft) return $this->response->setStatusCode(404)->setJSON(['ok'=>false]);

        return $this->response->setJSON(['ok'=>true, 'draft'=>$draft]);
    }

    private function resolveMitt($utente): array
    {
        if (($utente->tipo ?? null) == 3) return ['C', (int)($utente->id_client ?? 0)];
        if (($utente->tipo ?? null) == 2) return ['P', (int)($utente->id_personale ?? 0)];
        return ['P', (int)($utente->id_user ?? 0)];
    }
}
