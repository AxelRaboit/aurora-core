<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\GitHub\Service;

use Aurora\Module\Editorial\GitHub\Service\GitHubContributions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reading a profile's contributions grid, and living with GitHub not answering.
 *
 * The page is not a documented API, so the tests hold its shape as GitHub
 * served it in September 2026: cells carrying a level and a position, the
 * count only in the tooltip.
 */
final class GitHubContributionsTest extends TestCase
{
    private const string PAGE = <<<'HTML'
        <table><tbody>
        <tr>
          <td data-date="2026-09-13" id="contribution-day-component-0-0" data-level="0" class="ContributionCalendar-day"></td>
          <td data-date="2026-09-20" id="contribution-day-component-0-1" data-level="4" class="ContributionCalendar-day"></td>
        </tr>
        <tr>
          <td data-date="2026-09-14" id="contribution-day-component-1-0" data-level="2" class="ContributionCalendar-day"></td>
        </tr>
        </tbody></table>
        <tool-tip for="contribution-day-component-0-0">No contributions on September 13th.</tool-tip>
        <tool-tip for="contribution-day-component-0-1">1,204 contributions on September 20th.</tool-tip>
        <tool-tip for="contribution-day-component-1-0">3 contributions on September 14th.</tool-tip>
        HTML;

    public function testTheCountComesFromTheTooltip(): void
    {
        $grid = GitHubContributions::parse(self::PAGE);

        self::assertNotNull($grid);
        self::assertSame(1207, $grid['total']);
        self::assertSame(
            [
                ['date' => '2026-09-13', 'level' => 0, 'count' => 0, 'row' => 0, 'col' => 0],
                ['date' => '2026-09-14', 'level' => 2, 'count' => 3, 'row' => 1, 'col' => 0],
                ['date' => '2026-09-20', 'level' => 4, 'count' => 1204, 'row' => 0, 'col' => 1],
            ],
            $grid['days'],
        );
    }

    /** A page that changed shape is "nothing to show", never a half-read grid. */
    public function testAPageWithoutCellsIsNotAGrid(): void
    {
        self::assertNull(GitHubContributions::parse('<p>Not found</p>'));
        self::assertNull(GitHubContributions::parse(''));
    }

    public function testAGridIsAskedForOnceAndThenServedFromTheCache(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::PAGE);
        });

        $service = new GitHubContributions($client, new ArrayAdapter(), new NullLogger());

        self::assertSame(1207, $service->forLogin('AxelRaboit')['total'] ?? null);
        self::assertSame(1207, $service->forLogin('axelraboit')['total'] ?? null);
        self::assertSame(1, $calls);
    }

    /**
     * GitHub down is not a hole in the page: the last grid read stays, and the
     * next visitor does not wait on GitHub all over again.
     */
    public function testTheLastGridReadOutlivesAnOutage(): void
    {
        $cache = new ArrayAdapter();
        $up = new GitHubContributions(new MockHttpClient(new MockResponse(self::PAGE)), $cache, new NullLogger());
        $up->forLogin('AxelRaboit');

        // The fresh entry expires; only the stale copy is left.
        $cache->delete('editorial.github.contributions.axelraboit');

        $calls = 0;
        $down = new GitHubContributions(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 503]);
        }), $cache, new NullLogger());

        self::assertSame(1207, $down->forLogin('AxelRaboit')['total'] ?? null);
        self::assertSame(1207, $down->forLogin('AxelRaboit')['total'] ?? null);
        self::assertSame(1, $calls);
    }

    public function testAnAccountThatNeverAnsweredShowsNothing(): void
    {
        $service = new GitHubContributions(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertNull($service->forLogin('nobody-here'));
    }
}
