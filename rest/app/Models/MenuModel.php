<?php
namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper; // Importa la libreria
use App\Libraries\DatabaseConfig;
use App\Config\MessageRoles;
use App\Services\MessageService;
use App\Services\StaffDoctorAccessService;

class MenuModel extends Model
{
    protected $table      = 'dap02_clients';
    protected $primaryKey = 'id_client';

    protected $allowedFields = [
        'avviso_mail', 'cellulare', 'citta', 'codice_fiscale', 'cognome', 
        'email', 'id_personale', 'id_user', 'indirizzo', 'nome', 'provincia', 'vector_id'
    ];

    // Attivazione della gestione automatica di created_at e updated_at
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    private function normalizeLegacyString($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function normalizeLegacyRows(array $rows, array $keys): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $this->normalizeLegacyString($row[$key]);
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function getMessageService($db): MessageService
    {
        return new MessageService($db, new DatabaseConfig());
    }

    private function detectMessageRoleForObject($obj): string
    {
        if (!is_object($obj)) {
            return 'UNKNOWN';
        }

        if ((int)($obj->tipo ?? 0) === 3) {
            return MessageRoles::ROLE_PATIENT;
        }

        $tipoPers = (int)($obj->tipo_pers ?? 0);
        if ($tipoPers === StaffDoctorAccessService::TIPO_ADMIN) {
            $tipoPers = StaffDoctorAccessService::TIPO_SEGRETERIA;
        }

        return match ($tipoPers) {
            StaffDoctorAccessService::TIPO_DOTTORE => MessageRoles::ROLE_DOCTOR,
            StaffDoctorAccessService::TIPO_INFERMIERE => MessageRoles::ROLE_INFERM,
            StaffDoctorAccessService::TIPO_SEGRETERIA => MessageRoles::ROLE_SEGR,
            default => 'UNKNOWN',
        };
    }

    private function getUnifiedPostaBadgeCount($db, $obj): int
    {
        if (!is_object($obj)) {
            return 0;
        }

        $role = $this->detectMessageRoleForObject($obj);
        $svc = $this->getMessageService($db);

        if ($role === MessageRoles::ROLE_PATIENT) {
            return $svc->countUnreadInboxThreads((int)($obj->id_utente ?? 0), $role);
        }

        if ($role === MessageRoles::ROLE_DOCTOR) {
            return $svc->countUnreadInboxThreads((int)($obj->id_personale ?? 0), $role);
        }

        if (in_array($role, [MessageRoles::ROLE_SEGR, MessageRoles::ROLE_INFERM], true)) {
            $staffPersonaleId = (int)($obj->id_personale ?? 0);
            $staffTipoPers = (int)($obj->tipo_pers ?? 0);
            $doctorIds = (new StaffDoctorAccessService($db))
                ->getDoctorPersonaleIdsForStaff($staffPersonaleId, $staffTipoPers, 'posta');

            $countsByDoctor = $svc->countUnreadInboxThreadsByDoctorForStaff($role, $doctorIds);
            return array_sum($countsByDoctor);
        }

        return 0;
    }

    private function getUnifiedStaffDoctorCounts($db, $obj): array
    {
        if (!is_object($obj)) {
            return [];
        }

        $role = $this->detectMessageRoleForObject($obj);
        if (!in_array($role, [MessageRoles::ROLE_SEGR, MessageRoles::ROLE_INFERM], true)) {
            return [];
        }

        $staffPersonaleId = (int)($obj->id_personale ?? 0);
        $staffTipoPers = (int)($obj->tipo_pers ?? 0);
        $doctorIds = (new StaffDoctorAccessService($db))
            ->getDoctorPersonaleIdsForStaff($staffPersonaleId, $staffTipoPers, 'posta');

        return $this->getMessageService($db)->countUnreadInboxThreadsByDoctorForStaff($role, $doctorIds);
    }

    private function isInboxPostaMenuRow(array $row): bool
    {
        $link = strtolower(trim((string)($row['link'] ?? '')));
        $titolo = strtolower(trim((string)($row['titolo_menu'] ?? '')));

        if (
            str_contains($link, 'inviata')
            || str_contains($link, 'bozze')
            || str_contains($link, 'draft')
            || str_contains($link, 'scrivi')
            || str_contains($link, 'compose')
            || str_contains($link, 'logout')
            || str_contains($titolo, 'inviata')
            || str_contains($titolo, 'bozze')
            || str_contains($titolo, 'scrivi')
            || str_contains($titolo, 'logout')
        ) {
            return false;
        }

        return str_contains($link, 'messaggi/inbox')
            || str_starts_with($link, 'posta')
            || str_contains($titolo, 'posta in arrivo')
            || $titolo === 'posta';
    }

    private function applyUnifiedPostaBadgeToMenuRows(array $rows, int $badgeCount): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row) || !$this->isInboxPostaMenuRow($row)) {
                continue;
            }

            $row['conteggio'] = $badgeCount;
        }
        unset($row);

        return $rows;
    }

    public function getMenuForUser($userId,$obj)
    {
        log_message('info', 'Recupero menu per userId: ' . $userId);

        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();
        if($obj->tipo==3)
        {

            return $this->creaMenuCliente($userId,$obj,$db);

        }
        else if($obj->tipo==2 || $obj->tipo==1)
        {
            return $this->creaMenuDottore($userId,$obj,$db);
        }
       
    }

    private function getVisibleDoctorRowsForStaff($db, int $staffPersonaleId, int $staffTipoPers): array
    {
        $access = new StaffDoctorAccessService($db);
        $doctorIds = $access->getDoctorPersonaleIdsForStaff($staffPersonaleId, $staffTipoPers, 'posta');

        if (empty($doctorIds)) {
            return [];
        }

        $idsSql = implode(',', array_map('intval', $doctorIds));
        $qualificaExpr = "CONVERT(CAST(AES_DECRYPT(UNHEX(qualifica),@key_str,vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
        $cognomeExpr = "CONVERT(CAST(AES_DECRYPT(UNHEX(cognome),@key_str,vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
        $nomeExpr = "CONVERT(CAST(AES_DECRYPT(UNHEX(nome),@key_str,vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)";

        $sql = "
            select CONCAT(CONCAT(
                {$qualificaExpr},
                ' ',
                {$cognomeExpr},
                ' ',
                {$nomeExpr}
            )) as titolo,
            id_personale as link,
            id_personale as class,
            id_personale as icon,
            {$cognomeExpr} as cognome
            from dap03_personale
            where tipo=1
              and id_user not in (15,41)
              and titolare=1
              and id_personale in ($idsSql)
            order by cognome
        ";

        $rows = $db->query($sql)->getResultArray();

        return $this->normalizeLegacyRows($rows, ['titolo', 'cognome']);
    }

    private function getDoctorUnreadCountsForStaff($db, array $doctorIds, bool $forSegreteria): array
    {
        $role = $forSegreteria ? MessageRoles::ROLE_SEGR : MessageRoles::ROLE_INFERM;
        return $this->getMessageService($db)->countUnreadInboxThreadsByDoctorForStaff($role, $doctorIds);
    }





    public function creaMenuDottore($userId,$obj,$db)
    {
                $mailboxTipoPers = (int)($obj->tipo_pers ?? 0);
                if ($mailboxTipoPers === StaffDoctorAccessService::TIPO_ADMIN) {
                    $mailboxTipoPers = StaffDoctorAccessService::TIPO_SEGRETERIA;
                }

                if($mailboxTipoPers==3)
            {
                //SEGRETARIO
                //PRIMA PARTE
                $sql="SELECT '' as conteggio,a.titolo_menu,a.link2 as link,CASE WHEN a.id_mnu=2 then 'inboxDoctor' else a.class end as class,a.class_icon as icon 
                FROM  dap06_mnu a,  dap08_mnu_ruo b
                where a.id_mnu=b.id_mnu and b.id_type_user=".$obj->tipo." and a.id_mnu<>4  order by order_col ";
                $query = $db->query($sql);
                $result = $query->getResultArray();
                $postaBadge = $this->getUnifiedPostaBadgeCount($db, $obj);
                $result = $this->applyUnifiedPostaBadgeToMenuRows($result, $postaBadge);
        
                if (!empty($result)) {
                    log_message('info', 'Menu trovato per userId: ' . $userId);
                } else {
                    log_message('warning', 'Nessun menu trovato per userId: ' . $userId);
                }
                //LISTA DOTTORI
                    $resultDoctor = $this->getVisibleDoctorRowsForStaff($db, (int)($obj->id_personale ?? 0), StaffDoctorAccessService::TIPO_SEGRETERIA);
                    $cont_dottori=0;
                    $dottori = [];
                    $doctorIds = array_map(static fn(array $row): int => (int)($row['icon'] ?? 0), $resultDoctor);
                    $countsByDoctor = $this->getUnifiedStaffDoctorCounts($db, $obj);
                    if (!empty($resultDoctor)) {
                        foreach ($resultDoctor as $row) {
                                $conteggio = (int)($countsByDoctor[(int)($row['icon'] ?? 0)] ?? 0);
                                $cont_dottori += $conteggio;
                                $dottori[$row['class']]=$row;    
                                $dottori[$row['class']]['conteggio']=$conteggio; 
                        }
                    } else {
                        log_message('warning', 'Nessun dottore trovato');
                    }
              
                //LOGOUT
                $sql="   select titolo_menu as titolo,link,class,class_icon as icon,\"\" as cognome  FROM  dap06_mnu where id_mnu=4 ";
                log_message('info', $sql);
                    $query = $db->query($sql);
                    $resultLogout = $query->getResultArray();
                    if (!empty($resultLogout)) {
                        log_message('info', 'Totale per dottori: SURPRIESE');
                    } else {
                        log_message('warning', 'Nessun totale per dottori');
                        
                    }
                    $data = [
                        'result' => $result,              // Valore generico di ritorno
                        'cont_dottori' => $cont_dottori, // Numero di dottori trovati
                        'dottori' => $dottori,    // Array dei dottori con dettagli
                        'resultLogout' => $resultLogout // Informazioni per il logout
                    ];
                
                }
            else if($mailboxTipoPers==2)
            {
                //INFERMOERE
                //PRIMA PARTE
                $sql="SELECT '' as conteggio,a.titolo_menu,a.link2 as link,a.class as class,a.class_icon as icon 
                FROM  dap06_mnu a,  dap08_mnu_ruo b
                where a.id_mnu=b.id_mnu and b.id_type_user=".$obj->tipo." and a.id_mnu<>4  order by order_col ";
                $query = $db->query($sql);
                $result = $query->getResultArray();
                $postaBadge = $this->getUnifiedPostaBadgeCount($db, $obj);
                $result = $this->applyUnifiedPostaBadgeToMenuRows($result, $postaBadge);
        
                if (!empty($result)) {
                    log_message('info', 'Menu trovato per userId: ' . $userId);
                } else {
                    log_message('warning', 'Nessun menu trovato per userId: ' . $userId);
                }
                //VOCE DOTTORI
                //LISTA DOTTORI
                    $result_cont = $this->getVisibleDoctorRowsForStaff($db, (int)($obj->id_personale ?? 0), StaffDoctorAccessService::TIPO_INFERMIERE);
                    $cont_dottori=0;
                    $dottori = [];
                    $doctorIds = array_map(static fn(array $row): int => (int)($row['icon'] ?? 0), $result_cont);
                    $countsByDoctor = $this->getUnifiedStaffDoctorCounts($db, $obj);
                    if (!empty($result_cont)) {
                        foreach ($result_cont as $row) {
                            $conteggio = (int)($countsByDoctor[(int)($row['icon'] ?? 0)] ?? 0);
                            $cont_dottori += $conteggio;
                            $dottori[$row['class']]=$row;    
                            $dottori[$row['class']]['conteggio']=$conteggio; 
                    }
                }
                //LOGOUT
                $sql="   select titolo_menu as titolo,link,class,class_icon as icon,\"\" as cognome  FROM  dap06_mnu where id_mnu=4 ";
                log_message('info', $sql);
                $query = $db->query($sql);
                $resultLogout = $query->getResultArray();
                if (!empty($resultLogout)) {
                    log_message('info', 'Totale per dottori: ' );
                } else {
                    log_message('warning', 'Nessun totale per dottori');
                    
                }

                $data = [
                    'result' => $result,              // Valore generico di ritorno
                    'cont_dottori' => $cont_dottori, // Numero di dottori trovati
                    'dottori' => $dottori,    // Array dei dottori con dettagli
                    'resultLogout' => $resultLogout // Informazioni per il logout
                ];
        
            }
            else
            {
            $sql="SELECT '' as conteggio,a.titolo_menu,a.link2 as link,a.class as class,a.class_icon as icon 
            FROM  dap06_mnu a,  dap08_mnu_ruo b
            where a.id_mnu=b.id_mnu and b.id_type_user=".$obj->tipo." order by order_col ";
            log_message('debug', 'Eseguito SQL dottori:  ' . $sql);
                
            $query = $db->query($sql);
            $result = $query->getResultArray();
            $postaBadge = $this->getUnifiedPostaBadgeCount($db, $obj);
            $result = $this->applyUnifiedPostaBadgeToMenuRows($result, $postaBadge);
    
            if (!empty($result)) {
                log_message('info', 'Menu trovato per userId: ' . $userId);
                $data = [
                    'result' => $result,             // Valore generico di ritorno
                    'cont_dottori' => null, // Numero di dottori trovati
                    'dottori' => null,    // Array dei dottori con dettagli
                    'resultLogout' => null // Informazioni per il logout
                ];
        
            } else {
                log_message('warning', 'Nessun menu trovato per userId: ' . $userId);
            }

            
         }
         log_message('debug',"MENURECUEPRATO");
         return $data;
         
        
    }

      

       public function getMenuAgenda(): array
{
    $device = 'pc';
    $idRuo = 3;
    $db = \Config\Database::connect();

    $rows = $db->query(
        "SELECT DISTINCT
            m.id_menu,
            m.id_menu_padre,
            m.codice,
            m.tipo_voce,
            m.label_menu,
            m.icona,
            m.rotta,
            m.ordinamento
        FROM dap17_agenda_menu m
        INNER JOIN dap18_agenda_menu_permessi p
            ON p.id_menu = m.id_menu
           AND p.id_ruo = ?
           AND p.id_ope IS NULL
           AND p.visibile = 1
        WHERE m.attivo = 1
        ORDER BY
            COALESCE(m.id_menu_padre, m.id_menu),
            m.ordinamento ASC,
            m.label_menu ASC",
        [$idRuo]
    )->getResultArray();

    $byParent = [];
    foreach ($rows as $row) {
        $parentId = empty($row['id_menu_padre']) ? 0 : (int)($row['id_menu_padre'] ?? 0);
        $byParent[$parentId][] = $row;
    }

    $buildTree = function (int $parentId) use (&$buildTree, $byParent, $device): array {
        $items = [];

        foreach ($byParent[$parentId] ?? [] as $row) {
            $children = $buildTree((int)($row['id_menu'] ?? 0));
            $route = trim((string)($row['rotta'] ?? ''));
            [$mappedRoute, $isExternal] = $this->mapLinkForDevice($route, $device);
            $label = (string)($row['label_menu'] ?? '');
            $icon = (string)($row['icona'] ?? '');
            $isGroup = strtoupper(trim((string)($row['tipo_voce'] ?? 'ITEM'))) === 'MENU';

            if ($isGroup && $children !== []) {
                $items[] = [
                    'type'     => 'group',
                    'key'      => (int)($row['id_menu'] ?? 0),
                    'name'     => preg_replace('/\s+/', '', $label),
                    'label'    => $label,
                    'icon'     => $icon,
                    'children' => $children,
                ];
                continue;
            }

            if ($mappedRoute === '' && $children === []) {
                continue;
            }

            $idSource = $mappedRoute !== '' ? $mappedRoute : ((string)($row['codice'] ?? 'menu_' . (int)($row['id_menu'] ?? 0)));

            $items[] = [
                'type'       => 'item',
                'key'        => (int)($row['id_menu'] ?? 0),
                'id'         => $this->linkToId($idSource),
                'label'      => $label,
                'icon'       => $icon,
                'isExternal' => $isExternal,
                'url'        => $isExternal ? $mappedRoute : null,
                'route'      => $isExternal ? null : ($mappedRoute !== '' ? $mappedRoute : null),
                'target'     => $isExternal ? '_blank' : null,
            ];
        }

        return $items;
    };

    return ['menu' => $buildTree(0)];
}

