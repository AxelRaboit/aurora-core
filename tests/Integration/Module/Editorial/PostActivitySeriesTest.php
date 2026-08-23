<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial;

use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;

/**
 * The publishing series, and the one way it could lie.
 *
 * A `GROUP BY month` returns only the months that have something in them. Fed
 * straight to a chart, the gaps close up: a quiet August disappears and the busy
 * July next to it slides over to where August was. Nothing errors, the line
 * still looks like a line, and it says the opposite of the truth.
 *
 * So the repository fills the window itself, and that is what this asserts:
 * every month present, in order, zeroes included.
 */
final class PostActivitySeriesTest extends IntegrationTestCase
{
    private PostRepository $posts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->posts = static::getContainer()->get(PostRepository::class);
    }

    public function testEveryMonthOfTheWindowIsPresent(): void
    {
        $series = $this->posts->countPublishedByMonth(12);

        self::assertCount(12, $series);

        foreach ($series as $month => $count) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', (string) $month);
            self::assertIsInt($count);
        }
    }

    /**
     * Contiguous and ascending, ending on the current month. A chart reads the
     * keys in the order they arrive, so the order is part of the answer.
     */
    public function testTheMonthsAreContiguousAndEndOnThisOne(): void
    {
        $months = array_keys($this->posts->countPublishedByMonth(6));

        self::assertSame((new DateTimeImmutable('first day of this month'))->format('Y-m'), end($months));

        $expected = [];
        for ($offset = 5; $offset >= 0; --$offset) {
            $expected[] = (new DateTimeImmutable(sprintf('first day of -%d month', $offset)))->format('Y-m');
        }

        self::assertSame($expected, array_keys($this->posts->countPublishedByMonth(6)));
    }

    /**
     * A window of one is still a window. Asked for zero or less, the repository
     * clamps rather than returning an empty series, which a chart would draw as
     * an empty box with no axis.
     */
    public function testAWindowIsNeverEmpty(): void
    {
        self::assertCount(1, $this->posts->countPublishedByMonth(1));
        self::assertCount(1, $this->posts->countPublishedByMonth(0));
        self::assertCount(1, $this->posts->countPublishedByMonth(-3));
    }
}
