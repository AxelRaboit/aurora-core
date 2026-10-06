<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Command;

use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function implode;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Sets the scroll-in effect on posts already written.
 *
 * The setting has existed since 0.9.235, but it does not apply itself to
 * forty pages published before it, and opening them one by one in the
 * editor to change a dropdown is not a job.
 *
 * **It never argues with a zone that has an opinion.** Only the zones that
 * say "same as the page" are touched, and the page setting overwrites
 * nothing but itself: running the command again after an author set a zone
 * by hand does not undo their work.
 *
 * `--pairs` handles the case the page setting cannot write: two zones that
 * share a row come in, one from the left and the other from the right, and
 * meet in the middle. The row is read from `newRow` - one zone opens it, one
 * follows - and a row of three zones or more is left to the page effect,
 * because "from the edge to the middle" no longer means anything with three.
 *
 * Everything goes through `GridNormalizer`, so no made-up value can reach the
 * database: an attribute the stylesheet cannot read would leave a zone
 * invisible on the public site.
 */
#[AsCommand(
    name: 'aurora:editorial:reveal',
    description: 'Set the scroll-in effect on existing publications.',
)]
final class SetPostRevealCommand extends Command
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly GridNormalizer $grid,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('effect', null, InputOption::VALUE_REQUIRED, 'The page-wide effect: '.implode(', ', GridNormalizer::REVEALS), 'up')
            ->addOption('type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only publications of this content type slug. Repeatable; every type when absent.')
            ->addOption('pairs', null, InputOption::VALUE_NONE, 'Two zones sharing a row arrive from the left and from the right.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change and stop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $effect */
        $effect = $input->getOption('effect');

        if (!in_array($effect, GridNormalizer::REVEALS, true)) {
            $io->error(sprintf('Unknown effect "%s". Pick one of: %s.', $effect, implode(', ', GridNormalizer::REVEALS)));

            return Command::INVALID;
        }

        /** @var list<string> $types */
        $types = $input->getOption('type');
        $pairs = (bool) $input->getOption('pairs');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title(sprintf("Effet d'apparition : %s", $effect));

        $rows = [];
        $touched = 0;

        foreach ($this->posts->findAll() as $post) {
            $type = $post->getPostType()->getSlug();

            if ([] !== $types && !in_array($type, $types, true)) {
                continue;
            }

            $layout = $post->getGridLayout();

            // A post without a grid has no zone to bring in.
            if (true !== ($layout['enabled'] ?? false)) {
                continue;
            }

            $before = $this->summarise($layout);
            $layout['reveal'] = $effect;

            if ($pairs) {
                $layout['zones'] = $this->pairUp(is_array($layout['zones'] ?? null) ? $layout['zones'] : []);
            }

            $layout = $this->grid->normalizeLayout($layout);
            $after = $this->summarise($layout);

            if ($before === $after) {
                continue;
            }

            ++$touched;
            $rows[] = [$type, (string) $post->getId(), $before, $after];

            if (!$dryRun) {
                $post->setGridLayout($layout);
            }
        }

        if ([] === $rows) {
            $io->success('Rien à changer.');

            return Command::SUCCESS;
        }

        $io->table(['Type', 'Publication', 'Avant', 'Après'], $rows);

        if ($dryRun) {
            $io->note(sprintf('%d publication(s) changeraient. Rien n\'a été écrit.', $touched));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d publication(s) mises à jour.', $touched));

        return Command::SUCCESS;
    }

    /**
     * Two zones that share a row, from the edge to the middle.
     *
     * With two, the first comes from the left and the second from the right;
     * alone or with three, the row keeps the page effect.
     *
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function pairUp(array $zones): array
    {
        // **The row is asked of the normaliser, not guessed.**
        // First attempt: read `newRow`. It says a zone *opens* a row, not
        // that it shares one, and a page can carry none of them while still
        // placing two zones side by side - that is the case for the whole
        // local demo, where fourteen pages out of fourteen would have been
        // treated as a single row each. `place()` already does the
        // calculation, with widths, offsets and overflows, and it is what
        // the rendering follows.
        $rows = [];

        foreach (GridNormalizer::place($zones) as $index => $place) {
            $rows[$place['row']][] = $index;
        }

        foreach ($rows as $row) {
            if (2 !== count($row)) {
                continue;
            }

            foreach (['left', 'right'] as $position => $effect) {
                $index = $row[$position];

                // A zone that already has an opinion keeps it.
                if (GridNormalizer::ZONE_REVEALS[0] !== ($zones[$index]['reveal'] ?? GridNormalizer::ZONE_REVEALS[0])) {
                    continue;
                }

                $zones[$index]['reveal'] = $effect;
            }
        }

        return $zones;
    }

    /**
     * Enough to tell whether something moved, and to show it in one line.
     *
     * @param array<string, mixed> $layout
     */
    private function summarise(array $layout): string
    {
        $zones = is_array($layout['zones'] ?? null) ? $layout['zones'] : [];
        $own = [];

        foreach ($zones as $zone) {
            $reveal = $zone['reveal'] ?? GridNormalizer::ZONE_REVEALS[0];

            if (GridNormalizer::ZONE_REVEALS[0] === $reveal) {
                continue;
            }

            $own[] = $reveal;
        }

        return sprintf(
            '%s%s',
            $layout['reveal'] ?? GridNormalizer::REVEALS[0],
            [] === $own ? '' : ' + '.implode(', ', $own),
        );
    }
}
