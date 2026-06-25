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
    private bool $provisioningFallbackAttempted = false;

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
            $this->repairThroughTenantProvisioning();
        }

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

    private function repairThroughTenantProvisioning(): void
    {
        if ($this->provisioningFallbackAttempted) {
            return;
        }

        $this->provisioningFallbackAttempted = true;

        try {
            $tenantId = (new TenantContextService())->getCurrentTenant()?->tenantId ?? 0;
            if ($tenantId <= 0) {
                return;
            }

            (new TenantInfrastructureProvisioningService())->ensureTenantMigrationsApplied($tenantId);
            $this->db->reconnect();
        } catch (\Throwable $e) {
            log_message('error', 'AgendaVisitTypeSchemaService fallback provisioning failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
