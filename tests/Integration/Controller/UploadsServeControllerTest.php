<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Smoke tests for the `/uploads/{path}` catch-all serve route. Uses a
 * temp fixture inside `var/uploads/` so the test doesn't depend on
 * specific media data and cleans up after itself.
 */
final class UploadsServeControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private UrlGeneratorInterface $urlGenerator;
    private string $uploadDir;
    private string $fixtureRelativePath = 'tests-fixtures/sample.txt';
    private string $fixtureAbsolutePath;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->urlGenerator = static::getContainer()->get(UrlGeneratorInterface::class);
        $this->uploadDir = static::getContainer()->getParameter('app.upload_dir');

        $this->filesystem = new Filesystem();
        $this->fixtureAbsolutePath = Path::join($this->uploadDir, $this->fixtureRelativePath);
        $this->filesystem->mkdir(dirname($this->fixtureAbsolutePath));
        file_put_contents($this->fixtureAbsolutePath, 'hello-from-uploads');
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixtureAbsolutePath)) {
            $this->filesystem->remove(dirname($this->fixtureAbsolutePath));
        }

        parent::tearDown();
    }

    public function testServesExistingFile(): void
    {
        ob_start();
        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('uploads_serve', ['path' => $this->fixtureRelativePath]),
        );
        ob_end_clean();

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Cache-Control directives are normalised + potentially overridden by
        // Symfony's session listener at request end - we only assert the
        // `immutable` hint we added explicitly survives. The full
        // `public, max-age=…` story is validated at the unit level via
        // `BinaryFileServerTest::testServePublicUsesPublicCacheControl`.
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testReturns404OnMissingPath(): void
    {
        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('uploads_serve', ['path' => 'does/not/exist.txt']),
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testRefusesTraversalAttempt(): void
    {
        // Direct request bypasses the URL generator (which would refuse to
        // build a path containing `..`); we simulate the raw URL a hostile
        // client might send.
        $this->client->request(HttpMethodEnum::Get->value, '/uploads/foo/../../etc/passwd');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }
}
