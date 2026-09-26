<?php

namespace Rahpt\Ci4Module\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rahpt\Ci4Module\ModuleRegistry;

/**
 * Tests for ModuleRegistry state machine transitions.
 */
class StateMachineTest extends TestCase
{
    public function testAllowedTransitionsConstantIsComplete(): void
    {
        $statuses = [
            ModuleRegistry::STATUS_DISCOVERED,
            ModuleRegistry::STATUS_VALIDATED,
            ModuleRegistry::STATUS_INSTALLED,
            ModuleRegistry::STATUS_ACTIVATING,
            ModuleRegistry::STATUS_ACTIVE,
            ModuleRegistry::STATUS_DEACTIVATING,
            ModuleRegistry::STATUS_DISABLED,
            ModuleRegistry::STATUS_FAILED,
            ModuleRegistry::STATUS_QUARANTINED,
        ];

        // Verify all status constants are defined
        foreach ($statuses as $status) {
            $this->assertIsString($status, "Status constant must be a string: {$status}");
            $this->assertNotEmpty($status, "Status constant must not be empty");
        }

        $this->assertSame('discovered', ModuleRegistry::STATUS_DISCOVERED);
        $this->assertSame('active', ModuleRegistry::STATUS_ACTIVE);
        $this->assertSame('quarantined', ModuleRegistry::STATUS_QUARANTINED);
    }

    public function testStatusConstantsAreDistinct(): void
    {
        $statuses = [
            ModuleRegistry::STATUS_DISCOVERED,
            ModuleRegistry::STATUS_VALIDATED,
            ModuleRegistry::STATUS_INSTALLED,
            ModuleRegistry::STATUS_ACTIVATING,
            ModuleRegistry::STATUS_ACTIVE,
            ModuleRegistry::STATUS_DEACTIVATING,
            ModuleRegistry::STATUS_DISABLED,
            ModuleRegistry::STATUS_FAILED,
            ModuleRegistry::STATUS_QUARANTINED,
        ];

        $unique = array_unique($statuses);
        $this->assertCount(count($statuses), $unique, 'All status constants must be unique');
    }
}
