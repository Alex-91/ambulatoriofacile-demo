<?php

use App\Services\PatientAddressSchemaService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class PatientAddressSchemaServiceTest extends CIUnitTestCase
{
    public function testAddressGroupCopyUsesOneAtomicAssignmentSet(): void
    {
        $db = Database::connect([
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
        ]);
        $db->query(
            'CREATE TABLE dap02_clients ('
            . 'indirizzo TEXT, citta TEXT, residenza_indirizzo TEXT, residenza_comune TEXT)'
        );
        $db->query(
            "INSERT INTO dap02_clients (indirizzo, citta) VALUES ('Via Roma', 'Bologna')"
        );

        $reflection = new ReflectionClass(PatientAddressSchemaService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('copyAddressGroup');
        $method->invoke(
            $service,
            $db,
            ['residenza_indirizzo', 'residenza_comune'],
            ['indirizzo', 'citta'],
            "COALESCE(indirizzo, '') <> ''",
            "COALESCE(residenza_indirizzo, '') <> ''"
        );

        $row = $db->table('dap02_clients')->get()->getRowArray();
        self::assertSame('Via Roma', $row['residenza_indirizzo']);
        self::assertSame('Bologna', $row['residenza_comune']);
    }
}
