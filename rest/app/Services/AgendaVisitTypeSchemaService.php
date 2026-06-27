<?php

namespace App\Services;

use App\Database\Migrations\AddColorToAgendaVisitTypes;
use App\Database\Migrations\CreateAgendaVisitTypesAndAppointmentSpan;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AgendaVisitTypeSchemaService
{
    private const VISIT_TYPES_TABLE = 'dap44_agenda_tipi_visita';
    private const APPOINTMENTS_TABLE = 'dap12_agenda_appuntamenti';
    private const APPOINTMENT_SLOT_LINK_TABLE = 'dap45_agenda_appuntamenti_slot';

    /** @var array<int, string> */
    private const REQUIRED_VISIT_TYPES_COLUMNS = [
        'id_tipo_visita',
        'nome',
        'durata_minuti',
        'colore',
        'attivo',
        'ordinamento',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];

    /** @var array<int, string> */
    private const REQUIRED_APPOINTMENT_COLUMNS = [
        'id_tipo_visita',
        'tipo_visita_label',
        'durata_minuti',
        'ora_fine_appuntamento',
    ];

    /** @var array<int, string> */
    private const REQUIRED_APPOINTMENT_SLOT_LINK_COLUMNS = [
        'id_appuntamento_slot',
        'id_appuntamento',
        'id_slot',
        'posizione',
        'is_primario',
        'created_at',
    ];

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

        if (!$this->hasRequiredSchema()) {
            $this->attemptRuntimeMigration();
        }

        if (!$this->hasRequiredSchema()) {
            $this->repairThroughTenantProvisioning();
        }

        if (!$this->hasRequiredSchema()) {
            $error = $this->db->error();
            $message = trim((string) ($error['message'] ?? ''));
            $schemaProblem = $this->describeMissingSchema();

            if ($schemaProblem !== '') {
                $message = $message !== ''
                    ? $schemaProblem . ' - ' . $message
                    : $schemaProblem;
            }

            throw new \RuntimeException(
                $message !== ''
                    ? 'Impossibile preparare la tabella dei tipi visita: ' . $message
                    : 'Impossibile preparare la tabella dei tipi visita.'
            );
        }

        $this->schemaEnsured = true;
    }

    private function attemptRuntimeMigration(): void
    {
        try {
            $forge = Database::forge($this->db);

            $migration = new CreateAgendaVisitTypesAndAppointmentSpan($forge);
            $migration->up();

            $colorMigration = new AddColorToAgendaVisitTypes($forge);
            $colorMigration->up();
        } catch (\Throwable $e) {
            log_message('error', 'AgendaVisitTypeSchemaService runtime migration failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
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

    private function hasRequiredSchema(): bool
    {
        if (!$this->tableHasColumns(self::VISIT_TYPES_TABLE, self::REQUIRED_VISIT_TYPES_COLUMNS)) {
            return false;
        }

        if (
            $this->db->tableExists(self::APPOINTMENTS_TABLE)
            && !$this->tableHasColumns(self::APPOINTMENTS_TABLE, self::REQUIRED_APPOINTMENT_COLUMNS)
        ) {
            return false;
        }

        if (
            $this->db->tableExists(self::APPOINTMENTS_TABLE)
            && $this->db->tableExists('dap11_agenda_slot')
            && !$this->tableHasColumns(self::APPOINTMENT_SLOT_LINK_TABLE, self::REQUIRED_APPOINTMENT_SLOT_LINK_COLUMNS)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param array<int, string> $columns
     */
    private function tableHasColumns(string $table, array $columns): bool
    {
        if (!$this->db->tableExists($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!$this->db->fieldExists($column, $table)) {
                return false;
            }
        }

        return true;
    }

    private function describeMissingSchema(): string
    {
        $missingParts = [];

        $visitTypeColumns = $this->missingColumns(self::VISIT_TYPES_TABLE, self::REQUIRED_VISIT_TYPES_COLUMNS);
        if ($visitTypeColumns !== []) {
            $missingParts[] = self::VISIT_TYPES_TABLE . ' (' . implode(', ', $visitTypeColumns) . ')';
        }

        if ($this->db->tableExists(self::APPOINTMENTS_TABLE)) {
            $appointmentColumns = $this->missingColumns(self::APPOINTMENTS_TABLE, self::REQUIRED_APPOINTMENT_COLUMNS);
            if ($appointmentColumns !== []) {
                $missingParts[] = self::APPOINTMENTS_TABLE . ' (' . implode(', ', $appointmentColumns) . ')';
            }
        }

        if ($this->db->tableExists(self::APPOINTMENTS_TABLE) && $this->db->tableExists('dap11_agenda_slot')) {
            $slotLinkColumns = $this->missingColumns(
                self::APPOINTMENT_SLOT_LINK_TABLE,
                self::REQUIRED_APPOINTMENT_SLOT_LINK_COLUMNS
            );
            if ($slotLinkColumns !== []) {
                $missingParts[] = self::APPOINTMENT_SLOT_LINK_TABLE . ' (' . implode(', ', $slotLinkColumns) . ')';
            }
        }

        if ($missingParts === []) {
            return '';
        }

        return 'Schema tipi visita incompleto: ' . implode('; ', $missingParts);
    }

    /**
     * @param array<int, string> $requiredColumns
     * @return array<int, string>
     */
    private function missingColumns(string $table, array $requiredColumns): array
    {
        if (!$this->db->tableExists($table)) {
            return ['table missing'];
        }

        $missing = [];
        foreach ($requiredColumns as $column) {
            if (!$this->db->fieldExists($column, $table)) {
                $missing[] = $column;
            }
        }

        return $missing;
    }
}
