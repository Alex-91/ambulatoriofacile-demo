<?php
namespace App\Services;
use CodeIgniter\Database\BaseConnection;

/** Called inside the booking transaction before reserving slots. */
final class AgendaResourceGuard
{
    public function __construct(private BaseConnection $db) {}
    public function assertAvailable(array $slot,array $window,int $doctorId,int $ignoreAppointment=0): void
    {
        if ($doctorId <= 0 || (int)$slot['id_dot'] !== $doctorId) throw new \RuntimeException('Lo slot non appartiene al medico selezionato.');
        $start=(string)($window['custom_start'] ?: $slot['ora_inizio']);$end=(string)$window['end'];
        $day=(string)$slot['data_slot'];
        if ($start >= $end) throw new \RuntimeException('Intervallo appuntamento non valido.');
        $room=$this->db->fieldExists('id_stanza','dap11_agenda_slot') ? (int)($slot['id_stanza'] ?? 0) : 0;
        // Room assignments are legacy agenda labels, not exclusive resources.
        // Different professionals may intentionally share the same room label.
        // Serialize overlapping bookings for the selected doctor only.
        $resource='id_dot = ?';$resourceParams=[$doctorId];
        $locking=$this->db->DBDriver==='MySQLi' ? ' FOR UPDATE' : '';
        $this->db->query('SELECT id_slot FROM dap11_agenda_slot WHERE data_slot = ? AND ora_inizio < ? AND ora_fine > ? AND ('.$resource.') ORDER BY id_slot'.$locking,
            array_merge([$day,$end,$start],$resourceParams))->getResultArray();
        $ids=array_map('intval',array_column($window['covered_slots'] ?? [$slot],'id_slot'));
        if (!$ids) throw new \RuntimeException('Nessuno slot disponibile.');
        $coveredRows=$this->db->query('SELECT * FROM dap11_agenda_slot WHERE id_slot IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id_slot'.$locking,$ids)->getResultArray();
        if(count($coveredRows)!==count($ids)) throw new \RuntimeException('Uno slot non è più disponibile.');
        foreach($coveredRows as $covered) {
            if ((int)$covered['id_dot']!==$doctorId || $covered['data_slot']!==$day || (int)($covered['id_stanza'] ?? 0)!==$room) throw new \RuntimeException('La prestazione deve restare nella stessa fascia di medico e stanza.');
        }
        $aStart=$this->db->fieldExists('ora_inizio_appuntamento','dap12_agenda_appuntamenti') ? 'COALESCE(a.ora_inizio_appuntamento,s.ora_inizio)' : 's.ora_inizio';
        $aEnd=$this->db->fieldExists('ora_fine_appuntamento','dap12_agenda_appuntamenti') ? 'COALESCE(a.ora_fine_appuntamento,s.ora_fine)' : 's.ora_fine';
        $scope='a.id_dot = ?';$params=[$day,$end,$start,$doctorId];
        $params[]=$ignoreAppointment;
        // A locking read sees the transaction that just released the resource lock,
        // even on MySQL REPEATABLE READ after earlier planning reads.
        $conflict=$this->db->query('SELECT a.id_appuntamento FROM dap12_agenda_appuntamenti a JOIN dap11_agenda_slot s ON s.id_slot=a.id_slot
            WHERE s.data_slot=? AND '.$aStart.' < ? AND '.$aEnd.' > ? AND ('.$scope.') AND a.stato <> \'ANNULLATO\' AND a.id_appuntamento <> ? LIMIT 1'.$locking,$params)->getRowArray();
        if ($conflict) throw new \RuntimeException('Medico o stanza già occupati nell’orario richiesto.');
    }
}
