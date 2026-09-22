<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\StoredFileLocator;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
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

        // This used to assert only that `immutable` survived, and explained
        // in a comment that the session listener overrode the rest. It did,
        // and the consequence was never measured: in production a published
        // image came back `max-age=0, must-revalidate, private`, so it was
        // revalidated on every view and no shared cache could hold it. The
        // assertion is now on the header that is actually sent.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertStringNotContainsString('must-revalidate', $cacheControl);

        // Symfony strips its own opt-out header before sending. A visitor
        // seeing it would mean the listener never ran, which would make the
        // assertions above prove nothing.
        self::assertFalse(
            $this->client->getResponse()->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER),
            'the opt-out header is internal and must not reach the visitor',
        );
    }

    /**
     * The guard rail under the fix above.
     *
     * The listener rewrites `Cache-Control` on any request that merely
     * *reads* the session - `getUsageIndex() !== 0`, not `isStarted()` - and
     * on this application `LocaleSubscriber` reads it on every request to
     * decide the language. So the session really is used here; what the
     * opt-out says is that these bytes do not depend on it.
     *
     * If a future change stops the session being read at all, this test goes
     * green for a different reason and the one above stops proving anything.
     * Hence asserting the usage, not just the header.
     */
    public function testTheSessionIsIndeedReadOnThisRequest(): void
    {
        ob_start();
        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('uploads_serve', ['path' => $this->fixtureRelativePath]),
        );
        ob_end_clean();

        $session = $this->client->getRequest()->getSession();

        self::assertInstanceOf(Session::class, $session);
        self::assertFalse($session->isStarted(), 'nothing writes to the session here');
        self::assertGreaterThan(
            0,
            $session->getUsageIndex(),
            'something reads the session on every request, which is what downgrades the cache header',
        );
    }

    /**
     * An object streamed back from a remote backend says what it is.
     *
     * `StreamedResponse` carries no type of its own, so this branch answered
     * `text/html` for everything it served. Raster images survived it - a
     * browser sniffs an `<img>` out of a wrong type - and SVG never does,
     * being markup rather than bytes, so a pictogram stored in a bucket drew
     * nothing at all while the photograph beside it drew fine. The local
     * branch never had the bug, which is why it went unseen: it is
     * `BinaryFileResponse` that fills the type there, in `prepare()`.
     */
    public function testARemoteObjectIsServedUnderItsOwnType(): void
    {
        $path = 'tests-fixtures/pictogram.svg';
        $this->serveFromRemote($path, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $headers = $this->client->getResponse()->headers;

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('image/svg+xml', $headers->get('Content-Type'));

        // Declared and to be believed: the sniffing that rescued the images is
        // the same sniffing that turns a file stored under a harmless type
        // into a document.
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));

        // And the wall the local branch already puts up. A stored SVG opened
        // in a tab is script on this origin, running as whoever opened it.
        // Browsers ignore this on a subresource, so the pictogram in a page
        // still draws; what it stops is navigating to the address.
        self::assertSame('attachment', $headers->get('Content-Disposition'));
    }

    /** A photograph from the same backend keeps its own type, and no wall. */
    public function testARemotePhotographIsNotTurnedIntoADownload(): void
    {
        $this->serveFromRemote('tests-fixtures/photo.jpg', 'not really a jpeg');

        $headers = $this->client->getResponse()->headers;

        self::assertSame('image/jpeg', $headers->get('Content-Type'));
        self::assertFalse($headers->has('Content-Disposition'));
    }

    /**
     * Serve `$path` as if it lived on a backend that is not a filesystem.
     *
     * The locator asks the local disk first and only then the others, so a
     * fake that holds the key is enough to reach the streaming branch. It is
     * deliberately not `LocalPathAware`: that interface is what the controller
     * branches on, and a fake that implemented it would take the other path
     * and prove nothing.
     */
    private function serveFromRemote(string $path, string $contents): void
    {
        $container = static::getContainer();
        $storageManager = $container->get(StorageManager::class);
        $activeDiskProvider = $container->get(ActiveStorageDiskProviderInterface::class);

        $remote = new RemoteOnlyAdapter($contents);

        $container->set(StoredFileLocator::class, new StoredFileLocator(
            new StorageManager(
                [$storageManager->forDisk(StorageDiskEnum::Local), $remote],
                $activeDiskProvider,
            ),
        ));

        ob_start();
        $this->client->request(
            HttpMethodEnum::Get->value,
            $this->urlGenerator->generate('uploads_serve', ['path' => $path]),
        );
        ob_end_clean();
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
