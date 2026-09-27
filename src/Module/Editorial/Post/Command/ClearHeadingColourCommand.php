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
 * Retire la couleur posée à la main sur des titres entiers.
 *
 * Avant le réglage « Titres du contenu » du thème, la seule façon d'avoir des
 * intertitres de couleur était de les colorer un par un dans l'éditeur. Sur
 * un site monté ainsi, changer la couleur des titres voulait dire rouvrir des
 * dizaines de pages. Une fois le réglage choisi, cette couleur posée à la
 * main n'est plus qu'un obstacle : elle passe devant lui, titre par titre.
 *
 * **Seul un titre coloré d'un bout à l'autre est touché**, et seulement dans
 * la couleur demandée. Un mot mis en couleur au milieu d'une phrase, ou un
 * titre dont une partie seulement est colorée, est un choix d'écriture : il
 * reste tel quel.
 */
#[AsCommand(
    name: 'aurora:editorial:headings:clear-colour',
    description: 'Remove the colour set by hand on whole headings, so the theme heading colour applies.',
)]
final class ClearHeadingColourCommand extends Command
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly EntityManagerInterface $em,
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

        foreach ($this->posts->findAll() as $post) {
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

        $this->em->flush();
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
