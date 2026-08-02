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
use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
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
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [CoreDemoFixtures::class];
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
     * A body in the shape BlocksRenderer expects, so the public pages have
     * something to render and the search index something to find. Kept to
     * the block types the renderer actually handles.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocks(string $title, string $description): array
    {
        return [
            ['type' => 'paragraph', 'data' => ['text' => $description]],
            ['type' => 'header', 'data' => ['text' => $title, 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'Ce contenu est une démonstration. Remplacez-le par le vôtre depuis l\'administration.']],
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                ['content' => 'Un premier point'],
                ['content' => 'Un deuxième point'],
            ]]],
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
