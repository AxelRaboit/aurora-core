<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\GitHub\Service;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\GitHub\Service\GitHubActivityView;
use Aurora\Module\Editorial\GitHub\Service\GitHubContributions;
use Aurora\Module\Editorial\GitHub\Service\GitHubRepositories;
use Aurora\Module\Editorial\GitHub\Setting\GitHubSettingEnum;
use Aurora\Module\Editorial\GitHub\Setting\GitHubSettings;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\IdentityTranslator;

use function array_filter;
use function array_map;
use function sprintf;

/**
 * Where the month names go above the grid.
 */
final class GitHubActivityViewTest extends TestCase
{
    /**
     * The grid opens on the last two Sundays of September: its name would sit
     * against October's, so September goes unnamed and October keeps its own.
     */
    public function testTheMonthCutAtTheLeftEdgeGivesWayToTheNextOne(): void
    {
        self::assertSame([2 => 'oct.', 6 => 'nov.'], $this->labels('2026-09-20'));
    }

    public function testAGridOpeningOnAWholeMonthNamesIt(): void
    {
        self::assertSame([0 => 'nov.', 5 => 'déc.', 9 => 'janv.'], $this->labels('2026-11-01'));
    }

    /** Every other name only on a phone, starting with the first. */
    public function testAPhoneKeepsEveryOtherMonthName(): void
    {
        $months = array_filter($this->view('2026-11-01', 12)->build('fr')['accounts'][0]['months'] ?? []);

        self::assertSame([0 => true, 5 => false, 9 => true], array_map(static fn (array $month): bool => $month['phone'], $months));
    }

    /** @return array<int, string> */
    private function labels(string $start): array
    {
        $months = array_filter($this->view($start, 12)->build('fr')['accounts'][0]['months'] ?? []);

        return array_map(static fn (array $month): string => $month['label'], $months);
    }

    /** One Sunday per week, from `$start`: enough to place the labels. */
    private function view(string $start, int $weeks): GitHubActivityView
    {
        $cells = '';
        for ($col = 0; $col < $weeks; ++$col) {
            $date = (new DateTimeImmutable($start))->modify(sprintf('+%d weeks', $col))->format('Y-m-d');
            $cells .= sprintf('<td data-date="%s" id="contribution-day-component-0-%d" data-level="1"></td>', $date, $col);
        }

        $store = [GitHubSettingEnum::Enabled->value => '1', GitHubSettingEnum::Logins->value => 'AxelRaboit'];
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(static fn (string $key, ?string $default = null): ?string => $store[$key] ?? $default);
        $repository->method('getBoolean')->willReturnCallback(static fn (string $key): bool => '1' === ($store[$key] ?? '0'));

        return new GitHubActivityView(
            new GitHubSettings($repository),
            new GitHubContributions(new MockHttpClient(new MockResponse($cells)), new ArrayAdapter(), new NullLogger()),
            new IdentityTranslator(),
            new GitHubRepositories(new MockHttpClient(), new ArrayAdapter(), new NullLogger()),
        );
    }
}
