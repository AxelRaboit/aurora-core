<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\DataFixtures;

use Aurora\Core\DataFixtures\CoreDemoFixtures;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Ged\DataFixtures\GedDemoFixtures;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function assert;

/**
 * Demo content: a handful of posts across the statuses, terms to file them
 * under, and a primary menu that points at them.
 *
 * Builds on what `aurora:install` already created rather than creating it
 * again — the post types and the taxonomies are the product's floor, not
 * demo data, and a fixture that made its own would give the site two
 * "Articles" types the day someone ran both. It looks them up and fails
 * loudly if they are absent, which means the install step was skipped.
 *
 * Dev/test only, `demo` group.
 */
class EditorialDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly PostTypeRepository $postTypeRepository,
        private readonly TaxonomyRepository $taxonomyRepository,
        private readonly MenuRepository $menuRepository,
        private readonly PostTextExtractor $textExtractor,
        private readonly GridNormalizer $gridNormalizer,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        // GED too, since the welcome page's grid points at a demo picture.
        return [CoreDemoFixtures::class, GedDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $article = $this->postTypeRepository->findOneBySlug('article');
        $page = $this->postTypeRepository->findOneBySlug('page');
        if (!$article instanceof PostTypeInterface || !$page instanceof PostTypeInterface) {
            // Better than a fatal further down with no clue why: the demo
            // stands on the install step, and saying so is the whole message.
            throw new RuntimeException('Run `aurora:install` before loading the demo fixtures — the built-in post types are missing.');
        }

        $terms = $this->createTerms($manager);
        $posts = $this->createPosts($manager, $article, $page, $terms);

        $manager->flush();

        // After the flush on purpose: a grid points at a publication and a
        // document by *id*, and neither has one before it is written.
        $this->layOutWelcomePage($posts);

        $this->fillPrimaryMenu($manager, $posts);

        $manager->flush();
    }

    /**
     * A nested pair under `category` and two flat ones under `tag`, so both
     * taxonomy shapes have something in them to look at.
     *
     * @return array<string, TaxonomyTermInterface>
     */
    private function createTerms(EntityManagerInterface $em): array
    {
        $category = $this->taxonomyRepository->findOneBySlug('category');
        $tag = $this->taxonomyRepository->findOneBySlug('tag');

        $terms = [];

        if ($category instanceof TaxonomyInterface) {
            $terms['guides'] = $this->term($em, $category, [
                'fr' => ['Guides', 'guides'],
                'en' => ['Guides', 'guides'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-1');

            $terms['starters'] = $this->term($em, $category, [
                'fr' => ['Premiers pas', 'premiers-pas'],
                'en' => ['Getting started', 'getting-started'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-2', $terms['guides']);
        }

        if ($tag instanceof TaxonomyInterface) {
            $terms['editorial'] = $this->term($em, $tag, [
                'fr' => ['Éditorial', 'editorial'],
                'en' => ['Editorial', 'editorial'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-3');

            $terms['release'] = $this->term($em, $tag, [
                'fr' => ['Nouveautés', 'nouveautes'],
                'en' => ['Releases', 'releases'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-4');
        }

        return $terms;
    }

    /**
     * @param array<string, array{0: string, 1: string}> $translations locale → [name, slug]
     */
    private function term(
        EntityManagerInterface $em,
        TaxonomyInterface $taxonomy,
        array $translations,
        string $reference,
        ?TaxonomyTermInterface $parent = null,
    ): TaxonomyTermInterface {
        $term = new TaxonomyTerm()
            ->setTaxonomy($taxonomy)
            ->setParent($parent)
            ->setReference($reference)
            ->setPosition(0);

        foreach ($translations as $locale => [$name, $slug]) {
            $term->translate($locale)->setName($name)->setSlug($slug);
        }

        $em->persist($term);

        return $term;
    }

    /**
     * One post per status, so every filter on the list has something to
     * show and the dashboard bars are not a single full-width block.
     *
     * @param array<string, TaxonomyTermInterface> $terms
     *
     * @return array<string, PostInterface>
     */
    private function createPosts(
        EntityManagerInterface $em,
        PostTypeInterface $article,
        PostTypeInterface $page,
        array $terms,
    ): array {
        // Doctrine keys references by concrete class, so this asks for what
        // CoreDemoFixtures actually stored rather than for the interface.
        $author = $this->getReference(CoreDemoFixtures::userRef(0), User::class);
        $now = new DateTimeImmutable();

        $defs = [
            'welcome' => [
                'type' => $page,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-30 days'),
                'terms' => [],
                'fr' => ['Bienvenue', 'bienvenue', 'La page d\'accueil de ce site de démonstration.'],
                'en' => ['Welcome', 'welcome', 'The landing page of this demo site.'],
            ],
            'first-steps' => [
                'type' => $article,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-12 days'),
                'terms' => ['starters', 'editorial'],
                'fr' => ['Écrire son premier article', 'ecrire-premier-article', 'Du brouillon à la mise en ligne, en cinq minutes.'],
                'en' => ['Writing your first post', 'writing-your-first-post', 'From draft to published, in five minutes.'],
            ],
            'blocks' => [
                'type' => $article,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-3 days'),
                'terms' => ['guides'],
                'fr' => ['Composer avec les blocs', 'composer-avec-les-blocs', 'Titres, listes, encadrés : ce que l\'éditeur sait faire.'],
                'en' => ['Composing with blocks', 'composing-with-blocks', 'Headings, lists, callouts: what the editor can do.'],
            ],
            'roadmap' => [
                'type' => $article,
                'status' => PostStatusEnum::Draft,
                'publishedAt' => null,
                'terms' => ['release'],
                'fr' => ['Ce qui arrive ensuite', 'ce-qui-arrive-ensuite', 'Un brouillon, visible seulement en administration.'],
                'en' => ['What comes next', 'what-comes-next', 'A draft, visible in the backend only.'],
            ],
            'announcement' => [
                'type' => $article,
                'status' => PostStatusEnum::Scheduled,
                'publishedAt' => null,
                'scheduledAt' => $now->modify('+7 days'),
                'terms' => ['release'],
                'fr' => ['Annonce à venir', 'annonce-a-venir', 'Programmée : elle se publiera toute seule.'],
                'en' => ['Upcoming announcement', 'upcoming-announcement', 'Scheduled: it will publish itself.'],
            ],
        ];

        $posts = [];
        $index = 0;

        foreach ($defs as $key => $def) {
            ++$index;

            $post = new Post()
                ->setPostType($def['type'])
                ->setAuthor($author)
                ->setStatus($def['status'])
                ->setPublishedAt($def['publishedAt'])
                ->setScheduledAt($def['scheduledAt'] ?? null)
                ->setReference(sprintf('%s-DEMO-%d', SequencePrefixEnum::Post->value, $index));

            foreach ($def['terms'] as $termKey) {
                if (isset($terms[$termKey])) {
                    $post->addTerm($terms[$termKey]);
                }
            }

            foreach (['fr', 'en'] as $locale) {
                [$title, $slug, $description] = $def[$locale];

                $translation = $post->translate($locale)
                    ->setTitle($title)
                    ->setSlug($slug)
                    ->setDescription($description)
                    ->setBlocks($this->blocks($title, $description));

                $this->indexForSearch($translation);
            }

            $em->persist($post);
            $posts[$key] = $post;
        }

        return $posts;
    }

    /**
     * The welcome page shows what a content grid can do: all four zone types
     * and four different widths, on the 48-column grid.
     *
     * Widths are all multiples of four, so every one of them is reachable at
     * the default snap — a demo an author cannot reproduce with the controls
     * in front of them teaches the wrong thing.
     *
     * The arrangement is written once and both languages share it; only what
     * fills the zones is translated. That is the feature, so the fixture has
     * to be built that way rather than duplicating a layout per locale.
     *
     * @param array<string, PostInterface> $posts
     */
    private function layOutWelcomePage(array $posts): void
    {
        $welcome = $posts['welcome'];
        $linked = $posts['first-steps'];
        $picture = $this->getReference(GedDemoFixtures::mediaRef(1), Document::class);

        $zones = [
            ['id' => 'intro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
            // A picture and its commentary side by side: half each above the
            // large breakpoint, stacked below it.
            ['id' => 'picture', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'mediaId' => $picture->getId()],
            ['id' => 'beside', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
            // Two thirds and one third: a player next to the article it is
            // about.
            ['id' => 'film', 'type' => GridNormalizer::ZONE_VIDEO, 'span' => ['base' => 48, 'md' => null, 'lg' => 32]],
            ['id' => 'linked', 'type' => GridNormalizer::ZONE_POST, 'span' => ['base' => 48, 'md' => null, 'lg' => 16], 'postId' => $linked->getId()],
            ['id' => 'outro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
        ];

        $welcome->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => $zones,
        ]));

        $content = [
            'fr' => [
                'intro' => [EditorBlocks::header('Une page composée par zones'),
                    EditorBlocks::paragraph('Chaque bloc ci-dessous est une zone posée sur une grille de 48 colonnes. Leur largeur se règle indépendamment, et ce qui les remplit se traduit — la disposition, elle, est écrite une seule fois.')],
                'beside' => [EditorBlocks::header('Texte et image côte à côte', 3),
                    EditorBlocks::paragraph('Deux zones de 24 colonnes se partagent la ligne sur grand écran. Sur téléphone elles s\'empilent : une colonne de quatre mots n\'est pas une mise en page.')],
                'outro' => [EditorBlocks::paragraph("Une zone pleine largeur pour refermer. Modifiez tout ceci depuis l'administration, onglet Contenu.")],
            ],
            'en' => [
                'intro' => [EditorBlocks::header('A page laid out in zones'),
                    EditorBlocks::paragraph('Every block below is a zone on a 48-column grid. Widths are set independently, and what fills them is translated — the arrangement is written once.')],
                'beside' => [EditorBlocks::header('Text and picture side by side', 3),
                    EditorBlocks::paragraph('Two 24-column zones share the row on a large screen. On a phone they stack: a column four words wide is not a layout.')],
                'outro' => [EditorBlocks::paragraph('A full-width zone to close. Change any of this from the backend, under Content.')],
            ],
        ];

        $captions = [
            'fr' => ['alt' => 'Un paysage de démonstration', 'caption' => 'Une image, avec sa légende — les deux se traduisent, l\'image non.'],
            'en' => ['alt' => 'A demo landscape', 'caption' => 'A picture and its caption — both translated, the picture itself is not.'],
        ];

        foreach (['fr', 'en'] as $locale) {
            $translation = $welcome->translate($locale);

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'intro' => ['blocks' => $content[$locale]['intro']],
                    'picture' => $captions[$locale],
                    'beside' => ['blocks' => $content[$locale]['beside']],
                    // Big Buck Bunny — Blender's open movie, which is here
                    // because a demo address that refuses to embed looks like
                    // a broken feature rather than a placeholder.
                    'film' => [
                        'url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
                        'caption' => 'fr' === $locale ? 'Une vidéo, par langue.' : 'A video, per language.',
                    ],
                    'outro' => ['blocks' => $content[$locale]['outro']],
                ],
            ], $welcome->getGridLayout()));

            $this->indexForSearch($translation);
        }
    }

    /**
     * A body in the shape the *editor* expects — which is stricter than what
     * the renderer accepts, and the reason these go through EditorBlocks
     * rather than being written out by hand.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocks(string $title, string $description): array
    {
        return [
            EditorBlocks::paragraph($description),
            EditorBlocks::header($title),
            EditorBlocks::paragraph('Ce contenu est une démonstration. Remplacez-le par le vôtre depuis l\'administration.'),
            EditorBlocks::list(['Un premier point', 'Un deuxième point']),
        ];
    }

    /**
     * The same flattening the Manager does on save. Without it a demo post
     * exists but no search finds it, which reads as a broken search rather
     * than as an unindexed fixture.
     */
    private function indexForSearch(PostTranslationInterface $translation): void
    {
        $translation->setSearchContent($this->textExtractor->extract($translation));
    }

    /**
     * Hangs the demo pages off the primary menu the install created, rather
     * than making a second menu: a location holds one menu, and a demo that
     * needed its own would be demonstrating something the product cannot do.
     *
     * @param array<string, PostInterface> $posts
     */
    private function fillPrimaryMenu(EntityManagerInterface $em, array $posts): void
    {
        $menu = $this->menuRepository->findOneByLocation('primary');
        if (!$menu instanceof MenuInterface) {
            return;
        }

        $entries = [
            ['post' => 'welcome', 'fr' => 'Bienvenue', 'en' => 'Welcome'],
            ['post' => 'first-steps', 'fr' => 'Premiers pas', 'en' => 'Getting started'],
        ];

        // The seeded "Home" entry sits at position 0; these follow it.
        $position = 1;

        foreach ($entries as $entry) {
            $post = $posts[$entry['post']] ?? null;
            if (!$post instanceof PostInterface) {
                continue;
            }

            $item = new MenuItem()
                ->setTargetType(MenuItemTargetTypeEnum::Post)
                ->setTargetId($post->getId())
                ->setPosition($position++);

            $item->translate('fr')->setLabel($entry['fr']);
            $item->translate('en')->setLabel($entry['en']);

            $menu->addItem($item);
            $em->persist($item);
        }
    }
}
