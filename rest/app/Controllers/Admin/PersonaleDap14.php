<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\StaffDoctorLinkService;

class PersonaleDap14 extends BaseController
{
    private function currentAdminUser()
    {
        $me = session()->get('utente_sess');
        if (!$me || empty($me->id_user)) {
            return null;
        }

        return $me;
    }

    private function isAdminAuthorized(): bool
    {
        $me = $this->currentAdminUser();
        if (!$me) {
            return false;
        }

        return (int)(session()->get('admin') ?? 0) === 1
            || session()->get('is_admin') === true
            || (int)($me->tipo ?? 0) === 1;
    }

    private function ensureAdminPage()
    {
        if (!$this->currentAdminUser()) {
            return redirect()->to('/login');
        }

        if (!$this->isAdminAuthorized()) {
            return redirect()->to('/');
        }

        return null;
    }

    public function index()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $menuItems = session()->get('header_menu_items') ?? [];
        $result = session()->get('menuDataAdmin');
        if (!empty($result['result'])) {
            $menuItems = $result['result'];
        }

        $service = new StaffDoctorLinkService();
        $secretaries = $service->getSecretariesForSelect();
        $selectedSecretaryId = (int)($this->request->getGet('id_segretaria') ?? 0);
        $selectedSecretary = null;
        foreach ($secretaries as $secretary) {
            if ((int)$secretary['id_personale'] === $selectedSecretaryId) {
                $selectedSecretary = $secretary;
                break;
            }
        }

        $doctorRows = [];
        $doctorsWithoutLocation = [];
        $selectedCount = 0;
        $loadError = null;

        if ($selectedSecretaryId > 0) {
            if ($selectedSecretary === null) {
                $loadError = 'Segretaria non trovata.';
            } else {
                $doctorRows = $service->getDoctorsForDap14Grid($selectedSecretaryId);
                $doctorsWithoutLocation = array_values(array_filter(
                    $doctorRows,
                    static fn(array $row): bool => !empty($row['missing_location'])
                ));
                $selectedCount = count(array_filter(
                    $doctorRows,
                    static fn(array $row): bool => !empty($row['selected'])
                ));
            }
        }

        return view('admin/personale_dap14', [
            'menu_items' => $menuItems,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'loadError' => $loadError,
            'secretaries' => $secretaries,
            'selectedSecretaryId' => $selectedSecretaryId,
            'selectedSecretary' => $selectedSecretary,
            'doctorRows' => $doctorRows,
            'doctorsWithoutLocation' => $doctorsWithoutLocation,
            'selectedCount' => $selectedCount,
        ]);
    }

    public function update()
    {
        if ($guard = $this->ensureAdminPage()) {
            return $guard;
        }

        $service = new StaffDoctorLinkService();
        $secretaryId = (int)($this->request->getPost('id_segretaria') ?? 0);
        $redirectUrl = site_url('admin/personale/dap14') . '?id_segretaria=' . $secretaryId;

        if ($secretaryId <= 0) {
            return redirect()->to(site_url('admin/personale/dap14'))
                ->with('errors', ['generic' => 'Seleziona una segretaria valida.']);
        }

        $secretaries = $service->getSecretariesForSelect();
        $secretaryExists = false;
        foreach ($secretaries as $secretary) {
            if ((int)$secretary['id_personale'] === $secretaryId) {
                $secretaryExists = true;
                break;
            }
        }

        if (!$secretaryExists) {
            return redirect()->to(site_url('admin/personale/dap14'))
                ->with('errors', ['generic' => 'Segretaria non trovata.']);
        }

        $doctorIds = $this->request->getPost('doctor_ids');
        $doctorIds = is_array($doctorIds) ? $doctorIds : [];

        if (!$service->replaceSecretaryDoctorLinks($secretaryId, $doctorIds)) {
            return redirect()->to($redirectUrl)
                ->with('errors', ['generic' => 'Errore durante il salvataggio dei collegamenti segretaria-medici.']);
        }

        $linkedCount = count($service->getLinkedDoctorIdsForSecretary($secretaryId));

        return redirect()->to($redirectUrl)
            ->with('success', 'Collegamenti segretaria-medici aggiornati correttamente. Medici associati: ' . $linkedCount . '.');
    }
}
