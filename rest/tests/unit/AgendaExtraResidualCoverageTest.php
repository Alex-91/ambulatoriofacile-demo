<?php

use App\Models\AgendaAppointmentModel;
use App\Models\AgendaSlotModel;
use App\Models\AgendaModel;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;

final class AgendaExtraResidualCoverageTest extends CIUnitTestCase
{
    private Connection $agendaDb;
    private AgendaAppointmentModel $appointments;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated synthetic database. SQLite has no FOR UPDATE; the production
        // queries are otherwise executed unchanged, including transactions.
        $this->agendaDb = new AgendaResidualTestConnection([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ]);
        $schemas = [
            'dap11_agenda_slot' => 'id_slot INTEGER PRIMARY KEY AUTOINCREMENT, id_dot INTEGER, data_slot TEXT, ora_inizio TEXT, ora_fine TEXT, origine_slot TEXT, stato TEXT, tipo_slot TEXT, titolo_libero TEXT, id_amb_legacy INTEGER, ambulatorio TEXT, stanza TEXT, note_interne TEXT, updated_at TEXT, created_at TEXT',
            'dap12_agenda_appuntamenti' => 'id_appuntamento INTEGER PRIMARY KEY AUTOINCREMENT, id_slot INTEGER, id_dot INTEGER, id_paziente INTEGER, cognome TEXT, nome TEXT, telefono TEXT, cellulare TEXT, email TEXT, note TEXT, motivo_visita TEXT, indirizzo_visita TEXT, comune_visita TEXT, stato TEXT, durata_minuti INTEGER, ora_inizio_appuntamento TEXT, ora_fine_appuntamento TEXT, id_tipo_visita INTEGER, tipo_visita_label TEXT, created_at TEXT, updated_at TEXT',
            'dap45_agenda_appuntamenti_slot' => 'id_appuntamento_slot INTEGER PRIMARY KEY AUTOINCREMENT, id_appuntamento INTEGER, id_slot INTEGER, posizione INTEGER, is_primario INTEGER, created_at TEXT',
            'dap46_agenda_slot_frammenti' => 'id_frammento INTEGER PRIMARY KEY AUTOINCREMENT, id_slot INTEGER UNIQUE, gruppo_token TEXT, id_slot_origine INTEGER, ora_inizio_originale TEXT, ora_fine_originale TEXT, created_at TEXT, updated_at TEXT',
            'dap14_agenda_lock' => 'id_lock INTEGER PRIMARY KEY AUTOINCREMENT, id_slot INTEGER, token_lock TEXT, stato TEXT, expires_at TEXT',
            'dap21_agenda_giorni_bloccati' => 'id_dot INTEGER, data_agenda TEXT',
            'dap44_agenda_tipi_visita' => 'id_tipo_visita INTEGER PRIMARY KEY, nome TEXT, durata_minuti INTEGER, colore TEXT, usa_colore_tipo_visita_slot INTEGER, attivo INTEGER, ordinamento INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT',
            'dap02_clients' => 'id_client INTEGER PRIMARY KEY, legacy_id_paziente INTEGER, paz_spec TEXT, vector_id TEXT',
        ];
        foreach ($schemas as $table => $columns) {
            $this->agendaDb->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        $this->agendaDb->connID->createFunction('TIMESTAMPDIFF', static fn($unit, $start, $end) => (int) ((strtotime($end ?? '') - strtotime($start ?? '')) / 60), 3);
        $this->agendaDb->connID->createFunction('AES_DECRYPT', static fn(...$args) => null, 3);
        $this->agendaDb->connID->createFunction('UNHEX', static fn($value) => $value, 1);
        $this->appointments = new AgendaAppointmentModel($this->agendaDb);
        $this->slot(1, '11:20', '12:15', 'CONFIG', 'PRENOTATO');
        $this->slot(2, '12:15', '12:30', 'CONFIG');
        $this->fragment(1, '11:20', '12:30');
        $this->fragment(2, '11:20', '12:30');
        $this->agendaDb->table('dap12_agenda_appuntamenti')->insert([
            'id_appuntamento' => 10, 'id_slot' => 1, 'id_dot' => 7,
            'cognome' => 'SINTETICO', 'nome' => 'PRECEDENTE', 'stato' => 'CONFERMATO',
        ]);
        $this->slot(3, '12:15', '13:15', 'EXTRA');
        $this->slot(4, '14:00', '15:00', 'CONFIG');
    }

