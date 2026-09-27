<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial;

use Aurora\Core\Scheduling\Availability\ScheduleAvailabilityInterface;
use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function implode;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sprintf;
use function str_ends_with;

/**
 * Editorial talks to the calendar through core, never to it.
 *
 * A publication's date and a visitor's booking reach the calendar by
 * announcing {@see EntityScheduledEvent}, and
 * a booking zone learns which slots are taken from
 * {@see ScheduleAvailabilityInterface}.
 * The booking feature once wrote calendar events itself: it worked, and it
 * borrowed the page's id as the event's source, so the second booking on a
 * page collided with the first. Nothing flagged the import that allowed it.
 */
final class EditorialKnowsNoCalendarTest extends TestCase
{
    private const string EDITORIAL = __DIR__.'/../../../../src/Module/Editorial';

    public function testNoEditorialFileReachesIntoPlanning(): void
    {
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::EDITORIAL, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php') && 1 === preg_match('/Aurora\\\\Module\\\\Planning\\\\/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = mb_substr($file->getPathname(), mb_strlen(self::EDITORIAL) + 1);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These Editorial files name the calendar module directly:\n%s\n"
            .'Announce dates with EntityScheduledEvent and ask ScheduleAvailabilityInterface what is taken.',
            implode("\n", $offenders),
        ));
    }
}
