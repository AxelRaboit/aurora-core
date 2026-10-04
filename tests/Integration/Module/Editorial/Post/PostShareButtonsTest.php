<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_reverse;

/**
 * The share buttons at the bottom of a page, from the editor's box to the
 * served HTML. On by default: every page had them before the box existed.
 */
final class PostShareButtonsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<array{class-string, int}> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as [$class, $id]) {
            $entity = $this->entityManager->find($class, $id);
            if (null !== $entity) {
                $this->entityManager->remove($entity);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testAPageKeepsItsShareButtonsByDefault(): void
    {
        $post = $this->published('Page partagée', []);

        self::assertTrue($post->isShareEnabled());

        $this->client->request('GET', '/fr/share-type/page-partagee');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ShareButtons', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUntickedBoxDropsThem(): void
    {
        $post = $this->published('Page sans partage', ['shareEnabled' => false]);

        self::assertFalse($post->isShareEnabled());

        $this->client->request('GET', '/fr/share-type/page-sans-partage');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('ShareButtons', (string) $this->client->getResponse()->getContent());
    }

    /** A page nobody configured hands the buttons no list: they draw the default row. */
    public function testAnUnconfiguredPageSendsNoLinks(): void
    {
        $post = $this->published('Page par défaut', []);

        self::assertNull($post->getShareLinks());

        $this->client->request('GET', '/fr/share-type/page-par-defaut');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('&quot;links&quot;:null', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The page's own links reach the buttons in order, and an address that is
     * not https or mailto never gets that far.
     */
    public function testChosenLinksReachThePageAndBadAddressesDoNot(): void
    {
        $post = $this->published('Page liens choisis', ['shareLinks' => [
            ['type' => 'copy', 'label' => 'Copier', 'color' => '#0f766e'],
            ['type' => 'custom', 'label' => 'Piège', 'url' => 'javascript:alert(1)'],
            ['type' => 'custom', 'label' => 'Mon partage', 'url' => 'https://share.example/?u={url}'],
        ]]);

        self::assertSame(['copy', 'custom'], array_column((array) $post->getShareLinks(), 'type'));

        $this->client->request('GET', '/fr/share-type/page-liens-choisis');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Mon partage', $html);
        self::assertStringContainsString('#0f766e', $html);
        self::assertStringNotContainsString('javascript:alert', $html);
    }

    /** @param array<string, mixed> $fields */
    private function published(string $title, array $fields): PostInterface
    {
        $postType = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'share-type']);

        if (!$postType instanceof PostType) {
            $postType = new PostType();
            $postType->setSlug('share-type');
            $postType->setLabel('Share type');
            $this->entityManager->persist($postType);
            $this->entityManager->flush();
            $this->created[] = [PostType::class, (int) $postType->getId()];
        }

        $post = self::getContainer()->get(PostManagerInterface::class)->create(
            self::getContainer()->get(PostInputFactoryInterface::class)->fromArray([
                'postTypeId' => $postType->getId(),
                'status' => 'published',
                'translations' => ['fr' => ['title' => $title]],
                ...$fields,
            ]),
        );
        $this->entityManager->flush();
        $this->created[] = [Post::class, (int) $post->getId()];

        return $post;
    }
}
