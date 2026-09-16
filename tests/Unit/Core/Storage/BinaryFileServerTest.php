<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage;

use Aurora\Core\Storage\BinaryFileServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

use const DIRECTORY_SEPARATOR;

final class BinaryFileServerTest extends TestCase
{
    private string $rootDir;
    private string $intruderDir;
    private BinaryFileServer $server;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $base = sys_get_temp_dir().'/aurora-binary-server-'.bin2hex(random_bytes(4));
        $this->rootDir = $base.'/uploads';
        $this->intruderDir = $base.'/intruder';
        $this->filesystem->mkdir([$this->rootDir, $this->intruderDir]);

        file_put_contents($this->rootDir.'/sample.txt', 'hello');
        file_put_contents($this->rootDir.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        // A one-pixel GIF: small, and unambiguously an image to the guesser.
        file_put_contents($this->rootDir.'/photo.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true));
        file_put_contents($this->intruderDir.'/secret.txt', 'forbidden');

        $this->server = new BinaryFileServer();
    }

    protected function tearDown(): void
    {
        $base = dirname($this->rootDir);
        if (is_dir($base)) {
            $this->filesystem->remove($base);
        }
    }

    public function testServeReturnsBinaryResponseForFileInsideRoot(): void
    {
        $response = $this->server->serve($this->rootDir.'/sample.txt', $this->rootDir);

        self::assertSame(200, $response->getStatusCode());
        // Symfony normalises Cache-Control directive order alphabetically.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('max-age=3600', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testServePublicUsesPublicCacheControl(): void
    {
        $response = $this->server->servePublic($this->rootDir.'/sample.txt', $this->rootDir);

        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Without this header the three lines above are decoration: Symfony's
     * session listener rewrites `Cache-Control` at the end of every request
     * that reads the session, and declaring the response `public` is what
     * makes it choose `max-age=0` rather than keeping the day we asked for.
     */
    public function testServePublicOptsOutOfTheSessionCacheDowngrade(): void
    {
        $response = $this->server->servePublic($this->rootDir.'/sample.txt', $this->rootDir);

        self::assertTrue($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
    }

    /**
     * And the gated flavour must not opt out. Its callers serve files whose
     * reader was checked, so the listener landing on `private` is the answer
     * we want anyway.
     */
    public function testServeDoesNotOptOut(): void
    {
        $response = $this->server->serve($this->rootDir.'/sample.txt', $this->rootDir);

        self::assertFalse($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function testServeRefusesFileOutsideAllowedRoot(): void
    {
        $this->expectException(RuntimeException::class);
        // Try to read the intruder file via a traversal-style path.
        $traversal = $this->rootDir.'/../intruder/secret.txt';
        $this->server->serve($traversal, $this->rootDir);
    }

    public function testServeRefusesPrefixSiblingDirectory(): void
    {
        // Sibling root that *prefix-matches* the allowed one - must be rejected
        // (the normalised root comparison uses a trailing separator).
        $sibling = $this->rootDir.'-twin';
        $this->filesystem->mkdir($sibling);
        file_put_contents($sibling.'/x.txt', 'nope');

        $this->expectException(RuntimeException::class);
        $this->server->serve($sibling.'/x.txt', $this->rootDir);
    }

    public function testServeRefusesMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->server->serve($this->rootDir.'/does-not-exist.txt', $this->rootDir);
    }

    public function testServeAttachesContentDispositionWhenDownloadNameProvided(): void
    {
        $response = $this->server->serve(
            $this->rootDir.'/sample.txt',
            $this->rootDir,
            'private, max-age=60',
            'pretty-name.txt',
        );

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('pretty-name.txt', $disposition);
    }

    public function testPathJoinsRootAndRelative(): void
    {
        self::assertSame(
            $this->rootDir.DIRECTORY_SEPARATOR.'foo/bar.png',
            $this->server->path($this->rootDir, 'foo/bar.png'),
        );
    }

    /**
     * Every response says not to sniff.
     *
     * The forced download below is a list, and a list is a guess about which
     * types matter. This is the rule underneath it: a file stored with a
     * harmless type but HTML-shaped content cannot be promoted to a document
     * by a browser being helpful.
     */
    public function testEveryResponseRefusesContentSniffing(): void
    {
        $response = $this->server->serve($this->rootDir.'/sample.txt', $this->rootDir);

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * An SVG is a document that can carry script, and it comes back from the
     * application's own origin. Opened in a tab it would run with the reader's
     * session, so it is handed over as a download instead.
     *
     * The assertion is on the header the caller never asked for: nothing here
     * passed a download name, so this is the type refusing to be shown.
     */
    public function testAnExecutableTypeIsHandedOverAsADownload(): void
    {
        $response = $this->server->serve($this->rootDir.'/logo.svg', $this->rootDir);

        self::assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * And a picture is still a picture.
     *
     * The guard has to be narrow: forcing every file to download would break
     * every image on every public page, which is most of what this serves.
     */
    public function testAPictureIsStillShownInline(): void
    {
        $response = $this->server->serve($this->rootDir.'/photo.gif', $this->rootDir);

        self::assertNull($response->headers->get('Content-Disposition'));
    }
}
