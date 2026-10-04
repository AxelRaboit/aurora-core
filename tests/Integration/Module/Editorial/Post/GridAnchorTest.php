<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A zone an author named, and the address that name becomes.
 *
 * The point of the feature is a link that lands on a block rather than at the
 * top of the page, so the thing worth asserting is the `id` in the served
 * markup - not the field on the way in, which the unit tests already cover.
 *
 * `scroll-mt-24` travels with it. The site's header is fixed, so a browser
 * told to jump to an element puts that element underneath the bar unless the
 * element reserves the room itself; without the class the feature works and
 * looks broken, which is the worse of the two failures.
 */
final class GridAnchorTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testANamedZoneCarriesItsAnchorIntoThePage(): void
    {
        $slug = $this->publish('Où me trouver ?');

        $this->client->request('GET', '/fr/anchor-type/'.$slug);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('id="ou-me-trouver"', $html);
        self::assertStringContainsString('scroll-mt-24', $html);
    }

    /** A zone nobody named gets no id: a page full of addresses nobody chose. */
    public function testAnUnnamedZoneGetsNoAnchor(): void
    {
        $slug = $this->publish(null);

        $this->client->request('GET', '/fr/anchor-type/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('scroll-mt-24', (string) $this->client->getResponse()->getContent());
    }

    private function publish(?string $anchor): string
    {
        $suffix = bin2hex(random_bytes(4));

        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'anchor-type']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('anchor-type')->setLabel('Anchor type')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        $zone = ['id' => 'a1', 'type' => 'text', 'span' => ['base' => 48, 'md' => null, 'lg' => 48]];

        if (null !== $anchor) {
            $zone['anchor'] = $anchor;
        }

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setGridLayout(['enabled' => true, 'snap' => true, 'zones' => [$zone]]);

        $slug = 'ancre-'.$suffix;
        $post->translate('fr')
            ->setTitle('Ancre')
            ->setSlug($slug)
            ->setGrid(['zones' => ['a1' => ['blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Une zone que quelqu\'un a nommée.']],
            ]]]]);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return $slug;
    }
}
