<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The two-step upload the image picker chains: bytes first, then the Document
 * row. `useImageUpload` reads specific keys out of each response and would
 * fail silently at the second call if either shape moved.
 *
 * Worth pinning because that composable existed for months without a single
 * caller — the picker only offered already-filed media — so nothing exercised
 * this contract at all. The same module already shipped an editor pointing at
 * `/backend/media/media/upload`, a route that no longer exists, and no test
 * noticed.
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

    public function testUploadingBytesThenCreatingYieldsAUsableImage(): void
    {
        $uploaded = $this->upload();

        // The exact keys the composable forwards to /create. Named one by one
        // rather than compared as a whole: a missing key here is the failure
        // mode, and the message should say which.
        foreach (['filePath', 'fileName', 'originalName', 'mimeType', 'size', 'width', 'height'] as $key) {
            self::assertArrayHasKey($key, $uploaded, $key.' is missing from the upload response');
        }

        self::assertSame('image/png', $uploaded['mimeType']);
        self::assertSame(1, $uploaded['width']);
        self::assertSame(1, $uploaded['height']);

        $this->client->request(
            'POST',
            '/backend/ged/documents/create',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'title' => $uploaded['originalName'],
                'filePath' => $uploaded['filePath'],
                'fileName' => $uploaded['fileName'],
                'originalName' => $uploaded['originalName'],
                'mimeType' => $uploaded['mimeType'],
                'size' => $uploaded['size'],
                'width' => $uploaded['width'],
                'height' => $uploaded['height'],
                'thumbnailPath' => $uploaded['thumbnailPath'] ?? null,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success'], 'the create call refused a payload the upload call produced');

        // The two fields the picker sets on its model. Without them the image
        // is filed but the field it was picked for stays empty.
        self::assertNotNull($payload['document']['id']);
        self::assertNotEmpty($payload['document']['fileUrl']);
    }

    public function testUploadingNothingIsRefusedRatherThanStored(): void
    {
        $this->client->request('POST', '/backend/ged/documents/upload');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
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
            '/backend/ged/documents/upload',
            files: ['file' => new UploadedFile($path, 'pixel.png', 'image/png', null, true)],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success'], 'the upload endpoint refused a plain PNG');

        return $payload;
    }
}
