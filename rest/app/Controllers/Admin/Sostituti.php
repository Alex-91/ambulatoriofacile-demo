<?php
namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\SostitutiModel;

class Sostituti extends BaseController
{
    public function index()
    {
        $menu_items = session()->get('header_menu_items') ?? [];
        $result = session()->get('menuDataAdmin');
        if (!empty($result['result'])) $menu_items = $result['result'];

        $model = new SostitutiModel();

        return view('admin/sostituti_index', [
            'menu_items' => $menu_items,
            'medici'     => $model->getMediciTipo1(),
            'rows'       => $model->listAllWithNames(),
            'success'    => session()->getFlashdata('success'),
            'errors'     => session()->getFlashdata('errors') ?? [],
        ]);
    }

    public function store()
    {
        $model = new SostitutiModel();

        $idDaSostituire = (int)$this->request->getPost('id_personale_da_sostituire');
        $idSostituto    = (int)$this->request->getPost('id_personale');
        $inizio         = trim((string)$this->request->getPost('data_inizio'));
        $fine           = trim((string)$this->request->getPost('data_fine'));

        // Validazioni base
        if ($idDaSostituire <= 0 || $idSostituto <= 0) {
            return redirect()->back()->with('errors', ['generic' => 'Seleziona medico da sostituire e sostituto.']);
        }
        if ($idDaSostituire === $idSostituto) {
            return redirect()->back()->with('errors', ['generic' => 'Il sostituto non può essere lo stesso medico.']);
        }

        if ($inizio === '' || $fine === '') {
            return redirect()->back()->with('errors', ['generic' => 'Data inizio e data fine sono obbligatorie.']);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inizio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fine)) {
            return redirect()->back()->with('errors', ['generic' => 'Formato date non valido (YYYY-MM-DD).']);
        }
        if ($fine < $inizio) {
            return redirect()->back()->with('errors', ['generic' => 'La data fine non può essere prima della data inizio.']);
        }

        // Blocca solo duplicati sovrapposti dello stesso medico+sostituto.
        if ($model->hasPairOverlap($idDaSostituire, $idSostituto, $inizio, $fine)) {
            return redirect()->back()->with('errors', ['generic' => 'Esiste gia una sostituzione sovrapposta per questo stesso medico e sostituto.']);
        }

        $ok = $model->insert([
            'id_personale'               => $idSostituto,
            'id_personale_da_sostituire' => $idDaSostituire,
            'data_inizio'                => $inizio,
            'data_fine'                  => $fine,
        ]);

        if ($ok) {
            return redirect()->to(site_url('admin/personale/sostituti'))
                ->with('success', 'Sostituzione inserita con successo.');
        }

        return redirect()->back()->with('errors', ['generic' => 'Errore inserimento sostituzione.']);
    }

    public function delete(int $idSost)
    {
        $model = new SostitutiModel();
        $model->delete($idSost);

        return redirect()->to(site_url('admin/personale/sostituti'))
            ->with('success', 'Sostituzione eliminata.');
    }
}
