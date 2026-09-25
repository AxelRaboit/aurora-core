<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Service;

use Aurora\Module\Editorial\GitHub\Setting\GitHubSettings;
use DateTimeImmutable;
use IntlDateFormatter;
use NumberFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_fill;
use function array_shift;
use function max;
use function rawurlencode;
use function sprintf;

/**
 * Ce que dessine la zone « Activité GitHub » : une carte par compte réglé.
 *
 * Les semaines arrivent en colonnes de sept cases, dimanche en haut, comme
 * GitHub les montre. La première et la dernière semaine sont incomplètes : les
 * jours qui manquent restent des cases vides plutôt que de décaler la grille,
 * sans quoi chaque ligne cesserait d'être un jour de la semaine.
 *
 * `null` quand l'intégration est éteinte ou qu'aucun compte ne répond : la
 * zone ne dessine alors rien, comme une présentation que personne n'a
 * partagée.
 */
final readonly class GitHubActivityView
{
    /** L'écart minimal, en semaines, entre deux noms de mois. */
    private const int MONTH_GAP = 3;

    public function __construct(
        private GitHubSettings $settings,
        private GitHubContributions $contributions,
        private TranslatorInterface $translator,
    ) {}

    /**
     * @return array{accounts: list<array<string, mixed>>}|null
     */
    public function build(string $locale): ?array
    {
        if (!$this->settings->isEnabled()) {
            return null;
        }

        $accounts = [];

        foreach ($this->settings->logins() as $login) {
            $grid = $this->contributions->forLogin($login);

            if (null !== $grid) {
                $accounts[] = $this->account($login, $grid, $locale);
            }
        }

        return [] === $accounts ? null : ['accounts' => $accounts];
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

            // Un mois s'écrit sur sa première semaine pleine, celle dont le
            // dimanche lui appartient : une étiquette par mois, espacées de
            // quatre ou cinq colonnes, sans chevauchement. Les deux dernières
            // colonnes restent nues, l'étiquette y déborderait du cadre.
            $month = $date->format('Y-m');

            if (0 === $day['row'] && !isset($labelled[$month]) && $day['col'] < $weeks - 2) {
                $labelled[$month] = true;
                $candidates[] = [$day['col'], (string) $monthFormat->format($date)];
            }
        }

        // Le mois coupé au bord gauche n'a souvent qu'une ou deux semaines
        // dans la grille, et son nom se colle au suivant : « sept.oct. ». Il
        // cède la place au mois entier qui le suit.
        if (isset($candidates[1]) && $candidates[1][0] - $candidates[0][0] < self::MONTH_GAP) {
            array_shift($candidates);
        }

        // Sur un téléphone la grille fait un tiers de sa largeur d'écran
        // d'ordinateur, et douze noms de mois s'y touchent : un sur deux y
        // suffit à se repérer.
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
