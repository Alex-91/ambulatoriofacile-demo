<?php

namespace App\Services;

use App\Database\Migrations\CreateAgendaVisitTypesAndAppointmentSpan;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AgendaVisitTypeSchemaService
{
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
        $this->schemaEnsured = true;
    }
}
