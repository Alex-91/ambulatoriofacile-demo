<?php

use App\Services\AgendaResourceGuard;
use CodeIgniter\Test\CIUnitTestCase;

require_once dirname(__DIR__, 2) . '/app/Services/AgendaResourceGuard.php';

final class AgendaSharedRoomBookingTest extends CIUnitTestCase
{
    public function testSharedRoomDoesNotBlockAnotherDoctorButRealDoctorOverlapStillDoes(): void
    {
        $db = \Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true], false);
        try {
            $db->query('CREATE TABLE dap11_agenda_slot (id_slot INTEGER PRIMARY KEY, id_dot INTEGER, id_stanza INTEGER, data_slot TEXT, ora_inizio TEXT, ora_fine TEXT)');
            $db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INTEGER PRIMARY KEY, id_slot INTEGER, id_dot INTEGER, stato TEXT)');
            $slot = ['id_slot'=>1,'id_dot'=>10,'id_stanza'=>1,'data_slot'=>'2026-09-17','ora_inizio'=>'2026-09-17 16:00:00','ora_fine'=>'2026-09-17 17:00:00'];
            $db->table('dap11_agenda_slot')->insert($slot);
            $db->table('dap11_agenda_slot')->insert(array_replace($slot, ['id_slot'=>2,'id_dot'=>20]));
            $db->table('dap12_agenda_appuntamenti')->insert(['id_appuntamento'=>7,'id_slot'=>2,'id_dot'=>20,'stato'=>'CONFERMATO']);
            $window = ['custom_start'=>null,'end'=>$slot['ora_fine'],'covered_slots'=>[$slot]];
            $guard = new AgendaResourceGuard($db);
            $guard->assertAvailable($slot, $window, 10);
            self::assertTrue(true, 'An appointment for another doctor sharing the room label must not block this free slot.');
            $db->table('dap12_agenda_appuntamenti')->insert(['id_appuntamento'=>8,'id_slot'=>1,'id_dot'=>10,'stato'=>'ANNULLATO']);
            $guard->assertAvailable($slot, $window, 10);
            self::assertTrue(true, 'Cancelled appointments must release availability.');
            $db->table('dap12_agenda_appuntamenti')->where('id_appuntamento',8)->update(['stato'=>'CONFERMATO']);
            $guard->assertAvailable($slot, $window, 10, 8);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Medico o stanza già occupati');
            $guard->assertAvailable($slot, $window, 10);
        } finally {
            $db->close();
        }
    }
}
