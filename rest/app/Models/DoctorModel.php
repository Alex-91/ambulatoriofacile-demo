<?php
namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper; // Importa la libreria

class DoctorModel extends Model
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

    public function getMenuForUser($userId,$obj)
    {
        log_message('info', 'Recupero menu per userId: ' . $userId);

        $crypto_helper = new Crypto_helper();
        $db = \Config\Database::connect();
        $query="SELECT CASE WHEN a.id_mnu=2 then CONCAT(a.titolo_menu,(
            select CASE when count(*)=0 then '<span id=\"count_inbox\"></span>' else CONCAT(' <span id=\"count_inbox\">(',count(*),')</span>') end from (Select a.id_message as text 
            from ".$obj->tabella." a,dap10_message_delete b
            where id_dest = ".$obj->id_client." and letto=0 and b.eliminato=0 and a.id_message=b.id_message and b.id_utente=".$obj->id_client."
            union all
            Select id_message_ini as text from ".$obj->tabella_reply." a,dap10_message_reply_delete b
            where id_dest  = ".$obj->id_client." and letto=0 and b.eliminato=0 and a.id_message=b.id_message and b.id_utente=".$obj->id_client." group by id_message_ini) as a where text<>''
                )) 
            else a.titolo_menu end as titolo,a.link2 as link,a.class as class,a.class_icon as icon 
            FROM  dap06_mnu a,  dap08_mnu_ruo b
            where a.id_mnu=b.id_mnu and b.id_type_user=".$obj->tipo." order by order_col ";

        log_message('debug', 'Eseguito SQL: ' . $sql);

        $query = $db->query($sql);
        $result = $query->getResultArray();

        if (!empty($result)) {
            log_message('info', 'Cellulare trovato per userId: ' . $userId);
            return $result[0]['cellulare'];
        } else {
            log_message('warning', 'Nessun cellulare trovato per userId: ' . $userId);
            return null;
        }
    }
    
    // Metodo per ottenere un cliente per ID
    public function getClientById($id)
    {
        log_message('info', 'Recupero cliente con ID: ' . $id);
        $client = $this->where('id_client', $id)->first();

        if ($client) {
            log_message('info', 'Cliente trovato con ID: ' . $id);
        } else {
            log_message('warning', 'Nessun cliente trovato con ID: ' . $id);
        }

        return $client;
    }

    public function getCellulareByUserId(int $userId): ?string
{
    $crypto = new \App\Libraries\Crypto_helper();

    $sql = "
        SELECT
            CAST(AES_DECRYPT(UNHEX(cellulare), @key_str, vector_id) AS CHAR) AS cellulare
        FROM dap03_personale
        WHERE id_user = ?
        LIMIT 1
    ";

    $row = $this->db->query($sql, [$userId])->getRowArray();

    return $row['cellulare'] ?? null;
}

    
    // Metodo per ottenere tutti i clienti
    public function getAllClients()
    {
        log_message('info', 'Recupero tutti i clienti');
        $clients = $this->findAll();

        if (empty($clients)) {
            log_message('warning', 'Nessun cliente trovato');
        } else {
            log_message('info', 'Tutti i clienti recuperati');
        }

        return $clients;
    }
    
    // Metodo per inserire un nuovo cliente
    public function insertClient($data)
    {
        log_message('info', 'Inserimento nuovo cliente');
        $result = $this->insert($data);

        if ($result) {
            log_message('info', 'Cliente inserito con successo');
        } else {
            log_message('error', 'Errore nell\'inserimento del cliente');
        }

        return $result;
    }
    
    // Metodo per aggiornare un cliente
    public function updateClient($id, $data)
    {
        log_message('info', 'Aggiornamento cliente con ID: ' . $id);
        $result = $this->update($id, $data);

        if ($result) {
            log_message('info', 'Cliente con ID ' . $id . ' aggiornato con successo');
        } else {
            log_message('error', 'Errore nell\'aggiornamento del cliente con ID: ' . $id);
        }

        return $result;
    }
    
    // Metodo per eliminare un cliente
    public function deleteClient($id)
    {
        log_message('info', 'Eliminazione cliente con ID: ' . $id);
        $result = $this->delete($id);

        if ($result) {
            log_message('info', 'Cliente con ID ' . $id . ' eliminato con successo');
        } else {
            log_message('error', 'Errore nell\'eliminazione del cliente con ID: ' . $id);
        }

        return $result;
    }
}
