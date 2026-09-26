<?php

namespace Rahpt\Ci4Module\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rahpt\Ci4Module\Validators\DependencyChecker;

/**
 * Tests for DependencyChecker version compatibility logic.
 */
class DependencyCheckerTest extends TestCase
{
    protected DependencyChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        // DependencyChecker can be tested without registry for version comparison
        $this->checker = $this->getMockBuilder(DependencyChecker::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    public function testCaretVersionCompatibilityPass(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.5.0', '^1.0'));
        $this->assertTrue($this->checker->isVersionCompatible('1.0.0', '^1.0'));
        $this->assertTrue($this->checker->isVersionCompatible('0.2.5', '^0.2'));
    }

    public function testCaretVersionCompatibilityFail(): void
    {
        $this->assertFalse($this->checker->isVersionCompatible('2.0.0', '^1.0'));
        $this->assertFalse($this->checker->isVersionCompatible('0.3.0', '^0.2'));
    }

    public function testTildeVersionCompatibilityPass(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.2.5', '~1.2'));
        $this->assertTrue($this->checker->isVersionCompatible('1.2.0', '~1.2'));
    }

    public function testTildeVersionCompatibilityFail(): void
    {
        $this->assertFalse($this->checker->isVersionCompatible('1.3.0', '~1.2'));
        $this->assertFalse($this->checker->isVersionCompatible('2.2.0', '~1.2'));
    }

    public function testComparisonOperatorsGte(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.5.0', '>=1.0'));
        $this->assertTrue($this->checker->isVersionCompatible('1.0.0', '>=1.0'));
        $this->assertFalse($this->checker->isVersionCompatible('0.9.0', '>=1.0'));
    }

    public function testComparisonOperatorsGt(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.1.0', '>1.0'));
        // PHP version_compare: '1.0.0' > '1.0' is TRUE (1.0.0 is treated as greater than 1.0)
        $this->assertTrue($this->checker->isVersionCompatible('1.0.0', '>1.0'));
        $this->assertFalse($this->checker->isVersionCompatible('1.0.0', '>1.0.0'));
    }

    public function testComparisonOperatorsLte(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.5.0', '<=2.0'));
        // PHP: version_compare('2.0.0', '2.0', '<=') is FALSE — 2.0.0 is treated as > 2.0
        $this->assertTrue($this->checker->isVersionCompatible('2.0.0', '<=2.0.0'));
        $this->assertFalse($this->checker->isVersionCompatible('2.1.0', '<=2.0.0'));
        $this->assertFalse($this->checker->isVersionCompatible('2.0.0', '<=2.0'));
    }

    public function testWildcardVersionPass(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.0.5', '1.0.*'));
        $this->assertTrue($this->checker->isVersionCompatible('1.5.0', '1.*'));
    }

    public function testWildcardVersionFail(): void
    {
        $this->assertFalse($this->checker->isVersionCompatible('1.1.0', '1.0.*'));
    }

    public function testStarWildcardAlwaysMatches(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('99.99.99', '*'));
    }

    public function testEmptyRequirementAlwaysMatches(): void
    {
        $this->assertTrue($this->checker->isVersionCompatible('1.0.0', ''));
    }
}
