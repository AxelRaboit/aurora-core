<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\View\DocumentsViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;

use function array_column;
use function array_combine;
use function array_reverse;
use function bin2hex;
use function random_bytes;

/**
 * The library's listing says, row by row, whether anything is drawing the file.
 *
 * The badge is the whole point of the screen for somebody tidying up: it marks
 * what can be deleted without breaking a page. It is drawn from `usageCount`,
 * so that key travelling with the listing is a contract, not an implementation
 * detail - and a listing that quietly stopped carrying it would mark every
 * document as free to delete.
 */
final class DocumentListingCarriesUsageTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
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

    public function testEachRowSaysWhetherItIsUsed(): void
    {
        $drawn = $this->givenDocument();
        $free = $this->givenDocument();

        $this->givenPost(static function (PostInterface $post) use ($drawn): void {
            $post->setGridLayout(['zones' => [['id' => 'z1', 'type' => 'media', 'mediaId' => (int) $drawn->getId()]]]);
        });

        $counts = $this->usageCountsOfTheListing();

        self::assertArrayHasKey((int) $drawn->getId(), $counts, 'the picture placed in a page is missing from the listing');
        self::assertArrayHasKey((int) $free->getId(), $counts);
        self::assertSame(1, $counts[(int) $drawn->getId()]);
        self::assertSame(0, $counts[(int) $free->getId()], 'a document nobody draws is answered with a zero, which is what the badge reads');
    }

    /**
     * Every row carries the key, including the ones nothing draws.
     *
     * An absent key is falsy in the template exactly like a zero, so leaving
     * it out for used documents would paint them all as unused the day the
     * badge changed its test.
     *
     * @return array<int, int>
     */
    private function usageCountsOfTheListing(): array
    {
        $payload = static::getContainer()->get(DocumentsViewBuilder::class)->buildListPayload(
            new PaginationRequest(page: 1, limit: 200, search: null),
        );

        /** @var list<array<string, mixed>> $items */
        $items = $payload['items'];

        /** @var array<int, int> $counts */
        $counts = array_combine(
            array_column($items, 'id'),
            array_column($items, 'usageCount'),
        );

        return $counts;
    }

    private function givenDocument(): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle('Photo '.bin2hex(random_bytes(4)))
            ->setOriginalName('photo.jpg')
            ->setFilePath('ged/2026/09/'.bin2hex(random_bytes(8)).'.jpg')
            ->setMimeType('image/jpeg');

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->created[] = $document;

        return $document;
    }

    private function givenPost(callable $arrange): PostInterface
    {
        $type = $this->entityManager->getRepository(PostType::class)->findOneBy(['slug' => 'listing-usage-type']);

        if (null === $type) {
            $type = new PostType();
            $type->setSlug('listing-usage-type')->setLabel('Listing usage type')->setIcon('file-text')->setHasArchive(false);
            $this->entityManager->persist($type);
            $this->entityManager->flush();
            $this->created[] = $type;
        }

        $post = new Post();
        $post->setPostType($type)->setStatus(PostStatusEnum::Draft);
        $post->translate('fr')->setTitle('Une page')->setSlug('listing-usage-'.bin2hex(random_bytes(4)));

        $arrange($post);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->created[] = $post;

        return $post;
    }
}
