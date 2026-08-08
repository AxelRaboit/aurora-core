<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\DocumentCategory\Service\InlineUploadCategoryProvider;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What happens to an image dropped into an editing form.
 *
 * The endpoint decides where it lands, and these are the two decisions that
 * are not the browser's: the category and the status. Pinned because getting
 * either wrong is invisible at the moment of upload — the picture appears in
 * the field and renders on the page — and only shows up later, as an asset
 * nobody can find again.
 */
final class ImageUploadContractTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $userRepository = static::getContainer()->get(UserRepository::class);
        $admin = $userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testAnUploadedImageComesBackReadyToUse(): void
    {
        $payload = $this->upload();

        self::assertTrue($payload['success']);

        // The two fields the picker sets on its model. Without them the image
        // is filed but the field it was picked for stays empty.
        self::assertNotNull($payload['document']['id']);
        self::assertNotEmpty($payload['document']['fileUrl']);
    }

    /**
     * The picker lists published documents only. A draft would be usable once
     * and then vanish from "choose an image" — the disorder the dedicated
     * category exists to prevent, arriving by another door.
     */
    public function testTheImageIsPublishedSoThePickerCanFindItAgain(): void
    {
        $document = $this->uploadedDocument();

        self::assertSame('published', $document->getStatus()->value);
    }

    public function testTheImageIsFiledUnderTheCategoryMeantForIt(): void
    {
        $document = $this->uploadedDocument();

        self::assertSame(
            InlineUploadCategoryProvider::SLUG,
            $document->getCategory()?->getSlug(),
            'an inline upload with no category is exactly the litter this endpoint avoids',
        );
    }

    /**
     * The bootstrap seeds the category, and the bootstrap is the one step a
     * project upgrading from an older version can skip. Every image uploaded
     * before someone remembered to run `aurora:install` would then land
     * uncategorised — so the first upload creates it.
     */
    public function testTheCategoryIsCreatedByTheFirstUploadWhenNothingSeededIt(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $category = static::getContainer()->get(InlineUploadCategoryProvider::class)->find();

        // Back to a database that never ran the bootstrap. Documents already
        // filed there would hold the foreign key, so they go first.
        self::assertNotNull($category, 'the suite is expected to have seeded it');
        $entityManager->createQuery('DELETE FROM '.Document::class.' d WHERE d.category = :category')
            ->setParameter('category', $category)
            ->execute();
        $entityManager->remove($category);
        $entityManager->flush();

        self::assertNull(
            static::getContainer()->get(InlineUploadCategoryProvider::class)->find(),
            'the category was not actually removed, so what follows would prove nothing',
        );

        $document = $this->uploadedDocument();

        self::assertSame(InlineUploadCategoryProvider::SLUG, $document->getCategory()?->getSlug());
    }

    /**
     * The endpoint is open to anyone who may create a document, and every
     * caller renders what it returns as an `<img>`. A PDF would file quietly
     * and show up as a broken picture.
     */
    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aurora-upload').'.txt';
        file_put_contents($path, 'pas une image');

        $this->client->request(
            'POST',
            '/backend/ged/documents/upload-image',
            files: ['file' => new UploadedFile($path, 'note.txt', 'text/plain', null, true)],
        );

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
    }

    public function testUploadingNothingIsRefusedRatherThanStored(): void
    {
        $this->client->request('POST', '/backend/ged/documents/upload-image');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
    }

    private function uploadedDocument(): object
    {
        $id = (int) $this->upload()['document']['id'];

        $document = static::getContainer()->get(DocumentRepository::class)->find($id);
        self::assertNotNull($document);

        return $document;
    }

    /** @return array<string, mixed> */
    private function upload(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'aurora-upload').'.png';

        // A real 1×1 PNG: the uploader reads the dimensions, so a text file
        // with a .png name would exercise a different path than the one the
        // picker takes.
        file_put_contents($path, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));

        $this->client->request(
            'POST',
            '/backend/ged/documents/upload-image',
            files: ['file' => new UploadedFile($path, 'pixel.png', 'image/png', null, true)],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
