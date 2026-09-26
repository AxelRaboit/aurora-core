<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\View\DocumentsViewBuilder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_reverse;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * An original and its alternates, and the documents kept on purpose.
 *
 * The screen reads three things off the listing - `alternateCount` on an
 * original, `originalId` and `alternateLabel` on an alternate, `kept` on
 * either - so those keys are the contract. The edit form sends them back
 * on every save, which is also why a save must leave them as they were.
 */
final class DocumentFamiliesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<DocumentInterface> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $document) {
            $managed = $this->entityManager->find(Document::class, $document->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testASaveDeclaresTheAlternateAndKeepsIt(): void
    {
        $green = $this->givenDocument('Entête accueil');
        $yellow = $this->givenDocument('Entête accueil jaune');

        $response = $this->update($yellow, [
            'originalId' => $green->getId(),
            'alternateLabel' => 'jaune',
            'kept' => true,
        ]);

        self::assertTrue($response['success'] ?? false, json_encode($response) ?: '');
        self::assertSame($green->getId(), $response['document']['originalId']);
        self::assertSame($green->getTitle(), $response['document']['originalTitle']);
        self::assertSame('jaune', $response['document']['alternateLabel']);
        self::assertTrue($response['document']['kept']);
    }

    public function testTheListingCountsAnOriginalsAlternatesAndCanFoldThem(): void
    {
        $green = $this->givenDocument('Carte verte');
        $yellow = $this->givenDocument('Carte jaune', $green, 'jaune');
        $red = $this->givenDocument('Carte rouge', $green, 'rouge');

        $rows = $this->listing(originalsOnly: false);
        self::assertSame(2, $rows[(int) $green->getId()]['alternateCount']);
        self::assertSame(0, $rows[(int) $yellow->getId()]['alternateCount']);
        self::assertSame('rouge', $rows[(int) $red->getId()]['alternateLabel']);

        $folded = $this->listing(originalsOnly: true);
        self::assertArrayHasKey((int) $green->getId(), $folded);
        self::assertArrayNotHasKey((int) $yellow->getId(), $folded, 'folded, a family shows its original alone');
        self::assertArrayNotHasKey((int) $red->getId(), $folded);
    }

    public function testTheAlternatesEndpointListsThemByLabel(): void
    {
        $green = $this->givenDocument('Portrait');
        $this->givenDocument('Portrait rouge', $green, 'rouge');
        $this->givenDocument('Portrait jaune', $green, 'jaune');

        $this->client->request('GET', sprintf('/backend/ged/documents/%d/alternates', $green->getId()));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(['jaune', 'rouge'], array_column($data['alternates'], 'alternateLabel'));
    }

    /** One level only: an alternate cannot be anybody's original. */
    public function testAnAlternateCannotBeChosenAsAnOriginal(): void
    {
        $green = $this->givenDocument('Visuel');
        $yellow = $this->givenDocument('Visuel jaune', $green, 'jaune');
        $other = $this->givenDocument('Autre');

        $response = $this->update($other, ['originalId' => $yellow->getId()]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('originalId', $response['errors']);
    }

    /** Nor can an original with alternates become an alternate itself. */
    public function testAnOriginalWithAlternatesStaysAnOriginal(): void
    {
        $green = $this->givenDocument('Visuel');
        $this->givenDocument('Visuel jaune', $green, 'jaune');
        $other = $this->givenDocument('Autre');

        $response = $this->update($green, ['originalId' => $other->getId()]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('originalId', $response['errors']);
    }

    public function testADocumentIsNotItsOwnAlternate(): void
    {
        $green = $this->givenDocument('Visuel');

        $response = $this->update($green, ['originalId' => $green->getId()]);

        self::assertFalse($response['success']);
        self::assertArrayHasKey('originalId', $response['errors']);
    }

    /** The label means nothing without an original, so it does not stay. */
    public function testRemovingTheOriginalDropsTheLabel(): void
    {
        $green = $this->givenDocument('Visuel');
        $yellow = $this->givenDocument('Visuel jaune', $green, 'jaune');

        $response = $this->update($yellow, ['originalId' => null, 'alternateLabel' => 'jaune']);

        self::assertNull($response['document']['originalId']);
        self::assertNull($response['document']['alternateLabel']);
    }

    /** Deleting the original leaves its alternates standing on their own. */
    public function testDeletingTheOriginalFreesItsAlternates(): void
    {
        $green = $this->givenDocument('Visuel');
        $yellow = $this->givenDocument('Visuel jaune', $green, 'jaune');

        $this->entityManager->remove($green);
        $this->entityManager->flush();
        $this->created = [$yellow];
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Document::class, $yellow->getId());
        self::assertInstanceOf(Document::class, $reloaded);
        self::assertNull($reloaded->getOriginal());
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function update(DocumentInterface $document, array $changes): array
    {
        $payload = [
            'title' => $document->getTitle(),
            'status' => $document->getStatus()->value,
            ...$changes,
        ];

        $this->client->request(
            'POST',
            sprintf('/backend/ged/documents/%d/update', $document->getId()),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload) ?: '{}',
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<int, array<string, mixed>> */
    private function listing(bool $originalsOnly): array
    {
        $payload = static::getContainer()->get(DocumentsViewBuilder::class)->buildListPayload(
            new PaginationRequest(page: 1, limit: 200, search: null),
            originalsOnly: $originalsOnly,
        );

        $rows = [];
        foreach ($payload['items'] as $item) {
            $rows[(int) $item['id']] = $item;
        }

        return $rows;
    }

    private function givenDocument(string $title, ?DocumentInterface $original = null, ?string $label = null): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle($title.' '.bin2hex(random_bytes(3)))
            ->setOriginalName('visuel.png')
            ->setFilePath('ged/2026/09/'.bin2hex(random_bytes(8)).'.png')
            ->setMimeType('image/png')
            ->setOriginal($original)
            ->setAlternateLabel($label);

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        $this->created[] = $document;

        return $document;
    }
}
