<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Search\DocumentOrientationEnum;
use Aurora\Module\Ged\Document\Search\DocumentSearchFieldEnum;
use Aurora\Module\Ged\Document\Search\DocumentSearchFilters;
use Aurora\Module\Ged\Document\Search\DocumentWeightEnum;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTag;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_map;
use function array_reverse;
use function bin2hex;
use function explode;
use function json_decode;
use function random_bytes;
use function sort;
use function sprintf;

/**
 * The library is searched by whatever someone remembers of a document, not
 * only its title: the name of the file it came in, a word of its caption,
 * the tag or folder it was filed under. And it is filtered by what a title
 * never says: when it was added, its shape, its weight, or that nobody ever
 * filed it.
 *
 * Every document here carries one marker in its title, so the filters are
 * checked against these three and never against the rest of the database.
 */
final class DocumentSearchBeyondTheTitleTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private DocumentRepository $documents;

    /** @var list<object> */
    private array $created = [];

    private string $marker;

    private DocumentInterface $invoice;

    private DocumentInterface $poster;

    private DocumentInterface $square;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->documents = $container->get(DocumentRepository::class);
        $this->marker = 'm'.bin2hex(random_bytes(4));

        $category = new DocumentCategory();
        $category->setName('Rubrique '.$this->marker.'cat')->setSlug('rubrique-'.$this->marker);
        $tag = new DocumentTag();
        $tag->setName('Etiquette '.$this->marker.'tag');
        $folder = new DocumentFolder();
        $folder->setName('Classeur '.$this->marker.'dir')->setPosition(0);
        foreach ([$category, $tag, $folder] as $entity) {
            $this->persist($entity);
        }

        // A light landscape PDF scan, filed nowhere.
        $this->invoice = $this->givenDocument('Facture', 100 * 1024, 2000, 1000);
        $this->invoice->setOriginalName('facture-'.$this->marker.'file.pdf');

        // A medium portrait, described, categorised and tagged.
        $this->poster = $this->givenDocument('Affiche', 2 * 1024 * 1024, 1000, 1500);
        $this->poster->setDescription('Une affiche qui contient '.$this->marker.'text.')->setCategory($category)->addTag($tag);

        // A heavy square in a folder, with alt text.
        $this->square = $this->givenDocument('Carre', 8 * 1024 * 1024, 1000, 1000);
        $this->square->setAlt('Un carré '.$this->marker.'alt')->setFolder($folder);

        $this->entityManager->flush();

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

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

    public function testTheFileNameIsSearchedEverywhereAndUnderFileButNotUnderTitle(): void
    {
        $word = $this->marker.'file';

        self::assertSame(['Facture'], $this->titles($word));
        self::assertSame(['Facture'], $this->titles($word, new DocumentSearchFilters(searchIn: DocumentSearchFieldEnum::File)));
        self::assertSame([], $this->titles($word, new DocumentSearchFilters(searchIn: DocumentSearchFieldEnum::Title)));
    }

    public function testTheDescriptionAndAltTextAreSearchedAsText(): void
    {
        self::assertSame(['Affiche'], $this->titles($this->marker.'text', new DocumentSearchFilters(searchIn: DocumentSearchFieldEnum::Text)));
        self::assertSame(['Carre'], $this->titles($this->marker.'alt', new DocumentSearchFilters(searchIn: DocumentSearchFieldEnum::Text)));
        self::assertSame([], $this->titles($this->marker.'text', new DocumentSearchFilters(searchIn: DocumentSearchFieldEnum::File)));
    }

    public function testTagCategoryAndFolderNamesFindWhatIsFiledUnderThem(): void
    {
        $filed = new DocumentSearchFilters(searchIn: DocumentSearchFieldEnum::Classification);

        self::assertSame(['Affiche'], $this->titles($this->marker.'tag', $filed));
        self::assertSame(['Affiche'], $this->titles($this->marker.'cat', $filed));
        self::assertSame(['Carre'], $this->titles($this->marker.'dir', $filed));
        self::assertSame(['Carre'], $this->titles($this->marker.'dir'), 'the default search reads them too');
    }

    public function testWhatWasNeverFiledCanBeFound(): void
    {
        $title = DocumentSearchFieldEnum::Title;

        self::assertSame(['Carre', 'Facture'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, uncategorized: true)));
        self::assertSame(['Carre', 'Facture'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, untagged: true)));
    }

    public function testShapeAndWeightSortTheThree(): void
    {
        $title = DocumentSearchFieldEnum::Title;

        self::assertSame(['Facture'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, orientation: DocumentOrientationEnum::Landscape)));
        self::assertSame(['Affiche'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, orientation: DocumentOrientationEnum::Portrait)));
        self::assertSame(['Carre'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, orientation: DocumentOrientationEnum::Square)));

        self::assertSame(['Facture'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, weight: DocumentWeightEnum::Light)));
        self::assertSame(['Affiche'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, weight: DocumentWeightEnum::Medium)));
        self::assertSame(['Carre'], $this->titles($this->marker, new DocumentSearchFilters(searchIn: $title, weight: DocumentWeightEnum::Heavy)));
    }

    /** "Up to the 15th" includes the 15th: the bound is the end of the day. */
    public function testTheAddedDatesIncludeTheirWholeDay(): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s d SET d.createdAt = :at WHERE d.id = :id', Document::class))
            ->setParameter('at', new DateTimeImmutable('2026-01-15 18:30:00'))
            ->setParameter('id', $this->invoice->getId())
            ->execute();

        $title = DocumentSearchFieldEnum::Title;
        $day = new DateTimeImmutable('2026-01-15');

        self::assertSame(['Facture'], $this->titles($this->marker, new DocumentSearchFilters(
            searchIn: $title,
            addedFrom: $day->setTime(0, 0),
            addedTo: $day->setTime(23, 59, 59),
        )));
        self::assertSame([], $this->titles($this->marker, new DocumentSearchFilters(
            searchIn: $title,
            addedTo: $day->modify('-1 day')->setTime(23, 59, 59),
        )));
    }

    /**
     * Through the screen's own endpoint: `none` is a category and a tag
     * value there, and an integer reading of it answered 400.
     */
    public function testTheListingReadsTheNewFiltersFromItsAddress(): void
    {
        $this->client->request('GET', '/suite/ged/documents/list', [
            'search' => $this->marker,
            'searchIn' => 'title',
            'categoryId' => 'none',
            'tagId' => 'none',
            'orientation' => 'square',
            'addedFrom' => 'pas-une-date',
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['Carre'], array_map(static fn (array $row): string => explode(' ', $row['title'])[0], $payload['items']));
    }

    /** @return list<string> */
    private function titles(string $search, DocumentSearchFilters $filters = new DocumentSearchFilters()): array
    {
        $result = $this->documents->findPaginated(1, 50, search: $search, filters: $filters);
        // The name without the marker every title carries.
        $titles = array_map(static fn (DocumentInterface $document): string => explode(' ', $document->getTitle())[0], $result['items']);
        sort($titles);

        return $titles;
    }

    private function givenDocument(string $title, int $size, int $width, int $height): Document
    {
        $document = new Document();
        $document
            ->setTitle($title.' '.$this->marker)
            ->setOriginalName($title.'.png')
            ->setFilePath('ged/2026/10/'.bin2hex(random_bytes(8)).'.png')
            ->setMimeType('image/png')
            ->setSize($size)
            ->setWidth($width)
            ->setHeight($height);

        $this->persist($document);

        return $document;
    }

    private function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
        $this->created[] = $entity;
    }
}
