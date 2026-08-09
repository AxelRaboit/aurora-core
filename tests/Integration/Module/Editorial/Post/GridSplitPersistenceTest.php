<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The write boundary of the content grid: the arrangement lands on the post
 * and what each zone holds lands on each translation, once each.
 *
 * The same test the banner has, for the same reason: a normaliser test cannot
 * see the wiring, and the wiring is what silently stops writing a column.
 */
final class GridSplitPersistenceTest extends IntegrationTestCase
{
    private PostManagerInterface $postManager;

    private PostInputFactoryInterface $inputFactory;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $container = static::getContainer();
        $this->postManager = $container->get(PostManagerInterface::class);
        $this->inputFactory = $container->get(PostInputFactoryInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testTheArrangementLandsOnThePostAndTheContentOnEachTranslation(): void
    {
        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $this->postType()->getId(),
            'status' => 'draft',
            'gridLayout' => [
                'enabled' => true,
                'snap' => 2,
                'zones' => [
                    ['id' => 'z1', 'type' => 'text', 'span' => ['lg' => 24]],
                    ['id' => 'z2', 'type' => 'media', 'mediaId' => 7],
                    ['id' => 'z3', 'type' => 'video'],
                ],
            ],
            'translations' => [
                'fr' => [
                    'title' => 'Bienvenue',
                    'grid' => ['zones' => [
                        'z1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Bonjour']]]],
                        'z2' => ['alt' => 'Le chantier'],
                        'z3' => ['url' => 'https://vimeo.com/fr'],
                    ]],
                ],
                'en' => [
                    'title' => 'Welcome',
                    'grid' => ['zones' => [
                        'z1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello']]]],
                        'z2' => ['alt' => 'The site'],
                        'z3' => ['url' => 'https://vimeo.com/en'],
                    ]],
                ],
            ],
        ]));

        $this->entityManager->flush();

        $layout = $post->getGridLayout();

        self::assertTrue($layout['enabled']);
        self::assertSame(2, $layout['snap']);
        self::assertSame(24, $layout['zones'][0]['span']['lg']);
        self::assertSame(7, $layout['zones'][1]['mediaId']);

        // The arrangement holds none of the content.
        self::assertArrayNotHasKey('blocks', $layout['zones'][0]);
        self::assertArrayNotHasKey('alt', $layout['zones'][1]);
        self::assertArrayNotHasKey('url', $layout['zones'][2]);

        $french = $post->getTranslation('fr')?->getGrid();
        $english = $post->getTranslation('en')?->getGrid();

        self::assertSame('Bonjour', $french['zones']['z1']['blocks'][0]['data']['text']);
        self::assertSame('Le chantier', $french['zones']['z2']['alt']);
        self::assertSame('https://vimeo.com/fr', $french['zones']['z3']['url']);

        self::assertSame('Hello', $english['zones']['z1']['blocks'][0]['data']['text']);
        self::assertSame('The site', $english['zones']['z2']['alt']);
        self::assertSame('https://vimeo.com/en', $english['zones']['z3']['url']);

        // And none of the arrangement, in either language.
        self::assertArrayNotHasKey('span', $french['zones']['z1']);
        self::assertArrayNotHasKey('mediaId', $english['zones']['z2']);
    }

    /**
     * Removing a zone from the shared arrangement has to clear what it held in
     * every language at once — otherwise a translation nobody can open keeps
     * content for something that is not on the page any more.
     */
    public function testDroppingAZoneClearsItsContentInEveryLanguage(): void
    {
        $payload = [
            'postTypeId' => $this->postType()->getId(),
            'status' => 'draft',
            'gridLayout' => [
                'enabled' => true,
                'zones' => [['id' => 'z1', 'type' => 'text'], ['id' => 'z2', 'type' => 'text']],
            ],
            'translations' => [
                'fr' => ['title' => 'Deux', 'grid' => ['zones' => [
                    'z1' => ['caption' => 'Premier'],
                    'z2' => ['caption' => 'Second'],
                ]]],
                'en' => ['title' => 'Two', 'grid' => ['zones' => [
                    'z1' => ['caption' => 'First'],
                    'z2' => ['caption' => 'Second'],
                ]]],
            ],
        ];

        $post = $this->postManager->create($this->inputFactory->fromArray($payload));
        $this->entityManager->flush();

        // The second zone goes; both languages still send their old content,
        // which is exactly what the editor does on the save right after.
        $payload['gridLayout']['zones'] = [['id' => 'z1', 'type' => 'text']];
        $this->postManager->update($post, $this->inputFactory->fromArray($payload));
        $this->entityManager->flush();

        foreach (['fr', 'en'] as $locale) {
            $grid = $post->getTranslation($locale)?->getGrid();

            self::assertSame(['z1'], array_keys($grid['zones']), $locale);
        }
    }

    /** A post that never touched the grid keeps an empty one, not a null. */
    public function testAPostWithNoGridIsStillReadable(): void
    {
        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $this->postType()->getId(),
            'status' => 'draft',
            'translations' => ['fr' => ['title' => 'Sans grille']],
        ]));

        $this->entityManager->flush();

        self::assertFalse($post->getGridLayout()['enabled']);
        self::assertSame([], $post->getGridLayout()['zones']);
        self::assertSame([], $post->getTranslation('fr')?->getGrid()['zones']);
    }

    private function postType(): PostType
    {
        $postType = new PostType();
        $postType->setSlug('grid-split-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Grid split');

        $this->entityManager->persist($postType);
        $this->entityManager->flush();

        return $postType;
    }
}
