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
 * Pose l'effet d'apparition sur des publications déjà écrites.
 *
 * Le réglage existe depuis la 0.9.235, mais il ne se met pas tout seul sur
 * quarante pages publiées avant lui, et les ouvrir une par une dans
 * l'éditeur pour changer un menu déroulant n'est pas un travail.
 *
 * **Elle ne discute jamais avec une zone qui a un avis.** Seules celles qui
 * disent « comme la page » sont touchées, et le réglage de page n'écrase
 * rien d'autre que lui-même : relancer la commande après qu'un auteur a
 * réglé une zone à la main ne défait pas son travail.
 *
 * `--pairs` traite le cas que le réglage de page ne sait pas écrire : deux
 * zones qui partagent une ligne arrivent l'une par la gauche et l'autre par
 * la droite, et se rejoignent au milieu. La ligne se lit dans `newRow` -
 * une zone qui l'ouvre, une qui la suit - et une ligne de trois zones ou
 * plus est laissée à l'effet de la page, parce que « du bord vers le
 * milieu » ne veut plus rien dire à trois.
 *
 * Tout passe par `GridNormalizer`, donc aucune valeur inventée ne peut
 * atteindre la base : un attribut que la feuille de style ne sait pas lire
 * laisserait une zone invisible sur le site public.
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

            // Une publication sans grille n'a pas de zone à faire arriver.
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
     * Deux zones qui partagent une ligne, du bord vers le milieu.
     *
     * À deux, la première vient de la gauche et la seconde de la droite ;
     * seule ou à trois, la ligne garde l'effet de la page.
     *
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function pairUp(array $zones): array
    {
        // **La ligne se demande au normaliseur, elle ne se devine pas.**
        // Premier essai : lire `newRow`. Il dit qu'une zone *ouvre* une
        // ligne, pas qu'elle en partage une, et une page peut n'en porter
        // aucun tout en plaçant deux zones côte à côte - c'est le cas de
        // toute la démonstration locale, où quatorze pages sur quatorze
        // auraient été traitées comme une seule ligne chacune. `place()`
        // fait déjà le calcul, avec les largeurs, les décalages et les
        // débordements, et c'est lui que le rendu suit.
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

                // Une zone qui a déjà un avis le garde.
                if (GridNormalizer::ZONE_REVEALS[0] !== ($zones[$index]['reveal'] ?? GridNormalizer::ZONE_REVEALS[0])) {
                    continue;
                }

                $zones[$index]['reveal'] = $effect;
            }
        }

        return $zones;
    }

    /**
     * De quoi dire si quelque chose a bougé, et le montrer en une ligne.
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
