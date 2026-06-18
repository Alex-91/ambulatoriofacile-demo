<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Database;

class SchedeModel extends Model
{
    protected $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = Database::connect();
    }

    /**
     * Ritorna le schede visibili per utente, già ordinate.
     * Ogni scheda includerà anche 'badge' calcolato in base a badge_tipo (posta/chat).
     */
    public function getSchedeForUser(int $idUser, int $badgePosta, int $badgeChat): array
    {
        $sql = "
            SELECT
              s.id_scheda,
              s.codice,
              s.titolo,
              s.url,
              s.ordine,
              s.badge_tipo,
              s.icon_svg,
              COALESCE(s.aria_label, CONCAT('Vai a ', s.titolo)) AS aria_label,
              us.can_view,
              us.can_access
            FROM dap_user_schede us
            JOIN dap_menu_schede s ON s.id_scheda = us.id_scheda
            WHERE us.id_user = ?
              AND us.can_view = 1
              AND s.attiva = 1
            ORDER BY s.ordine ASC, s.titolo ASC
        ";

        $rows = $this->db->query($sql, [$idUser])->getResultArray();

        foreach ($rows as &$r) {
            $tipo = $r['badge_tipo'] ?? 'none';
            $badge = 0;
            if ($tipo === 'posta') $badge = $badgePosta;
            if ($tipo === 'chat')  $badge = $badgeChat;

            // La scheda "Posta" apre la vista legacy Inbox ("Posta in Arrivo").
            if (($r['codice'] ?? '') === 'posta') {
                $r['url'] = 'posta';
            }

            $r['badge'] = (int)$badge;
            $r['can_access'] = (int)($r['can_access'] ?? 0);
            $r['can_view']   = (int)($r['can_view'] ?? 0);
        }

        return $rows;
    }

    /**
     * Check server-side per proteggere route:
     * dato un "codice" scheda (es: 'posta') verifica can_access.
     */
    public function userCanAccessCodice(int $idUser, string $codice): bool
    {
        $sql = "
          SELECT 1
          FROM dap_user_schede us
          JOIN dap_menu_schede s ON s.id_scheda = us.id_scheda
          WHERE us.id_user = ?
            AND s.codice = ?
            AND s.attiva = 1
            AND us.can_access = 1
          LIMIT 1
        ";
        $row = $this->db->query($sql, [$idUser, $codice])->getRowArray();
        return !empty($row);
    }
}
