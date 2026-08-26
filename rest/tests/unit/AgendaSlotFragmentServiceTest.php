<?php

use App\Services\AgendaSlotFragmentService;
use CodeIgniter\Test\CIUnitTestCase;

final class AgendaSlotFragmentServiceTest extends CIUnitTestCase
{
    public function testResidualSlotsRequireBothFeatureFlags(): void
    {
        self::assertFalse(AgendaSlotFragmentService::isFeatureEnabled(false, false));
        self::assertFalse(AgendaSlotFragmentService::isFeatureEnabled(false, true));
        self::assertFalse(AgendaSlotFragmentService::isFeatureEnabled(true, false));
        self::assertTrue(AgendaSlotFragmentService::isFeatureEnabled(true, true));
    }

    public function testDisablingFeatureStopsNewSplitsButKeepsExistingGroupsManaged(): void
    {
        self::assertFalse(AgendaSlotFragmentService::shouldManageCustomWindow(false, false));
        self::assertTrue(AgendaSlotFragmentService::shouldManageCustomWindow(false, true));
        self::assertTrue(AgendaSlotFragmentService::shouldManageCustomWindow(true, false));
    }

    public function testResidualFeatureCannotBeEnabledWithoutCustomTimes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Orari personalizzati');

        AgendaSlotFragmentService::assertFeatureDependencies([
            AgendaSlotFragmentService::FEATURE_KEY,
        ]);
    }

    public function testResidualFeatureCanBeEnabledWithCustomTimes(): void
    {
        AgendaSlotFragmentService::assertFeatureDependencies([
            AgendaSlotFragmentService::PARENT_FEATURE_KEY,
            AgendaSlotFragmentService::FEATURE_KEY,
        ]);

        self::assertTrue(true);
    }

    public function testPartitionCreatesBothResidualSlots(): void
    {
        $result = AgendaSlotFragmentService::calculatePartition(
            '2026-09-07 14:00:00',
            '2026-09-07 15:00:00',
            '2026-09-07 14:15:00',
            '2026-09-07 14:45:00'
        );

        self::assertSame(
            ['start' => '2026-09-07 14:00:00', 'end' => '2026-09-07 14:15:00'],
            $result['before']
        );
        self::assertSame(
            ['start' => '2026-09-07 14:15:00', 'end' => '2026-09-07 14:45:00'],
            $result['inside']
        );
        self::assertSame(
            ['start' => '2026-09-07 14:45:00', 'end' => '2026-09-07 15:00:00'],
            $result['after']
        );
    }

    public function testPartitionDoesNotCreateZeroLengthResiduals(): void
    {
        $result = AgendaSlotFragmentService::calculatePartition(
            '2026-09-07 14:00:00',
            '2026-09-07 15:00:00',
            '2026-09-07 14:00:00',
            '2026-09-07 14:45:00'
        );

        self::assertNull($result['before']);
        self::assertSame(
            ['start' => '2026-09-07 14:45:00', 'end' => '2026-09-07 15:00:00'],
            $result['after']
        );
    }

    public function testSlotFullyInsideWindowRemainsWhole(): void
    {
        $result = AgendaSlotFragmentService::calculatePartition(
            '2026-09-07 15:00:00',
            '2026-09-07 16:00:00',
            '2026-09-07 14:15:00',
            '2026-09-07 16:45:00'
        );

        self::assertNull($result['before']);
        self::assertSame(
            ['start' => '2026-09-07 15:00:00', 'end' => '2026-09-07 16:00:00'],
            $result['inside']
        );
        self::assertNull($result['after']);
    }

    public function testNonIntersectingSlotIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('non interseca');

        AgendaSlotFragmentService::calculatePartition(
            '2026-09-07 14:00:00',
            '2026-09-07 15:00:00',
            '2026-09-07 15:00:00',
            '2026-09-07 16:00:00'
        );
    }
}
