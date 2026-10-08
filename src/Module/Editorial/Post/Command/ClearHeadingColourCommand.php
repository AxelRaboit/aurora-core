<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Command;

use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_array;
use function is_string;
use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * Removes the colour set by hand on whole headings.
 *
 * Before the theme's "Titres du contenu" setting, the only way to get
 * coloured subheadings was to colour them one by one in the editor. On a site
 * built that way, changing the heading colour meant reopening dozens of
 * pages. Once the setting is chosen, that hand-set colour is only an
 * obstacle: it wins over the setting, heading by heading.
 *
 * **Only a heading coloured from end to end is touched**, and only in the
 * requested colour. A word coloured in the middle of a sentence, or a heading
 * only part of which is coloured, is a writing choice: it stays as it is.
 */
#[AsCommand(
    name: 'aurora:editorial:headings:clear-colour',
    description: 'Remove the colour set by hand on whole headings, so the theme heading colour applies.',
)]
final class ClearHeadingColourCommand extends Command
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('colour', null, InputOption::VALUE_REQUIRED, 'The colour to remove, as the editor wrote it.', 'var(--th-accent)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change and stop.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $colour */
        $colour = $input->getOption('colour');
        $dryRun = (bool) $input->getOption('dry-run');
        $pattern = '{^<span class="cdx-text-color" style="color: '.preg_quote($colour, '{').'">((?:(?!</span>).)*)</span>$}s';

        $rows = [];
        $total = 0;

        foreach ($this->postRepository->findAll() as $post) {
            foreach ($post->getTranslations() as $translation) {
                $count = 0;
                $grid = $this->unwrap($translation->getGrid(), $pattern, $count);

                if (0 === $count) {
                    continue;
                }

                $total += $count;
                $rows[] = [$post->getId(), $translation->getLocale(), (string) $translation->getTitle(), $count];

                if (!$dryRun) {
                    $translation->setGrid($grid);
                }
            }
        }

        if ([] === $rows) {
            $io->success(sprintf('No whole heading coloured %s.', $colour));

            return Command::SUCCESS;
        }

        $io->table(['Post', 'Locale', 'Title', 'Headings'], $rows);

        if ($dryRun) {
            $io->note(sprintf('%d heading(s) would lose their colour. Nothing was written.', $total));

            return Command::SUCCESS;
        }

        $this->entityManager->flush();
        $io->success(sprintf('%d heading(s) now follow the theme heading colour.', $total));

        return Command::SUCCESS;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function unwrap(array $node, string $pattern, int &$count): array
    {
        if ('header' === ($node['type'] ?? null) && is_array($node['data'] ?? null) && is_string($node['data']['text'] ?? null)
            && 1 === preg_match($pattern, $node['data']['text'], $match)) {
            $node['data']['text'] = $match[1];
            ++$count;
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = $this->unwrap($child, $pattern, $count);
            }
        }

        return $node;
    }
}