    protected function tearDown(): void
    {
        $this->agendaDb->close();
        parent::tearDown();
    }

    public function testExtraBookingAbsorbsResidualWithoutChangingBriefingTimes(): void
    {
        $id = $this->appointments->saveAppointment($this->booking());
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('PRENOTATO', $this->row(2)['stato']);
        self::assertSame('LIBERO', $this->row(4)['stato']);
        $appointment = $this->agendaDb->table('dap12_agenda_appuntamenti')->where('id_appuntamento', $id)->get()->getRowArray();
        self::assertSame(3, (int) $appointment['id_slot']);
        self::assertSame(60, (int) $appointment['durata_minuti']);
        self::assertSame('2026-09-21 13:15:00', $appointment['ora_fine_appuntamento']);
        self::assertSame('2026-09-21 12:15:00', $this->row(3)['ora_inizio']);
    }

    public function testCancellationReleasesAbsorbedResidualAndKeepsPreviousAppointment(): void
    {
        $id = $this->appointments->saveAppointment($this->booking());
        $this->appointments->deleteAppointment($id, 1);
        self::assertSame([], $this->linkedIds($id));
        self::assertSame('LIBERO', $this->row(2)['stato']);
        self::assertSame('LIBERO', $this->row(3)['stato']);
        self::assertSame('PRENOTATO', $this->row(1)['stato']);
        self::assertSame('2026-09-21 12:15:00', $this->row(1)['ora_fine']);
    }

    public function testEditingExistingBriefingRepairsMissingResidualLink(): void
    {
        $id = $this->legacyBriefing();
        $this->appointments->updateAppointment($this->booking(['id_appuntamento' => $id]));
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('PRENOTATO', $this->row(2)['stato']);
    }

    public function testExistingBriefingDoesNotOfferResidualInDayWeekOrAvailability(): void
    {
        $this->legacyBriefing();
        $slots = new AgendaResidualTestSlotModel($this->agendaDb);
        foreach (['day', 'week'] as $view) {
            $rows = $slots->getSlotsCalendario(7, '2026-09-21', $view);
            self::assertNotContains(2, array_map('intval', array_column($rows, 'id_slot')));
            $briefings = array_values(array_filter($rows, static fn($row) => (int) $row['id_slot'] === 3));
            self::assertCount(1, $briefings);
            self::assertSame('2026-09-21 12:15:00', $briefings[0]['appointment_ora_inizio']);
            self::assertSame('2026-09-21 13:15:00', $briefings[0]['appointment_ora_fine']);
        }
        self::assertSame([['data_slot' => '2026-09-21', 'slot_liberi' => 1]],
            $slots->getAvailabilityDaysForRange(7, '2026-09-21', '2026-09-21'));
        self::assertSame('LIBERO', $this->row(2)['stato'], 'Reading legacy coverage must not write to the database.');
    }

    public function testLegacyCancellationMakesResidualAvailableAgain(): void
    {
        $id = $this->legacyBriefing();
        $this->appointments->deleteAppointment($id, 1);
        $slots = new AgendaResidualTestSlotModel($this->agendaDb);
        self::assertContains(2, array_map('intval', array_column($slots->getSlotsCalendario(7, '2026-09-21'), 'id_slot')));
        self::assertSame([['data_slot' => '2026-09-21', 'slot_liberi' => 3]],
            $slots->getAvailabilityDaysForRange(7, '2026-09-21', '2026-09-21'));
    }

    public function testStaleBrowserCannotBookResidualCoveredByLegacyExtra(): void
    {
        $this->legacyBriefing();
        $this->assertOverlapRejectedWithoutChanges($this->booking(['id_slot' => 2]));
    }

