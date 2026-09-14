<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * A block written once and drawn on every page that names it.
 *
 * Two things earn their tests here. The block is rendered in the language of
 * the page it lands on, not in one of its own - that is the whole reason the
 * id is shared and the words are not. And the recursion stops: a block naming
 * a block draws the first and no further, so two naming each other terminate
 * instead of resolving until the process dies.
 */
final class GridSharedZoneTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    private Environment $twig;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    public function testItDrawsTheBlocksOwnGrid(): void
    {
        $id = $this->block([
            ['id' => 'b1', 'type' => 'button'],
        ], ['b1' => ['label' => 'Parlons de votre projet', 'url' => 'https://exemple.fr/contact']]);

        $html = $this->render($id);

        self::assertStringContainsString('Parlons de votre projet', $html);
        self::assertStringContainsString('https://exemple.fr/contact', $html);
    }

    /**
     * In the language of the page it appears on. A block with nothing written
     * in that language draws nothing rather than falling back to another.
     */
    public function testABlockUntranslatedHereDrawsNothing(): void
    {
        $id = $this->block(
            [['id' => 'b1', 'type' => 'button']],
            ['b1' => ['label' => 'Let us talk', 'url' => 'https://example.com']],
            locale: 'en',
        );

        self::assertStringContainsString('Let us talk', $this->render($id, 'en'));
        self::assertSame('', mb_trim(strip_tags($this->render($id, 'fr'))));
    }

    /**
     * Depth stops at one. Without this a pair of blocks naming each other
     * would resolve until the process died, and nothing in the markup would
     * hint at why.
     */
    public function testABlockThatSharesABlockIsNotDrawnTwiceOver(): void
    {
        $inner = $this->block(
            [['id' => 'i1', 'type' => 'button']],
            ['i1' => ['label' => 'Le plus profond', 'url' => 'https://exemple.fr']],
        );

        $outer = $this->block(
            [['id' => 'o1', 'type' => 'shared', 'postId' => $inner]],
            [],
        );

        $html = $this->render($outer);

        // The outer block resolves; what it names does not, one level further.
        self::assertStringNotContainsString('Le plus profond', $html);
    }

    /** A block somebody has deleted stops appearing on the pages that used it. */
    public function testATrashedBlockDrawsNothing(): void
    {
        $id = $this->block(
            [['id' => 'b1', 'type' => 'button']],
            ['b1' => ['label' => 'Supprimé', 'url' => 'https://exemple.fr']],
        );

        $post = $this->entityManager->find(Post::class, $id);
        self::assertNotNull($post);
        $post->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame('', mb_trim(strip_tags($this->render($id))));
    }

    /**
     * @param list<array<string, mixed>>          $zones
     * @param array<string, array<string, mixed>> $words
     */
    private function block(array $zones, array $words, string $locale = 'fr'): int
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'bloc-partage']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('bloc-partage')->setLabel('Bloc')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout(['enabled' => true, 'zones' => $zones]);

        $post->translate($locale)
            ->setTitle('Bloc')
            ->setSlug('bloc-'.bin2hex(random_bytes(4)))
            ->setGrid(['zones' => $words]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return (int) $post->getId();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());

            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    private function render(int $postId, string $locale = 'fr'): string
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'shared', 'postId' => $postId]]],
            ['zones' => []],
            $locale,
        );

        self::assertNotNull($grid);

        return $this->twig->render(
            'Frontend/themes/default/editorial/post/_grid_zone.html.twig',
            ['zone' => $grid['zones'][0], 'locale' => $locale],
        );
    }
}
