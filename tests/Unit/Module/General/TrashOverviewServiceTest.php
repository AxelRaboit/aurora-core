<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\General;

use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\General\Trash\Service\TrashOverviewService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the overview shows, and what it must not.
 *
 * The two filters are the whole point of the service: a module that is
 * switched off has no screen to send anyone to, and a reader without the
 * privilege would be told how many things they may not see.
 */
final class TrashOverviewServiceTest extends TestCase
{
    public function testAModuleThatIsOffContributesNothing(): void
    {
        $service = new TrashOverviewService(
            [$this->source('ged', 'ged_documents', 3), $this->source('editorial', 'editorial_posts', 1)],
            $this->checkerGranting(true),
        );

        $summaries = $service->getSummaries(['ged']);

        self::assertCount(1, $summaries);
        self::assertSame('ged_documents', $summaries[0]->key);
    }

    public function testASourceTheReaderMayNotOpenIsDropped(): void
    {
        $service = new TrashOverviewService(
            [$this->source('ged', 'ged_documents', 3, 'ged.documents.view')],
            $this->checkerGranting(false),
        );

        self::assertSame([], $service->getSummaries(['ged']));
    }

    public function testWhatIsWaitingComesFirst(): void
    {
        $service = new TrashOverviewService(
            [
                $this->source('ged', 'empty_one', 0),
                $this->source('ged', 'small', 2),
                $this->source('ged', 'big', 40),
            ],
            $this->checkerGranting(true),
        );

        $keys = array_map(static fn (TrashSummary $s): string => $s->key, $service->getSummaries(['ged']));

        self::assertSame(['big', 'small', 'empty_one'], $keys);
    }

    private function source(string $module, string $key, int $count, ?string $privilege = null): TrashSourceInterface
    {
        return new class($module, $key, $count, $privilege) implements TrashSourceInterface {
            public function __construct(
                private readonly string $module,
                private readonly string $key,
                private readonly int $count,
                private readonly ?string $privilege,
            ) {}

            public function getModuleKey(): string
            {
                return $this->module;
            }

            public function getRequiredPrivilege(): ?string
            {
                return $this->privilege;
            }

            public function getSummary(int $limit): TrashSummary
            {
                return new TrashSummary($this->key, 'backend.nav.documents', 'folder-open', $this->count);
            }
        };
    }

    private function checkerGranting(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }
}
