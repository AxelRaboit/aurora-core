<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Space;

use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Enum\NoteSpaceScopeEnum;
use Aurora\Module\Notes\Space\Hosting\HostedNoteSpace;
use Aurora\Module\Notes\Space\Hosting\HostedNoteSpaceVoter;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceHostInterface;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceScope;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The notes engine serves the Notes module and the modules that host a space
 * of notes; the scope is what keeps them apart (10/10/2026).
 */
final class NoteSpaceScopeTest extends TestCase
{
    public function testEverySpaceIsAdmittedByDefault(): void
    {
        $scope = new NoteSpaceScope();

        self::assertSame(NoteSpaceScopeEnum::All, $scope->mode());
        self::assertTrue($scope->admits($this->space(1)));
        self::assertTrue($scope->admits($this->space(2, 'studio.customer_space')));
        self::assertFalse($scope->isHosted());
        self::assertSame([], $scope->routeParameters());
    }

    /** The module only thins its lists: a hosted note opened by id still answers. */
    public function testTheModuleAdmitsHostedSpacesById(): void
    {
        $scope = new NoteSpaceScope();
        $scope->restrictToModule();

        self::assertTrue($scope->admits($this->space(2, 'studio.customer_space')));
        self::assertFalse($scope->isHosted());
    }

    public function testAHostedRequestAdmitsItsSpaceAlone(): void
    {
        $scope = new NoteSpaceScope();
        $scope->confineTo($this->hosted($this->space(7, 'studio.customer_space'), '12'));

        self::assertTrue($scope->admits($this->space(7, 'studio.customer_space')));
        self::assertFalse($scope->admits($this->space(8, 'studio.customer_space')));
        self::assertFalse($scope->admits($this->space(1)));
        self::assertTrue($scope->isHosted());
        self::assertSame([NoteSpaceScope::HOST_PARAMETER => 'studio.customer_space:12'], $scope->routeParameters());
    }

    public function testTheHostsScopeAdmitsHostedSpacesOnly(): void
    {
        $scope = new NoteSpaceScope();
        $scope->restrictToHosts();

        self::assertTrue($scope->admits($this->space(2, 'studio.customer_space')));
        self::assertFalse($scope->admits($this->space(1)));
    }

    public function testWithinPutsTheRequestScopeBack(): void
    {
        $scope = new NoteSpaceScope();
        $scope->restrictToModule();
        $hosted = $this->hosted($this->space(7, 'studio.customer_space'), '12');

        $seen = $scope->within(NoteSpaceScopeEnum::Hosted, static fn (): NoteSpaceScopeEnum => $scope->mode(), $hosted);

        self::assertSame(NoteSpaceScopeEnum::Hosted, $seen);
        self::assertSame(NoteSpaceScopeEnum::Module, $scope->mode());
        self::assertNull($scope->hosted());
    }

    public function testTheHostedScopeNeedsItsSpace(): void
    {
        $this->expectException(LogicException::class);

        new NoteSpaceScope()->within(NoteSpaceScopeEnum::Hosted, static fn (): null => null);
    }

    /** The voter grants the module's privilege to a hosted request, and only to one. */
    public function testTheVoterGrantsTheEngineToAHostedRequestOnly(): void
    {
        $scope = new NoteSpaceScope();
        $voter = new HostedNoteSpaceVoter($scope);
        $token = new NullToken();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, [HostedNoteSpaceVoter::PRIVILEGE]));

        $scope->confineTo($this->hosted($this->space(7, 'studio.customer_space'), '12'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [HostedNoteSpaceVoter::PRIVILEGE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['notes.spaces.create']));
    }

    private function space(int $id, ?string $managedBy = null): NoteSpace
    {
        $space = new NoteSpace();
        $space->setManagedBy($managedBy);
        new ReflectionProperty($space, 'id')->setValue($space, $id);

        return $space;
    }

    private function hosted(NoteSpace $space, string $reference): HostedNoteSpace
    {
        $host = $this->createStub(NoteSpaceHostInterface::class);
        $host->method('getKey')->willReturn('studio.customer_space');

        return new HostedNoteSpace($space, $host, $reference);
    }
}
