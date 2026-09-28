<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Aurora\Module\Ged\Document\Dto\DocumentInput;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Manager\DocumentManagerInterface;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_reverse;
use function bin2hex;
use function imagecolorallocate;
use function imagecolorat;
use function imagecreatefrompng;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagepng;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;

/**
 * A visual declined in another colour from the library, born in its family.
 */
final class DocumentRecolorTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $documentIds = [];

    private ?DocumentFolder $folder = null;

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
        $this->entityManager->clear();
        foreach (array_reverse($this->documentIds) as $id) {
            $managed = $this->entityManager->find(Document::class, $id);
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        if (null !== $this->folder) {
            $folder = $this->entityManager->find(DocumentFolder::class, $this->folder->getId());
            if (null !== $folder) {
                $this->entityManager->remove($folder);
                $this->entityManager->flush();
            }
        }

        parent::tearDown();
    }

    public function testTheCopyIsBornInTheFamilyWithTheOriginalsDescription(): void
    {
        $green = $this->givenGreenVisual();

        $data = $this->recolor($green, ['color' => '#BD4A55', 'label' => 'rouge']);

        self::assertTrue($data['success']);
        $red = $this->find((int) $data['document']['id']);
        self::assertSame($green->getId(), $red->getOriginal()?->getId());
        self::assertSame('rouge', $red->getAlternateLabel());
        self::assertTrue($red->isKept(), 'kept on purpose: a new colour is rarely used the day it is made');
        self::assertSame($green->getFolder()?->getId(), $red->getFolder()?->getId());
        self::assertSame($green->getAlt(), $red->getAlt());
        self::assertSame(DocumentStatusEnum::Published, $red->getStatus());
        self::assertStringContainsString('(rouge)', $red->getTitle());
        self::assertNotSame($green->getFilePath(), $red->getFilePath(), 'a file of its own');

        [$r, $g] = $this->firstPixel($red);
        self::assertGreaterThan($g, $r, 'and that file is red');
    }

    public function testAskedFromAnAlternateItDeclinesTheOriginal(): void
    {
        $green = $this->givenGreenVisual();
        $yellow = $this->find((int) $this->recolor($green, ['color' => '#cd8f31', 'label' => 'jaune'])['document']['id']);

        $data = $this->recolor($yellow, ['color' => '#0093ed', 'label' => 'bleu']);

        self::assertTrue($data['success']);
        self::assertSame($green->getId(), $this->find((int) $data['document']['id'])->getOriginal()?->getId(), 'never a copy of a copy');
    }

    public function testAColourMustBeAHexColour(): void
    {
        $green = $this->givenGreenVisual();

        $this->client->request(
            'POST',
            sprintf('/backend/ged/documents/%d/recolor', $green->getId()),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['color' => 'red; background: url(x)', 'label' => 'rouge']) ?: '{}',
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('color', $data['errors']);
    }

    public function testALabelIsRequired(): void
    {
        $green = $this->givenGreenVisual();

        $this->client->request(
            'POST',
            sprintf('/backend/ged/documents/%d/recolor', $green->getId()),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode(['color' => '#bd4a55', 'label' => '  ']) ?: '{}',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testADocumentThatIsNotAStillImageIsRefused(): void
    {
        $pdf = new Document();
        $pdf->setTitle('Contrat '.bin2hex(random_bytes(3)))
            ->setOriginalName('contrat.pdf')
            ->setFilePath('ged/2026/09/'.bin2hex(random_bytes(8)).'.pdf')
            ->setMimeType('application/pdf');
        $this->entityManager->persist($pdf);
        $this->entityManager->flush();
        $this->documentIds[] = (int) $pdf->getId();

        $data = $this->recolor($pdf, ['color' => '#bd4a55', 'label' => 'rouge'], 422);

        self::assertSame('backend.ged.documents.recolor.errors.not_an_image', $data['errors']['color']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function recolor(DocumentInterface $document, array $payload, int $expected = 200): array
    {
        $this->client->request(
            'POST',
            sprintf('/backend/ged/documents/%d/recolor', $document->getId()),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode($payload) ?: '{}',
        );
        self::assertResponseStatusCodeSame($expected);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        if (isset($data['document']['id'])) {
            $this->documentIds[] = (int) $data['document']['id'];
        }

        return $data;
    }

    private function givenGreenVisual(): DocumentInterface
    {
        $image = imagecreatetruecolor(60, 40);
        imagefilledrectangle($image, 0, 0, 59, 39, imagecolorallocate($image, 6, 90, 68));
        $path = tempnam(sys_get_temp_dir(), 'aurora-recolor').'.png';
        imagepng($image, $path);

        $this->folder = new DocumentFolder();
        $this->folder->setName('Visuels '.bin2hex(random_bytes(3)))->setPosition(0);
        $this->entityManager->persist($this->folder);
        $this->entityManager->flush();

        $file = static::getContainer()->get(GedDocumentUploader::class)->upload(new UploadedFile($path, 'visuel.png', null, null, true));
        $document = static::getContainer()->get(DocumentManagerInterface::class)->create(new DocumentInput(
            title: 'Visuel '.bin2hex(random_bytes(3)),
            status: DocumentStatusEnum::Published,
            filePath: $file['filePath'],
            fileName: $file['fileName'],
            originalName: $file['originalName'],
            mimeType: $file['mimeType'],
            size: $file['size'],
            width: $file['width'],
            height: $file['height'],
            alt: 'Un visuel vert',
            folderId: $this->folder->getId(),
        ));
        $this->documentIds[] = (int) $document->getId();

        return $document;
    }

    private function find(int $id): DocumentInterface
    {
        $this->entityManager->clear();
        $document = $this->entityManager->find(Document::class, $id);
        self::assertInstanceOf(DocumentInterface::class, $document);

        return $document;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function firstPixel(DocumentInterface $document): array
    {
        $storage = static::getContainer()->get(StorageManager::class);

        return static::getContainer()->get(LocalWorkspace::class)->readable(
            $storage->forDisk($document->getStorageDisk()),
            (string) $document->getFilePath(),
            static function (string $path): array {
                $colour = imagecolorat(imagecreatefrompng($path), 5, 5);

                return [($colour >> 16) & 255, ($colour >> 8) & 255, $colour & 255];
            },
        );
    }
}
