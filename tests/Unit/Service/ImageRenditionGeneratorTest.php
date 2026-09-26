<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Service;

use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Service\ImageRenditionGenerator;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ImageRenditionGeneratorTest extends TestCase
{
    private string $sandbox;
    private LocalStorageAdapter $adapter;
    private Filesystem $filesystem;
    private ImageRenditionGenerator $generator;

    protected function setUp(): void
    {
        $this->sandbox = Path::join(sys_get_temp_dir(), 'aurora-rendition-'.uniqid());
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->sandbox);
        $this->adapter = new LocalStorageAdapter($this->filesystem, $this->sandbox);
        $this->generator = new ImageRenditionGenerator(new LocalWorkspace($this->filesystem));
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->sandbox)) {
            $this->filesystem->remove($this->sandbox);
        }
    }

    public function testGeneratesAllRenditionsForLargeImage(): void
    {
        $relative = $this->createPngFixture('big.png', 2400, 1600);

        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');

        // Past 1920 the full resolution is kept as `xlarge`, never upscaled.
        self::assertSame(['thumbnail', 'medium', 'large', 'xlarge'], array_keys($renditions));
        foreach ($renditions as $name => $renditionPath) {
            self::assertFileExists(Path::join($this->sandbox, $renditionPath), "rendition {$name} not written");
            self::assertStringContainsString('variants/'.$name.'/', $renditionPath);
        }
    }

    public function testAVeryWideSourceAlsoGetsAnExtraLargeRendition(): void
    {
        // What a full-width banner draws on a high-density screen: more
        // pixels than `large` holds, so a rendition of its own - and only
        // because the source has them.
        $relative = $this->createPngFixture('wide.png', 4000, 1400);

        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertSame(['thumbnail', 'medium', 'large', 'xlarge'], array_keys($renditions));
        [$width] = getimagesize(Path::join($this->sandbox, $renditions['xlarge']));
        self::assertSame(3840, $width);
    }

    public function testASourceExactlyThatWideStillGetsItsExtraLargeRendition(): void
    {
        // A banner rendered at twice 1920 is 3840 wide: it fits inside the
        // rendition, and still needs it, since `large` would halve it.
        $relative = $this->createPngFixture('retina.png', 3840, 1364);

        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertArrayHasKey('xlarge', $renditions);
        self::assertArrayNotHasKey('xlarge', $this->generator->generate(
            $this->adapter,
            $this->createPngFixture('plain.png', 1920, 682),
            'image/png',
        ));
    }

    public function testStillGeneratesLargeRenditionWhenSourceIsSmaller(): void
    {
        // Even when the source is smaller than every preset, the largest
        // rendition is always generated - that's the EXIF-strip safety net so
        // the public download path never falls back to the raw original.
        $relative = $this->createPngFixture('small.png', 100, 100);

        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertSame(['large'], array_keys($renditions));
        self::assertFileExists(Path::join($this->sandbox, $renditions['large']));
    }

    public function testGeneratesShrinkingRenditionsAndAlwaysKeepsLarge(): void
    {
        $relative = $this->createPngFixture('medium.png', 500, 500);

        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertSame(['thumbnail', 'large'], array_keys($renditions));
        self::assertFileExists(Path::join($this->sandbox, $renditions['thumbnail']));
        self::assertFileExists(Path::join($this->sandbox, $renditions['large']));
    }

    public function testReturnsEmptyForUnsupportedMimeType(): void
    {
        $relative = $this->createPngFixture('any.png', 2000, 2000);

        $renditions = $this->generator->generate($this->adapter, $relative, 'application/pdf');

        self::assertSame([], $renditions);
    }

    public function testReturnsEmptyWhenSourceFileMissing(): void
    {
        $renditions = $this->generator->generate($this->adapter, 'does-not-exist.png', 'image/png');

        self::assertSame([], $renditions);
    }

    public function testRenditionsAreReencodedAsWebpForRasterImages(): void
    {
        $relative = $this->createPngFixture('huge.png', 3000, 3000);

        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');

        foreach ($renditions as $renditionPath) {
            self::assertStringEndsWith('.webp', $renditionPath);
        }
    }

    public function testDeleteRenditionsRemovesFiles(): void
    {
        $relative = $this->createPngFixture('cleanup.png', 2000, 2000);
        $renditions = $this->generator->generate($this->adapter, $relative, 'image/png');
        self::assertNotEmpty($renditions);

        $this->generator->deleteRenditions($this->adapter, $renditions);

        foreach ($renditions as $renditionPath) {
            self::assertFileDoesNotExist(Path::join($this->sandbox, $renditionPath));
        }
    }

    public function testDeleteRenditionsIgnoresMissingFiles(): void
    {
        $this->generator->deleteRenditions($this->adapter, ['thumbnail' => 'variants/thumbnail/nope.webp']);

        $this->expectNotToPerformAssertions();
    }

    private function createPngFixture(string $name, int $width, int $height): string
    {
        $absolute = Path::join($this->sandbox, $name);
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 100, 150, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagepng($image, $absolute);
        imagedestroy($image);

        return $name;
    }
}
