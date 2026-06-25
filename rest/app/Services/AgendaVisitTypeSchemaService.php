<?php

namespace App\Services;

use App\Database\Migrations\CreateAgendaVisitTypesAndAppointmentSpan;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AgendaVisitTypeSchemaService
{
    private const VISIT_TYPES_TABLE = 'dap44_agenda_tipi_visita';

    private BaseConnection $db;
    private bool $schemaEnsured = false;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function ensureReady(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $migration = new CreateAgendaVisitTypesAndAppointmentSpan(Database::forge($this->db));
        $migration->up();

        if (!$this->db->tableExists(self::VISIT_TYPES_TABLE)) {
            $error = $this->db->error();
            $message = trim((string) ($error['message'] ?? ''));

            throw new \RuntimeException(
                $message !== ''
                    ? 'Impossibile preparare la tabella dei tipi visita: ' . $message
                    : 'Impossibile preparare la tabella dei tipi visita.'
            );
        }

        $this->schemaEnsured = true;
    }
}
