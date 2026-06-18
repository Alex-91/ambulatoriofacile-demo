<?php

namespace App\Controllers\Register;

use App\Controllers\BaseController;
use App\Models\UsersModel;
use App\Models\ClientsModel;
use App\Models\ClientDoctorModel;
use App\Libraries\Crypto_helper; // Importa la libreria
use App\Libraries\DatabaseConfig;

class RegisterController extends BaseController
{
    protected $db;
    protected $dbConfig;

    public function __construct()
    {
        $this->db = \Config\Database::connect(); // Assegna alla proprietà della classe
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
    }
    

    public function index()
    {
        return view('register/register');
    }

    public function salva()
{
    log_message('info', 'Inizio processo di registrazione utente');

    $userModel = new UsersModel();
    $clientModel = new ClientsModel();
    $clientDoctorModel = new ClientDoctorModel();
    $crypto_helper = new Crypto_helper();
    
    // Ricevi i dati in formato JSON
    $json = $this->request->getJSON();

    if (!$json) {
        log_message('error', 'Dati non validi ricevuti in registrazione');
        return $this->response->setJSON(['error' => 'Dati non validi'])->setStatusCode(400);
    }

    // Dati ricevuti
    $codice_fiscale = strtoupper(trim((string)$json->codice_fiscale));
    $password = $crypto_helper->encrypt($json->password); // Utilizzo della funzione encrypt
    $nome = $crypto_helper->encrypt($json->nome);
    $cognome = $crypto_helper->encrypt($json->cognome);
    $email = $crypto_helper->encrypt($json->email);
    $cellulare = $crypto_helper->encrypt($json->cellulare);
    $codiceFiscaleEncrypted = $crypto_helper->encrypt($codice_fiscale);
    $dottore = (int)$json->dottore;

    // Controllo se l'utente è già presente
    $utentePresente = $userModel->findByUsernameInsensitive($codice_fiscale);

    if ($utentePresente) {
        log_message('warning', 'Tentativo di registrazione di un utente già presente: ' . $codice_fiscale);
        return $this->response->setJSON(['error' => 'Utente già registrato'])->setStatusCode(400);
    }

    // Inizio transazione
    $this->db->transStart();

    try {
        log_message('info', 'Inserimento dati dell\'utente nel database');
        $this->db->query("
            INSERT INTO dap01_users (username, password, datascadenza, tipo_user, vector_id)
            VALUES (?, ".$password.", DATE_ADD(NOW(), INTERVAL 1 YEAR), 3, @init_vector)",
            [$codice_fiscale]
        );

        $id_user = $this->db->insertID();
        log_message('info', "Nuovo utente registrato con ID: {$id_user}");

        // ✅ DEFAULT: assegna la scheda 2 al nuovo utente
        $this->db->query("
            INSERT INTO dap_user_schede (id_user, id_scheda, can_view, can_access)
            VALUES (?, ?, 1, 1)
        ", [$id_user, 2]);

        // Abilita sempre anche la scheda "Posta" per i nuovi pazienti.
        $postaScheda = $this->db->table('dap_menu_schede')
            ->select('id_scheda')
            ->where('codice', 'posta')
            ->orderBy('attiva', 'DESC')
            ->orderBy('id_scheda', 'ASC')
            ->get(1)
            ->getRowArray();

        if (!$postaScheda) {
            throw new \RuntimeException('Scheda posta non trovata in dap_menu_schede');
        }

        $this->db->query("
            INSERT INTO dap_user_schede (id_user, id_scheda, can_view, can_access)
            VALUES (?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE can_view = 1, can_access = 1
        ", [$id_user, (int) $postaScheda['id_scheda']]);

        $existingClient = $clientModel->findClientByCodiceFiscaleInsensitive($codice_fiscale);
        $doctorToAssign = $dottore;

        if ($existingClient) {
            $existingClientId = (int)($existingClient['id_client'] ?? 0);
            $existingClientUserId = (int)($existingClient['id_user'] ?? 0);
            if ($existingClientUserId > 0 && $existingClientUserId !== $id_user) {
                throw new \RuntimeException('Esiste gia un profilo paziente collegato a un altro account.');
            }

            $doctorSnapshot = $clientDoctorModel->getPreferredDoctorLinkForClient($existingClientId);
            if ((int)($doctorSnapshot['id_dot'] ?? 0) > 0) {
                $doctorToAssign = (int)$doctorSnapshot['id_dot'];
            } elseif ((int)($existingClient['id_personale'] ?? 0) > 0) {
                $doctorToAssign = (int)$existingClient['id_personale'];
            }

            if ($doctorToAssign > 0 && $doctorToAssign !== $dottore) {
                log_message('warning', 'Registrazione paziente con dottore selezionato diverso dal profilo importato; mantengo il dottore gia assegnato', [
                    'codice_fiscale' => $codice_fiscale,
                    'selected_doctor' => $dottore,
                    'existing_doctor' => $doctorToAssign,
                    'id_client' => $existingClientId,
                ]);
            }

            $linked = $clientModel->fillMissingClientDataAndLinkUser($existingClientId, $id_user, [
                'nome' => (string)$json->nome,
                'cognome' => (string)$json->cognome,
                'email' => (string)$json->email,
                'cellulare' => (string)$json->cellulare,
                'codice_fiscale' => $codice_fiscale,
            ], $doctorToAssign);

            if (!$linked) {
                throw new \RuntimeException('Errore durante l\'aggancio del paziente gia presente.');
            }

            $id_client = $existingClientId;
        } else {
            $this->db->query('SET @init_vector = RANDOM_BYTES(16)');
            $this->db->query("
                INSERT INTO dap02_clients (id_user, nome, cognome, email, cellulare, codice_fiscale, id_personale, vector_id)
                VALUES (?, ".$nome.", ".$cognome.", ".$email.", ".$cellulare.", ".$codiceFiscaleEncrypted.", ?, @init_vector)",
                [$id_user, $doctorToAssign]
            );

            $id_client = $this->db->insertID();
        }

        if ($doctorToAssign > 0) {
            $okDoctorLink = $clientDoctorModel->setDoctorForClient((int)$id_client, $doctorToAssign);
            if (!$okDoctorLink) {
                throw new \RuntimeException('Errore durante il collegamento paziente-dottore.');
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            log_message('error', 'Errore durante la registrazione dell\'utente');
            return redirect()->back()->with('error', 'Errore durante la registrazione.');
        }

        log_message('info', 'Registrazione completata con successo');
        return $this->response->setJSON([
            'success' => true,
            'message' => 'Registrazione completata. Reindirizzamento in corso...'
        ]);

    } catch (\Exception $e) {
        // Rollback in caso di errore
        log_message('error', 'Errore durante la registrazione: ' . $e->getMessage());
        $this->db->transRollback();
        return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(500);
    }
}

}
