<?php

namespace App\Controllers;

use App\Libraries\DatabaseConfig;
use App\Libraries\WhatsappAppointmentNote;
use App\Models\AgendaAppointmentModel;
use App\Models\AgendaBackupModel;
use App\Models\AgendaConfigModel;
use App\Models\AgendaJobModel;
use App\Models\AgendaLocationModel;
use App\Models\AgendaModel;
use App\Models\AgendaLockModel;
use App\Models\AgendaNoteModel;
use App\Models\AgendaSlotModel;
use App\Models\AgendaVisitTypeModel;
use App\Models\PazientiModel;
use App\Services\AgendaAppointmentNotificationService;
use App\Services\NotificationService;
use App\Services\SmsReminderDashboardService;
use App\Services\StaffDoctorAccessService;
use App\Services\TenantCatalogService;
use App\Services\TenantFeatureService;
use App\Services\TenantContextService;
use App\Services\TenantDatabaseConnector;
use App\Services\TenantRuntimeBindingService;
use Exception;

class Agenda extends BaseController
{
    private const BACKUP_PDF_MAX_ROWS = 1200;
    private const DEMO_DEFAULT_DATE = '2026-06-01';
    private const TEAM_DAY_VIEW_FEATURE = 'agenda_team_day_view';
    private const SHARED_AGENDA_MEMOS_FEATURE = 'shared_agenda_memos';
    private const VISIT_TYPES_FEATURE = 'agenda_visit_types';

    protected AgendaModel $agendaModel;
    protected AgendaSlotModel $slotModel;
    protected AgendaAppointmentModel $appointmentModel;
    protected AgendaLockModel $lockModel;
    protected AgendaNoteModel $noteModel;
    protected PazientiModel $pazientiModel;
    protected AgendaConfigModel $agendaConfigModel;
    protected AgendaBackupModel $agendaBackupModel;
    protected AgendaJobModel $agendaJobModel;
    protected AgendaLocationModel $locationModel;
    protected AgendaVisitTypeModel $visitTypeModel;
    protected NotificationService $notificationService;
    protected TenantContextService $tenantContextService;
    protected TenantFeatureService $tenantFeatureService;
    protected $db;
    protected ?array $tenantFeatureMap = null;
    protected ?array $runtimeTenantFeatureMap = null;

    public function __construct()
    {
        $this->agendaModel       = new AgendaModel();
        $this->slotModel         = new AgendaSlotModel();
        $this->appointmentModel  = new AgendaAppointmentModel();
        $this->lockModel         = new AgendaLockModel();
        $this->noteModel         = new AgendaNoteModel();
        $this->pazientiModel     = new PazientiModel();
        $this->agendaConfigModel = new AgendaConfigModel();
        $this->agendaBackupModel = new AgendaBackupModel();
        $this->agendaJobModel    = new AgendaJobModel();
        $this->locationModel     = new AgendaLocationModel();
        $this->visitTypeModel    = new AgendaVisitTypeModel();
        $this->notificationService = new NotificationService();
        $this->tenantContextService = new TenantContextService();
        $this->tenantFeatureService = new TenantFeatureService();

        $this->db = \Config\Database::connect();

           $this->dbConfig = new DatabaseConfig();
    $this->dbConfig->setEncryptionConfig($this->db);

        helper(['url', 'form']);
    }

    protected function getUserSession()
    {
        return session()->get('utente_sess');
    }
public function copiaAppuntamenti()
{
    $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

    $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

    if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
        $selectedDot = $this->getFirstVisibleDoctorId($medici);
    }

    return view('agenda/copia_appuntamenti', [
        'pageTitle'   => 'Copia appuntamenti',
        'medici'      => $medici,
        'selectedDot' => $selectedDot,
        'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
            ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
            : $this->agendaModel->getMenuVisible(),
        'menu_items'  => [],
    ]);
}