    public function testPartialCoveragePreservesTheFreePartBeforeExtra(): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['ora_inizio' => '2026-09-21 12:20:00']);
        $id = $this->appointments->saveAppointment($this->booking());
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('2026-09-21 12:20:00', $this->row(2)['ora_inizio']);
        self::assertSame('2026-09-21 12:30:00', $this->row(2)['ora_fine']);
        self::assertSame('2026-09-21 12:15:00', $this->row(5)['ora_inizio']);
        self::assertSame('2026-09-21 12:20:00', $this->row(5)['ora_fine']);
        self::assertSame('LIBERO', $this->row(5)['stato']);
    }

    public function testPartialCoveragePreservesTheFreePartAfterExtra(): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['ora_fine' => '2026-09-21 12:20:00']);
        $id = $this->appointments->saveAppointment($this->booking());
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('2026-09-21 12:20:00', $this->row(2)['ora_fine']);
        self::assertSame('2026-09-21 12:20:00', $this->row(5)['ora_inizio']);
        self::assertSame('2026-09-21 12:30:00', $this->row(5)['ora_fine']);
        self::assertSame('LIBERO', $this->row(5)['stato']);
    }

    public function testAdjacentExtraDoesNotConsumeAnUnbookedResidual(): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['ora_inizio' => '2026-09-21 12:30:00']);
        $id = $this->appointments->saveAppointment($this->booking());
        self::assertSame([3], $this->linkedIds($id));
        self::assertSame('LIBERO', $this->row(2)['stato']);
    }

    public function testConsecutiveResidualAndExtraCanStillFormOneAppointment(): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['ora_inizio' => '2026-09-21 12:30:00']);
        $id = $this->appointments->saveAppointment($this->booking([
            'id_slot' => 2, 'allow_custom_duration' => true, 'durata_minuti' => 60,
        ]));
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('PRENOTATO', $this->row(2)['stato']);
        self::assertSame('PRENOTATO', $this->row(3)['stato']);
    }

    public function testRegularBookingDoesNotChangeUnbookedExtraOrAfternoon(): void
    {
        $id = $this->appointments->saveAppointment($this->booking(['id_slot' => 2]));
        self::assertSame([2], $this->linkedIds($id));
        self::assertSame('LIBERO', $this->row(3)['stato']);
        self::assertSame('LIBERO', $this->row(4)['stato']);
    }

    public function testOtherDoctorAndOtherDateAreNotAbsorbedOrHidden(): void
    {
        $this->slot(5, '12:15', '12:30', 'CONFIG', 'LIBERO', 8);
        $this->fragment(5, '11:20', '12:30');
        $this->slot(6, '12:15', '12:30', 'CONFIG');
        $this->fragment(6, '11:20', '12:30');
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 6)->update([
            'data_slot' => '2026-09-22', 'ora_inizio' => '2026-09-22 12:15:00', 'ora_fine' => '2026-09-22 12:30:00',
        ]);
        $id = $this->appointments->saveAppointment($this->booking());
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('LIBERO', $this->row(5)['stato']);
        self::assertSame('LIBERO', $this->row(6)['stato']);
        $slots = new AgendaResidualTestSlotModel($this->agendaDb);
        self::assertCount(1, $slots->getSlotsCalendario(8, '2026-09-21'));
        self::assertCount(1, $slots->getSlotsCalendario(7, '2026-09-22'));
    }

    public function testLockedResidualPreventsUpdatingExtra(): void
    {
        $id = $this->legacyBriefing();
        $this->agendaDb->table('dap14_agenda_lock')->insert([
            'id_slot' => 2, 'token_lock' => 'another-operator', 'stato' => 'ATTIVO', 'expires_at' => '2099-01-01 00:00:00',
        ]);
        $this->expectExceptionMessage('in modifica');
        $this->appointments->updateAppointment($this->booking(['id_appuntamento' => $id]));
    }

    public function testOccupiedResidualPreventsExtraBooking(): void
    {
        $this->appointments->saveAppointment($this->booking(['id_slot' => 2]));
        $this->assertOverlapRejectedWithoutChanges($this->booking());
    }

    private function assertOverlapRejectedWithoutChanges(array $booking): void
    {
        $snapshot = function (): array {
            $rows = [];
            foreach ([
                'dap11_agenda_slot' => 'id_slot',
                'dap12_agenda_appuntamenti' => 'id_appuntamento',
                'dap45_agenda_appuntamenti_slot' => 'id_appuntamento_slot',
                'dap46_agenda_slot_frammenti' => 'id_frammento',
            ] as $table => $key) {
                $rows[$table] = $this->agendaDb->table($table)->orderBy($key)->get()->getResultArray();
            }
            return $rows;
        };
        $before = $snapshot();
        $failure = null;
        try {
            $this->appointments->saveAppointment($booking);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        // The resource guard runs before the narrower residual-slot guard.
        self::assertSame('Medico o stanza già occupati nell’orario richiesto.', $failure->getMessage());
        self::assertSame($before, $snapshot(), 'Rejected overlapping bookings must leave all reservations and fragments unchanged.');
    }

    public function testFailedBookingRollsBackResidualSplitting(): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['ora_inizio' => '2026-09-21 12:20:00']);
        $this->agendaDb->query("CREATE TRIGGER reject_booking BEFORE INSERT ON dap12_agenda_appuntamenti BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->appointments->saveAppointment($this->booking());
            self::fail('The synthetic insert must fail.');
        } catch (\Exception $e) {
            self::assertSame('LIBERO', $this->row(2)['stato']);
            self::assertSame('2026-09-21 12:15:00', $this->row(2)['ora_inizio']);
            self::assertSame(4, $this->agendaDb->table('dap11_agenda_slot')->countAllResults());
            self::assertSame(0, $this->agendaDb->table('dap45_agenda_appuntamenti_slot')->countAllResults());
        }
    }

    public function testCustomExtraWindowCanAbsorbOverlappingResidual(): void
    {
        $id = $this->appointments->saveAppointment($this->booking([
            'custom_time_enabled' => true, 'custom_start_time' => '12:15', 'custom_end_time' => '13:15',
        ]));
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('PRENOTATO', $this->row(2)['stato']);
    }

    public function testFeatureDisabledStillMaintainsExistingFragments(): void
    {
        $id = $this->appointments->saveAppointment($this->booking([
            'custom_appointment_time_feature_enabled' => false, 'custom_time_residual_slots_feature_enabled' => false,
        ]));
        self::assertSame([2, 3], $this->linkedIds($id));
    }

    public function testDeletingExtraReleasesItsAbsorbedResidual(): void
    {
        $id = $this->appointments->saveAppointment($this->booking());
        $agenda = new AgendaResidualTestAgendaModel($this->agendaDb);
        $result = $agenda->deleteExtraSlotsByIds([3], 7, true);
        self::assertTrue($result['status']);
        self::assertSame(1, $result['deleted_appointments']);
        self::assertSame('LIBERO', $this->row(2)['stato']);
        self::assertSame('PRENOTATO', $this->row(1)['stato']);
        self::assertSame([], $this->linkedIds($id));
    }

    public function testSecondaryExtraIsCountedAndProtectedBeforeDeletion(): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['ora_inizio' => '2026-09-21 12:30:00']);
        $id = $this->appointments->saveAppointment($this->booking([
            'id_slot' => 2, 'allow_custom_duration' => true, 'durata_minuti' => 60,
        ]));
        $agenda = new AgendaResidualTestAgendaModel($this->agendaDb);
        $rows = $agenda->getSlotExtraByDoctorPaginate(7, '2026-09-21', '2026-09-21')['rows'];
        self::assertSame(1, (int) $rows[0]['appuntamenti_attivi']);
        $result = $agenda->deleteExtraSlotsByIds([3], 7, false);
        self::assertTrue($result['hasAppointments']);
        self::assertSame([2, 3], $this->linkedIds($id));
        $agenda->deleteExtraSlotsByIds([3], 7, true);
        self::assertSame('LIBERO', $this->row(2)['stato']);
        self::assertSame([], $this->linkedIds($id));
    }

    public function testNewResidualIsOnlySecondaryCoverageInCalendar(): void
    {
        $id = $this->appointments->saveAppointment($this->booking());
        $slots = new AgendaResidualTestSlotModel($this->agendaDb);
        $rows = $slots->getSlotsCalendario(7, '2026-09-21');
        $covered = array_values(array_filter($rows, static fn($row) => (int) $row['id_appuntamento'] === $id));
        self::assertCount(2, $covered);
        $primary = array_filter($covered, static fn($row) => (int) $row['appointment_is_primary_slot'] === 1);
        self::assertCount(1, $primary);
        self::assertSame(3, (int) array_values($primary)[0]['id_slot']);
        foreach ($covered as $row) {
            self::assertSame('PRENOTATO', $row['stato']);
        }
    }

    public function testCustomReductionReleasesPreviouslyAbsorbedResidual(): void
    {
        $id = $this->appointments->saveAppointment($this->booking());
        $this->appointments->updateAppointment($this->booking([
            'id_appuntamento' => $id, 'custom_time_enabled' => true,
            'custom_start_time' => '12:30', 'custom_end_time' => '13:15',
        ]));
        self::assertSame([3], $this->linkedIds($id));
        self::assertSame('LIBERO', $this->row(2)['stato']);
        self::assertSame('2026-09-21 12:30:00', $this->row(3)['ora_inizio']);
        self::assertSame('2026-09-21 13:15:00', $this->row(3)['ora_fine']);
    }

    public function testConfiguredUnfragmentedSlotsKeepTheirExistingBehavior(): void
    {
        $this->legacyBriefing();
        $this->slot(5, '12:15', '12:30', 'CONFIG');
        $slots = new AgendaResidualTestSlotModel($this->agendaDb);
        self::assertContains(5, array_map('intval', array_column($slots->getSlotsCalendario(7, '2026-09-21'), 'id_slot')));
        self::assertSame('LIBERO', $this->row(5)['stato']);
    }

    public function testMissingFragmentSchemaDoesNotChangeStandardBooking(): void
    {
        $this->agendaDb->query('DROP TABLE dap46_agenda_slot_frammenti');
        $id = $this->appointments->saveAppointment($this->booking());
        self::assertSame([3], $this->linkedIds($id));
        self::assertSame('LIBERO', $this->row(2)['stato']);
        self::assertFalse($this->agendaDb->tableExists('dap46_agenda_slot_frammenti'));
    }

    public function testFailedExtraDeletionRestoresAppointmentAndResidualCoverage(): void
    {
        $id = $this->appointments->saveAppointment($this->booking());
        $this->agendaDb->query("CREATE TRIGGER reject_extra_deletion BEFORE DELETE ON dap11_agenda_slot WHEN OLD.id_slot = 3 BEGIN SELECT RAISE(ABORT, 'synthetic delete failure'); END");
        $failed = false;
        try {
            (new AgendaResidualTestAgendaModel($this->agendaDb))->deleteExtraSlotsByIds([3], 7, true);
        } catch (\Exception $e) {
            $failed = true;
        }
        self::assertTrue($failed);
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('PRENOTATO', $this->row(2)['stato']);
        self::assertSame('PRENOTATO', $this->row(3)['stato']);
        self::assertSame('CONFERMATO', $this->agendaDb->table('dap12_agenda_appuntamenti')
            ->where('id_appuntamento', $id)->get()->getRowArray()['stato']);
    }

    public function testCustomExtraDoesNotAbsorbOverlappingOrdinarySlot(): void
    {
        $this->slot(5, '12:15', '12:30', 'CONFIG');
        $this->expectExceptionMessage('slot sovrapposti');
        $this->appointments->saveAppointment($this->booking([
            'custom_time_enabled' => true, 'custom_start_time' => '12:15', 'custom_end_time' => '13:15',
        ]));
    }

    public function testCustomWindowWithResidualFeatureDisabledStillSplitsExistingResidual(): void
    {
        $id = $this->appointments->saveAppointment($this->booking([
            'custom_time_residual_slots_feature_enabled' => false,
            'custom_time_enabled' => true, 'custom_start_time' => '12:20', 'custom_end_time' => '13:15',
        ]));
        self::assertSame([2, 3], $this->linkedIds($id));
        self::assertSame('2026-09-21 12:20:00', $this->row(2)['ora_inizio']);
        self::assertSame('2026-09-21 12:15:00', $this->row(5)['ora_inizio']);
        self::assertSame('2026-09-21 12:20:00', $this->row(5)['ora_fine']);
        self::assertSame('LIBERO', $this->row(5)['stato']);
    }

    private function legacyBriefing(): int
    {
        $this->agendaDb->table('dap12_agenda_appuntamenti')->insert([
            'id_slot' => 3, 'id_dot' => 7, 'cognome' => 'BRIEFING', 'nome' => 'SINTETICO',
            'stato' => 'CONFERMATO', 'durata_minuti' => 60, 'ora_fine_appuntamento' => '2026-09-21 13:15:00',
        ]);
        $id = (int) $this->agendaDb->insertID();
        $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', 3)->update(['stato' => 'PRENOTATO']);
        return $id;
    }

    private function booking(array $overrides = []): array
    {
        return array_replace([
            'id_slot' => 3, 'id_dot' => 7, 'cognome' => 'BRIEFING', 'nome' => 'SINTETICO',
            'slot_lock_required' => false, 'visit_types_feature_enabled' => false,
            'custom_appointment_time_feature_enabled' => true,
            'custom_time_residual_slots_feature_enabled' => true,
        ], $overrides);
    }

    private function slot(int $id, string $start, string $end, string $origin, string $state = 'LIBERO', int $doctor = 7): void
    {
        $this->agendaDb->table('dap11_agenda_slot')->insert([
            'id_slot' => $id, 'id_dot' => $doctor, 'data_slot' => '2026-09-21',
            'ora_inizio' => '2026-09-21 ' . $start . ':00', 'ora_fine' => '2026-09-21 ' . $end . ':00',
            'origine_slot' => $origin, 'stato' => $state,
        ]);
    }

    private function fragment(int $id, string $originalStart, string $originalEnd): void
    {
        $this->agendaDb->table('dap46_agenda_slot_frammenti')->insert([
            'id_slot' => $id, 'gruppo_token' => 'synthetic-' . $originalStart,
            'id_slot_origine' => 1, 'ora_inizio_originale' => '2026-09-21 ' . $originalStart . ':00',
            'ora_fine_originale' => '2026-09-21 ' . $originalEnd . ':00',
        ]);
    }

    private function linkedIds(int $id): array
    {
        return array_map('intval', array_column($this->agendaDb->table('dap45_agenda_appuntamenti_slot')
            ->where('id_appuntamento', $id)->orderBy('id_slot')->get()->getResultArray(), 'id_slot'));
    }

    private function row(int $id): array
    {
        return $this->agendaDb->table('dap11_agenda_slot')->where('id_slot', $id)->get()->getRowArray();
    }
}

final class AgendaResidualTestConnection extends Connection
{
    public function query(string $sql, $binds = null, bool $setEscapeFlags = true, string $queryClass = '')
    {
        $sql = str_replace('TIMESTAMPDIFF(MINUTE,', "TIMESTAMPDIFF('MINUTE',", $sql);
        return parent::query(preg_replace('/\s+FOR UPDATE\b/i', '', $sql), $binds, $setEscapeFlags, $queryClass);
    }
}

class AgendaResidualTestBuilder extends \CodeIgniter\Database\SQLite3\Builder {}
class AgendaResidualTestResult extends \CodeIgniter\Database\SQLite3\Result {}

final class AgendaResidualTestSlotModel extends AgendaSlotModel
{
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    protected function buildConfiguredDayExistsSql(string $slotTableAlias): string
    {
        return '1 = 1';
    }
}

final class AgendaResidualTestAgendaModel extends AgendaModel
{
    public function __construct(Connection $db)
    {
        $this->db = $db;
    }
}
