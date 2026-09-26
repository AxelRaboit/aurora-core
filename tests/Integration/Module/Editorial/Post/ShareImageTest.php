<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The picture a link shows when it is shared.
 *
 * It used to be the original file, which is whatever was uploaded: a portrait
 * exported at 1744 by 2700 came to 2.8 MB, downloaded by every crawler that
 * met the link, for a card that renders at about 1200 pixels wide. Nothing on
 * the page was slow, so nothing said so - the cost lands on the unfurl, out of
 * sight.
 *
 * An integration test through the served page rather than a unit test of the
 * renderer: what matters is the URL that ends up in the `og:image` tag, and a
 * unit test would assert the same string one call earlier.
 */
final class ShareImageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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

    public function testTheShareImageIsARenditionRatherThanTheOriginal(): void
    {
        $slug = $this->publish([
            'large' => 'ged/2026/09/variants/large/photo.webp',
            'medium' => 'ged/2026/09/variants/medium/photo.webp',
        ]);

        $this->client->request('GET', '/fr/share-type/'.$slug);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('variants/large/photo.webp', $html);
        self::assertStringNotContainsString('content="https://localhost/uploads/ged/2026/09/photo.png"', $html);
    }

    /** A document with no renditions still gets shared, with what it has. */
    public function testAPictureWithNoRenditionFallsBackToTheOriginal(): void
    {
        $slug = $this->publish([]);

        $this->client->request('GET', '/fr/share-type/'.$slug);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'ged/2026/09/photo.png',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** @param array<string, string> $renditions */
    private function publish(array $renditions): string
    {
        $suffix = bin2hex(random_bytes(4));

        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'share-type']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('share-type')->setLabel('Share type')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        $document = new Document();
        $document->setTitle('Photo '.$suffix)
            ->setMimeType('image/png')
            ->setFilePath('ged/2026/09/photo.png')
            ->setRenditions($renditions);

        $this->entityManager->persist($document);

        $post = new Post();
        $post->setPostType($type)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-1 day'))
            ->setThumbnail($document);

        $slug = 'partage-'.$suffix;
        $post->translate('fr')->setTitle('Partage')->setSlug($slug);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;
        $this->created[] = $document;

        return $slug;
    }
}