public function eseguiCopiaAppuntamenti()
{
    try {
        $payload = $this->request->getPost();

        $idDot      = (int)($payload['id_dot'] ?? 0);
        $data       = (string)($payload['data'] ?? '');
        $oraInizio  = (string)($payload['ora_inizio'] ?? '');
        $oraFine    = (string)($payload['ora_fine'] ?? '');
        $idPaziente = (int)($payload['id_paziente'] ?? 0);

        if ($idDot <= 0) {
            throw new \Exception('Medico non valido.');
        }

        if ($data === '') {
            throw new \Exception('Seleziona il giorno.');
        }

        if ($oraInizio === '' || $oraFine === '') {
            throw new \Exception('Seleziona ora inizio e ora fine.');
        }

        if ($idPaziente <= 0) {
            throw new \Exception('Seleziona il paziente.');
        }

        if ($oraInizio >= $oraFine) {
            throw new \Exception('L\'ora fine deve essere successiva all\'ora inizio.');
        }

        $this->assertDoctorAllowed($idDot);

        if ($this->agendaModel->isGiornoBloccato($idDot, $data)) {
            throw new \Exception('La giornata Ã¨ bloccata.');
        }

        $orariGiorno = $this->agendaModel->getOrariAgendaByDoctorAndDate($idDot, $data);
        if (empty($orariGiorno)) {
            throw new \Exception('Prima devi creare l\'agenda per questo giorno.');
        }

        $result = $this->agendaModel->copyPatientOnFreeSlots([
            'id_dot'      => $idDot,
            'data'        => $data,
            'ora_inizio'  => $oraInizio,
            'ora_fine'    => $oraFine,
            'id_paziente' => $idPaziente,
        ], $this->getCurrentUserId());

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'Appuntamenti creati correttamente.',
            'result'  => $result,
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}

public function menuRuoli()
{
    try {
        if (!$this->agendaModel->canManageMenuRoles($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per accedere a questa sezione.');
        }

        $ruoli = $this->agendaModel->getRuoliAgenda();
        $selectedRuo = (int)($this->request->getGet('id_ruo') ?? 0);

        if ($selectedRuo <= 0 && !empty($ruoli)) {
            $selectedRuo = (int)($ruoli[0]['id_ruo'] ?? 0);
        }

        return view('agenda/menu_ruoli', [
            'pageTitle'    => 'Permessi menu ruoli',
            'ruoli'        => $ruoli,
            'selectedRuo'  => $selectedRuo,
            'menuAgenda'   => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'   => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}


public function menuRuoliDati()
{
    try {
        if (!$this->agendaModel->canManageMenuRoles($this->getCurrentUserId())) {
            throw new \Exception('Non autorizzato1.');
        }

        $idRuo = (int)$this->request->getGet('id_ruo');
        if ($idRuo <= 0) {
            throw new \Exception('Ruolo non valido.');
        }

        $rows = $this->agendaModel->getAgendaMenuTreeWithRolePermissions($idRuo);

        return $this->response->setJSON([
            'status' => true,
            'rows'   => $rows,
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
            'rows'    => [],
        ]);
    }
}


public function salvaMenuRuoli()
{
    try {
        if (!$this->agendaModel->canManageMenuRoles($this->getCurrentUserId())) {
            throw new \Exception('Non autorizzato3.');
        }

        $idRuo = (int)$this->request->getPost('id_ruo');
        $idMenu = $this->request->getPost('id_menu');

        if ($idRuo <= 0) {
            throw new \Exception('Ruolo non valido.');
        }

        $idMenu = is_array($idMenu) ? $idMenu : [];

        $ok = $this->agendaModel->saveAgendaMenuPermissionsByRole($idRuo, $idMenu);

        if (!$ok) {
            throw new \Exception('Errore durante il salvataggio dei permessi.');
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'Permessi menu salvati correttamente.'
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage()
        ]);
    }
}

public function storicoMemo()
{
    try {
        $currentUserId = $this->getCurrentUserId();
        $lockToCurrentDoctor = false;
        $sharedMemoManagementEnabled = $this->isSharedAgendaMemosFeatureEnabled();

        if ($sharedMemoManagementEnabled) {
            $medici = $this->getMemoDoctorOptions($this->agendaModel->getMediciVisibili($currentUserId));
            $selectedDot = max(0, (int)($this->request->getGet('id_dot') ?? 0));

            if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($currentUserId, $selectedDot)) {
                $selectedDot = $this->getFirstVisibleDoctorId($medici);
            }
        } else {
            $medici = $this->agendaModel->getMediciVisibili($currentUserId);
            $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

            if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($currentUserId, $selectedDot)) {
                $selectedDot = $this->getFirstVisibleDoctorId($medici);
            }
        }

        $page = max(1, (int)($this->request->getGet('page') ?? 1));
        $perPage = 20;
        $searchTerm = trim((string)($this->request->getGet('search') ?? ''));

        $rows = [];
        $total = 0;
        $lastPage = 1;

        if ($sharedMemoManagementEnabled) {
            if ($selectedDot > 0) {
                $this->assertMemoDoctorAllowed($selectedDot);
                $result = $this->noteModel->getNoteFatteByDoctorPaginate($selectedDot, $perPage, $page, $searchTerm);
            } else {
                $result = $this->noteModel->getNoteFatteByDoctorsPaginate(
                    $this->getSharedAgendaMemoDoctorIds(),
                    $perPage,
                    $page,
                    $searchTerm
                );
            }

            $rows = $this->enrichMemoRowsForResponse($result['rows']);
            $total = $result['total'];
            $lastPage = $result['lastPage'];
            $page = min($result['page'], $lastPage);
        } elseif ($selectedDot > 0) {
            $this->assertDoctorAllowed($selectedDot);

            $result = $this->noteModel->getNoteFatteByDoctorPaginate($selectedDot, $perPage, $page, $searchTerm);
            $rows = $result['rows'];
            $total = $result['total'];
            $lastPage = $result['lastPage'];
            $page = min($result['page'], $lastPage);
        }

        return view('agenda/storico_memo', [
            'pageTitle'   => 'Storico memo',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'lockToCurrentDoctor' => $lockToCurrentDoctor,
            'searchTerm'  => $searchTerm,
            'rows'        => $rows,
            'page'        => $page,
            'perPage'     => $perPage,
            'total'       => $total,
            'lastPage'    => $lastPage,
            'sharedMemoManagementEnabled' => $sharedMemoManagementEnabled,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($currentUserId)
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function gestioneSlotExtra()
{
    try {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/gestione_slot_extra', [
            'pageTitle'   => 'Gestione slot extra',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'locationCatalog' => $this->locationModel->getCatalog(true),
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function repairRecurringExtraSlots()
{
    try {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/repair_recurring_extra_slots', [
            'pageTitle'   => 'Repair slot extra ricorrenti',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'defaultSourceDb' => 'farmacia',
            'defaultDateFrom' => date('Y-m-d'),
            'defaultDateTo' => date('Y-m-d', strtotime('+18 months')),
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function eseguiSlotExtraPeriodo()
{
    try {
        $payload = $this->request->getPost();

        $idDot = (int)($payload['id_dot'] ?? 0);
        $this->assertDoctorAllowed($idDot);

        $result = $this->noteModel->insertExtraSlotsInPeriod($payload, $this->getCurrentUserId());

        $msg = 'Operazione completata. Inseriti ' . (int)$result['inserted'] . ' slot extra';

        if (!empty($result['collisioni'])) {
            $msg .= '. Slot gia presenti saltati: ' . count($result['collisioni']);
        }

        if (!empty($result['giorni_bloccati'])) {
            $msg .= '. Giorni bloccati: ' . count($result['giorni_bloccati']);
        }

        if (!empty($result['giorni_senza_config'])) {
            $msg .= '. Giorni senza configurazione valida: ' . count($result['giorni_senza_config']);
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => $msg,
            'result'  => $result,
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function eseguiRepairRecurringExtraSlots()
{
    try {
        $payload = $this->request->getPost();
        $idDot = (int)($payload['id_dot'] ?? 0);
        $this->assertDoctorAllowed($idDot);

        $summary = $this->runRecurringExtraSlotRepair($payload);

        return $this->respondJsonSafe([
            'status' => true,
            'message' => !empty($payload['apply'])
                ? 'Repair applicato con successo.'
                : 'Dry-run completato con successo.',
            'result' => $summary,
        ]);
    } catch (\Throwable $e) {
        return $this->respondJsonSafe([
            'status' => false,
            'message' => $e->getMessage(),
        ], 400);
    }
}

    public function orariGiornoCopia()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $data  = (string)$this->request->getGet('data');

        if ($idDot <= 0 || $data === '') {
            throw new \Exception('Parametri mancanti.');
        }

        $this->assertDoctorAllowed($idDot);

        $rows = $this->agendaModel->getOrariAgendaByDoctorAndDate($idDot, $data);

        return $this->respondJsonSafe([
            'status'        => true,
            'rows'          => $rows,
            'has_agenda'    => !empty($rows),
            'message'       => empty($rows) ? 'Prima devi creare l\'agenda per questo giorno.' : ''
        ]);
    } catch (\Exception $e) {
        return $this->respondJsonSafe([
            'status'  => false,
            'message' => $e->getMessage(),
            'rows'    => [],
            'has_agenda' => false
        ]);
    }
}

    protected function runRecurringExtraSlotRepair(array $payload): array
    {
        $sourceDb = trim((string)($payload['source_db'] ?? 'farmacia'));
        $dateFrom = trim((string)($payload['date_from'] ?? ''));
        $dateTo = trim((string)($payload['date_to'] ?? ''));
        $idDot = (int)($payload['id_dot'] ?? 0);

        if ($sourceDb === '') {
            throw new \Exception('Database sorgente non valido.');
        }

        if ($dateFrom === '' || $dateTo === '') {
            throw new \Exception('Intervallo date obbligatorio.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new \Exception('Le date devono essere nel formato YYYY-MM-DD.');
        }

        if ($dateFrom > $dateTo) {
            throw new \Exception('La data fine deve essere uguale o successiva alla data inizio.');
        }

        $mysqli = $this->db->connID ?? null;
        if (!$mysqli instanceof \mysqli) {
            throw new \Exception('Connessione database non disponibile per il repair.');
        }

        require_once ROOTPATH . 'repair_legacy_recurring_extra_slots.php';

        $targetDb = (string)($this->db->database ?? '');
        if ($targetDb === '') {
            $targetDb = 'mail';
        }

        $repair = new \LegacyRecurringExtraSlotRepair($mysqli, $sourceDb, $targetDb, [
            'apply' => !empty($payload['apply']),
            'doctors' => [$idDot],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return $repair->run();
    }

    protected function getCurrentUserId(): int
    {
        $user = $this->getUserSession();

        if (isset($user->id_user) && (int)$user->id_user > 0) {
            return (int)$user->id_user;
        }

        $sessionUserId = (int)(session()->get('userId') ?? 0);
        if ($sessionUserId > 0) {
            return $sessionUserId;
        }

        $username = (string)(session()->get('username') ?? '');
        if ($username !== '') {
            $row = $this->db->table('dap01_users')
                ->select('id_user')
                ->where('username', $username)
                ->get()
                ->getRowArray();

            $idUser = (int)($row['id_user'] ?? 0);
            if ($idUser > 0) {
                return $idUser;
            }
        }

        return (int)($user->id_user ?? 0);
    }

    protected function notifyBookedAppointmentIfNeeded(int $appointmentId, int $targetLegacyIdDot): void
    {
        if ($appointmentId <= 0 || $targetLegacyIdDot <= 0) {
            return;
        }

        $sessionUser = $this->getUserSession();
        $actorTipoPers = is_object($sessionUser) ? (int) ($sessionUser->tipo_pers ?? 0) : 0;
        $actorIsDoctor = $actorTipoPers === StaffDoctorAccessService::TIPO_DOTTORE;
        $actorUserId = $this->getCurrentUserId();
        if ($actorUserId <= 0) {
            return;
        }

        $actorLegacyIdDot = $actorIsDoctor
            ? $this->agendaModel->getIdDotByOperatore($actorUserId)
            : 0;

        try {
            (new AgendaAppointmentNotificationService($this->db))
                ->handleBookedAppointment($appointmentId, $targetLegacyIdDot, $actorUserId, $actorLegacyIdDot, $actorIsDoctor);
        } catch (\Throwable $e) {
            log_message('warning', '[Agenda] Notifiche appuntamento fallite: {message}', [
                'message' => $e->getMessage(),
                'appointment_id' => $appointmentId,
                'actor_user_id' => $actorUserId,
                'actor_id_dot' => $actorLegacyIdDot,
                'target_id_dot' => $targetLegacyIdDot,
            ]);
        }
    }

    protected function getDefaultAgendaDate(): string
    {
        $selectedDate = trim((string)($this->request->getGet('data') ?? ''));
        if ($selectedDate !== '') {
            return $selectedDate;
        }

        $username = strtolower(trim((string)(session()->get('username') ?? '')));
        if ($username !== '' && strpos($username, 'demo.') === 0) {
            return self::DEMO_DEFAULT_DATE;
        }

        return date('Y-m-d');
    }

    protected function isTeamDayViewFeatureEnabled(): bool
    {
        return $this->tenantFeatureEnabled(self::TEAM_DAY_VIEW_FEATURE);
    }

    protected function canUseTeamDayView(array $medici): bool
    {
        return $this->isTeamDayViewFeatureEnabled() && count($medici) > 1;
    }

    protected function getTeamDayDoctorsForCurrentUser(): array
    {
        $mediciVisibili = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());
        if (!$this->isTeamDayViewFeatureEnabled()) {
            return $mediciVisibili;
        }

        $teamDoctors = $this->agendaModel->getAllAgendaProfessionals();
        if (count($teamDoctors) > 1) {
            return $teamDoctors;
        }

        return $mediciVisibili;
    }

    protected function doctorListContainsId(array $medici, int $idDot): bool
    {
        if ($idDot <= 0) {
            return false;
        }

        foreach ($medici as $medico) {
            $currentId = (int) (is_object($medico)
                ? ($medico->id_dot ?? 0)
                : ($medico['id_dot'] ?? 0));

            if ($currentId === $idDot) {
                return true;
            }
        }

        return false;
    }

    protected function isSharedAgendaMemosFeatureEnabled(): bool
    {
        return $this->tenantFeatureEnabled(self::SHARED_AGENDA_MEMOS_FEATURE);
    }

    protected function isVisitTypesFeatureEnabled(): bool
    {
        return $this->tenantFeatureEnabled(self::VISIT_TYPES_FEATURE);
    }

    protected function assertVisitTypesFeatureEnabled(): void
    {
        if (!$this->isVisitTypesFeatureEnabled()) {
            throw new \Exception('La gestione dei tipi visita non e attiva per questo studio.');
        }
    }

    protected function tenantFeatureEnabled(string $featureKey): bool
    {
        $featureKey = trim($featureKey);
        if ($featureKey === '') {
            return false;
        }

        $context = $this->tenantContextService->getCurrentTenant();
        if ($context === null) {
            $runtimeFeatureMap = $this->getRuntimeTenantFeatureMap();
            return array_key_exists($featureKey, $runtimeFeatureMap)
                ? (bool) $runtimeFeatureMap[$featureKey]
                : false;
        }

        $featureMap = $this->getTenantFeatureMap($context->tenantId);
        if (array_key_exists($featureKey, $featureMap)) {
            return (bool) $featureMap[$featureKey];
        }

        return $context->allows($featureKey);
    }

    /**
     * @return array<string, bool>
     */
    protected function getRuntimeTenantFeatureMap(): array
    {
        if ($this->runtimeTenantFeatureMap === null) {
            try {
                $this->runtimeTenantFeatureMap = (new TenantCatalogService())->resolveFeatureMapForCurrentRuntimeTenant();
            } catch (\Throwable $e) {
                log_message('error', 'Agenda::getRuntimeTenantFeatureMap failed: {message}', [
                    'message' => $e->getMessage(),
                ]);
                $this->runtimeTenantFeatureMap = [];
            }
        }

        return $this->runtimeTenantFeatureMap;
    }

    /**
     * @return array<string, bool>
     */
    protected function getTenantFeatureMap(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        if ($this->tenantFeatureMap === null) {
            $this->tenantFeatureMap = $this->tenantFeatureService->resolveEffectiveFeatureMapForTenant($tenantId);
        }

        return $this->tenantFeatureMap;
    }

    /**
     * @return array<int, int>
     */
    protected function getSharedAgendaMemoDoctorIds(): array
    {
        $ids = [];

        foreach ($this->getSharedAgendaMemoDoctors() as $doctor) {
            $idDot = (int) ($doctor['id_dot'] ?? 0);
            if ($idDot > 0) {
                $ids[] = $idDot;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getSharedAgendaMemoDoctors(array $visibleDoctors = []): array
    {
        $allDoctors = $this->agendaModel->getAllAgendaProfessionals();
        $currentUserId = $this->getCurrentUserId();

        if ($currentUserId <= 0) {
            return $allDoctors;
        }

        $filtered = [];
        foreach ($allDoctors as $doctor) {
            $doctorRow = is_object($doctor) ? get_object_vars($doctor) : (array) $doctor;
            $idDot = (int) ($doctorRow['id_dot'] ?? 0);
            if ($idDot <= 0 || !$this->agendaModel->canUserAccessDoctor($currentUserId, $idDot)) {
                continue;
            }

            $filtered[] = $doctorRow;
        }

        if ($filtered !== []) {
            return $filtered;
        }

        return $visibleDoctors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getMemoDoctorOptions(array $visibleDoctors): array
    {
        if ($this->isSharedAgendaMemosFeatureEnabled()) {
            return $this->getSharedAgendaMemoDoctors($visibleDoctors);
        }

        return $visibleDoctors;
    }

    protected function assertMemoDoctorAllowed(int $idDot): void
    {
        if ($idDot <= 0) {
            throw new \Exception('Medico non valido.');
        }

        if (!$this->isSharedAgendaMemosFeatureEnabled()) {
            $this->assertDoctorAllowed($idDot);
            return;
        }

        if (!$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $idDot)) {
            throw new \Exception('Medico non valido.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function enrichMemoRowsForResponse(array $rows, string $agendaData = ''): array
    {
        if ($rows === []) {
            return [];
        }

        $doctorMap = $this->agendaModel->getAgendaProfessionalMapByLegacyIds(array_map(
            static fn(array $row): int => (int) ($row['id_dot'] ?? 0),
            $rows
        ));

        foreach ($rows as &$row) {
            $idDot = (int) ($row['id_dot'] ?? 0);
            $doctor = $doctorMap[$idDot] ?? null;

            $row['doctor_label'] = trim((string) ($doctor['label'] ?? ''));
            $row['memo_action_blocked'] = $agendaData !== '' && $idDot > 0
                ? $this->agendaModel->isMemoGiornoBloccato($idDot, $agendaData)
                : false;
        }
        unset($row);

        return $rows;
    }

    protected function normalizeAgendaViewMode(string $viewMode, bool $teamDayEnabled): string
    {
        $viewMode = trim(strtolower($viewMode));

        if ($viewMode === 'week') {
            return 'week';
        }

        if ($viewMode === 'team_day' && $teamDayEnabled) {
            return 'team_day';
        }

        return 'day';
    }

    /**
     * @param object|array<string, mixed> $medico
     * @return array<string, mixed>
     */
    protected function normalizeAgendaProfessionalRow($medico): array
    {
        $row = is_object($medico) ? get_object_vars($medico) : (array)$medico;
        $label = trim((string)($row['label'] ?? ''));

        if ($label === '') {
            $label = trim((string)($row['cognome'] ?? '') . ' ' . (string)($row['nome'] ?? ''));
        }

        return [
            'id_dot' => (int)($row['id_dot'] ?? 0),
            'label' => $label,
            'tipo' => (int)($row['tipo'] ?? 0),
            'f_dom' => (int)($row['f_dom'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $doctor
     * @return array<string, mixed>
     */
    protected function buildTeamDayColumnPayload(array $doctor, string $data, bool $isSelected): array
    {
        $idDot = (int)($doctor['id_dot'] ?? 0);
        $slots = $idDot > 0 ? $this->slotModel->getSlotsCalendario($idDot, $data, 'day') : [];
        $hasSlots = !empty($slots);
        $message = '';

        if (!$hasSlots && $idDot > 0) {
            $message = $this->agendaConfigModel->getNoAgendaMessageForDate($idDot, $data);
        }

        return [
            'id_dot' => $idDot,
            'label' => (string)($doctor['label'] ?? ''),
            'tipo' => (int)($doctor['tipo'] ?? 0),
            'f_dom' => (int)($doctor['f_dom'] ?? 0),
            'is_selected' => $isSelected,
            'has_slots' => $hasSlots,
            'slots' => $slots,
            'message' => $message,
            'giorno_bloccato' => $idDot > 0 ? $this->agendaModel->isGiornoBloccato($idDot, $data) : false,
        ];
    }

    protected function assertDoctorAllowed(int $idDot): void
    {
        if ($idDot <= 0) {
            throw new \Exception('Medico non valido.');
        }

        if (!$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $idDot)) {
            throw new \Exception('Non autorizzato2.');
        }
    }

    protected function normalizeAppointmentPatientPayload(array $payload): array
    {
        if (array_key_exists('cognome', $payload)) {
            $payload['cognome'] = $this->normalizeAppointmentPatientName((string)$payload['cognome']);
        }

        if (array_key_exists('nome', $payload)) {
            $payload['nome'] = $this->normalizeAppointmentPatientName((string)$payload['nome']);
        }

        return $payload;
    }

    protected function normalizeAppointmentPatientName(string $value): string
    {
        $value = trim((string)(preg_replace('/\s+/', ' ', $value) ?? ''));
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    protected function respondJsonSafe(array $payload, int $statusCode = 200)
    {
        $body = json_encode(
            $this->sanitizeJsonValue($payload),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($body === false) {
            $statusCode = 500;
            $body = json_encode([
                'status'  => false,
                'message' => 'Errore di codifica della risposta JSON.',
            ], JSON_UNESCAPED_UNICODE);
        }

        return $this->response
            ->setStatusCode($statusCode)
            ->setContentType('application/json; charset=UTF-8')
            ->setBody($body ?: '{}');
    }

    protected function sanitizeJsonValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeJsonValue($item);
            }

            return $value;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $encoding = mb_detect_encoding($value, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);

            if ($encoding !== false) {
                return mb_convert_encoding($value, 'UTF-8', $encoding);
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $value);

            if ($converted !== false) {
                return $converted;
            }
        }

        return utf8_encode($value);
    }

    protected function buildCalendarioErrorContext(
        int $idDot,
        string $data,
        string $view,
        string $step,
        \Throwable $e
    ): array {
        $range = $this->getCalendarioRangeSafe($data, $view);
        $sessionUser = $this->getUserSession();
        $resolvedUserId = $this->getCurrentUserIdSafe();

        return [
            'step' => $step,
            'request' => [
                'id_dot' => $idDot,
                'data' => $data,
                'view' => $view,
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'query_string' => $_SERVER['QUERY_STRING'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            ],
            'session' => [
                'username' => (string)(session()->get('username') ?? ''),
                'resolved_user_id' => $resolvedUserId,
                'id_user' => (int)($sessionUser->id_user ?? 0),
                'id_ope' => (int)($sessionUser->id_ope ?? 0),
            ],
            'environment' => [
                'app_env' => ENVIRONMENT,
                'db_name' => $this->db->database ?? '',
            ],
            'agenda_config_model' => [
                'class' => get_class($this->agendaConfigModel),
                'has_date_method' => method_exists($this->agendaConfigModel, 'getNoAgendaMessageForDate'),
                'has_range_method' => method_exists($this->agendaConfigModel, 'getNoAgendaMessageForRange'),
            ],
            'doctor_access' => $this->getDoctorAccessDebugInfo($resolvedUserId, $idDot),
            'range' => $range,
            'db_snapshot' => $this->getCalendarioDbSnapshot($idDot, $data, $range['start'], $range['end']),
            'last_query' => $this->getLastQueryString(),
            'exception' => [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->truncateLogValue($e->getTraceAsString(), 4000),
            ],
        ];
    }

    protected function getCalendarioRangeSafe(string $data, string $view): array
    {
        try {
            if ($view === 'week') {
                $start = new \DateTime($data);
                $weekday = (int)$start->format('N');
                $start->modify('-' . ($weekday - 1) . ' days');

                $end = clone $start;
                $end->modify('+6 days');

                return [
                    'start' => $start->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ];
            }

            return [
                'start' => $data,
                'end' => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'start' => $data,
                'end' => $data,
                'range_error' => $e->getMessage(),
            ];
        }
    }

    protected function getCurrentUserIdSafe(): int
    {
        try {
            return $this->getCurrentUserId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function getDoctorAccessDebugInfo(int $userId, int $idDot): array
    {
        try {
            return [
                'user_id' => $userId,
                'id_dot' => $idDot,
                'allowed' => $idDot > 0 && $userId > 0
                    ? $this->agendaModel->canUserAccessDoctor($userId, $idDot)
                    : false,
            ];
        } catch (\Throwable $e) {
            return [
                'user_id' => $userId,
                'id_dot' => $idDot,
                'allowed' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getCalendarioDbSnapshot(int $idDot, string $data, string $rangeStart, string $rangeEnd): array
    {
        $snapshot = [
            'raw_slot_count' => null,
            'active_config_overlap_count' => null,
            'matching_config' => null,
            'matching_giorno' => null,
            'next_config_start' => null,
            'last_config_end' => null,
            'errors' => [],
        ];

        try {
            $snapshot['raw_slot_count'] = $this->db->table('dap11_agenda_slot')
                ->where('id_dot', $idDot)
                ->where('data_slot >=', $rangeStart)
                ->where('data_slot <=', $rangeEnd)
                ->countAllResults();
        } catch (\Throwable $e) {
            $snapshot['errors']['raw_slot_count'] = $e->getMessage();
        }

        try {
            $snapshot['active_config_overlap_count'] = $this->db->table('dap10_agenda_config')
                ->where('id_dot', $idDot)
                ->where('attiva', 1)
                ->where('data_inizio <=', $rangeEnd)
                ->groupStart()
                    ->where('data_fine >=', $rangeStart)
                    ->orWhere('data_fine', null)
                ->groupEnd()
                ->countAllResults();
        } catch (\Throwable $e) {
            $snapshot['errors']['active_config_overlap_count'] = $e->getMessage();
        }

        try {
            $config = $this->db->table('dap10_agenda_config')
                ->select('id_config, data_inizio, data_fine, attiva')
                ->where('id_dot', $idDot)
                ->where('attiva', 1)
                ->where('data_inizio <=', $data)
                ->groupStart()
                    ->where('data_fine >=', $data)
                    ->orWhere('data_fine', null)
                ->groupEnd()
                ->orderBy('id_config', 'DESC')
                ->get()
                ->getRowArray();

            if (!empty($config)) {
                $snapshot['matching_config'] = $config;

                $giornoSettimana = (int)date('N', strtotime($data));
                $snapshot['matching_giorno'] = $this->db->table('dap10_agenda_config_giorni')
                    ->select('id_config_giorno, id_config, giorno_settimana, giorno_libero, mattina_attiva, pomeriggio_attiva, mattina_ora_inizio, mattina_ora_fine, pomeriggio_ora_inizio, pomeriggio_ora_fine')
                    ->where('id_config', (int)$config['id_config'])
                    ->where('giorno_settimana', $giornoSettimana)
                    ->get()
                    ->getRowArray();

                if (!empty($snapshot['matching_giorno']['id_config_giorno']) && $this->db->tableExists('dap10_agenda_config_fasce')) {
                    $snapshot['matching_fasce'] = $this->db->table('dap10_agenda_config_fasce')
                        ->select('id_config_fascia, ordine, ora_inizio, ora_fine, durata_slot')
                        ->where('id_config_giorno', (int)$snapshot['matching_giorno']['id_config_giorno'])
                        ->orderBy('ordine', 'ASC')
                        ->get()
                        ->getResultArray();
                }
            }
        } catch (\Throwable $e) {
            $snapshot['errors']['matching_config'] = $e->getMessage();
        }

        try {
            $nextConfig = $this->db->table('dap10_agenda_config')
                ->select('data_inizio')
                ->where('id_dot', $idDot)
                ->where('attiva', 1)
                ->where('data_inizio >', $data)
                ->orderBy('data_inizio', 'ASC')
                ->get()
                ->getRowArray();

            $snapshot['next_config_start'] = $nextConfig['data_inizio'] ?? null;
        } catch (\Throwable $e) {
            $snapshot['errors']['next_config_start'] = $e->getMessage();
        }

        try {
            $lastConfig = $this->db->table('dap10_agenda_config')
                ->select('data_fine')
                ->where('id_dot', $idDot)
                ->where('attiva', 1)
                ->where('data_fine <', $data)
                ->orderBy('data_fine', 'DESC')
                ->get()
                ->getRowArray();

            $snapshot['last_config_end'] = $lastConfig['data_fine'] ?? null;
        } catch (\Throwable $e) {
            $snapshot['errors']['last_config_end'] = $e->getMessage();
        }

        return $snapshot;
    }

    protected function getLastQueryString(): string
    {
        try {
            $lastQuery = $this->db->getLastQuery();
            if ($lastQuery === null) {
                return '';
            }

            return $this->truncateLogValue((string)$lastQuery, 2000);
        } catch (\Throwable $e) {
            return 'last_query_unavailable: ' . $e->getMessage();
        }
    }

    protected function encodeLogContext(array $context): string
    {
        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            return '[json_encode_failed]';
        }

        return $json;
    }

    protected function respondCalendarioJson(array $payload, int $statusCode = 200)
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $statusCode = 500;
            $json = '{"status":false,"has_slots":false,"slots":[],"grid_duration":15,"min_time":null,"max_time":null,"message":"Errore durante la serializzazione della risposta agenda.","debug_message":""}';
        }

        return $this->response
            ->setStatusCode($statusCode)
            ->setContentType('application/json')
            ->setBody($json);
    }

    protected function truncateLogValue(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength) . '... [truncated]';
    }

    protected function assertVisibilityManager(): void
    {
        if (!$this->agendaModel->canManageVisibility($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per gestire la visibilita operatori.');
        }
    }

    protected function getIdDotFromSlot(int $idSlot): int
    {
        $row = $this->db->table('dap11_agenda_slot')
            ->select('id_dot')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        return (int)($row['id_dot'] ?? 0);
    }

    protected function getSlotRow(int $idSlot): array
    {
        $row = $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        return $row ?: [];
    }

    protected function getIdDotFromNota(int $idNota): int
    {
        $row = $this->db->table('dap15_agenda_note')
            ->select('id_dot')
            ->where('id_nota', $idNota)
            ->get()
            ->getRowArray();

        return (int)($row['id_dot'] ?? 0);
    }

    protected function getIdDotFromNotaGiorno(int $idNotaGiorno): int
    {
        $row = $this->db->table('dap23_agenda_nota_giorno')
            ->select('id_dot')
            ->where('id_nota_giorno', $idNotaGiorno)
            ->get()
            ->getRowArray();

        return (int)($row['id_dot'] ?? 0);
    }

    protected function getIdDotFromAppuntamento(int $idAppuntamento): int
    {
        $row = $this->db->table('dap12_agenda_appuntamenti a')
            ->select('s.id_dot')
            ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
            ->where('a.id_appuntamento', $idAppuntamento)
            ->get()
            ->getRowArray();

        return (int)($row['id_dot'] ?? 0);
    }

    protected function isDomiciliareAbilitatoPerDottore(array $medici, int $selectedDot): bool
    {
        foreach ($medici as $m) {
            $idDot = is_object($m) ? (int)($m->id_dot ?? 0) : (int)($m['id_dot'] ?? 0);

            if ($idDot === $selectedDot) {
                $flag = is_object($m)
                    ? (int)($m->f_dom ?? 0)
                    : (int)($m['f_dom'] ?? 0);

                return $flag === 1;
            }
        }

        return false;
    }

    protected function assertDomiciliariAbilitatiPerDottore(int $idDot): void
    {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        if (!$this->isDomiciliareAbilitatoPerDottore($medici, $idDot)) {
            throw new \Exception('Le visite domiciliari non sono abilitate per il dottore selezionato.');
        }
    }

    protected function getFirstVisibleDoctorId(array $medici): int
    {
        $currentUserId = $this->getCurrentUserIdSafe();
        if ($currentUserId > 0) {
            $currentUserDotId = (int)$this->agendaModel->getIdDotByOperatore($currentUserId);

            if ($currentUserDotId > 0) {
                foreach ($medici as $medico) {
                    $idDot = (int)(is_object($medico)
                        ? ($medico->id_dot ?? 0)
                        : ($medico['id_dot'] ?? 0));

                    if ($idDot === $currentUserDotId) {
                        return $idDot;
                    }
                }
            }
        }

        if (!isset($medici[0])) {
            return 0;
        }

        return (int)(is_object($medici[0])
            ? ($medici[0]->id_dot ?? 0)
            : ($medici[0]['id_dot'] ?? 0));
    }

    public function index()
    {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());
        $teamDayDoctors = $this->getTeamDayDoctorsForCurrentUser();
        $teamDayViewEnabled = $this->canUseTeamDayView($teamDayDoctors);
        $visitTypesFeatureEnabled = $this->isVisitTypesFeatureEnabled();
        $visitTypes = [];

        if ($visitTypesFeatureEnabled) {
            try {
                $visitTypes = $this->visitTypeModel->listForAgenda();
            } catch (\Throwable $e) {
                log_message('error', 'Agenda::index visit types bootstrap failed: {message}', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        $domiciliariAbilitati = $this->isDomiciliareAbilitatoPerDottore($medici, $selectedDot);

        $data = [
            'pageTitle'            => 'Agenda',
            'today'                => date('Y-m-d'),
            'selectedDate'         => $this->getDefaultAgendaDate(),
            'viewMode'             => $this->normalizeAgendaViewMode((string)($this->request->getGet('view') ?? 'day'), $teamDayViewEnabled),
            'medici'               => $medici,
            'memoDoctorOptions'    => $this->getMemoDoctorOptions($medici),
            'selectedDot'          => $selectedDot,
            'teamDayViewEnabled'   => $teamDayViewEnabled,
            'sharedMemoManagementEnabled' => $this->isSharedAgendaMemosFeatureEnabled(),
            'visitTypesFeatureEnabled' => $visitTypesFeatureEnabled,
            'visitTypes'           => $visitTypes,
            'domiciliariAbilitati' => $domiciliariAbilitati,
            'locationCatalog'      => $this->locationModel->getCatalog(true),
            'menuAgenda'           => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'           => [],
        ];

        return view('agenda/index', $data);
    }

    public function gestioneTipiVisita()
    {
        try {
            $this->assertVisitTypesFeatureEnabled();

            return view('agenda/tipi_visita', [
                'pageTitle' => 'Tipi visita',
                'visitTypesFeatureEnabled' => true,
                'visitTypes' => $this->visitTypeModel->listForAgenda(),
                'menuAgenda' => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                    ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                    : $this->agendaModel->getMenuVisible(),
                'menu_items' => [],
            ]);
        } catch (\Throwable $e) {
            return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
        }
    }

    public function configSlot()
    {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        $config = $selectedDot ? $this->agendaConfigModel->getUltimaConfigByDoctor($selectedDot) : null;

        return view('agenda/config_slot', [
            'pageTitle'   => 'Configurazione slot',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'config'      => $config,
            'locationCatalog' => $this->locationModel->getCatalog(true),
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    }

    public function visibilitaOperatori()
    {
        try {
            $this->assertVisibilityManager();

            $operatori = $this->agendaModel->getOperatoriGestibiliPerVisibilita();
            $selectedOpe = (int)$this->request->getGet('id_ope');

            if ($selectedOpe <= 0 && !empty($operatori)) {
                $selectedOpe = (int)($operatori[0]['id_ope'] ?? 0);
            }

            $opeIds = array_map(static fn($o) => (int)($o['id_ope'] ?? 0), $operatori);
            if ($selectedOpe > 0 && !in_array($selectedOpe, $opeIds, true)) {
                $selectedOpe = !empty($opeIds) ? (int)$opeIds[0] : 0;
            }

            $targets = $this->agendaModel->getDottoriInfermieriAgenda();
            $assegnati = $selectedOpe > 0
                ? $this->agendaModel->getDotIdsAssegnatiAOpe($selectedOpe)
                : [];

            return view('agenda/visibilita_operatori', [
                'pageTitle'    => 'Visibilita operatori',
                'operatori'    => $operatori,
                'targets'      => $targets,
                'assegnati'    => $assegnati,
                'selectedOpe'  => $selectedOpe,
                'menuAgenda'   => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                    ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                    : $this->agendaModel->getMenuVisible(),
                'menu_items'   => [],
            ]);
        } catch (\Exception $e) {
            return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
        }
    }

    public function salvaVisibilitaOperatori()
    {
        try {
            $this->assertVisibilityManager();

            $idOpe = (int)$this->request->getPost('id_ope');
            $idDots = $this->request->getPost('id_dot');

            if ($idOpe <= 0) {
                throw new \Exception('Operatore non valido.');
            }

            if (!$this->agendaModel->isOperatoreGestibilePerVisibilita($idOpe)) {
                throw new \Exception('Operatore non autorizzabile.');
            }

            $idDots = is_array($idDots) ? $idDots : [];

            $ok = $this->agendaModel->salvaVisibilitaOperatore($idOpe, $idDots);
            if (!$ok) {
                throw new \Exception('Errore durante il salvataggio.');
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Visibilita aggiornata correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function salvaConfigSlot()
    {
        try {
            $payload = $this->request->getPost();
            $idDot   = (int)($payload['id_dot'] ?? 0);

            $this->assertDoctorAllowed($idDot);

            $idConfig = $this->agendaConfigModel->saveConfig($payload, $this->getCurrentUserId());

            return $this->response->setJSON([
                'status'    => true,
                'id_config' => $idConfig,
                'message'   => 'Configurazione salvata correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function rigeneraSlotConfig()
    {
        try {
            $payload = $this->request->getPost();
            $idDot = (int)($payload['id_dot'] ?? 0);

            $this->assertDoctorAllowed($idDot);

            $activeJob = $this->normalizePossiblyStaleAgendaJob(
                $this->agendaJobModel->findActiveRigeneraJobByDoctor($idDot)
            );
            if ($this->isAgendaJobActive($activeJob)) {
                $this->dispatchAgendaJobIfNeeded($activeJob);

                return $this->respondJsonSafe([
                    'status'              => true,
                    'job_already_running' => true,
                    'job'                 => $this->buildAgendaJobStatusPayload($activeJob),
                    'message'             => 'Per questo professionista c\'e gia una rigenerazione agenda in corso.',
                ]);
            }

            $requestedBy = $this->getCurrentUserId();
            $idConfig = $this->agendaConfigModel->saveConfig($payload, $requestedBy);
            $config   = $this->agendaConfigModel->getConfigCompleta($idConfig);
            if (!$config) {
                throw new \Exception('Configurazione non trovata dopo il salvataggio.');
            }

            $job = $this->agendaJobModel->createRigeneraSlotConfigJob(
                $requestedBy,
                (int)($config['id_dot'] ?? 0),
                $idConfig,
                [
                    'data_inizio' => (string)($config['data_inizio'] ?? ''),
                    'data_fine'   => (string)($config['data_fine'] ?? ''),
                    'descrizione' => (string)($config['descrizione'] ?? ''),
                ]
            );

            if (session_status() === PHP_SESSION_ACTIVE && function_exists('session_write_close')) {
                @session_write_close();
            }

            $this->dispatchAgendaJobIfNeeded($job);

            return $this->respondJsonSafe([
                'status'  => true,
                'queued'  => true,
                'job'     => $this->buildAgendaJobStatusPayload($job),
                'message' => 'Rigenerazione agenda avviata in background. Puoi continuare a usare l\'applicativo.',
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function rigeneraSlotConfigStatus()
    {
        try {
            $idJob = (int)$this->request->getGet('id_job');
            $idDot = (int)$this->request->getGet('id_dot');

            $job = null;
            if ($idJob > 0) {
                $job = $this->agendaJobModel->find($idJob);
                if ($job) {
                    $job = $this->normalizePossiblyStaleAgendaJob($job);
                    $this->assertDoctorAllowed((int)($job['id_dot'] ?? 0));
                }
            } elseif ($idDot > 0) {
                $this->assertDoctorAllowed($idDot);
                $job = $this->normalizePossiblyStaleAgendaJob(
                    $this->agendaJobModel->findActiveRigeneraJobByDoctor($idDot)
                );
                if (!$this->isAgendaJobActive($job)) {
                    $job = null;
                }
            } else {
                throw new \Exception('Parametri mancanti.');
            }

            if ($job && ($job['status'] ?? '') === AgendaJobModel::STATUS_QUEUED) {
                $this->dispatchAgendaJobIfNeeded($job);
                $job = $this->agendaJobModel->find((int)$job['id_job']) ?? $job;
            }

            return $this->respondJsonSafe([
                'status' => true,
                'job'    => $job ? $this->buildAgendaJobStatusPayload($job) : null,
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function runRigeneraSlotConfigJob(int $idJob, string $token)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $tenantId = (int)($this->request->getGet('tenant_id') ?? 0);
        if ($tenantId > 0) {
            $this->bindTenantRuntimeForAgendaJob($tenantId);
        }

        $job = $this->agendaJobModel->claimQueuedJob($idJob, $token);
        if (!$job) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => 'Job non trovato.',
            ], 404);
        }

        if (empty($job['_claim_granted'])) {
            return $this->respondJsonSafe([
                'status'  => true,
                'message' => 'Job gia in esecuzione o gia processato.',
            ]);
        }

        try {
            $result = $this->processRigeneraSlotConfigJob($job);

            return $this->respondJsonSafe([
                'status'  => true,
                'job'     => $this->buildAgendaJobStatusPayload($this->agendaJobModel->find((int)$job['id_job']) ?? $job),
                'message' => $result['message'] ?? 'Operazione completata.',
            ]);
        } catch (\Throwable $e) {
            $this->agendaJobModel->markFailed((int)$job['id_job'], $e->getMessage());
            $freshJob = $this->agendaJobModel->find((int)$job['id_job']) ?? $job;
            $this->sendAgendaJobNotification($freshJob, false);

            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    protected function processRigeneraSlotConfigJob(array $job): array
    {
        $idJob = (int)($job['id_job'] ?? 0);
        $requestedBy = (int)($job['requested_by'] ?? 0);
        $idConfig = (int)($job['id_config'] ?? 0);

        if ($idConfig <= 0) {
            throw new \Exception('Configurazione job non valida.');
        }

        $config = $this->agendaConfigModel->getConfigCompleta($idConfig);
        if (!$config) {
            throw new \Exception('Configurazione associata al job non trovata.');
        }

        $idDot = (int)($config['id_dot'] ?? 0);
        $dataInizio = (string)($config['data_inizio'] ?? '');
        $dataFine = (string)($config['data_fine'] ?? '');

        if ($idDot <= 0 || $dataInizio === '' || $dataFine === '') {
            throw new \Exception('Configurazione agenda incompleta.');
        }

        if (!$this->agendaModel->canUserAccessDoctor($requestedBy, $idDot)) {
            throw new \Exception('Permessi non validi per eseguire la rigenerazione agenda.');
        }

        $this->agendaJobModel->updateProgress($idJob, 10, 'Analisi agenda esistente in corso...');

        $backupPdfPath = null;
        $backupPdfName = null;
        $backupFormat = null;

        $stats = $this->agendaBackupModel->getSlotPeriodoStats($idDot, $dataInizio, $dataFine);
        $totaleRigheBackup = (int)($stats['totale_righe'] ?? 0);
        $totaleAppuntamenti = (int)($stats['totale_appuntamenti'] ?? 0);

        if ($totaleRigheBackup > 0) {
            $this->agendaJobModel->updateProgress($idJob, 20, 'Creazione file di backup in corso...');

            $backupPdf = $this->generaBackupAgendaFile(
                $idDot,
                $dataInizio,
                $dataFine,
                $totaleRigheBackup,
                function (int $processedRows, int $totalRows) use ($idJob): void {
                    if ($totalRows <= 0) {
                        return;
                    }

                    $percent = 20 + (int)floor(min(1, $processedRows / max(1, $totalRows)) * 15);
                    $this->agendaJobModel->updateProgress($idJob, $percent, 'Backup file in scrittura...');
                }
            );
            $backupPdfPath = $backupPdf['path'];
            $backupPdfName = $backupPdf['name'];
            $backupFormat = $backupPdf['format'] ?? null;

            $this->agendaJobModel->updateProgress($idJob, 36, 'Registrazione backup nel database...');

            $this->agendaBackupModel->saveBackupRecordFromPeriodo(
                $idDot,
                $dataInizio,
                $dataFine,
                $backupPdfName,
                $backupPdfPath,
                $totaleRigheBackup,
                $totaleAppuntamenti,
                $requestedBy,
                function (int $processedRows, int $totalRows) use ($idJob): void {
                    if ($totalRows <= 0) {
                        return;
                    }

                    $percent = 36 + (int)floor(min(1, $processedRows / max(1, $totalRows)) * 18);
                    $this->agendaJobModel->updateProgress($idJob, $percent, 'Backup dati in registrazione...');
                }
            );

            $this->agendaJobModel->updateProgress($idJob, 56, 'Cancellazione vecchi slot in corso...');
            $this->agendaBackupModel->deletePeriodoAgenda(
                $idDot,
                $dataInizio,
                $dataFine,
                function (int $processedRows) use ($idJob, $totaleRigheBackup): void {
                    if ($totaleRigheBackup <= 0) {
                        return;
                    }

                    $percent = 56 + (int)floor(min(1, $processedRows / max(1, $totaleRigheBackup)) * 12);
                    $this->agendaJobModel->updateProgress($idJob, $percent, 'Rimozione agenda precedente...');
                }
            );
        } else {
            $this->agendaJobModel->updateProgress($idJob, 68, 'Nessun backup necessario. Generazione nuovi slot...');
        }

        $this->agendaJobModel->updateProgress($idJob, 70, 'Generazione nuovi slot in corso...');
        $lastProgressDay = 0;

        $inserted = $this->slotModel->generateFromWeeklyConfig(
            $config,
            $idDot,
            function (int $processedDays, int $totalDays, string $currentDate) use ($idJob, &$lastProgressDay): void {
                if ($totalDays <= 0) {
                    return;
                }

                if ($processedDays !== $totalDays && $processedDays !== 1 && ($processedDays - $lastProgressDay) < 5) {
                    return;
                }

                $lastProgressDay = $processedDays;
                $percent = 70 + (int)floor(min(1, $processedDays / max(1, $totalDays)) * 25);
                $this->agendaJobModel->updateProgress($idJob, $percent, 'Generazione slot: giorno ' . $currentDate);
            }
        );

        $this->agendaJobModel->updateProgress($idJob, 96, 'Finalizzazione job agenda...');

        $message = 'Configurazione salvata e slot rigenerati correttamente.';
        if ($backupFormat === 'pdf') {
            $message = 'Backup eseguito e slot rigenerati correttamente.';
        } elseif ($backupFormat === 'csv') {
            $message = 'Backup salvato in CSV per il volume dei dati e slot rigenerati correttamente.';
        }

        $result = [
            'inserted'      => $inserted,
            'backup_file'   => $backupPdfName,
            'backup_path'   => $backupPdfPath,
            'backup_format' => $backupFormat,
            'message'       => $message,
            'id_dot'        => $idDot,
            'id_config'     => $idConfig,
        ];

        $this->agendaJobModel->markCompleted($idJob, $result);
        $freshJob = $this->agendaJobModel->find($idJob) ?? $job;
        $this->sendAgendaJobNotification($freshJob, true);

        return $result;
    }

    protected function generaBackupAgendaFile(
        int $idDot,
        string $dataInizio,
        string $dataFine,
        int $totaleRighe,
        ?callable $progressCallback = null
    ): array
    {
        if ($totaleRighe <= self::BACKUP_PDF_MAX_ROWS && class_exists('\Dompdf\Dompdf')) {
            $rows = $this->agendaBackupModel->getSlotPeriodo($idDot, $dataInizio, $dataFine);

            return $this->generaPdfBackupAgenda($idDot, $dataInizio, $dataFine, $rows);
        }

        return $this->generaCsvBackupAgenda($idDot, $dataInizio, $dataFine, $totaleRighe, $progressCallback);
    }

    protected function generaPdfBackupAgenda(int $idDot, string $dataInizio, string $dataFine, array $rows): array
    {

        if (!class_exists('\Dompdf\Dompdf')) {
            throw new \Exception('Dompdf non disponibile. Installa o collega Dompdf per il backup PDF.');
        }

        $doctorLabel = $this->getAgendaProfessionalLabel($idDot);
        $showWaConfirmationColumn = $this->shouldShowWaConfirmationColumn($idDot);

        $html = '
            <html>
            <head>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
                    h1 { font-size: 18px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                    th, td { border: 1px solid #ccc; padding: 6px; }
                    th { background: #f0f0f0; }
                </style>
            </head>
            <body>
                <h1>Backup Agenda</h1>
                <p><strong>Dottore:</strong> ' . htmlspecialchars($doctorLabel) . '</p>
                <p><strong>Periodo:</strong> ' . htmlspecialchars($dataInizio) . ' - ' . htmlspecialchars($dataFine) . '</p>
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Inizio</th>
                            <th>Fine</th>
                            <th>Tipo</th>
                            <th>Stato</th>
                            <th>Paziente</th>
                            ' . ($showWaConfirmationColumn ? '<th>CONFERMA WA</th>' : '') . '
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
        ';

        foreach ($rows as $r) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($r['data_slot'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['ora_inizio'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['ora_fine'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['tipo_slot'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($r['stato_slot'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars(trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''))) . '</td>';
            if ($showWaConfirmationColumn) {
                $html .= '<td>' . htmlspecialchars(WhatsappAppointmentNote::hasWaConfirmation((string)($r['note'] ?? '')) ? 'SI' : 'NO') . '</td>';
            }
            $html .= '<td>' . htmlspecialchars($r['note'] ?? '') . '</td>';
            $html .= '</tr>';
        }

        $html .= '
                    </tbody>
                </table>
            </body>
            </html>
        ';

        $dir = $this->ensureAgendaBackupDir();
        $fileName = 'backup_agenda_' . $idDot . '_' . $dataInizio . '_' . $dataFine . '_' . date('Ymd_His') . '.pdf';
        $fullPath = $dir . $fileName;

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        file_put_contents($fullPath, $dompdf->output());

        return [
            'name'   => $fileName,
            'path'   => $fullPath,
            'format' => 'pdf',
        ];
    }

    protected function generaCsvBackupAgenda(
        int $idDot,
        string $dataInizio,
        string $dataFine,
        int $totaleRighe = 0,
        ?callable $progressCallback = null
    ): array
    {
        $doctorLabel = $this->getAgendaProfessionalLabel($idDot);
        $showWaConfirmationColumn = $this->shouldShowWaConfirmationColumn($idDot);

        $dir = $this->ensureAgendaBackupDir();
        $fileName = 'backup_agenda_' . $idDot . '_' . $dataInizio . '_' . $dataFine . '_' . date('Ymd_His') . '.csv';
        $fullPath = $dir . $fileName;

        $handle = fopen($fullPath, 'wb');
        if ($handle === false) {
            throw new \Exception('Impossibile creare il file di backup agenda.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Backup Agenda'], ';');
            fputcsv($handle, ['Dottore', $doctorLabel], ';');
            fputcsv($handle, ['Periodo', $dataInizio . ' - ' . $dataFine], ';');
            fputcsv($handle, [], ';');
            $header = ['Data', 'Inizio', 'Fine', 'Tipo', 'Stato', 'Paziente'];
            if ($showWaConfirmationColumn) {
                $header[] = 'Conferma WA';
            }
            $header[] = 'Motivo visita';
            $header[] = 'Note';
            fputcsv($handle, $header, ';');

            $this->agendaBackupModel->processSlotPeriodoInChunks(
                $idDot,
                $dataInizio,
                $dataFine,
                static function (array $rows) use ($handle, $showWaConfirmationColumn): void {
                    foreach ($rows as $r) {
                        $csvRow = [
                            $r['data_slot'] ?? '',
                            $r['ora_inizio'] ?? '',
                            $r['ora_fine'] ?? '',
                            $r['tipo_slot'] ?? '',
                            $r['stato_slot'] ?? '',
                            trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')),
                        ];
                        if ($showWaConfirmationColumn) {
                            $csvRow[] = WhatsappAppointmentNote::hasWaConfirmation((string)($r['note'] ?? '')) ? 'SI' : 'NO';
                        }
                        $csvRow[] = $r['motivo_visita'] ?? '';
                        $csvRow[] = $r['note'] ?? '';
                        fputcsv($handle, $csvRow, ';');
                    }
                },
                250,
                $progressCallback !== null
                    ? static function (int $processed) use ($progressCallback, $totaleRighe): void {
                        $progressCallback($processed, $totaleRighe);
                    }
                    : null
            );
        } finally {
            fclose($handle);
        }

        return [
            'name'   => $fileName,
            'path'   => $fullPath,
            'format' => 'csv',
        ];
    }

    protected function ensureAgendaBackupDir(): string
    {
        $dir = WRITEPATH . 'uploads/agenda_backup/';

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \Exception('Impossibile creare la cartella di backup agenda.');
        }

        return $dir;
    }

    protected function shouldShowWaConfirmationColumn(int $idDot): bool
    {
        if ($idDot <= 0) {
            return false;
        }

        return $this->agendaModel->getSmsAppointmentConfigByDoctor($idDot) !== null;
    }

    protected function dispatchAgendaJobIfNeeded(array $job): bool
    {
        if (($job['status'] ?? '') !== AgendaJobModel::STATUS_QUEUED) {
            return false;
        }

        $idJob = (int)($job['id_job'] ?? 0);
        $token = trim((string)($job['token'] ?? ''));
        if ($idJob <= 0 || $token === '') {
            return false;
        }

        $url = site_url('agenda/job-run/' . $idJob . '/' . rawurlencode($token));
        $tenantId = $this->resolveCurrentRuntimeTenantId();
        if ($tenantId > 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'tenant_id=' . $tenantId;
        }

        return $this->fireAndForgetUrl($url);
    }

    protected function resolveCurrentRuntimeTenantId(): int
    {
        try {
            $tenant = (new TenantCatalogService())->resolveCurrentRuntimeTenant();
            return (int)($tenant['id_tenant'] ?? 0);
        } catch (\Throwable $e) {
            log_message('error', 'Agenda job tenant resolve failed: ' . $e->getMessage());
            return 0;
        }
    }

    protected function bindTenantRuntimeForAgendaJob(int $tenantId): void
    {
        $tenant = (new TenantCatalogService())->getTenantById($tenantId);
        if (!$tenant || (int)($tenant['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Tenant del job agenda non disponibile.');
        }

        $config = (new TenantDatabaseConnector())->buildConnectionConfig($tenant);
        (new TenantRuntimeBindingService())->bindConnectionConfig($config);
        $this->reloadAgendaRuntimeState();
    }

    protected function reloadAgendaRuntimeState(): void
    {
        $this->agendaModel       = new AgendaModel();
        $this->slotModel         = new AgendaSlotModel();
        $this->appointmentModel  = new AgendaAppointmentModel();
        $this->lockModel         = new AgendaLockModel();
        $this->noteModel         = new AgendaNoteModel();
        $this->pazientiModel     = new PazientiModel();
        $this->agendaConfigModel = new AgendaConfigModel();
        $this->agendaBackupModel = new AgendaBackupModel();
        $this->agendaJobModel    = new AgendaJobModel();
        $this->locationModel     = new AgendaLocationModel();
        $this->visitTypeModel    = new AgendaVisitTypeModel();
        $this->db = \Config\Database::connect();

        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
    }

    protected function fireAndForgetUrl(string $url): bool
    {
        if (trim($url) === '') {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                // The background runner response must never be streamed into the
                // current HTTP response, otherwise the frontend receives invalid JSON.
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 500);
                curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
                curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: close']);
                @curl_exec($ch);
                curl_close($ch);
                return true;
            }
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string)($parts['path'] ?? '/');
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        $transport = $scheme === 'https' ? 'ssl://' : '';
        $socket = @fsockopen($transport . $host, $port, $errno, $errstr, 1.0);
        if ($socket === false) {
            return false;
        }

        stream_set_blocking($socket, false);
        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Connection: Close\r\n";
        $request .= "User-Agent: AgendaJobRunner/1.0\r\n\r\n";
        fwrite($socket, $request);
        fclose($socket);

        return true;
    }

    protected function normalizePossiblyStaleAgendaJob(?array $job): ?array
    {
        if (!$job) {
            return null;
        }

        $status = (string)($job['status'] ?? '');
        if (!in_array($status, [AgendaJobModel::STATUS_QUEUED, AgendaJobModel::STATUS_RUNNING], true)) {
            return $job;
        }

        $referenceTime = (string)($job['heartbeat_at'] ?? ($job['started_at'] ?? ($job['created_at'] ?? '')));
        if ($referenceTime === '') {
            return $job;
        }

        $timestamp = strtotime($referenceTime);
        if ($timestamp === false || $timestamp >= (time() - 1800)) {
            return $job;
        }

        $this->agendaJobModel->markFailed((int)$job['id_job'], 'Job interrotto o scaduto.');
        return $this->agendaJobModel->find((int)$job['id_job']);
    }

    protected function isAgendaJobActive(?array $job): bool
    {
        if (!$job) {
            return false;
        }

        return in_array((string)($job['status'] ?? ''), [
            AgendaJobModel::STATUS_QUEUED,
            AgendaJobModel::STATUS_RUNNING,
        ], true);
    }

    protected function buildAgendaJobStatusPayload(array $job): array
    {
        $result = $this->agendaJobModel->decodeResult($job);

        return [
            'id_job'            => (int)($job['id_job'] ?? 0),
            'status'            => (string)($job['status'] ?? ''),
            'progress_percent'  => (int)($job['progress_percent'] ?? 0),
            'progress_message'  => (string)($job['progress_message'] ?? ''),
            'backup_file'       => (string)($job['backup_file_name'] ?? ($result['backup_file'] ?? '')),
            'backup_format'     => (string)($job['backup_file_format'] ?? ($result['backup_format'] ?? '')),
            'inserted'          => (int)($job['inserted_slots'] ?? ($result['inserted'] ?? 0)),
            'message'           => (string)($result['message'] ?? ($job['progress_message'] ?? '')),
            'error_message'     => (string)($job['error_message'] ?? ''),
            'created_at'        => (string)($job['created_at'] ?? ''),
            'started_at'        => (string)($job['started_at'] ?? ''),
            'finished_at'       => (string)($job['finished_at'] ?? ''),
            'id_dot'            => (int)($job['id_dot'] ?? 0),
        ];
    }

    protected function sendAgendaJobNotification(array $job, bool $success): void
    {
        if ((int)($job['notify_push_sent'] ?? 0) === 1) {
            return;
        }

        $userId = (int)($job['requested_by'] ?? 0);
        $idDot = (int)($job['id_dot'] ?? 0);
        if ($userId <= 0 || $idDot <= 0) {
            return;
        }

        $doctorLabel = $this->getAgendaProfessionalLabel($idDot);
        $result = $this->agendaJobModel->decodeResult($job);
        $body = $success
            ? 'Rigenerazione agenda completata per ' . $doctorLabel . '.'
            : 'Rigenerazione agenda non completata per ' . $doctorLabel . '.';

        $payload = [
            'type'  => 'agenda_job',
            'title' => 'AmbulatorioFacile',
            'body'  => $body,
            'tag'   => 'agenda-job-' . (int)($job['id_job'] ?? 0),
            'data'  => [
                'url' => base_url('agenda/config-slot?id_dot=' . $idDot),
            ],
        ];

        if ($success && (int)($result['inserted'] ?? 0) > 0) {
            $payload['body'] = 'Rigenerazione agenda completata per ' . $doctorLabel . '. Slot creati: ' . (int)$result['inserted'] . '.';
        }

        $this->notificationService->sendToUser($userId, $payload, 'agenda_job');
        $this->agendaJobModel->markNotificationSent((int)$job['id_job']);
    }

    protected function getAgendaProfessionalLabel(int $idDot): string
    {
        $dot = $this->agendaModel->getAgendaProfessionalByLegacyId($idDot) ?? [];
        return trim(($dot['cognome'] ?? '') . ' ' . ($dot['nome'] ?? ''));
    }

    public function calendario()
    {
        $idDot = (int)$this->request->getGet('id_dot');
        $data  = $this->request->getGet('data') ?: date('Y-m-d');
        $view  = $this->request->getGet('view') ?: 'day';

        $step = 'start';

        try {
            $step = 'assert_doctor_allowed';
            $this->assertDoctorAllowed($idDot);
            $currentUserId = $this->getCurrentUserId();

            $step = 'cleanup_expired_locks';
            $this->lockModel->cleanupExpiredLocks();

            $step = 'load_slots';
            $slots = $this->slotModel->getSlotsCalendario($idDot, $data, $view);
            $hasSlots = !empty($slots);
            $noAgendaMessage = '';
            $giornoBloccato = $this->agendaModel->isGiornoBloccato($idDot, $data);
            $memoGiornoBloccato = $this->agendaModel->isMemoGiornoBloccato($idDot, $data);
            $domiciliareGiornoBloccato = $this->agendaModel->isDomiciliareGiornoBloccato($idDot, $data);
            $canBloccareGiorno = $this->agendaModel->canBloccareGiorno($currentUserId);
            $range = $this->getCalendarioRangeSafe($data, $view);
            $giorniBloccatiMap = $this->agendaModel->getGiorniBloccatiMapForRange(
                $idDot,
                (string)($range['start'] ?? $data),
                (string)($range['end'] ?? $data)
            );
            $memoGiorniBloccatiMap = $this->agendaModel->getMemoGiorniBloccatiMapForRange(
                $idDot,
                (string)($range['start'] ?? $data),
                (string)($range['end'] ?? $data)
            );
            $domiciliareGiorniBloccatiMap = $this->agendaModel->getDomiciliareGiorniBloccatiMapForRange(
                $idDot,
                (string)($range['start'] ?? $data),
                (string)($range['end'] ?? $data)
            );

            $minTime = null;
            $maxTime = null;

            foreach ($slots as $slot) {
                $oraInizio = date('H:i:s', strtotime($slot['ora_inizio']));
                $oraFine   = date('H:i:s', strtotime($slot['ora_fine']));

                if ($minTime === null || $oraInizio < $minTime) {
                    $minTime = $oraInizio;
                }

                if ($maxTime === null || $oraFine > $maxTime) {
                    $maxTime = $oraFine;
                }
            }

            $step = 'compute_grid_duration';
            $gridDuration = 15;
            if (!empty($slots)) {
                $gridDuration = $this->calcolaStepCalendario($slots);
            }

            if ($hasSlots) {
                if ($minTime === null) {
                    $minTime = '08:00:00';
                }

                if ($maxTime === null) {
                    $maxTime = '18:00:00';
                }

                $minTime = $this->floorTimeToStep($minTime, 5);
                $maxTime = $this->ceilTimeToStep($maxTime, 5);
            } else {
                $step = 'resolve_no_agenda_message';
                if ($view === 'week') {
                    $weekStart = new \DateTime($data);
                    $weekday = (int)$weekStart->format('N');
                    $weekStart->modify('-' . ($weekday - 1) . ' days');

                    $weekEnd = clone $weekStart;
                    $weekEnd->modify('+6 days');

                    $noAgendaMessage = $this->agendaConfigModel->getNoAgendaMessageForRange(
                        $idDot,
                        $weekStart->format('Y-m-d'),
                        $weekEnd->format('Y-m-d')
                    );
                } else {
                    $noAgendaMessage = $this->agendaConfigModel->getNoAgendaMessageForDate($idDot, $data);
                }
            }

            $step = 'return_json';
            return $this->respondCalendarioJson([
                'status'        => true,
                'has_slots'     => $hasSlots,
                'slots'         => $slots,
                'grid_duration' => $gridDuration,
                'min_time'      => $minTime,
                'max_time'      => $maxTime,
                'message'       => $noAgendaMessage,
                'giorno_bloccato' => $giornoBloccato,
                'memo_giorno_bloccato' => $memoGiornoBloccato,
                'domiciliare_giorno_bloccato' => $domiciliareGiornoBloccato,
                'giorni_bloccati_map' => $giorniBloccatiMap,
                'memo_giorni_bloccati_map' => $memoGiorniBloccatiMap,
                'domiciliare_giorni_bloccati_map' => $domiciliareGiorniBloccatiMap,
                'can_bloccare' => $canBloccareGiorno,
            ]);
        } catch (\Throwable $e) {
            $context = $this->buildCalendarioErrorContext($idDot, $data, $view, $step, $e);

            log_message(
                'error',
                '[Agenda::calendario] ERROR ' . $this->encodeLogContext($context)
            );

            return $this->respondCalendarioJson([
                'status'        => false,
                'has_slots'     => false,
                'slots'         => [],
                'grid_duration' => 15,
                'min_time'      => null,
                'max_time'      => null,
                'message'       => 'Errore interno durante il caricamento dell\'agenda.',
                'debug_message' => ENVIRONMENT === 'production' ? '' : $e->getMessage(),
                'giorno_bloccato' => false,
                'memo_giorno_bloccato' => false,
                'domiciliare_giorno_bloccato' => false,
                'giorni_bloccati_map' => [],
                'memo_giorni_bloccati_map' => [],
                'domiciliare_giorni_bloccati_map' => [],
                'can_bloccare' => false,
            ], 500);
        }
    }

    public function calendarioTeamDay()
    {
        $selectedDot = (int)$this->request->getGet('id_dot');
        $data = $this->request->getGet('data') ?: date('Y-m-d');
        $step = 'start';

        try {
            $step = 'load_visible_doctors';
            $medici = $this->getTeamDayDoctorsForCurrentUser();
            if (!$this->canUseTeamDayView($medici)) {
                return $this->respondCalendarioJson([
                    'status' => false,
                    'has_slots' => false,
                    'columns' => [],
                    'grid_duration' => 15,
                    'min_time' => '08:00:00',
                    'max_time' => '18:00:00',
                    'message' => 'La vista giorno team non e disponibile per questo spazio.',
                    'giorno_bloccato' => false,
                    'memo_giorno_bloccato' => false,
                    'domiciliare_giorno_bloccato' => false,
                    'can_bloccare' => false,
                ], 403);
            }

            $currentUserId = $this->getCurrentUserId();
            $selectedDot = $selectedDot > 0 ? $selectedDot : $this->getFirstVisibleDoctorId($medici);
            if ($selectedDot > 0 && !$this->doctorListContainsId($medici, $selectedDot)) {
                $selectedDot = $this->getFirstVisibleDoctorId($medici);
            }

            $step = 'cleanup_expired_locks';
            $this->lockModel->cleanupExpiredLocks();

            $step = 'build_columns';
            $columns = [];
            $allSlots = [];

            foreach ($medici as $medico) {
                $doctor = $this->normalizeAgendaProfessionalRow($medico);
                if ((int)($doctor['id_dot'] ?? 0) <= 0) {
                    continue;
                }

                $column = $this->buildTeamDayColumnPayload($doctor, $data, (int)$doctor['id_dot'] === $selectedDot);
                $columns[] = $column;

                if (!empty($column['slots']) && is_array($column['slots'])) {
                    $allSlots = array_merge($allSlots, $column['slots']);
                }
            }

            $hasSlots = !empty($allSlots);
            $gridDuration = $hasSlots ? $this->calcolaStepCalendario($allSlots) : 15;
            $minTime = '08:00:00';
            $maxTime = '18:00:00';

            if ($hasSlots) {
                $minTime = null;
                $maxTime = null;

                foreach ($allSlots as $slot) {
                    $oraInizio = date('H:i:s', strtotime((string)($slot['ora_inizio'] ?? '')));
                    $oraFine = date('H:i:s', strtotime((string)($slot['ora_fine'] ?? '')));

                    if ($minTime === null || $oraInizio < $minTime) {
                        $minTime = $oraInizio;
                    }

                    if ($maxTime === null || $oraFine > $maxTime) {
                        $maxTime = $oraFine;
                    }
                }

                $minTime = $this->floorTimeToStep($minTime ?? '08:00:00', 5);
                $maxTime = $this->ceilTimeToStep($maxTime ?? '18:00:00', 5);
            }

            $step = 'build_selected_state';
            $giornoBloccato = false;
            $memoGiornoBloccato = false;
            $domiciliareGiornoBloccato = false;
            $canBloccareGiorno = $this->agendaModel->canBloccareGiorno($currentUserId);

            if ($selectedDot > 0) {
                $giornoBloccato = $this->agendaModel->isGiornoBloccato($selectedDot, $data);
                $memoGiornoBloccato = $this->agendaModel->isMemoGiornoBloccato($selectedDot, $data);
                $domiciliareGiornoBloccato = $this->agendaModel->isDomiciliareGiornoBloccato($selectedDot, $data);
            }

            return $this->respondCalendarioJson([
                'status' => true,
                'has_slots' => $hasSlots,
                'columns' => $columns,
                'grid_duration' => $gridDuration,
                'min_time' => $minTime,
                'max_time' => $maxTime,
                'message' => $hasSlots ? '' : 'Nessuna agenda impostata per il giorno selezionato.',
                'giorno_bloccato' => $giornoBloccato,
                'memo_giorno_bloccato' => $memoGiornoBloccato,
                'domiciliare_giorno_bloccato' => $domiciliareGiornoBloccato,
                'can_bloccare' => $canBloccareGiorno,
            ]);
        } catch (\Throwable $e) {
            $context = $this->buildCalendarioErrorContext($selectedDot, $data, 'team_day', $step, $e);

            log_message(
                'error',
                '[Agenda::calendarioTeamDay] ERROR ' . $this->encodeLogContext($context)
            );

            return $this->respondCalendarioJson([
                'status' => false,
                'has_slots' => false,
                'columns' => [],
                'grid_duration' => 15,
                'min_time' => '08:00:00',
                'max_time' => '18:00:00',
                'message' => 'Errore interno durante il caricamento della vista team.',
                'debug_message' => ENVIRONMENT === 'production' ? '' : $e->getMessage(),
                'giorno_bloccato' => false,
                'memo_giorno_bloccato' => false,
                'domiciliare_giorno_bloccato' => false,
                'can_bloccare' => false,
            ], 500);
        }
    }

    public function disponibilitaMese()
    {
        $idDot = (int)$this->request->getGet('id_dot');
        $mese = trim((string)($this->request->getGet('mese') ?? ''));

        if (!preg_match('/^\d{4}-\d{2}$/', $mese)) {
            $mese = date('Y-m');
        }

        try {
            $this->assertDoctorAllowed($idDot);

            $monthStart = \DateTimeImmutable::createFromFormat('!Y-m', $mese);
            if (!$monthStart) {
                throw new \RuntimeException('Mese non valido.');
            }

            $dataInizio = $monthStart->format('Y-m-01');
            $dataFine = $monthStart->modify('last day of this month')->format('Y-m-d');
            $rows = $this->slotModel->getAvailabilityDaysForRange($idDot, $dataInizio, $dataFine);

            $dates = [];
            $counts = [];

            foreach ($rows as $row) {
                $dataSlot = trim((string)($row['data_slot'] ?? ''));
                if ($dataSlot === '') {
                    continue;
                }

                $dates[] = $dataSlot;
                $counts[$dataSlot] = (int)($row['slot_liberi'] ?? 0);
            }

            return $this->response->setJSON([
                'status' => true,
                'month'  => $monthStart->format('Y-m'),
                'dates'  => $dates,
                'counts' => $counts,
            ]);
        } catch (\Throwable $e) {
            log_message(
                'error',
                '[Agenda::disponibilitaMese] id_dot=' . $idDot . ' mese=' . $mese . ' errore=' . $e->getMessage()
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'status'  => false,
                    'month'   => $mese,
                    'dates'   => [],
                    'counts'  => [],
                    'message' => 'Errore nel caricamento delle disponibilita del mese.',
                ]);
        }
    }

    private function calcolaStepCalendario(array $slots): int
    {
        $durateConfigurate = [];
        $durateTutte = [];

        foreach ($slots as $slot) {
            $durata = (int)($slot['durata_slot_minuti'] ?? 0);
            if ($durata <= 0) {
                continue;
            }

            $durateTutte[] = $durata;

            $origine = strtoupper(trim((string)($slot['origine_slot'] ?? '')));
            if ($origine !== 'EXTRA') {
                $durateConfigurate[] = $durata;
            }
        }

        $stepDaPartenze = $this->inferStepFromSlotStarts($slots);
        if ($stepDaPartenze > 0 && $durateConfigurate !== []) {
            // Con fasce da 45 minuti e slot extra da 30/15 minuti
            // manteniamo una griglia leggibile senza scendere sotto 15.
            $stepDaPartenze = max(15, $stepDaPartenze);
        }

        if ($durateConfigurate !== []) {
            $stepConfigurato = $this->gcdArray($durateConfigurate);
            if ($stepConfigurato <= 0) {
                $stepConfigurato = min($durateConfigurate);
            }

            if ($stepDaPartenze > 0) {
                return max(5, min($stepConfigurato, $stepDaPartenze));
            }

            return max(5, $stepConfigurato);
        }

        if ($stepDaPartenze > 0) {
            return $stepDaPartenze;
        }

        if ($durateTutte === []) {
            return 15;
        }

        $stepTutti = $this->gcdArray($durateTutte);
        if ($stepTutti <= 0) {
            $stepTutti = min($durateTutte);
        }

        return max(5, $stepTutti);
    }

    private function inferStepFromSlotStarts(array $slots): int
    {
        $byDay = [];

        foreach ($slots as $slot) {
            $oraInizioRaw = (string)($slot['ora_inizio'] ?? '');
            $timestamp = strtotime($oraInizioRaw);
            if ($timestamp === false) {
                continue;
            }

            $dayKey = date('Y-m-d', $timestamp);
            $minutes = ((int)date('H', $timestamp) * 60) + (int)date('i', $timestamp);
            $byDay[$dayKey][$minutes] = true;
        }

        $gcd = 0;

        foreach ($byDay as $minutesMap) {
            $minutes = array_keys($minutesMap);
            sort($minutes, SORT_NUMERIC);

            $count = count($minutes);
            for ($index = 1; $index < $count; $index++) {
                $delta = (int)$minutes[$index] - (int)$minutes[$index - 1];
                if ($delta >= 5 && $delta <= 120) {
                    $gcd = $gcd === 0 ? $delta : $this->gcd($gcd, $delta);
                }
            }
        }

        if ($gcd < 5) {
            return 0;
        }

        return $gcd;
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $tmp = $b;
            $b = $a % $b;
            $a = $tmp;
        }

        return abs($a);
    }

    private function gcdArray(array $values): int
    {
        $gcd = 0;

        foreach ($values as $value) {
            $value = (int)$value;
            if ($value <= 0) {
                continue;
            }

            $gcd = $gcd === 0 ? $value : $this->gcd($gcd, $value);
        }

        return $gcd;
    }

    private function floorTimeToStep(string $time, int $stepMinutes): string
    {
        $stepMinutes = max(1, $stepMinutes);
        $timestamp = strtotime('1970-01-01 ' . $time);
        if ($timestamp === false) {
            return $time;
        }

        $minutes = ((int)date('H', $timestamp) * 60) + (int)date('i', $timestamp);
        $rounded = (int)(floor($minutes / $stepMinutes) * $stepMinutes);

        return sprintf('%02d:%02d:00', intdiv($rounded, 60), $rounded % 60);
    }

    private function ceilTimeToStep(string $time, int $stepMinutes): string
    {
        $stepMinutes = max(1, $stepMinutes);
        $timestamp = strtotime('1970-01-01 ' . $time);
        if ($timestamp === false) {
            return $time;
        }

        $minutes = ((int)date('H', $timestamp) * 60) + (int)date('i', $timestamp);
        $rounded = (int)(ceil($minutes / $stepMinutes) * $stepMinutes);

        return sprintf('%02d:%02d:00', intdiv($rounded, 60), $rounded % 60);
    }

    public function domiciliari()
    {
        $idDot = (int)$this->request->getGet('id_dot');
        $data  = $this->request->getGet('data') ?: date('Y-m-d');

        $this->assertDoctorAllowed($idDot);

        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());
        $domiciliariAbilitati = $this->isDomiciliareAbilitatoPerDottore($medici, $idDot);

        if (!$domiciliariAbilitati) {
            return $this->response->setJSON([
                'status' => true,
                'rows'   => [],
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'rows'   => $this->slotModel->getDomiciliari($idDot, $data),
        ]);
    }

    public function note()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $agendaData = $this->resolveAgendaDateFromPayload(
                $this->request->getGet(),
                $this->getDefaultAgendaDate()
            );

            if ($this->isSharedAgendaMemosFeatureEnabled()) {
                $rows = $this->noteModel->getNoteByDoctors($this->getSharedAgendaMemoDoctorIds());
            } else {
                $this->assertDoctorAllowed($idDot);
                $rows = $this->noteModel->getNoteByDoctor($idDot);
            }

            return $this->respondJsonSafe([
                'status' => true,
                'rows'   => $this->enrichMemoRowsForResponse($rows, $agendaData),
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function getNota()
    {
        try {
            $idNota = (int)$this->request->getGet('id_nota');

            if ($idNota <= 0) {
                throw new \Exception('Nota non valida.');
            }

            $this->assertMemoDoctorAllowed($this->getIdDotFromNota($idNota));

            $row = $this->noteModel->getNota($idNota);

            return $this->respondJsonSafe([
                'status' => true,
                'row'    => $this->enrichMemoRowsForResponse(
                    $row ? [$row] : [],
                    $this->resolveAgendaDateFromPayload(
                        $this->request->getGet(),
                        $this->getDefaultAgendaDate()
                    )
                )[0] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function segnaNotaFatta()
    {
        try {
            $idNota = (int)$this->request->getPost('id_nota');
            $fatta  = (int)$this->request->getPost('fatta');

            if ($idNota <= 0) {
                throw new \Exception('Nota non valida.');
            }

            $row = $this->noteModel->getNota($idNota);
            if (!$row) {
                throw new \Exception('Nota non valida.');
            }

            $idDot = (int)($row['id_dot'] ?? 0);
            $this->assertMemoDoctorAllowed($idDot);
            $this->assertMemoActionAllowed(
                $idDot,
                $this->resolveAgendaDateFromPayload(
                    $this->request->getPost(),
                    (string)($row['data_inizio_validita'] ?? '')
                )
            );

            return $this->response->setJSON([
                'status' => $this->noteModel->setFatta($idNota, $fatta, $this->getCurrentUserId())
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function lockSlot()
    {
        $idSlot = (int)$this->request->getPost('id_slot');
        $userId = $this->getCurrentUserId();

        $this->lockModel->cleanupExpiredLocks();

        $slot = $this->db->table('dap11_agenda_slot')
            ->select('id_dot, data_slot, stato')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray();

        if (!$slot) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => 'Slot non trovato.'
            ]);
        }

        $this->assertDoctorAllowed((int)$slot['id_dot']);

        if ($this->agendaModel->isGiornoBloccato((int)$slot['id_dot'], (string)$slot['data_slot'])) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => 'La giornata Ã¨ bloccata.'
            ]);
        }

        return $this->response->setJSON(
            $this->lockModel->lockSlot($idSlot, $userId)
        );
    }

    public function refreshLock()
    {
        $token = (string)$this->request->getPost('token_lock');

        return $this->response->setJSON(
            $this->lockModel->refreshLock($token)
        );
    }

    public function unlockSlot()
    {
        $token = (string)$this->request->getPost('token_lock');

        return $this->response->setJSON(
            $this->lockModel->unlockSlot($token)
        );
    }

    public function cercaPazienti()
    {
        try {
            $term  = trim((string)$this->request->getGet('term'));
            $idDot = (int)$this->request->getGet('id_dot');
            $onlyFutureAppointments = (int)$this->request->getGet('only_future_appointments') === 1;
            $memoScope = (int)$this->request->getGet('memo_scope') === 1;

            if ($memoScope && $this->isSharedAgendaMemosFeatureEnabled()) {
                $this->assertMemoDoctorAllowed($idDot);
            } else {
                $this->assertDoctorAllowed($idDot);
            }

            return $this->respondJsonSafe([
                'status' => true,
                'rows'   => $this->pazientiModel->autocompleteByDoctor($idDot, $term, $onlyFutureAppointments, $this->getCurrentUserId()),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Agenda::cercaPazienti failed: ' . $e->getMessage(), [
                'id_dot' => (int)$this->request->getGet('id_dot'),
                'term' => (string)$this->request->getGet('term'),
                'only_future_appointments' => (int)$this->request->getGet('only_future_appointments'),
            ]);

            return $this->respondJsonSafe([
                'status'  => false,
                'message' => 'Errore durante l\'elaborazione della ricerca.',
                'rows'    => [],
            ], 500);
        }
    }

    public function listaTipiVisita()
    {
        try {
            $this->assertVisitTypesFeatureEnabled();

            return $this->respondJsonSafe([
                'status' => true,
                'rows' => $this->visitTypeModel->listForAgenda(),
            ]);
        } catch (\Throwable $e) {
            return $this->respondJsonSafe([
                'status' => false,
                'message' => $e->getMessage(),
                'rows' => [],
            ], 403);
        }
    }

    public function salvaTipoVisita()
    {
        try {
            $this->assertVisitTypesFeatureEnabled();

            $id = $this->visitTypeModel->saveType([
                'id_tipo_visita' => (int) ($this->request->getPost('id_tipo_visita') ?? 0),
                'nome' => (string) ($this->request->getPost('nome') ?? ''),
                'durata_minuti' => (int) ($this->request->getPost('durata_minuti') ?? 0),
                'colore' => (string) ($this->request->getPost('colore') ?? ''),
                'attivo' => (int) ($this->request->getPost('attivo') ?? 1),
            ], $this->getCurrentUserId());

            return $this->respondJsonSafe([
                'status' => true,
                'id_tipo_visita' => $id,
                'message' => 'Tipo visita salvato correttamente.',
                'rows' => $this->visitTypeModel->listForAgenda(),
            ]);
        } catch (\Throwable $e) {
            return $this->respondJsonSafe([
                'status' => false,
                'message' => $e->getMessage(),
                'rows' => [],
            ], 400);
        }
    }

    public function toggleTipoVisita()
    {
        try {
            $this->assertVisitTypesFeatureEnabled();

            $idTipoVisita = (int) ($this->request->getPost('id_tipo_visita') ?? 0);
            $attivo = (int) ($this->request->getPost('attivo') ?? -1);

            if ($idTipoVisita <= 0 || ($attivo !== 0 && $attivo !== 1)) {
                throw new \Exception('Parametri del tipo visita non validi.');
            }

            $this->visitTypeModel->toggleActive($idTipoVisita, $attivo === 1, $this->getCurrentUserId());

            return $this->respondJsonSafe([
                'status' => true,
                'message' => $attivo === 1
                    ? 'Tipo visita riattivato correttamente.'
                    : 'Tipo visita disattivato correttamente.',
                'rows' => $this->visitTypeModel->listForAgenda(),
            ]);
        } catch (\Throwable $e) {
            return $this->respondJsonSafe([
                'status' => false,
                'message' => $e->getMessage(),
                'rows' => [],
            ], 400);
        }
    }

    public function appuntamentiPaziente()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $idPaziente = (int)$this->request->getGet('id_paziente');

            if ($idDot <= 0 || $idPaziente <= 0) {
                throw new \Exception('Parametri mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            return $this->respondJsonSafe([
                'status'  => true,
                'patient' => null,
                'rows'    => $this->pazientiModel->getAppointmentsByDoctorAndPatient($idPaziente, $idDot, 200),
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage(),
                'patient' => null,
                'rows'    => [],
            ], 400);
        }
    }

    public function getPaziente($idPaziente)
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');

            $this->assertDoctorAllowed($idDot);

            $row = $this->pazientiModel->getPazienteByDoctor((int)$idPaziente, $idDot, $this->getCurrentUserId());
            if (!$row) {
                throw new \Exception('Paziente non trovato.');
            }

            return $this->respondJsonSafe([
                'status' => true,
                'row'    => $row,
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function salvaPaziente()
    {
        try {
            $payload = $this->request->getPost();
            $idDot   = (int)($payload['id_dot'] ?? 0);

            $this->assertDoctorAllowed($idDot);

            $idPaziente = $this->pazientiModel->savePatientAndLink($payload, $idDot, $this->getCurrentUserId());

            return $this->response->setJSON([
                'status'      => true,
                'id_paziente' => $idPaziente,
                'message'     => 'Paziente salvato correttamente.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function salvaAppuntamento()
    {
        try {
            $payload = $this->normalizeAppointmentPatientPayload($this->request->getPost());
            $payload['created_by'] = $this->getCurrentUserId();
            $payload['visit_types_feature_enabled'] = $this->isVisitTypesFeatureEnabled();

            $idSlot = (int)($payload['id_slot'] ?? 0);

            if ($idSlot <= 0) {
                throw new \Exception('Slot non trovato.');
            }

            $slot = $this->db->table('dap11_agenda_slot')
                ->select('id_dot, data_slot')
                ->where('id_slot', $idSlot)
                ->get()
                ->getRowArray();

            if (!$slot) {
                throw new \Exception('Slot non trovato.');
            }

            $this->assertDoctorAllowed((int)$slot['id_dot']);

            if ($this->agendaModel->isGiornoBloccato((int)$slot['id_dot'], (string)$slot['data_slot'])) {
                throw new \Exception('La giornata Ã¨ bloccata. Non Ã¨ possibile prenotare.');
            }

            $idDot = (int)$slot['id_dot'];
            $payload['id_dot'] = $idDot;

            $idPaziente = $this->pazientiModel->savePatientAndLink($payload, $idDot, $this->getCurrentUserId());
            $payload['id_paziente'] = $idPaziente;

            $id = $this->appointmentModel->saveAppointment($payload);
            $this->notifyBookedAppointmentIfNeeded($id, $idDot);

            return $this->response->setJSON([
                'status'          => true,
                'id_appuntamento' => $id,
                'message'         => 'Prenotazione confermata correttamente.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function aggiornaAppuntamento()
    {
        try {
            $payload = $this->normalizeAppointmentPatientPayload($this->request->getPost());
            $payload['visit_types_feature_enabled'] = $this->isVisitTypesFeatureEnabled();

            $idAppuntamento = (int)($payload['id_appuntamento'] ?? 0);
            if ($idAppuntamento > 0) {
                $this->assertDoctorAllowed($this->getIdDotFromAppuntamento($idAppuntamento));
            }

            $idDot = (int)($payload['id_dot'] ?? 0);
            if ($idDot > 0) {
                $this->assertDoctorAllowed($idDot);

                $idPaziente = $this->pazientiModel->savePatientAndLink($payload, $idDot, $this->getCurrentUserId());
                $payload['id_paziente'] = $idPaziente;
            }

            $this->appointmentModel->updateAppointment($payload);

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Appuntamento aggiornato correttamente.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function eliminaAppuntamento()
    {
        try {
            $idAppuntamento = (int)$this->request->getPost('id_appuntamento');

            $this->assertDoctorAllowed($this->getIdDotFromAppuntamento($idAppuntamento));

            $this->appointmentModel->deleteAppointment($idAppuntamento, $this->getCurrentUserId());

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Appuntamento annullato correttamente.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function salvaNota()
    {
        try {
            $payload = $this->request->getPost();
            $payload['created_by'] = $this->getCurrentUserId();

            $idDot = (int)($payload['id_dot'] ?? 0);
            $this->assertMemoDoctorAllowed($idDot);
            $this->assertMemoActionAllowed(
                $idDot,
                $this->resolveAgendaDateFromPayload($payload, (string)($payload['data_inizio_validita'] ?? ''))
            );

            $idNota = $this->noteModel->saveNota($payload);

            return $this->response->setJSON([
                'status'  => true,
                'id_nota' => $idNota,
                'message' => 'Nota salvata correttamente.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function eliminaNota()
    {
        try {
            $idNota = (int)$this->request->getPost('id_nota');
            $row = $this->noteModel->getNota($idNota);
            if (!$row) {
                throw new \Exception('Nota non valida.');
            }

            $idDot = (int)($row['id_dot'] ?? 0);
            $this->assertMemoDoctorAllowed($idDot);
            $this->assertMemoActionAllowed(
                $idDot,
                $this->resolveAgendaDateFromPayload(
                    $this->request->getPost(),
                    (string)($row['data_inizio_validita'] ?? '')
                )
            );

            return $this->response->setJSON([
                'status' => $this->noteModel->deleteNota($idNota)
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function statoGiorno()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $data  = (string)$this->request->getGet('data');

            $this->assertDoctorAllowed($idDot);

            return $this->response->setJSON([
                'status'                      => true,
                'giorno_bloccato'             => $this->agendaModel->isGiornoBloccato($idDot, $data),
                'memo_giorno_bloccato'        => $this->agendaModel->isMemoGiornoBloccato($idDot, $data),
                'domiciliare_giorno_bloccato' => $this->agendaModel->isDomiciliareGiornoBloccato($idDot, $data),
                'can_bloccare'                => $this->agendaModel->canBloccareGiorno($this->getCurrentUserId()),
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sbloccaGiorno()
    {
        try {
            $idDot = (int)$this->request->getPost('id_dot');
            $data  = (string)$this->request->getPost('data');

            if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
                throw new \Exception('Non hai i permessi per sbloccare la giornata.');
            }

            if (!$idDot || !$data) {
                throw new \Exception('Dati mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            $this->agendaModel->sbloccaGiorno($idDot, $data);

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Giornata sbloccata correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function bloccaDomiciliariGiorno()
    {
        try {
            $idDot = (int)$this->request->getPost('id_dot');
            $data  = (string)$this->request->getPost('data');

            if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
                throw new \Exception('Non hai i permessi per bloccare le domiciliari del giorno.');
            }

            if (!$idDot || !$data) {
                throw new \Exception('Dati mancanti.');
            }

            $this->assertDoctorAllowed($idDot);
            $this->assertDomiciliariAbilitatiPerDottore($idDot);

            if ($this->agendaModel->isGiornoBloccato($idDot, $data)) {
                throw new \Exception('La giornata agenda e gia bloccata: anche le domiciliari risultano gia bloccate.');
            }

            $this->agendaModel->bloccaDomiciliareGiorno($idDot, $data);

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Domiciliari bloccate correttamente per il giorno selezionato.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sbloccaDomiciliariGiorno()
    {
        try {
            $idDot = (int)$this->request->getPost('id_dot');
            $data  = (string)$this->request->getPost('data');

            if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
                throw new \Exception('Non hai i permessi per sbloccare le domiciliari del giorno.');
            }

            if (!$idDot || !$data) {
                throw new \Exception('Dati mancanti.');
            }

            $this->assertDoctorAllowed($idDot);
            $this->assertDomiciliariAbilitatiPerDottore($idDot);

            if ($this->agendaModel->isGiornoBloccato($idDot, $data)) {
                throw new \Exception('La giornata agenda e bloccata. Sblocca prima la giornata completa.');
            }

            $this->agendaModel->sbloccaDomiciliareGiorno($idDot, $data);

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Blocco domiciliari rimosso correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getNotaGiorno()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $data  = (string)$this->request->getGet('data');

            if ($idDot <= 0 || $data === '') {
                throw new \Exception('Parametri mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            $row = $this->agendaModel->getNotaGiorno($idDot, $data);

            return $this->response->setJSON([
                'status' => true,
                'row'    => $row,
                'nota'   => (string)($row['nota'] ?? '')
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function salvaNotaGiorno()
    {
        try {
            $idDot = (int)$this->request->getPost('id_dot');
            $data  = (string)$this->request->getPost('data');
            $nota  = (string)$this->request->getPost('nota');

            if ($idDot <= 0 || $data === '') {
                throw new \Exception('Parametri mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            // Agenda day lock must not prevent memo operations.
            if ($this->agendaModel->isMemoGiornoBloccato($idDot, $data)) {
                throw new \Exception('Il giorno selezionato e bloccato per le memo.');
            }
            if (false && method_exists($this->agendaModel, 'isGiornoBloccato') &&
                $this->agendaModel->isGiornoBloccato($idDot, $data)) {
                throw new \Exception('La giornata Ã¨ bloccata.');
            }

            $id = $this->agendaModel->saveNotaGiorno(
                $idDot,
                $data,
                $nota,
                $this->getCurrentUserId()
            );

            return $this->response->setJSON([
                'status'         => true,
                'id_nota_giorno' => $id,
                'message'        => 'Nota del giorno salvata.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function gestionePazienti()
    {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/gestione_pazienti', [
            'pageTitle'   => 'Gestione pazienti',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    }

    public function listaPazienti()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $term  = trim((string)$this->request->getGet('term'));
            $page  = max(1, (int)($this->request->getGet('page') ?? 1));
            $perPage = 20;

            if ($idDot <= 0) {
                throw new \Exception('Medico non valido.');
            }

            $this->assertDoctorAllowed($idDot);

            $result = $this->pazientiModel->getPatientsByDoctorPaginate($idDot, $term, $page, $perPage, $this->getCurrentUserId());

            return $this->respondJsonSafe([
                'status'   => true,
                'rows'     => $result['rows'],
                'page'     => $result['page'],
                'perPage'  => $result['perPage'],
                'total'    => $result['total'],
                'lastPage' => $result['lastPage'],
                'from'     => $result['from'],
                'to'       => $result['to'],
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function salvaPazienteGestione()
    {
        try {
            $payload = $this->request->getPost();
            $idDot   = (int)($payload['id_dot'] ?? 0);

            if ($idDot <= 0) {
                throw new \Exception('Medico non valido.');
            }

            $this->assertDoctorAllowed($idDot);

            $idPaziente = $this->pazientiModel->savePatientAndLink($payload, $idDot, $this->getCurrentUserId());

            return $this->response->setJSON([
                'status'      => true,
                'id_paziente' => $idPaziente,
                'message'     => 'Paziente salvato correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function eliminaPaziente()
    {
        try {
            $idPaziente = (int)$this->request->getPost('id_paziente');
            $idDot      = (int)$this->request->getPost('id_dot');

            if ($idPaziente <= 0 || $idDot <= 0) {
                throw new \Exception('Parametri mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            $this->pazientiModel->deletePatientByDoctor($idPaziente, $idDot);

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Paziente eliminato correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function slotBloccati()
    {
        try {
            $currentUserId = $this->getCurrentUserId();

            if (!$this->agendaModel->canBloccareGiorno($currentUserId)) {
                throw new \Exception('Non hai i permessi per gestire gli slot bloccati.');
            }

            $medici = $this->agendaModel->getMediciVisibili($currentUserId);
            $doctorLabels = [];

            foreach ($medici as $medico) {
                $idDot = (int)($medico->id_dot ?? 0);
                if ($idDot <= 0) {
                    continue;
                }

                $doctorLabels[$idDot] = trim((string)($medico->label ?? ''));
            }

            $selectedDot = (int)($this->request->getGet('id_dot') ?? 0);
            $hasSearched = $selectedDot > 0;
            $rows = [];

            if ($selectedDot > 0) {
                $this->assertDoctorAllowed($selectedDot);

                $this->lockModel->cleanupExpiredLocks();

                $rows = $this->lockModel->getBlockedSlotsReport([$selectedDot], $selectedDot);

                foreach ($rows as &$row) {
                    $idDot = (int)($row['id_dot'] ?? 0);
                    $row['doctor_label'] = $doctorLabels[$idDot] ?? ('Dottore #' . $idDot);
                    $row['is_active_lock'] = (int)($row['id_lock_attivo'] ?? 0) > 0;
                }
                unset($row);
            }

            return view('agenda/slot_bloccati', [
                'pageTitle'   => 'Slot bloccati',
                'medici'      => $medici,
                'selectedDot' => $selectedDot,
                'hasSearched' => $hasSearched,
                'rows'        => $rows,
                'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                    ? $this->agendaModel->getMenuVisibleByUser($currentUserId)
                    : $this->agendaModel->getMenuVisible(),
                'menu_items'  => [],
            ]);
        } catch (\Exception $e) {
            return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
        }
    }

    public function sbloccaSlotBloccato()
    {
        $selectedDot = (int)($this->request->getPost('id_dot_filter') ?? 0);
        $redirectUrl = base_url('agenda/slot-bloccati' . ($selectedDot > 0 ? ('?id_dot=' . $selectedDot) : ''));

        try {
            $currentUserId = $this->getCurrentUserId();

            if (!$this->agendaModel->canBloccareGiorno($currentUserId)) {
                throw new \Exception('Non hai i permessi per gestire gli slot bloccati.');
            }

            $idSlot = (int)($this->request->getPost('id_slot') ?? 0);
            if ($idSlot <= 0) {
                throw new \Exception('Slot non valido.');
            }

            $slot = $this->db->table('dap11_agenda_slot')
                ->select('id_slot, id_dot')
                ->where('id_slot', $idSlot)
                ->get()
                ->getRowArray();

            if (!$slot) {
                throw new \Exception('Slot non trovato.');
            }

            $this->assertDoctorAllowed((int)($slot['id_dot'] ?? 0));

            $result = $this->lockModel->forceUnlockSlot($idSlot);
            if (empty($result['status'])) {
                throw new \Exception((string)($result['message'] ?? 'Errore durante lo sblocco dello slot.'));
            }

            return redirect()->to($redirectUrl)->with('success', (string)($result['message'] ?? 'Slot sbloccato correttamente.'));
        } catch (\Exception $e) {
            return redirect()->to($redirectUrl)->with('error', $e->getMessage());
        }
    }

    public function bloccaGiorno()
    {
        try {
            $idDot  = (int)$this->request->getPost('id_dot');
            $data   = (string)$this->request->getPost('data');
            $motivo = trim((string)$this->request->getPost('motivo'));

            if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
                throw new \Exception('Non hai i permessi per bloccare la giornata.');
            }

            if (!$idDot || !$data) {
                throw new \Exception('Dati mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            $this->agendaModel->bloccaGiorno($idDot, $data, $this->getCurrentUserId(), $motivo);

            return $this->response->setJSON([
                'status'  => true,
                'message' => 'Giornata bloccata correttamente.'
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function stampaPdfGiorno()
    {
        $idDot = (int)$this->request->getGet('id_dot');
        $data = trim((string)($this->request->getGet('data') ?? ''));
        $requestedView = (string)($this->request->getGet('view') ?? 'day');

        if ($data === '') {
            $data = date('Y-m-d');
        }

        $teamDayDoctors = $this->getTeamDayDoctorsForCurrentUser();
        $view = $this->normalizeAgendaViewMode($requestedView, $this->canUseTeamDayView($teamDayDoctors));

        if ($view === 'week') {
            return $this->stampaPdfSettimanaResponse($idDot, $data);
        }

        if ($view === 'team_day') {
            return $this->stampaPdfGiornoTeamResponse($idDot, $data, $teamDayDoctors);
        }

        return $this->stampaPdfGiornoSingoloResponse($idDot, $data);
    }

    private function stampaPdfGiornoSingoloResponse(int $idDot, string $data)
    {
        $this->assertDoctorAllowed($idDot);
        $this->ensureDompdfAvailable();

        $showWaConfirmationColumn = $this->shouldShowWaConfirmationColumn($idDot);

        // Il PDF deve mostrare al massimo un appuntamento attivo per slot:
        // in produzione possono esistere righe storiche annullate o duplicate
        // sullo stesso id_slot, che altrimenti verrebbero stampate piu volte.
        $activeAppointmentSubquery = $this->db->table('dap12_agenda_appuntamenti a_active')
            ->select('a_active.id_slot, MAX(a_active.id_appuntamento) AS id_appuntamento', false)
            ->where('a_active.stato <>', 'ANNULLATO')
            ->groupBy('a_active.id_slot')
            ->getCompiledSelect();

        $rows = $this->db->table('dap11_agenda_slot s')
            ->select("
                s.data_slot, s.ora_inizio, s.ora_fine,
                a.cognome, a.nome, a.telefono, a.cellulare, a.note
            ")
            ->join('(' . $activeAppointmentSubquery . ') a_sel', 'a_sel.id_slot = s.id_slot', 'left', false)
            ->join('dap12_agenda_appuntamenti a', 'a.id_appuntamento = a_sel.id_appuntamento', 'left')
            ->where('s.id_dot', $idDot)
            ->where('s.data_slot', $data)
            ->orderBy('s.ora_inizio', 'ASC')
            ->get()
            ->getResultArray();

        $dot = $this->agendaModel->getAgendaProfessionalByLegacyId($idDot) ?? [];
        $doctorLabel = $this->resolveAgendaProfessionalDisplayLabel($dot, $idDot);

        $html = '<html><head><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;}
            table{width:100%;border-collapse:collapse;margin-top:15px;}
            th,td{border:1px solid #ccc;padding:6px;}
            th{background:#f0f0f0;}
        </style></head><body>';

        $html .= '<h2>Agenda del giorno</h2>';
        $html .= '<p><strong>Dottore:</strong> ' . esc($doctorLabel) . '</p>';
        $html .= '<p><strong>Data:</strong> ' . esc($data) . '</p>';

        $html .= '<table><thead><tr>
            <th>Inizio</th>
            <th>Paziente</th>
            <th>Telefono</th>
            <th>Cellulare</th>
            ' . ($showWaConfirmationColumn ? '<th>CONFERMA WA</th>' : '') . '
            <th>Note</th>
        </tr></thead><tbody>';

        foreach ($rows as $r) {
            $waConfirmedLabel = WhatsappAppointmentNote::hasWaConfirmation((string)($r['note'] ?? '')) ? 'SI' : 'NO';
            $html .= '<tr>
                <td>' . htmlspecialchars(date('H:i', strtotime((string)$r['ora_inizio']))) . '</td>
                <td>' . htmlspecialchars(trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? ''))) . '</td>
                <td>' . htmlspecialchars((string)($r['telefono'] ?? '')) . '</td>
                <td>' . htmlspecialchars((string)($r['cellulare'] ?? '')) . '</td>
                ' . ($showWaConfirmationColumn ? '<td>' . htmlspecialchars($waConfirmedLabel) . '</td>' : '') . '
                <td>' . htmlspecialchars((string)($r['note'] ?? '')) . '</td>
            </tr>';
        }

        $html .= '</tbody></table></body></html>';

        return $this->renderAgendaPdfResponse(
            $html,
            'agenda_' . $idDot . '_' . $data . '.pdf',
            'A4',
            'landscape'
        );
    }

    private function stampaPdfSettimanaResponse(int $idDot, string $data)
    {
        $this->assertDoctorAllowed($idDot);
        $this->ensureDompdfAvailable();

        $dot = $this->agendaModel->getAgendaProfessionalByLegacyId($idDot) ?? [];
        $doctorLabel = $this->resolveAgendaProfessionalDisplayLabel($dot, $idDot);
        $weekRange = $this->resolveAgendaWeekRange($data);
        $slots = $this->slotModel->getSlotsCalendario($idDot, $data, 'week');
        $blockedMap = $this->agendaModel->getGiorniBloccatiMapForRange($idDot, $weekRange['start'], $weekRange['end']);
        $gridDuration = !empty($slots) ? $this->calcolaStepCalendario($slots) : 15;
        [$minTime, $maxTime] = $this->resolveTimelinePdfBounds($slots, $gridDuration);

        $slotsByDate = [];
        foreach ($slots as $slot) {
            $dateKey = substr((string)($slot['data_slot'] ?? ''), 0, 10);
            if ($dateKey === '') {
                continue;
            }

            $slotsByDate[$dateKey][] = $slot;
        }

        $columns = [];
        foreach ($weekRange['dates'] as $dateValue) {
            $daySlots = $slotsByDate[$dateValue] ?? [];
            $isBlocked = !empty($blockedMap[$dateValue]);
            $message = '';

            if (empty($daySlots)) {
                $message = $isBlocked
                    ? 'Giornata bloccata'
                    : $this->agendaConfigModel->getNoAgendaMessageForDate($idDot, $dateValue);
            }

            $headerBadges = [];
            if ($dateValue === $data) {
                $headerBadges[] = ['label' => 'Data attiva', 'tone' => 'primary'];
            }
            if ($isBlocked) {
                $headerBadges[] = ['label' => 'Bloccata', 'tone' => 'danger'];
            }

            $columns[] = [
                'key' => $dateValue,
                'label' => $this->formatAgendaPdfWeekdayLabel($dateValue),
                'sub_label' => $this->formatAgendaPdfShortDate($dateValue),
                'header_badges' => $headerBadges,
                'has_slots' => !empty($daySlots),
                'slots' => $daySlots,
                'message' => $message,
                'giorno_bloccato' => $isBlocked,
            ];
        }

        $timeline = $this->buildTimelinePdfTable($columns, $gridDuration, $minTime, $maxTime);
        $html = view('agenda/timeline_pdf', [
            'title' => 'Agenda settimanale',
            'subtitle' => 'Settimana dal ' . $this->formatAgendaPdfLongDate($weekRange['start']) . ' al ' . $this->formatAgendaPdfLongDate($weekRange['end']),
            'contextLabel' => $doctorLabel,
            'generatedAt' => date('d/m/Y H:i'),
            'columns' => $columns,
            'rows' => $timeline['rows'],
            'rowHeightPx' => $timeline['row_height_px'],
            'pageMode' => 'week',
        ]);

        return $this->renderAgendaPdfResponse(
            $html,
            'agenda_settimana_' . $idDot . '_' . $weekRange['start'] . '_' . $weekRange['end'] . '.pdf',
            'A3',
            'landscape'
        );
    }

    private function stampaPdfGiornoTeamResponse(int $selectedDot, string $data, array $medici)
    {
        if (!$this->canUseTeamDayView($medici)) {
            throw new \Exception('La vista giorno team non e disponibile per questo spazio.');
        }

        $this->ensureDompdfAvailable();

        if ($selectedDot <= 0 || !$this->doctorListContainsId($medici, $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        $columns = [];
        $allSlots = [];
        $selectedDoctorLabel = '';

        foreach ($medici as $medico) {
            $doctor = $this->normalizeAgendaProfessionalRow($medico);
            $idDot = (int)($doctor['id_dot'] ?? 0);
            if ($idDot <= 0) {
                continue;
            }

            $columnPayload = $this->buildTeamDayColumnPayload($doctor, $data, $idDot === $selectedDot);
            $headerBadges = [];

            if (!empty($columnPayload['is_selected'])) {
                $selectedDoctorLabel = (string)($columnPayload['label'] ?? '');
                $headerBadges[] = ['label' => 'Attivo', 'tone' => 'primary'];
            }

            if (!empty($columnPayload['giorno_bloccato'])) {
                $headerBadges[] = ['label' => 'Bloccata', 'tone' => 'danger'];
            } elseif (empty($columnPayload['has_slots'])) {
                $headerBadges[] = ['label' => 'Senza agenda', 'tone' => 'muted'];
            }

            $columns[] = [
                'key' => 'dot-' . $idDot,
                'label' => (string)($columnPayload['label'] ?? ('Professionista ' . $idDot)),
                'sub_label' => !empty($columnPayload['giorno_bloccato'])
                    ? 'Giornata bloccata'
                    : (!empty($columnPayload['has_slots']) ? $this->formatAgendaPdfLongDate($data) : 'Nessuna agenda'),
                'header_badges' => $headerBadges,
                'has_slots' => !empty($columnPayload['has_slots']),
                'slots' => is_array($columnPayload['slots'] ?? null) ? $columnPayload['slots'] : [],
                'message' => (string)($columnPayload['message'] ?? ''),
                'giorno_bloccato' => !empty($columnPayload['giorno_bloccato']),
            ];

            if (!empty($columnPayload['slots']) && is_array($columnPayload['slots'])) {
                $allSlots = array_merge($allSlots, $columnPayload['slots']);
            }
        }

        $gridDuration = !empty($allSlots) ? $this->calcolaStepCalendario($allSlots) : 15;
        [$minTime, $maxTime] = $this->resolveTimelinePdfBounds($allSlots, $gridDuration);
        $timeline = $this->buildTimelinePdfTable($columns, $gridDuration, $minTime, $maxTime);

        $html = view('agenda/timeline_pdf', [
            'title' => 'Agenda giornaliera team',
            'subtitle' => 'Vista orizzontale del team del ' . $this->formatAgendaPdfLongDate($data),
            'contextLabel' => $selectedDoctorLabel !== '' ? ('Professionista attivo: ' . $selectedDoctorLabel) : 'Tutti i professionisti visibili',
            'generatedAt' => date('d/m/Y H:i'),
            'columns' => $columns,
            'rows' => $timeline['rows'],
            'rowHeightPx' => $timeline['row_height_px'],
            'pageMode' => 'team_day',
        ]);

        return $this->renderAgendaPdfResponse(
            $html,
            'agenda_team_' . $data . '.pdf',
            'A3',
            'landscape'
        );
    }

    private function buildTimelinePdfTable(array $columns, int $stepMinutes, string $minTime, string $maxTime): array
    {
        $stepMinutes = max(5, $stepMinutes);
        $startMinutes = $this->agendaPdfTimeToMinutes($minTime) ?? 480;
        $endMinutes = $this->agendaPdfTimeToMinutes($maxTime) ?? 1080;

        if ($endMinutes <= $startMinutes) {
            $endMinutes = $startMinutes + max($stepMinutes, 60);
        }

        $timeRows = [];
        for ($minute = $startMinutes; $minute < $endMinutes; $minute += $stepMinutes) {
            $timeRows[] = $minute;
        }

        if ($timeRows === []) {
            $timeRows[] = $startMinutes;
        }

        $columnState = [];
        foreach ($columns as $index => $column) {
            $slotMap = [];

            foreach (($column['slots'] ?? []) as $slot) {
                $slotStart = $this->agendaPdfSlotStartMinutes($slot);
                if ($slotStart === null) {
                    continue;
                }

                $slotMap[$slotStart] = $slot;
            }

            ksort($slotMap);
            $columnState[$index] = [
                'slot_map' => $slotMap,
                'rowspan_skip' => 0,
            ];
        }

        $rows = [];
        $totalRows = count($timeRows);

        foreach ($timeRows as $rowIndex => $minute) {
            $row = [
                'time_label' => $this->formatAgendaPdfTimeFromMinutes($minute),
                'cells' => [],
            ];

            foreach ($columns as $index => $column) {
                if ($columnState[$index]['rowspan_skip'] > 0) {
                    $columnState[$index]['rowspan_skip']--;
                    $row['cells'][] = null;
                    continue;
                }

                $slotMap = $columnState[$index]['slot_map'];
                if (empty($slotMap) && empty($column['has_slots'])) {
                    if ($rowIndex === 0) {
                        $cell = $this->buildTimelinePdfEmptyColumnCell($column, $totalRows);
                        $columnState[$index]['rowspan_skip'] = max(0, ($cell['rowspan'] ?? 1) - 1);
                        $row['cells'][] = $cell;
                    } else {
                        $row['cells'][] = null;
                    }
                    continue;
                }

                if (isset($slotMap[$minute])) {
                    $cell = $this->buildTimelinePdfSlotCell(
                        $slotMap[$minute],
                        !empty($column['giorno_bloccato']),
                        $stepMinutes,
                        $totalRows - $rowIndex
                    );
                    $columnState[$index]['rowspan_skip'] = max(0, ($cell['rowspan'] ?? 1) - 1);
                    $row['cells'][] = $cell;
                    continue;
                }

                $row['cells'][] = [
                    'rowspan' => 1,
                    'class' => 'is-gap',
                    'time_range' => '',
                    'primary_label' => '',
                    'secondary_label' => '',
                ];
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'row_height_px' => $this->resolveTimelinePdfRowHeight($totalRows),
        ];
    }

    private function buildTimelinePdfSlotCell(array $slot, bool $giornoBloccato, int $stepMinutes, int $remainingRows): array
    {
        $startMinutes = $this->agendaPdfSlotStartMinutes($slot);
        $endMinutes = $this->agendaPdfSlotVisualEndMinutes($slot);
        $slotEndMinutes = $this->agendaPdfTimeToMinutes((string)($slot['ora_fine'] ?? ''));

        if ($startMinutes === null) {
            $startMinutes = 0;
        }

        if ($endMinutes === null || $endMinutes <= $startMinutes) {
            $endMinutes = $slotEndMinutes !== null && $slotEndMinutes > $startMinutes
                ? $slotEndMinutes
                : ($startMinutes + $stepMinutes);
        }

        $rowspan = (int)ceil(($endMinutes - $startMinutes) / max(1, $stepMinutes));
        $rowspan = max(1, min($remainingRows, $rowspan));
        $stato = strtoupper(trim((string)($slot['stato'] ?? '')));
        $hasAppointment = $this->agendaPdfSlotHasAppointment($slot);

        if (!$hasAppointment && $giornoBloccato) {
            return [
                'rowspan' => $rowspan,
                'class' => 'is-blocked',
                'time_range' => $this->formatAgendaPdfTimeFromMinutes($startMinutes),
                'primary_label' => 'Bloccato',
                'secondary_label' => 'Fascia non disponibile',
            ];
        }

        if ($stato === 'CHIUSO') {
            return [
                'rowspan' => $rowspan,
                'class' => 'is-blocked',
                'time_range' => $this->formatAgendaPdfTimeFromMinutes($startMinutes),
                'primary_label' => 'Bloccato',
                'secondary_label' => 'Fascia non disponibile',
            ];
        }

        if (!$hasAppointment) {
            return [
                'rowspan' => $rowspan,
                'class' => 'is-free',
                'time_range' => '',
                'primary_label' => '',
                'secondary_label' => '',
            ];
        }

        $patientLabel = trim((string)($slot['cognome'] ?? '') . ' ' . (string)($slot['nome'] ?? ''));
        if ($patientLabel === '') {
            $patientLabel = 'Appuntamento';
        }

        return [
            'rowspan' => $rowspan,
            'class' => $giornoBloccato ? 'is-booked-locked' : 'is-booked',
            'time_range' => $this->formatAgendaPdfTimeFromMinutes($startMinutes) . ' - ' . $this->formatAgendaPdfTimeFromMinutes($endMinutes),
            'primary_label' => $patientLabel,
            'secondary_label' => $this->buildAgendaPdfAppointmentNote($slot),
        ];
    }

    private function buildTimelinePdfEmptyColumnCell(array $column, int $rowspan): array
    {
        $isBlocked = !empty($column['giorno_bloccato']);
        $message = trim((string)($column['message'] ?? ''));

        if ($message === '') {
            $message = $isBlocked ? 'Giornata bloccata' : 'Nessuna agenda';
        }

        return [
            'rowspan' => max(1, $rowspan),
            'class' => $isBlocked ? 'is-blocked is-empty-column' : 'is-no-agenda is-empty-column',
            'time_range' => '',
            'primary_label' => $message,
            'secondary_label' => '',
        ];
    }

    private function resolveTimelinePdfBounds(array $slots, int $stepMinutes): array
    {
        if (empty($slots)) {
            return ['08:00:00', '18:00:00'];
        }

        $minTime = null;
        $maxTime = null;

        foreach ($slots as $slot) {
            $slotStart = date('H:i:s', strtotime((string)($slot['ora_inizio'] ?? '')));
            $slotEndRaw = $this->agendaPdfSlotVisualEndMinutes($slot);
            $slotEnd = $slotEndRaw !== null
                ? $this->formatAgendaPdfTimeFromMinutes($slotEndRaw) . ':00'
                : date('H:i:s', strtotime((string)($slot['ora_fine'] ?? '')));

            if ($minTime === null || $slotStart < $minTime) {
                $minTime = $slotStart;
            }

            if ($maxTime === null || $slotEnd > $maxTime) {
                $maxTime = $slotEnd;
            }
        }

        return [
            $this->floorTimeToStep($minTime ?? '08:00:00', 5),
            $this->ceilTimeToStep($maxTime ?? '18:00:00', 5),
        ];
    }

    private function resolveAgendaWeekRange(string $date): array
    {
        $anchor = \DateTimeImmutable::createFromFormat('Y-m-d', $date) ?: new \DateTimeImmutable();
        $weekday = (int)$anchor->format('N');
        $weekStart = $anchor->modify('-' . ($weekday - 1) . ' days');
        $dates = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $dates[] = $weekStart->modify('+' . $offset . ' days')->format('Y-m-d');
        }

        return [
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekStart->modify('+6 days')->format('Y-m-d'),
            'dates' => $dates,
        ];
    }

    private function resolveAgendaProfessionalDisplayLabel(array $doctor, int $fallbackId = 0): string
    {
        $label = trim((string)($doctor['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $label = trim((string)($doctor['cognome'] ?? '') . ' ' . (string)($doctor['nome'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return $fallbackId > 0 ? ('Professionista #' . $fallbackId) : 'Professionista';
    }

    private function buildAgendaPdfAppointmentNote(array $slot): string
    {
        $pieces = [];
        $visitType = $this->buildAgendaPdfVisitTypeLabel($slot);
        $note = trim((string)($slot['note'] ?? ''));
        $createdByUsername = trim((string)($slot['created_by_username'] ?? ''));

        if ($visitType !== '') {
            $pieces[] = $visitType;
        }

        if ($note !== '') {
            $pieces[] = $note;
        }

        if ($createdByUsername !== '') {
            $createdByLabel = 'Utente: ' . $createdByUsername;
            $noteLower = function_exists('mb_strtolower')
                ? mb_strtolower($note, 'UTF-8')
                : strtolower($note);
            $createdByLower = function_exists('mb_strtolower')
                ? mb_strtolower($createdByLabel, 'UTF-8')
                : strtolower($createdByLabel);

            if ($note === '' || strpos($noteLower, $createdByLower) === false) {
                $pieces[] = $createdByLabel;
            }
        }

        $pieces = array_values(array_unique(array_filter(array_map('trim', $pieces), static fn(string $value): bool => $value !== '')));

        return implode(' - ', $pieces);
    }

    private function buildAgendaPdfVisitTypeLabel(array $slot): string
    {
        $label = trim((string)($slot['tipo_visita_label'] ?? ''));
        $duration = (int)($slot['appointment_durata_minuti'] ?? 0);

        if ($label !== '' && $duration > 0) {
            return $label . ' (' . $duration . ' min)';
        }

        if ($label !== '') {
            return $label;
        }

        return $duration > 0 ? ($duration . ' min') : '';
    }

    private function agendaPdfSlotHasAppointment(array $slot): bool
    {
        $appointmentId = (int)($slot['id_appuntamento'] ?? 0);
        $stato = strtoupper(trim((string)($slot['stato'] ?? '')));

        return $appointmentId > 0 || !in_array($stato, ['LIBERO', 'BLOCCATO', 'CHIUSO'], true);
    }

    private function agendaPdfSlotStartMinutes(array $slot): ?int
    {
        return $this->agendaPdfTimeToMinutes((string)($slot['ora_inizio'] ?? ''));
    }

    private function agendaPdfSlotVisualEndMinutes(array $slot): ?int
    {
        $explicitEnd = trim((string)($slot['appointment_ora_fine'] ?? ''));
        $isCoveredSecondary = (int)($slot['id_appuntamento'] ?? 0) > 0
            && (int)($slot['appointment_is_primary_slot'] ?? 0) !== 1;

        if ($explicitEnd !== '' && !$isCoveredSecondary) {
            return $this->agendaPdfTimeToMinutes($explicitEnd);
        }

        return $this->agendaPdfTimeToMinutes((string)($slot['ora_fine'] ?? ''));
    }

    private function agendaPdfTimeToMinutes(string $time): ?int
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }

        if (!preg_match('/(\d{2}):(\d{2})/', $time, $matches)) {
            return null;
        }

        return ((int)$matches[1] * 60) + (int)$matches[2];
    }

    private function formatAgendaPdfTimeFromMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function formatAgendaPdfShortDate(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp === false ? $date : date('d/m', $timestamp);
    }

    private function formatAgendaPdfLongDate(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp === false ? $date : date('d/m/Y', $timestamp);
    }

    private function formatAgendaPdfWeekdayLabel(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        $days = [
            1 => 'Lunedi',
            2 => 'Martedi',
            3 => 'Mercoledi',
            4 => 'Giovedi',
            5 => 'Venerdi',
            6 => 'Sabato',
            7 => 'Domenica',
        ];

        return $days[(int)date('N', $timestamp)] ?? $date;
    }

    private function resolveTimelinePdfRowHeight(int $rowCount): int
    {
        if ($rowCount >= 110) {
            return 8;
        }

        if ($rowCount >= 85) {
            return 10;
        }

        if ($rowCount >= 60) {
            return 12;
        }

        if ($rowCount >= 44) {
            return 14;
        }

        return 18;
    }

    private function ensureDompdfAvailable(): void
    {
        if (!class_exists('\Dompdf\Dompdf')) {
            throw new \Exception('Dompdf non disponibile.');
        }
    }

    private function renderAgendaPdfResponse(string $html, string $filename, $paper, string $orientation = 'portrait')
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($dompdf->output());
    }

    public function stampaPdfMemo()
    {
        $idDot = (int)$this->request->getGet('id_dot');

        if ($idDot <= 0) {
            throw new \Exception('Dottore non valido.');
        }

        $this->assertDoctorAllowed($idDot);

        if (!class_exists('\Dompdf\Dompdf')) {
            throw new \Exception('Dompdf non disponibile.');
        }

        $dot = $this->agendaModel->getAgendaProfessionalByLegacyId($idDot) ?? [];
        $doctorLabel = trim(($dot['cognome'] ?? '') . ' ' . ($dot['nome'] ?? ''));
        if ($doctorLabel === '') {
            $doctorLabel = 'Dottore #' . $idDot;
        }

        $today = date('Y-m-d');
        $notes = array_map(function (array $row) use ($today): array {
            return $this->buildMemoPdfRow($row, $today);
        }, $this->noteModel->getNoteByDoctor($idDot));

        $html = view('agenda/memo_pdf', [
            'doctorLabel' => $doctorLabel,
            'notes'       => $notes,
            'generatedAt' => date('d/m/Y H:i'),
            'todayLabel'  => $this->formatMemoPdfDate($today),
            'totalNotes'  => count($notes),
        ]);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="memo_' . $idDot . '_' . date('Ymd_His') . '.pdf"')
            ->setBody($dompdf->output());
    }

    private function buildMemoPdfRow(array $row, string $today): array
    {
        $dataValidita = trim((string)($row['data_inizio_validita'] ?? ''));
        $statusClass = 'status-oggi';
        $statusBadgeClass = 'badge-oggi';
        $statusLabel = 'Oggi';

        if ($dataValidita !== '') {
            if ($dataValidita < $today) {
                $statusClass = 'status-scaduta';
                $statusBadgeClass = 'badge-scaduta';
                $statusLabel = 'Scaduta';
            } elseif ($dataValidita > $today) {
                $statusClass = 'status-futura';
                $statusBadgeClass = 'badge-futura';
                $statusLabel = 'Futura';
            }
        }

        return [
            'cliente_label'      => trim((string)($row['cliente'] ?? '')),
            'telefono'           => trim((string)($row['telefono'] ?? '')),
            'cellulare'          => trim((string)($row['cellulare'] ?? '')),
            'indirizzo'          => trim((string)($row['indirizzo'] ?? '')),
            'citta'              => trim((string)($row['citta'] ?? '')),
            'note'               => trim((string)($row['note'] ?? '')),
            'created_by_username'=> trim((string)($row['created_by_username'] ?? '')),
            'data_validita_label'=> $this->formatMemoPdfDate($dataValidita),
            'created_at_label'   => $this->formatMemoPdfDateTime((string)($row['created_at'] ?? '')),
            'status_class'       => $statusClass,
            'status_badge_class' => $statusBadgeClass,
            'status_label'       => $statusLabel,
        ];
    }

    private function formatMemoPdfDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date instanceof \DateTime) {
                return $date->format('d/m/Y');
            }
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('d/m/Y', $timestamp) : $value;
    }

    private function formatMemoPdfDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date
                ->setTimezone(new \DateTimeZone('Europe/Rome'))
                ->format('d/m/Y H:i');
        } catch (\Exception $e) {
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
    }

    public function stampaTicketAppuntamento(int $idAppuntamento)
    {
        try {
            if ($idAppuntamento <= 0) {
                throw new \Exception('Appuntamento non valido.');
            }

            $idDot = $this->getIdDotFromAppuntamento($idAppuntamento);
            if ($idDot <= 0) {
                throw new \Exception('Dottore non trovato per questo appuntamento.');
            }

            $this->assertDoctorAllowed($idDot);

            $row = $this->db->table('dap12_agenda_appuntamenti a')
                ->select("
                    a.id_appuntamento,
                    a.id_paziente,
                    a.id_client,
                    a.cognome,
                    a.nome,
                    a.telefono,
                    a.cellulare,
                    a.email,
                    a.note,
                    s.id_slot,
                    s.id_dot,
                    s.data_slot,
                    s.ora_inizio,
                    s.ora_fine,
                    s.id_amb_legacy,
                    s.ambulatorio,
                    s.stanza
                ")
                ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
                ->where('a.id_appuntamento', $idAppuntamento)
                ->where('a.stato <>', 'ANNULLATO')
                ->get()
                ->getRowArray();

            if (!$row) {
                throw new \Exception('Appuntamento non trovato.');
            }

            if (!class_exists('\Dompdf\Dompdf')) {
                throw new \Exception('Dompdf non disponibile.');
            }

            $doctor = $this->agendaModel->getAgendaProfessionalByLegacyId((int)$row['id_dot']) ?? [];
            $doctorLabel = trim(
                implode(' ', array_filter([
                    trim((string)($doctor['titolo'] ?? '')),
                    trim((string)($doctor['nome'] ?? '')),
                    trim((string)($doctor['cognome'] ?? '')),
                ], static fn($value) => $value !== ''))
            );

            $ambulatorio = $this->db->table('dap42_ambulatori')
                ->where('id_amb_legacy', (int)($row['id_amb_legacy'] ?? 0))
                ->get()
                ->getRowArray();

            $logoDataUri = $this->buildAppointmentTicketLogoDataUri($ambulatorio);
            $ticketData = [
                'patient_label' => trim((string)($row['cognome'] ?? '') . ' ' . (string)($row['nome'] ?? '')),
                'doctor_label' => $doctorLabel,
                'weekday_label' => $this->formatItalianShortWeekday((string)$row['data_slot']),
                'date_label' => $this->formatItalianDate((string)$row['data_slot']),
                'time_label' => date('H:i', strtotime((string)$row['ora_inizio'])),
                'ambulatorio_label' => trim((string)($ambulatorio['nome'] ?? ($row['ambulatorio'] ?? ''))),
                'indirizzo' => trim((string)($ambulatorio['indirizzo'] ?? '')),
                'citta' => trim((string)($ambulatorio['citta'] ?? '')),
                'telefono' => trim((string)($ambulatorio['telefono'] ?? '')),
                'stanza' => trim((string)($row['stanza'] ?? '')),
                'logo_data_uri' => $logoDataUri,
            ];

            $html = view('agenda/ticket_appuntamento_pdf', $ticketData);

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper([0, 0, 178.56, 700], 'portrait');
            $dompdf->render();

            return $this->response
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'inline; filename="promemoria_appuntamento_' . $idAppuntamento . '.pdf"')
                ->setBody($dompdf->output());
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(400)
                ->setBody($e->getMessage());
        }
    }

    public function aggiungiSlotExtra()
    {
        try {
            $payload = [
                'id_dot'       => (int)$this->request->getPost('id_dot'),
                'data'         => (string)$this->request->getPost('data'),
                'ora_inizio'   => (string)$this->request->getPost('ora_inizio'),
                'ora_fine'     => (string)$this->request->getPost('ora_fine'),
                'durata_slot'  => (string)$this->request->getPost('durata_slot'),
                'ambulatorio'  => (string)$this->request->getPost('ambulatorio'),
                'stanza'       => (string)$this->request->getPost('stanza'),
            ];

            $idDot = (int)$payload['id_dot'];
            $data = (string)$payload['data'];
            if (!$idDot || !$data || empty($payload['ora_inizio'])) {
                throw new \Exception('Dati mancanti.');
            }

            $this->assertDoctorAllowed($idDot);

            $result = $this->noteModel->insertExtraSlotsForSingleDay($payload);

            if ((int)($result['inserted'] ?? 0) <= 0) {
                if (!empty($result['collisioni'])) {
                    throw new \Exception($this->buildExtraSlotCollisionMessage((array)$result['collisioni']));
                }

                throw new \Exception('Nessuno slot extra inserito.');
            }

            $ultimoSlot = !empty($result['inseriti']) ? $result['inseriti'][count($result['inseriti']) - 1] : null;
            $messaggio = 'Slot extra aggiunti correttamente.';
            if ((int)($result['inserted'] ?? 0) > 1) {
                $messaggio = 'Slot extra aggiunti correttamente. Inseriti ' . (int)$result['inserted'] . ' slot.';
            }
            if (!empty($result['collisioni'])) {
                $messaggio .= ' Slot gia presenti saltati: ' . count($result['collisioni']) . '.';
            }

            return $this->response->setJSON([
                'status'   => true,
                'message'  => $messaggio,
                'ora_fine' => $ultimoSlot['ora_fine'] ?? null,
                'result'   => $result,
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function buildExtraSlotCollisionMessage(array $collisioni): string
    {
        $collisioni = array_values(array_filter($collisioni, static fn($row) => is_array($row)));
        if ($collisioni === []) {
            return 'Gli orari richiesti sono gia presenti in agenda.';
        }

        if (count($collisioni) === 1) {
            $row = $collisioni[0];
            $oraInizio = trim((string)($row['ora_inizio'] ?? ''));
            $oraFine = trim((string)($row['ora_fine'] ?? ''));
            $fascia = ($oraInizio !== '' && $oraFine !== '')
                ? ($oraInizio . ' - ' . $oraFine)
                : 'richiesta';
            $origine = strtoupper(trim((string)($row['origine_slot'] ?? '')));

            if ($origine === 'CONFIG') {
                return 'Lo slot ' . $fascia . ' e gia presente in agenda come slot configurato. Non serve aggiungere uno slot extra.';
            }

            if ($origine === 'EXTRA') {
                return 'Lo slot extra ' . $fascia . ' e gia presente con lo stesso orario.';
            }

            return 'Lo slot ' . $fascia . ' e gia presente in agenda con lo stesso orario.';
        }

        $configCount = 0;
        $extraCount = 0;

        foreach ($collisioni as $row) {
            $origine = strtoupper(trim((string)($row['origine_slot'] ?? '')));
            if ($origine === 'CONFIG') {
                $configCount++;
                continue;
            }

            if ($origine === 'EXTRA') {
                $extraCount++;
            }
        }

        if ($configCount > 0 && $extraCount === 0) {
            return 'Gli orari richiesti sono gia presenti in agenda come slot configurati. Non serve aggiungere slot extra.';
        }

        if ($extraCount > 0 && $configCount === 0) {
            return 'Tutti gli slot extra richiesti sono gia presenti con lo stesso orario.';
        }

        return 'Gli orari richiesti sono gia presenti in agenda; alcuni come slot configurati e altri come slot extra.';
    }

    private function formatItalianShortWeekday(string $date): string
    {
        if ($date === '') {
            return '';
        }

        try {
            $formatter = new \IntlDateFormatter(
                'it_IT',
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::NONE,
                date_default_timezone_get(),
                \IntlDateFormatter::GREGORIAN,
                'EEE'
            );
            $text = (string)$formatter->format(new \DateTimeImmutable($date));
            $text = trim(str_replace('.', '', $text));
            return $text !== '' ? ucfirst($text) : '';
        } catch (\Throwable $e) {
            $map = [
                1 => 'Lun',
                2 => 'Mar',
                3 => 'Mer',
                4 => 'Gio',
                5 => 'Ven',
                6 => 'Sab',
                7 => 'Dom',
            ];
            $day = (int)date('N', strtotime($date));
            return $map[$day] ?? '';
        }
    }

    private function formatItalianDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        return date('d/m/Y', strtotime($date));
    }

    private function buildAppointmentTicketLogoDataUri(?array $ambulatorio): ?string
    {
        $binaryLogo = (string)($ambulatorio['logo'] ?? '');
        if ($binaryLogo !== '') {
            return 'data:' . $this->guessImageMime($binaryLogo) . ';base64,' . base64_encode($binaryLogo);
        }

        $fallbackPaths = [
            FCPATH . 'public/assets/images/logonew.jpg',
            FCPATH . 'public/assets/images/logonew.png',
            FCPATH . 'public/assets/images/logo.jpg',
            FCPATH . 'public/assets/images/logo.png',
        ];

        foreach ($fallbackPaths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $binary = @file_get_contents($path);
            if ($binary === false || $binary === '') {
                continue;
            }

            return 'data:' . $this->guessImageMime($binary, $path) . ';base64,' . base64_encode($binary);
        }

        return null;
    }

    private function guessImageMime(string $binary, string $pathHint = ''): string
    {
        if ($pathHint !== '') {
            $ext = strtolower((string)pathinfo($pathHint, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                return 'image/jpeg';
            }
            if ($ext === 'png') {
                return 'image/png';
            }
            if ($ext === 'gif') {
                return 'image/gif';
            }
            if ($ext === 'webp') {
                return 'image/webp';
            }
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_buffer($finfo, $binary);
                @finfo_close($finfo);
                if (is_string($mime) && str_starts_with($mime, 'image/')) {
                    return $mime;
                }
            }
        }

        if (substr($binary, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }
        if (substr($binary, 0, 3) === "\xFF\xD8\xFF") {
            return 'image/jpeg';
        }
        if (substr($binary, 0, 6) === 'GIF87a' || substr($binary, 0, 6) === 'GIF89a') {
            return 'image/gif';
        }
        if (substr($binary, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/png';
    }
public function eliminaSlotExtraView()
{
    try {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/elimina_slot_extra', [
            'pageTitle'   => 'Elimina slot extra',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function listaSlotExtra()
{
    try {
        $idDot = (int)$this->request->getGet('id_dot');
        $dataInizio = (string)$this->request->getGet('data_inizio');
        $dataFine = (string)$this->request->getGet('data_fine');
        $page = max(1, (int)($this->request->getGet('page') ?? 1));
        $perPage = 20;

        if ($idDot <= 0) {
            throw new \Exception('Dottore non valido.');
        }

        $this->assertDoctorAllowed($idDot);

        $result = $this->agendaModel->getSlotExtraByDoctorPaginate(
            $idDot,
            $dataInizio ?: null,
            $dataFine ?: null,
            $page,
            $perPage
        );

        return $this->response->setJSON([
            'status' => true,
            'rows' => $result['rows'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'total' => $result['total'],
            'lastPage' => $result['lastPage'],
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status' => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function eliminaSlotExtraSelezionati()
{
    try {
        $idDot = (int)$this->request->getPost('id_dot');
        $slotIds = $this->request->getPost('slot_ids');
        $forceDelete = (int)$this->request->getPost('force_delete') === 1;

        if (!is_array($slotIds)) {
            $slotIds = [];
        }

        if ($idDot <= 0) {
            throw new \Exception('Dottore non valido.');
        }

        $this->assertDoctorAllowed($idDot);

        $result = $this->agendaModel->deleteExtraSlotsByIds($slotIds, $idDot, $forceDelete);

        if (!empty($result['hasAppointments'])) {
            return $this->response->setJSON([
                'status' => false,
                'requires_confirmation' => true,
                'message' => $result['message'] ?? 'Sono presenti appuntamenti attivi.',
                'appointments' => $result['appointments'] ?? [],
                'deletable_slot_ids' => $result['deletable_slot_ids'] ?? [],
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => $result['message'] ?? 'Eliminazione completata.',
            'deleted_slots' => $result['deleted_slots'] ?? 0,
            'deleted_appointments' => $result['deleted_appointments'] ?? 0,
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status' => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function copiaAppuntamentiPeriodo()
{
    try {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/copia_appuntamenti_periodo', [
            'pageTitle'   => 'Copia appuntamenti per periodo',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function eseguiCopiaAppuntamentiPeriodo()
{
    try {
        $payload = $this->request->getPost();

        $idDot          = (int)($payload['id_dot'] ?? 0);
        $giornoRif      = (string)($payload['giorno_riferimento'] ?? '');
        $slotOraInizio  = (string)($payload['slot_ora_inizio'] ?? '');
        $dataInizio     = (string)($payload['data_inizio'] ?? '');
        $dataFine       = (string)($payload['data_fine'] ?? '');
        $idPaziente     = (int)($payload['id_paziente'] ?? 0);

        if ($idDot <= 0) {
            throw new \Exception('Medico non valido.');
        }

        if ($giornoRif === '') {
            throw new \Exception('Seleziona il giorno di riferimento.');
        }

        if ($slotOraInizio === '') {
            throw new \Exception('Seleziona lo slot da copiare.');
        }

        if ($dataInizio === '' || $dataFine === '') {
            throw new \Exception('Seleziona data inizio e data fine.');
        }

        if ($dataInizio > $dataFine) {
            throw new \Exception('La data fine deve essere uguale o successiva alla data inizio.');
        }

        if ($idPaziente <= 0) {
            throw new \Exception('Seleziona il paziente.');
        }

        $this->assertDoctorAllowed($idDot);

        // controllo che il giorno di riferimento abbia realmente un'agenda
        $orariGiorno = $this->agendaModel->getOrariAgendaByDoctorAndDate($idDot, $giornoRif);
        if (empty($orariGiorno)) {
            throw new \Exception('Prima devi creare l\'agenda per il giorno di riferimento.');
        }

        $result = $this->agendaModel->copyPatientOnSameSlotForPeriod([
            'id_dot'             => $idDot,
            'giorno_riferimento' => $giornoRif,
            'slot_ora_inizio'    => $slotOraInizio,
            'data_inizio'        => $dataInizio,
            'data_fine'          => $dataFine,
            'id_paziente'        => $idPaziente,
        ], $this->getCurrentUserId());

        $msg = 'Operazione completata. Creati ' . (int)$result['creati'] . ' appuntamenti';

        if (!empty($result['gia_pieni'])) {
            $msg .= '. Slot gia pieni: ' . count($result['gia_pieni']);
        }

        if (!empty($result['giorni_bloccati'])) {
            $msg .= '. Giorni bloccati: ' . count($result['giorni_bloccati']);
        }

        if (!empty($result['slot_non_trovati'])) {
            $msg .= '. Slot non trovati: ' . count($result['slot_non_trovati']);
        }

        return $this->respondJsonSafe([
            'status'  => true,
            'message' => $msg,
            'result'  => $result,
        ]);
    } catch (\Throwable $e) {
        return $this->respondJsonSafe([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function copiaAppuntamentiSettimanali()
{
    try {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/copia_appuntamenti_settimanali', [
            'pageTitle'   => 'Copia appuntamenti settimanale',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function eseguiCopiaAppuntamentiSettimanali()
{
    try {
        $payload = $this->request->getPost();

        $idDot        = (int)($payload['id_dot'] ?? 0);
        $dataSorgente = (string)($payload['data_sorgente'] ?? '');
        $oraInizio    = (string)($payload['ora_inizio'] ?? '');
        $oraFine      = (string)($payload['ora_fine'] ?? '');
        $dataFine     = (string)($payload['data_fine'] ?? '');

        if ($idDot <= 0) {
            throw new \Exception('Medico non valido.');
        }

        if ($dataSorgente === '') {
            throw new \Exception('Seleziona il giorno sorgente.');
        }

        if ($oraInizio === '' || $oraFine === '') {
            throw new \Exception('Seleziona ora inizio e ora fine.');
        }

        if ($oraInizio >= $oraFine) {
            throw new \Exception('L\'ora fine deve essere successiva all\'ora inizio.');
        }

        if ($dataFine === '') {
            throw new \Exception('Seleziona la data finale.');
        }

        if ($dataFine <= $dataSorgente) {
            throw new \Exception('La data finale deve essere successiva al giorno sorgente.');
        }

        $this->assertDoctorAllowed($idDot);

        if ($this->agendaModel->isGiornoBloccato($idDot, $dataSorgente)) {
            throw new \Exception('Il giorno sorgente Ã¨ bloccato.');
        }

        $orariGiorno = $this->agendaModel->getOrariAgendaByDoctorAndDate($idDot, $dataSorgente);
        if (empty($orariGiorno)) {
            throw new \Exception('Prima devi creare l\'agenda per questo giorno.');
        }

        $result = $this->agendaModel->copyWeeklyAppointmentsFromDayRange([
            'id_dot'        => $idDot,
            'data_sorgente' => $dataSorgente,
            'ora_inizio'    => $oraInizio,
            'ora_fine'      => $oraFine,
            'data_fine'     => $dataFine,
        ], $this->getCurrentUserId());

        $msg = 'Operazione completata. Creati ' . (int)$result['creati'] . ' appuntamenti';

        if (!empty($result['giorni_bloccati'])) {
            $msg .= '. Giorni bloccati: ' . count($result['giorni_bloccati']);
        }

        if (!empty($result['slot_non_trovati'])) {
            $msg .= '. Slot non trovati: ' . count($result['slot_non_trovati']);
        }

        if (!empty($result['gia_pieni'])) {
            $msg .= '. Slot gia pieni: ' . count($result['gia_pieni']);
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => $msg,
            'result'  => $result,
        ]);
    } catch (\Throwable $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function gestioneFerie()
{
    try {
        if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per gestire le ferie.');
        }

        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        return view('agenda/gestione_ferie', [
            'pageTitle'   => 'Gestione ferie',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}

public function salvaFeriePeriodo()
{
    try {
        if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per gestire le ferie.');
        }

        $payload = $this->request->getPost();
        $idDot = (int)($payload['id_dot'] ?? 0);

        $this->assertDoctorAllowed($idDot);

        $result = $this->agendaModel->bloccaFeriePeriodo($payload, $this->getCurrentUserId());

        $msg = 'Operazione completata. Giorni ferie bloccati: ' . (int)$result['bloccati'];

        if (!empty($result['gia_bloccati'])) {
            $msg .= '. Giorni giÃ  bloccati: ' . count($result['gia_bloccati']);
        }

        if (!empty($result['con_prenotazioni'])) {
            $msg .= '. Giorni con prenotazioni: ' . count($result['con_prenotazioni']);
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => $msg,
            'result'  => $result,
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function gestioneSmsAppuntamenti()
{
    try {
        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());
        $doctorIds = array_values(array_unique(array_filter(array_map(
            static function ($medico): int {
                return (int) (is_object($medico) ? ($medico->id_dot ?? 0) : ($medico['id_dot'] ?? 0));
            },
            $medici
        ), static fn(int $id): bool => $id > 0)));

        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        $configCorrente = null;
        if ($selectedDot > 0) {
            $this->assertDoctorAllowed($selectedDot);
            $configCorrente = $this->agendaModel->getSmsAppointmentConfigByDoctor($selectedDot);
        }

        $abilitati = $this->agendaModel->getSmsAppointmentsEnabledByDoctor();
        $smsDashboard = (new SmsReminderDashboardService($this->db))
            ->buildDashboard($doctorIds, $selectedDot, 30, 50);

        return view('agenda/gestione_sms_appuntamenti', [
            'pageTitle'      => 'Gestione SMS appuntamenti',
            'medici'         => $medici,
            'selectedDot'    => $selectedDot,
            'configCorrente' => $configCorrente,
            'abilitati'      => $abilitati,
            'smsDashboard'   => $smsDashboard,
            'appointmentNotificationsAvailable' => (bool) (($this->tenantContextService->getCurrentTenant()?->allows('appointment_notifications')) ?? false),
            'menuAgenda'     => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'     => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function salvaSmsAppuntamenti()
{
    try {
        $idDot = (int)($this->request->getPost('id_dot') ?? 0);
        $conferma = (int)($this->request->getPost('conferma') ?? 0);

        if ($idDot <= 0) {
            throw new \Exception('Dottore non valido.');
        }

        $this->assertDoctorAllowed($idDot);

        $ok = $this->agendaModel->saveSmsAppointmentConfig($idDot, $conferma ? 1 : 0);

        if (!$ok) {
            throw new \Exception('Errore durante il salvataggio della configurazione SMS.');
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'Configurazione SMS salvata correttamente.',
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function disattivaSmsAppuntamenti()
{
    try {
        $idSms = (int)($this->request->getPost('id_sms') ?? 0);

        if ($idSms <= 0) {
            throw new \Exception('Configurazione non valida.');
        }

        $row = $this->agendaModel->getSmsAppointmentConfigById($idSms);
        if (!$row) {
            throw new \Exception('Configurazione SMS non trovata.');
        }

        $this->assertDoctorAllowed((int)$row['id_dot']);

        $ok = $this->agendaModel->disableSmsAppointmentConfig($idSms);

        if (!$ok) {
            throw new \Exception('Errore durante la disattivazione.');
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'SMS appuntamenti disattivati correttamente.',
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function elencoFerie()
{
    try {
        if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per visualizzare le ferie.');
        }

        $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());
        $selectedDot = (int)($this->request->getGet('id_dot') ?: $this->getFirstVisibleDoctorId($medici));
        $page = max(1, (int)($this->request->getGet('page') ?? 1));
        $perPage = 20;

        if ($selectedDot > 0 && !$this->agendaModel->canUserAccessDoctor($this->getCurrentUserId(), $selectedDot)) {
            $selectedDot = $this->getFirstVisibleDoctorId($medici);
        }

        $rows = [];
        $total = 0;
        $lastPage = 1;

        if ($selectedDot > 0) {
            $this->assertDoctorAllowed($selectedDot);

            $result = $this->agendaModel->getFerieByDoctorPaginate($selectedDot, $perPage, $page);
            $rows = $result['rows'];
            $total = $result['total'];
            $lastPage = $result['lastPage'];
            $page = min($result['page'], $lastPage);
        }

        return view('agenda/elenco_ferie', [
            'pageTitle'   => 'Elenco ferie',
            'medici'      => $medici,
            'selectedDot' => $selectedDot,
            'rows'        => $rows,
            'page'        => $page,
            'perPage'     => $perPage,
            'total'       => $total,
            'lastPage'    => $lastPage,
            'menuAgenda'  => method_exists($this->agendaModel, 'getMenuVisibleByUser')
                ? $this->agendaModel->getMenuVisibleByUser($this->getCurrentUserId())
                : $this->agendaModel->getMenuVisible(),
            'menu_items'  => [],
        ]);
    } catch (\Exception $e) {
        return redirect()->to(base_url('agenda'))->with('error', $e->getMessage());
    }
}
public function eliminaGiornoFerie()
{
    try {
        if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per eliminare le ferie.');
        }

        $idGiornoBloccato = (int)($this->request->getPost('id_giorno_bloccato') ?? 0);
        if ($idGiornoBloccato <= 0) {
            throw new \Exception('Record non valido.');
        }

        $row = $this->agendaModel->findGiornoFerieById($idGiornoBloccato);

        if (!$row) {
            throw new \Exception('Giorno ferie non trovato.');
        }

        $this->assertDoctorAllowed((int)$row['id_dot']);

        $this->agendaModel->deleteGiornoFerie($idGiornoBloccato);

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'Giorno ferie eliminato correttamente.',
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
public function eliminaGiorniFerieSelezionati()
{
    try {
        if (!$this->agendaModel->canBloccareGiorno($this->getCurrentUserId())) {
            throw new \Exception('Non hai i permessi per eliminare le ferie.');
        }

        $ids = $this->request->getPost('ids');
        if (!is_array($ids) || empty($ids)) {
            throw new \Exception('Seleziona almeno un giorno ferie da eliminare.');
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));

        $rows = $this->agendaModel->findGiorniFerieByIds($ids);

        foreach ($rows as $row) {
            $this->assertDoctorAllowed((int)$row['id_dot']);
        }

        $result = $this->agendaModel->deleteGiorniFerieByIds($ids);

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'Giorni ferie eliminati: ' . (int)$result['eliminati'],
            'result'  => $result,
        ]);
    } catch (\Exception $e) {
        return $this->response->setJSON([
            'status'  => false,
            'message' => $e->getMessage(),
        ]);
    }
}
    private function getDurataSlotPerGiorno(int $idDot, string $data): int
    {
        $giornoSettimana = (int)date('N', strtotime($data));

        $config = $this->db->table('dap10_agenda_config')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('data_inizio <=', $data)
            ->where('data_fine >=', $data)
            ->orderBy('id_config', 'DESC')
            ->get()
            ->getRowArray();

        if (!$config) {
            return 0;
        }

        $giorno = $this->db->table('dap10_agenda_config_giorni')
            ->where('id_config', (int)$config['id_config'])
            ->where('giorno_settimana', $giornoSettimana)
            ->get()
            ->getRowArray();

        if (!$giorno) {
            return 0;
        }

        $durMattina = (int)($giorno['mattina_durata_slot'] ?? 0);
        $durPome    = (int)($giorno['pomeriggio_durata_slot'] ?? 0);

        if ($durMattina > 0) {
            return $durMattina;
        }

        if ($durPome > 0) {
            return $durPome;
        }

        return 0;
    }

    public function generaSlotPeriodo()
    {
        try {
            $payload = $this->request->getPost();
            $payload['created_by'] = $this->getCurrentUserId();

            $idDot = (int)($payload['id_dot'] ?? 0);
            $this->assertDoctorAllowed($idDot);

            $inserted = $this->slotModel->generateFromConfig($payload);

            return $this->response->setJSON([
                'status'   => true,
                'inserted' => $inserted,
                'message'  => 'Slot generati correttamente.'
            ]);
        } catch (Exception $e) {
            return $this->response->setJSON([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function refresh()
    {
        try {
            $idDot = (int)$this->request->getGet('id_dot');
            $data  = $this->request->getGet('data') ?: date('Y-m-d');
            $view  = $this->request->getGet('view') ?: 'day';

            $this->assertDoctorAllowed($idDot);

            $medici = $this->agendaModel->getMediciVisibili($this->getCurrentUserId());
            $domiciliariAbilitati = $this->isDomiciliareAbilitatoPerDottore($medici, $idDot);
            $noteRows = $this->isSharedAgendaMemosFeatureEnabled()
                ? $this->noteModel->getNoteByDoctors($this->getSharedAgendaMemoDoctorIds())
                : $this->noteModel->getNoteByDoctor($idDot);

            return $this->respondJsonSafe([
                'status'      => true,
                'slots'       => $this->slotModel->getSlotsCalendario($idDot, $data, $view),
                'domiciliari' => $domiciliariAbilitati ? $this->slotModel->getDomiciliari($idDot, $data) : [],
                'note'        => $this->enrichMemoRowsForResponse($noteRows, $data),
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            return $this->respondJsonSafe([
                'status'  => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    private function resolveAgendaDateFromPayload(array $payload, string $fallback = ''): string
    {
        $value = trim((string)($payload['agenda_data'] ?? ($payload['data_agenda'] ?? $fallback)));
        if ($value === '') {
            return '';
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        return ($dt instanceof \DateTime && $dt->format('Y-m-d') === $value) ? $value : '';
    }

    private function assertMemoActionAllowed(int $idDot, string $agendaData): void
    {
        if ($agendaData === '') {
            return;
        }

        // Memo actions are blocked only by the dedicated memo day lock.
        if (false && $this->agendaModel->isGiornoBloccato($idDot, $agendaData)) {
            throw new \Exception('La giornata agenda e bloccata.');
        }

        if ($this->agendaModel->isMemoGiornoBloccato($idDot, $agendaData)) {
            throw new \Exception('Il giorno selezionato e bloccato per le memo.');
        }
    }
}

