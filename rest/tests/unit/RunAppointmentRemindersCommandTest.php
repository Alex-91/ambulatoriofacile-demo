<?php

namespace Tests\Unit;

use App\Commands\RunAppointmentReminders;
use CodeIgniter\CLI\CLI;
use PHPUnit\Framework\TestCase;

final class RunAppointmentRemindersCommandTest extends TestCase
{
    public function testReadsFlagsAndValuesParsedByCodeIgniterCli(): void
    {
        $options = new \ReflectionProperty(CLI::class, 'options');
        $originalOptions = $options->getValue();
        $options->setValue(null, [
            'dry-run' => null,
            'force' => null,
            'reference-date' => '2026-09-06',
        ]);

        try {
            $command = (new \ReflectionClass(RunAppointmentReminders::class))->newInstanceWithoutConstructor();
            $hasFlag = new \ReflectionMethod($command, 'hasFlag');
            $readOptionValue = new \ReflectionMethod($command, 'readOptionValue');

            $this->assertTrue($hasFlag->invoke($command, [], 'dry-run'));
            $this->assertTrue($hasFlag->invoke($command, [], 'force'));
            $this->assertSame(
                '2026-09-06',
                $readOptionValue->invoke($command, [], '--reference-date')
            );
        } finally {
            $options->setValue(null, $originalOptions);
        }
    }
}
