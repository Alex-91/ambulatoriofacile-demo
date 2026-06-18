<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;

class UserSchedeModel extends Model
{
    protected $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::connect();
    }

    public function getSchedeWithUserFlags(int $idUser): array
    {
        $sql = "
            SELECT
                s.id_scheda,
                s.codice,
                s.titolo,
                s.url,
                s.ordine,
                s.badge_tipo,
                s.attiva,
                COALESCE(us.can_view, 0)   AS can_view,
                COALESCE(us.can_access, 0) AS can_access
            FROM dap_menu_schede s
            LEFT JOIN dap_user_schede us
              ON us.id_scheda = s.id_scheda
             AND us.id_user   = ?
            WHERE s.attiva = 1
            ORDER BY s.ordine ASC, s.titolo ASC
        ";

        $rows = $this->db->query($sql, [$idUser])->getResultArray();

        foreach ($rows as &$r) {
            $r['id_scheda']   = (int)$r['id_scheda'];
            $r['can_view']    = (int)$r['can_view'];
            $r['can_access']  = (int)$r['can_access'];
            $r['ordine']      = (int)$r['ordine'];
        }

        return $rows;
    }

    /**
     * Toggle con regole:
     * - se can_view diventa 0 => can_access va a 0
     * - se can_access diventa 1 => can_view va a 1
     * Upsert su PK (id_user, id_scheda)
     */
    public function setFlag(int $idUser, int $idScheda, string $field, int $value): bool
    {
        if (!in_array($field, ['can_view', 'can_access'], true)) {
            return false;
        }
        $value = $value ? 1 : 0;

        $exists = $this->db->query(
            "SELECT can_view, can_access FROM dap_user_schede WHERE id_user=? AND id_scheda=? LIMIT 1",
            [$idUser, $idScheda]
        )->getRowArray();

        $canView   = $exists ? (int)$exists['can_view']   : 0;
        $canAccess = $exists ? (int)$exists['can_access'] : 0;

        if ($field === 'can_view') {
            $canView = $value;
            if ($canView === 0) $canAccess = 0; // se non vede, non accede
        } else {
            $canAccess = $value;
            if ($canAccess === 1) $canView = 1; // se accede, deve vedere
        }

        if ($exists) {
            $this->db->query(
                "UPDATE dap_user_schede SET can_view=?, can_access=? WHERE id_user=? AND id_scheda=?",
                [$canView, $canAccess, $idUser, $idScheda]
            );
            return true;
        }

        // Insert: se abilitiamo qualcosa, creiamo la riga
        $this->db->query(
            "INSERT INTO dap_user_schede (id_user, id_scheda, can_view, can_access) VALUES (?,?,?,?)",
            [$idUser, $idScheda, $canView, $canAccess]
        );
        return true;
    }
}
