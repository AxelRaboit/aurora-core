<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_values;
use function bin2hex;
use function random_bytes;
use function sort;
use function sprintf;

/**
 * The posts list knows every language a post is written in.
 *
 * It fetch-joined the translation of the screen's locale only, which Doctrine
 * takes for the whole collection: every post of the list then carried one
 * translation, and its language badges showed one language.
 */
final class PostListLocalesTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private ?int $postId = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (null !== $this->postId) {
            $post = $this->entityManager->find(Post::class, $this->postId);
            if (null !== $post) {
                $this->entityManager->remove($post);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testAListedPostCarriesAllItsTranslations(): void
    {
        $tag = 'langues-'.bin2hex(random_bytes(4));
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy([]);
        self::assertInstanceOf(PostType::class, $type);

        $post = new Post();
        $post->setPostType($type)->setStatus(PostStatusEnum::Draft);
        foreach (['fr' => 'Trois langues '.$tag, 'en' => 'Three languages '.$tag, 'es' => 'Tres idiomas '.$tag] as $locale => $title) {
            $post->translate($locale)->setTitle($title)->setSlug(sprintf('%s-%s', $tag, $locale));
        }

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->postId = (int) $post->getId();
        $this->entityManager->clear();

        $result = self::getContainer()->get(PostRepository::class)->findPaginated(1, 'fr', 100);
        $listed = array_values(array_filter($result['items'], fn (Post $item): bool => $item->getId() === $this->postId));
        self::assertCount(1, $listed);

        $locales = [];
        foreach ($listed[0]->getTranslations() as $translation) {
            $locales[] = $translation->getLocale();
        }

        sort($locales);

        self::assertSame(['en', 'es', 'fr'], $locales);
    }
}
