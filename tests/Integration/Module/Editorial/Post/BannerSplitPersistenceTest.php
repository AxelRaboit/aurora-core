<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The write boundary of the split: the design has to land on the post and the
 * words on each translation, once each.
 *
 * Covered here rather than in a unit test because the thing that can break is
 * the wiring — the manager normalising one half against the other, and two
 * entities each keeping their share. A normaliser test cannot see any of that,
 * and this is what would fail if either column stopped being written.
 */
final class BannerSplitPersistenceTest extends IntegrationTestCase
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

    public function testTheDesignLandsOnThePostAndTheWordsOnEachTranslation(): void
    {
        $postType = $this->postType();

        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $postType->getId(),
            'status' => 'draft',
            'bannerLayout' => [
                'enabled' => true,
                'height' => 'lg',
                'background' => ['type' => 'solid', 'color' => '#123456'],
                'items' => [
                    ['id' => 'a1', 'type' => 'text', 'titleSize' => 'xl'],
                    ['id' => 'a2', 'type' => 'button', 'buttonColor' => '#ffffff'],
                ],
            ],
            'translations' => [
                'fr' => [
                    'title' => 'Bienvenue',
                    'banner' => ['items' => [
                        'a1' => ['title' => 'Bonjour'],
                        'a2' => ['label' => 'Découvrir', 'url' => '/fr/a-propos'],
                    ]],
                ],
                'en' => [
                    'title' => 'Welcome',
                    'banner' => ['items' => [
                        'a1' => ['title' => 'Hello'],
                        'a2' => ['label' => 'Discover', 'url' => '/en/about'],
                    ]],
                ],
            ],
        ]));

        $this->entityManager->flush();

        $layout = $post->getBannerLayout();

        self::assertTrue($layout['enabled']);
        self::assertSame('lg', $layout['height']);
        self::assertSame('xl', $layout['items'][0]['titleSize']);
        self::assertSame('#ffffff', $layout['items'][1]['buttonColor']);

        // The design is stored once, and holds none of the copy.
        self::assertArrayNotHasKey('title', $layout['items'][0]);
        self::assertArrayNotHasKey('label', $layout['items'][1]);

        $french = $post->getTranslation('fr')?->getBanner();
        $english = $post->getTranslation('en')?->getBanner();

        self::assertSame('Bonjour', $french['items']['a1']['title']);
        self::assertSame('/fr/a-propos', $french['items']['a2']['url']);
        self::assertSame('Hello', $english['items']['a1']['title']);
        self::assertSame('/en/about', $english['items']['a2']['url']);

        // And holds none of the design, in either language.
        self::assertArrayNotHasKey('titleSize', $french['items']['a1']);
        self::assertArrayNotHasKey('buttonColor', $english['items']['a2']);
    }

    /**
     * Removing an item from the shared design has to clear its words in every
     * language at once — otherwise a translation nobody can open keeps text
     * for something that is not on the page any more.
     */
    public function testDroppingAnItemClearsItsTextInEveryLanguage(): void
    {
        $postType = $this->postType();

        $payload = [
            'postTypeId' => $postType->getId(),
            'status' => 'draft',
            'bannerLayout' => [
                'enabled' => true,
                'items' => [
                    ['id' => 'a1', 'type' => 'text'],
                    ['id' => 'a2', 'type' => 'text'],
                ],
            ],
            'translations' => [
                'fr' => ['title' => 'Deux', 'banner' => ['items' => [
                    'a1' => ['title' => 'Premier'],
                    'a2' => ['title' => 'Second'],
                ]]],
                'en' => ['title' => 'Two', 'banner' => ['items' => [
                    'a1' => ['title' => 'First'],
                    'a2' => ['title' => 'Second'],
                ]]],
            ],
        ];

        $post = $this->postManager->create($this->inputFactory->fromArray($payload));
        $this->entityManager->flush();

        // The second item goes; both languages still send their old texts,
        // which is exactly what the editor does on the save right after.
        $payload['bannerLayout']['items'] = [['id' => 'a1', 'type' => 'text']];
        $this->postManager->update($post, $this->inputFactory->fromArray($payload));
        $this->entityManager->flush();

        foreach (['fr', 'en'] as $locale) {
            $texts = $post->getTranslation($locale)?->getBanner();

            self::assertSame(['a1'], array_keys($texts['items']), $locale);
        }
    }

    private function postType(): PostType
    {
        $postType = new PostType();
        $postType->setSlug('banner-split-'.bin2hex(random_bytes(4)));
        $postType->setLabel('Banner split');

        $this->entityManager->persist($postType);
        $this->entityManager->flush();

        return $postType;
    }
}
