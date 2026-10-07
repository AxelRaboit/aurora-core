<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Service;

use Aurora\Core\Storage\Service\VideoPosterGenerator;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Aurora\Tests\Unit\Core\Storage\InMemoryStorageAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;

final class VideoPosterGeneratorTest extends TestCase
{
    private string $workDirectory;
    private VideoPosterGenerator $generator;
    private InMemoryStorageAdapter $adapter;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/aurora-poster-'.uniqid();
        mkdir($this->workDirectory, 0o777, true);
        $this->generator = new VideoPosterGenerator(new LocalWorkspace(new Filesystem()));
        $this->adapter = new InMemoryStorageAdapter();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->workDirectory);
    }

    public function testACapturedFrameIsStoredUnderTheThumbnailPrefix(): void
    {
        $key = $this->generator->fromCapture(
            $this->adapter,
            $this->upload('poster.png', 'image/png'),
            'ged/thumbnails/2026/09',
            'reel-abc123',
        );

        self::assertSame('ged/thumbnails/2026/09/reel-abc123.png', $key);
        self::assertArrayHasKey('ged/thumbnails/2026/09/reel-abc123.png', $this->adapter->objects);
    }

    /**
     * The extension follows the bytes, not the wish.
     *
     * `canvas.toBlob` quietly falls back to PNG when a browser cannot encode
     * WebP, so a poster asked for as WebP can arrive as something else. Naming
     * the stored file after the type it actually is keeps it servable.
     */
    public function testTheStoredExtensionFollowsTheCapturedType(): void
    {
        $key = $this->generator->fromCapture(
            $this->adapter,
            $this->upload('poster.webp', 'image/webp'),
            'ged/thumbnails/2026/09',
            'reel-abc123',
        );

        self::assertSame('ged/thumbnails/2026/09/reel-abc123.webp', $key);
    }

    /**
     * An upload field is whatever was posted to it, and this one is written to
     * a path the site then serves. A film, a script or a PDF renamed `.png`
     * has no business becoming a document's poster.
     */
    public function testSomethingThatIsNotARasterImageIsRefused(): void
    {
        $path = $this->workDirectory.'/not-an-image.png';
        file_put_contents($path, 'MZ definitely not a picture');

        $key = $this->generator->fromCapture(
            $this->adapter,
            new UploadedFile($path, 'poster.png', 'application/pdf', null, true),
            'ged/thumbnails/2026/09',
            'reel-abc123',
        );

        self::assertNull($key);
        self::assertSame([], $this->adapter->writes);
    }

    /**
     * A file whose mime type says image but whose bytes do not decode: the
     * type is claimed by the client, so it is checked against the content.
     */
    public function testAnImageTypeOverBytesThatDoNotDecodeIsRefused(): void
    {
        $path = $this->workDirectory.'/corrupt.png';
        file_put_contents($path, 'not really a png');

        $key = $this->generator->fromCapture(
            $this->adapter,
            new UploadedFile($path, 'poster.png', 'image/png', null, true),
            'ged/thumbnails/2026/09',
            'reel-abc123',
        );

        self::assertNull($key);
        self::assertSame([], $this->adapter->writes);
    }

    /**
     * The point of the whole design: no ffmpeg is not a failure, it is simply
     * a document without a poster. Nothing throws, nothing half-written is
     * left behind, and the PDFs in the same batch still get processed.
     */
    public function testWithoutFfmpegTheSourcePathYieldsNothingAndWritesNothing(): void
    {
        $this->adapter->objects['ged/2026/09/reel.mp4'] = 'pretend this is a film';

        $generator = new VideoPosterGenerator(
            new LocalWorkspace(new Filesystem()),
            new Filesystem(),
            new class extends ExecutableFinder {
                public function find(string $name, ?string $default = null, array $extraDirectories = []): ?string
                {
                    return null;
                }
            },
        );

        $key = $generator->fromSource($this->adapter, 'ged/2026/09/reel.mp4', 'ged/thumbnails/2026/09', 'reel');

        self::assertNull($key);
        self::assertSame([], $this->adapter->writes);
    }

    /**
     * The batch command asks this once instead of reporting every film in the
     * library as a failure.
     */
    public function testExtractionIsReportedAsUnavailableWithoutFfmpeg(): void
    {
        $generator = new VideoPosterGenerator(
            new LocalWorkspace(new Filesystem()),
            new Filesystem(),
            new class extends ExecutableFinder {
                public function find(string $name, ?string $default = null, array $extraDirectories = []): ?string
                {
                    return null;
                }
            },
        );

        self::assertFalse($generator->canExtract());
    }

    public function testAMissingSourceYieldsNothing(): void
    {
        $key = $this->generator->fromSource($this->adapter, 'ged/2026/09/gone.mp4', 'ged/thumbnails/2026/09', 'gone');

        self::assertNull($key);
    }

    /**
     * A real picture in the format the name claims.
     *
     * The bytes have to match, because the generator asks the file what it is
     * rather than believing the client - which is the behaviour
     * {@see testTheStoredExtensionFollowsTheCapturedType} is there to pin
     * down.
     */
    private function upload(string $name, string $mimeType): UploadedFile
    {
        $path = $this->workDirectory.'/'.$name;
        $image = imagecreatetruecolor(16, 9);

        if ('image/webp' === $mimeType) {
            imagewebp($image, $path);
        } else {
            imagepng($image, $path);
        }

        imagedestroy($image);

        return new UploadedFile($path, $name, $mimeType, null, true);
    }
}
