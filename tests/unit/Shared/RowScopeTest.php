<?php

use App\Modules\Shared\Access\AccessDeniedException;
use App\Modules\Shared\Access\RowScope;
use App\Modules\Shared\Auth\CurrentUser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RowScopeTest extends CIUnitTestCase
{
    private function makeUser(
        bool $isPusat = false,
        bool $isKanwil = false,
        ?string $uptId = null,
        array $claims = [],
    ): CurrentUser {
        return new CurrentUser(
            id: 'u1',
            role: 'admin',
            name: 'Tester',
            level: '1',
            isPusat: $isPusat,
            isKanwil: $isKanwil,
            uptId: $uptId,
            uptName: 'Test',
            claims: $claims,
        );
    }

    public function testPusatWithoutFiltersIsUnrestricted(): void
    {
        $scope = RowScope::fromUser($this->makeUser(isPusat: true));

        $this->assertTrue($scope->isUnrestricted());
        $this->assertNull($scope->baseType);
        $scope->assertContextMatches(['idUpt' => '001', 'kanwil' => '01']);
        $this->addToAssertionCount(1);
    }

    public function testUptScopeAllowsOwnAndDeniesOtherUpt(): void
    {
        $scope = RowScope::fromUser($this->makeUser(uptId: '093'));

        $this->assertSame('upt', $scope->baseType);
        $this->assertSame('093', $scope->baseValue);
        $scope->assertContextMatches(['idUpt' => '093', 'kanwil' => '05']);

        $this->expectException(AccessDeniedException::class);
        $scope->assertContextMatches(['idUpt' => '001', 'kanwil' => '01']);
    }

    public function testKanwilScopeAllowsMatchingKanwilAndDeniesOther(): void
    {
        $scope = RowScope::fromUser($this->makeUser(isKanwil: true, uptId: '05'));

        $this->assertSame('kanwil', $scope->baseType);
        $this->assertSame('05', $scope->baseValue);
        $scope->assertContextMatches(['idUpt' => '093', 'kanwil' => '05']);

        $this->expectException(AccessDeniedException::class);
        $scope->assertContextMatches(['idUpt' => '001', 'kanwil' => '01']);
    }

    public function testPusatWorkingScopeFiltersEnforceMatch(): void
    {
        $scope = RowScope::fromUser($this->makeUser(
            isPusat: true,
            claims: ['sessionFilterUptId' => '093', 'sessionFilterKanwilId' => '05'],
        ));

        $this->assertFalse($scope->isUnrestricted());
        $scope->assertContextMatches(['idUpt' => '093', 'kanwil' => '05']);

        $this->expectException(AccessDeniedException::class);
        $scope->assertContextMatches(['idUpt' => '001', 'kanwil' => '05']);
    }

    public function testKanwilWorkingScopeRejectsUptOutsideOwnKanwil(): void
    {
        $scope = RowScope::fromUser($this->makeUser(
            isKanwil: true,
            uptId: '05',
            claims: ['sessionFilterUptId' => '001'],
        ));

        $this->assertTrue($scope->needsSessionFilterUptKanwilCheck());

        $this->expectException(AccessDeniedException::class);
        $scope->assertContextMatches(['idUpt' => '001', 'kanwil' => '05'], '01');
    }

    public function testNullContextIsAllowed(): void
    {
        $scope = RowScope::fromUser($this->makeUser(uptId: '093'));
        $scope->assertContextMatches(null);
        $this->addToAssertionCount(1);
    }
}