/** helper: id ricavato dal nome file prima del punto */
function linkToId(string $link): string
{
    return (strpos($link, '.') !== false) ? substr($link, 0, strpos($link, '.')) : $link;
}

/** helper: mappa link -> (link per device, è esterno?) */
function mapLinkForDevice(string $link, string $device): array
{
    if (preg_match('#^https?://#i', $link)) {
        return [$link, true]; // esterno
    }
    if ($link === 'test3.jsp') {
        return [($device === 'pc') ? 'test3.jsp' : 'test32.jsp', false];
    }
    return [$link, false];
}
    public function creaMenuCliente($userId,$obj,$db)
    {
        $sql="SELECT '' as conteggio,a.titolo_menu,a.link2 as link,a.class as class,a.class_icon as icon 
            FROM  dap06_mnu a,  dap08_mnu_ruo b
            where a.id_mnu=b.id_mnu and b.id_type_user=".$obj->tipo." order by order_col ";

        log_message('debug', 'Eseguito SQL: ' . $sql);

        $query = $db->query($sql);
        $result = $query->getResultArray();
        $postaBadge = $this->getUnifiedPostaBadgeCount($db, $obj);
        $result = $this->applyUnifiedPostaBadgeToMenuRows($result, $postaBadge);
        $data = [
            'result' => $result,             // Valore generico di ritorno
            'cont_dottori' => null, // Numero di dottori trovati
            'dottori' => null,    // Array dei dottori con dettagli
            'resultLogout' => null // Informazioni per il logout
        ];
        if (!empty($result)) {
            log_message('info', 'Menu trovato per userId: ' . $userId);
            return $data;
        } else {
            log_message('warning', 'Nessun menu trovato per userId: ' . $userId);
            return null;
        }
    }
    
   
}
