<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Service;

use Aurora\Module\Editorial\GitHub\Setting\GitHubSettings;
use DateTimeImmutable;
use IntlDateFormatter;
use NumberFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_fill;
use function array_filter;
use function array_map;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function max;
use function mb_strtolower;
use function rawurlencode;
use function sprintf;
use function usort;

/**
 * What the "Activité GitHub" zone draws: one card per configured account.
 *
 * The weeks come in columns of seven cells, Sunday at the top, as GitHub
 * shows them. The first and last weeks are incomplete: the missing days stay
 * empty cells rather than shifting the grid, otherwise each row would stop
 * being a day of the week.
 *
 * `null` when the integration is off or no account answers: the zone then
 * draws nothing, like a presentation nobody has shared.
 */
final readonly class GitHubActivityView
{
    /** The minimum gap, in weeks, between two month names. */
    private const int MONTH_GAP = 3;

    public function __construct(
        private GitHubSettings $settings,
        private GitHubContributions $contributions,
        private TranslatorInterface $translator,
        private GitHubRepositories $repositories,
    ) {}

    /**
     * @param array<string, mixed> $options the zone's options: which mode, which repositories
     *
     * @return array<string, mixed>|null
     */
    public function build(string $locale, array $options = []): ?array
    {
        if (!$this->settings->isEnabled()) {
            return null;
        }

        $mode = $options['githubMode'] ?? 'activity';
        $repos = $options['githubRepos'] ?? [];

        if ('repos' === $mode) {
            return $this->repositoryCards($repos, $locale);
        }

        if ('releases' === $mode) {
            return $this->releaseList($repos, $locale);
        }

        // A zone may name some of the site's accounts, so that two zones can
        // each show one beside its own text. The settings keep the order and
        // the final say: a name they do not hold is not drawn.
        $wanted = array_map(mb_strtolower(...), $options['githubLogins'] ?? []);
        $logins = [] === $wanted
            ? $this->settings->logins()
            : array_values(array_filter(
                $this->settings->logins(),
                static fn (string $login): bool => in_array(mb_strtolower($login), $wanted, true),
            ));

        $accounts = [];

        foreach ($logins as $login) {
            $grid = $this->contributions->forLogin($login);

            if (null !== $grid) {
                $accounts[] = $this->account($login, $grid, $locale);
            }
        }

        return [] === $accounts ? null : ['mode' => 'activity', 'accounts' => $accounts];
    }

    /**
     * One card per repository named on the zone, in its order.
     *
     * @param list<string> $repos
     *
     * @return array<string, mixed>|null
     */
    private function repositoryCards(array $repos, string $locale): ?array
    {
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $dates = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
        $cards = [];

        foreach ($repos as $repo) {
            $card = $this->repositories->repository($repo);

            if (null === $card) {
                continue;
            }

            $cards[] = [
                ...$card,
                'starsLabel' => (string) $numbers->format($card['stars']),
                'forksLabel' => (string) $numbers->format($card['forks']),
                'updatedLabel' => '' === $card['pushedAt']
                    ? ''
                    : $this->translator->trans('frontend.editorial.grid.github.updated', ['%date%' => $dates->format(new DateTimeImmutable($card['pushedAt']))], 'messages', $locale),
            ];
        }

        return [] === $cards ? null : ['mode' => 'repos', 'repos' => $cards];
    }

    /**
     * The latest releases of every repository named, newest first.
     *
     * @param list<string> $repos
     *
     * @return array<string, mixed>|null
     */
    private function releaseList(array $repos, string $locale): ?array
    {
        $dates = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC');
        $releases = [];

        foreach ($repos as $repo) {
            foreach ($this->repositories->releases($repo) ?? [] as $release) {
                $releases[] = [
                    ...$release,
                    'repo' => $repo,
                    'dateLabel' => '' === $release['publishedAt'] ? '' : (string) $dates->format(new DateTimeImmutable($release['publishedAt'])),
                ];
            }
        }

        usort($releases, static fn (array $a, array $b): int => $b['publishedAt'] <=> $a['publishedAt']);

        return [] === $releases ? null : ['mode' => 'releases', 'releases' => array_slice($releases, 0, 6), 'multiple' => count($repos) > 1];
    }

    /**
     * @param array{total: int, days: list<array{date: string, level: int, count: int, row: int, col: int}>} $grid
     *
     * @return array<string, mixed>
     */
    private function account(string $login, array $grid, string $locale): array
    {
        $weeks = 0;
        foreach ($grid['days'] as $day) {
            $weeks = max($weeks, $day['col'] + 1);
        }

        $dayFormat = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'UTC');
        $monthFormat = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'MMM');
        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);

        /** @var list<list<array{level: int, label: string}|null>> $columns */
        $columns = array_fill(0, $weeks, array_fill(0, 7, null));
        $months = array_fill(0, $weeks, null);
        $labelled = [];
        $candidates = [];

        foreach ($grid['days'] as $day) {
            $date = new DateTimeImmutable($day['date'].' 00:00:00 UTC');

            $columns[$day['col']][$day['row']] = [
                'level' => $day['level'],
                'label' => $this->translator->trans('frontend.editorial.grid.github.day', [
                    '%count%' => $day['count'],
                    '%number%' => $numbers->format($day['count']),
                    '%date%' => $dayFormat->format($date),
                ], 'messages', $locale),
            ];

            // A month is written on its first full week, the one whose Sunday belongs
            // to it: one label per month, spaced four or five columns apart, without
            // overlap. The last two columns stay bare, the label would overflow the
            // frame there.
            $month = $date->format('Y-m');

            if (0 === $day['row'] && !isset($labelled[$month]) && $day['col'] < $weeks - 2) {
                $labelled[$month] = true;
                $candidates[] = [$day['col'], (string) $monthFormat->format($date)];
            }
        }

        // The month cut off at the left edge often has only one or two weeks in
        // the grid, and its name sticks to the next one: "sept.oct.". It gives way
        // to the full month that follows it.
        if (isset($candidates[1]) && $candidates[1][0] - $candidates[0][0] < self::MONTH_GAP) {
            array_shift($candidates);
        }

        // On a phone the grid is a third of its width on a computer screen, and
        // twelve month names touch each other there: one in two is enough to find
        // your way.
        foreach ($candidates as $index => [$column, $label]) {
            $months[$column] = ['label' => $label, 'phone' => 0 === $index % 2];
        }

        return [
            'login' => $login,
            'url' => sprintf('https://github.com/%s', rawurlencode($login)),
            'total' => $grid['total'],
            'totalLabel' => $this->translator->trans('frontend.editorial.grid.github.total', [
                '%count%' => $grid['total'],
                '%number%' => $numbers->format($grid['total']),
            ], 'messages', $locale),
            'columns' => $columns,
            'months' => $months,
        ];
    }
}
