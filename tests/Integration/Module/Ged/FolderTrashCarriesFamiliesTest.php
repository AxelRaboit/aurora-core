<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Manager\DocumentFolderManagerInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_reverse;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;

/**
 * A folder sent to the trash can take its families along.
 *
 * The alternates of a visual are often filed elsewhere than the visual - at
 * the root, where the import left them, or in a folder of their own. Trashing
 * the folder of the original used to leave them behind, living alternates of
 * an original nobody could see any more. Asked for, they now fall with the
 * folder, and restoring the folder brings each one back where it was filed.
 */
final class FolderTrashCarriesFamiliesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<DocumentInterface> */
    private array $documents = [];

    /** @var list<DocumentFolder> */
    private array $folders = [];

    private DocumentFolder $visuals;

    private DocumentFolder $elsewhere;

    private DocumentInterface $green;

    private DocumentInterface $yellowInside;

    private DocumentInterface $redElsewhere;

    private DocumentInterface $blueAtRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');

        $this->visuals = $this->givenFolder('Visuels');
        $this->elsewhere = $this->givenFolder('Déclinaisons');

        $this->green = $this->givenDocument('Carte', $this->visuals);
        $this->yellowInside = $this->givenDocument('Carte jaune', $this->visuals, $this->green, 'jaune');
        $this->redElsewhere = $this->givenDocument('Carte rouge', $this->elsewhere, $this->green, 'rouge');
        $this->blueAtRoot = $this->givenDocument('Carte bleue', null, $this->green, 'bleu');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->documents) as $document) {
            $managed = $this->entityManager->find(Document::class, $document->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        foreach (array_reverse($this->folders) as $folder) {
            $managed = $this->entityManager->find(DocumentFolder::class, $folder->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheConfirmationKnowsHowManyAlternatesAreFiledOutside(): void
    {
        $this->client->request('GET', sprintf('/suite/ged/folders/%d/alternates-elsewhere', $this->visuals->getId()));
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $data['count'], 'the red one in another folder and the blue one at the root, not the yellow one inside');
    }

    public function testAskedForTheFamiliesGoWithTheFolderAndComeBackWithIt(): void
    {
        $this->trash($this->visuals, ['withAlternates' => true]);

        $this->entityManager->clear();
        foreach ([$this->green, $this->yellowInside, $this->redElsewhere, $this->blueAtRoot] as $document) {
            $found = $this->reload($document);
            self::assertNotNull($found->getDeletedAt(), $found->getTitle().' went with the folder');
            self::assertSame($this->visuals->getId(), $found->getTrashedWithFolderId());
        }

        $this->client->request('POST', sprintf('/suite/ged/folders/%d/restore', $this->visuals->getId()));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        foreach ([$this->green, $this->yellowInside, $this->redElsewhere, $this->blueAtRoot] as $document) {
            self::assertNull($this->reload($document)->getDeletedAt());
        }
        self::assertSame($this->elsewhere->getId(), $this->reload($this->redElsewhere)->getFolder()?->getId(), 'back in its own folder');
        self::assertNull($this->reload($this->blueAtRoot)->getFolder(), 'back at the root');
    }

    public function testUnaskedTheAlternatesFiledOutsideStay(): void
    {
        $this->trash($this->visuals, []);

        $this->entityManager->clear();
        self::assertNotNull($this->reload($this->yellowInside)->getDeletedAt(), 'what is inside still goes');
        self::assertNull($this->reload($this->redElsewhere)->getDeletedAt());
        self::assertNull($this->reload($this->blueAtRoot)->getDeletedAt());
    }

    public function testWithoutTheCascadeNothingElseMoves(): void
    {
        $this->trash($this->visuals, ['cascade' => false, 'withAlternates' => true]);

        $this->entityManager->clear();
        self::assertNull($this->reload($this->green)->getDeletedAt(), 'the documents surface at the root');
        self::assertNull($this->reload($this->redElsewhere)->getDeletedAt());
    }

    public function testDeletingTheFolderForGoodLeavesAnAlternateInItsOwnFolder(): void
    {
        $this->trash($this->visuals, ['withAlternates' => true]);

        $this->client->request('POST', sprintf('/suite/ged/folders/%d/force-delete', $this->visuals->getId()));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $red = $this->reload($this->redElsewhere);
        self::assertNull($red->getDeletedAt(), 'released, not destroyed');
        self::assertSame($this->elsewhere->getId(), $red->getFolder()?->getId(), 'its folder was not the one that went');
        self::assertNull($this->reload($this->green)->getFolder(), 'the original lost the folder that is gone');
    }

    public function testADocumentRestoredOnItsOwnNoLongerAnswersToTheFolder(): void
    {
        $this->trash($this->visuals, ['withAlternates' => true]);

        $this->entityManager->clear();
        $manager = static::getContainer()->get(DocumentManagerInterface::class);
        $manager->restore($this->reload($this->redElsewhere));
        $manager->delete($this->reload($this->redElsewhere));

        $this->client->request('POST', sprintf('/suite/ged/folders/%d/restore', $this->visuals->getId()));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertNotNull($this->reload($this->redElsewhere)->getDeletedAt(), 'trashed on its own since, it stays trashed');
        self::assertNull($this->reload($this->redElsewhere)->getTrashedWithFolderId());
    }

    public function testTheManagerCountsTheSameBranchItTrashes(): void
    {
        $inner = $this->givenFolder('Sous-dossier', $this->visuals);
        $other = $this->givenDocument('Affiche', $inner);
        $this->givenDocument('Affiche rouge', null, $other, 'rouge');

        $manager = static::getContainer()->get(DocumentFolderManagerInterface::class);
        self::assertSame(3, $manager->countAlternatesFiledOutside($this->visuals), 'the sub-folder is part of the branch');
    }

    /** @param array<string, bool> $payload */
    private function trash(DocumentFolder $folder, array $payload): void
    {
        $this->client->request(
            'POST',
            sprintf('/suite/ged/folders/%d/delete', $folder->getId()),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload) ?: '{}',
        );
        self::assertResponseIsSuccessful();
    }

    private function reload(DocumentInterface $document): DocumentInterface
    {
        $found = $this->entityManager->find(Document::class, $document->getId());
        self::assertInstanceOf(DocumentInterface::class, $found);

        return $found;
    }

    private function givenFolder(string $name, ?DocumentFolder $parent = null): DocumentFolder
    {
        $folder = new DocumentFolder();
        $folder->setName($name.' '.bin2hex(random_bytes(3)))->setParent($parent)->setPosition(0);
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
        $this->folders[] = $folder;

        return $folder;
    }

    private function givenDocument(string $title, ?DocumentFolder $folder, ?DocumentInterface $original = null, ?string $label = null): DocumentInterface
    {
        $document = new Document();
        $document
            ->setTitle($title.' '.bin2hex(random_bytes(3)))
            ->setOriginalName('visuel.png')
            ->setFilePath('ged/2026/09/'.bin2hex(random_bytes(8)).'.png')
            ->setMimeType('image/png')
            ->setFolder($folder)
            ->setOriginal($original)
            ->setAlternateLabel($label);

        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $this->documents[] = $document;

        return $document;
    }
}
