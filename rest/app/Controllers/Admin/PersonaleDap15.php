<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\StaffDoctorLinkService;

class PersonaleDap15 extends BaseController
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
        $nurses = $service->getNursesForSelect();
        $selectedNurseId = (int)($this->request->getGet('id_infermiera') ?? 0);
        $selectedNurse = null;
        foreach ($nurses as $nurse) {
            if ((int)$nurse['id_personale'] === $selectedNurseId) {
                $selectedNurse = $nurse;
                break;
            }
        }

        $doctorRows = [];
        $doctorsWithoutLocation = [];
        $selectedCount = 0;
        $loadError = null;

        if ($selectedNurseId > 0) {
            if ($selectedNurse === null) {
                $loadError = 'Infermiera non trovata.';
            } else {
                $doctorRows = $service->getDoctorsForDap15Grid($selectedNurseId);
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

        return view('admin/personale_dap15', [
            'menu_items' => $menuItems,
            'success' => session()->getFlashdata('success'),
            'errors' => session()->getFlashdata('errors') ?? [],
            'loadError' => $loadError,
            'nurses' => $nurses,
            'selectedNurseId' => $selectedNurseId,
            'selectedNurse' => $selectedNurse,
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
        $nurseId = (int)($this->request->getPost('id_infermiera') ?? 0);
        $redirectUrl = site_url('admin/personale/dap15') . '?id_infermiera=' . $nurseId;

        if ($nurseId <= 0) {
            return redirect()->to(site_url('admin/personale/dap15'))
                ->with('errors', ['generic' => 'Seleziona una infermiera valida.']);
        }

        $nurses = $service->getNursesForSelect();
        $nurseExists = false;
        foreach ($nurses as $nurse) {
            if ((int)$nurse['id_personale'] === $nurseId) {
                $nurseExists = true;
                break;
            }
        }

        if (!$nurseExists) {
            return redirect()->to(site_url('admin/personale/dap15'))
                ->with('errors', ['generic' => 'Infermiera non trovata.']);
        }

        $doctorIds = $this->request->getPost('doctor_ids');
        $doctorIds = is_array($doctorIds) ? $doctorIds : [];

        if (!$service->replaceNurseDoctorLinks($nurseId, $doctorIds)) {
            return redirect()->to($redirectUrl)
                ->with('errors', ['generic' => 'Errore durante il salvataggio dei collegamenti infermiere-medici.']);
        }

        $linkedCount = count($service->getLinkedDoctorIdsForNurse($nurseId));

        return redirect()->to($redirectUrl)
            ->with('success', 'Collegamenti infermiere-medici aggiornati correttamente. Medici associati: ' . $linkedCount . '.');
    }
}
