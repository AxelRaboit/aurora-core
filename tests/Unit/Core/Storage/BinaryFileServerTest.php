<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage;

use Aurora\Core\Storage\BinaryFileServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

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
}
