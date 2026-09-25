<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Service;

use Aurora\Core\Storage\Adapter\LocalStorageAdapter;
use Aurora\Core\Storage\Service\ImageVariantGenerator;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ImageVariantGeneratorTest extends TestCase
{
    private string $sandbox;
    private LocalStorageAdapter $adapter;
    private Filesystem $filesystem;
    private ImageVariantGenerator $generator;

    protected function setUp(): void
    {
        $this->sandbox = Path::join(sys_get_temp_dir(), 'aurora-variant-'.uniqid());
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->sandbox);
        $this->adapter = new LocalStorageAdapter($this->filesystem, $this->sandbox);
        $this->generator = new ImageVariantGenerator(new LocalWorkspace($this->filesystem));
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->exists($this->sandbox)) {
            $this->filesystem->remove($this->sandbox);
        }
    }

    public function testGeneratesAllVariantsForLargeImage(): void
    {
        $relative = $this->createPngFixture('big.png', 2400, 1600);

        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');

        // Past 1920 the full resolution is kept as `xlarge`, never upscaled.
        self::assertSame(['thumbnail', 'medium', 'large', 'xlarge'], array_keys($variants));
        foreach ($variants as $name => $variantPath) {
            self::assertFileExists(Path::join($this->sandbox, $variantPath), "variant {$name} not written");
            self::assertStringContainsString('variants/'.$name.'/', $variantPath);
        }
    }

    public function testAVeryWideSourceAlsoGetsAnExtraLargeVariant(): void
    {
        // What a full-width banner draws on a high-density screen: more
        // pixels than `large` holds, so a variant of its own - and only
        // because the source has them.
        $relative = $this->createPngFixture('wide.png', 4000, 1400);

        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertSame(['thumbnail', 'medium', 'large', 'xlarge'], array_keys($variants));
        [$width] = getimagesize(Path::join($this->sandbox, $variants['xlarge']));
        self::assertSame(3840, $width);
    }

    public function testASourceExactlyThatWideStillGetsItsExtraLargeVariant(): void
    {
        // A banner rendered at twice 1920 is 3840 wide: it fits inside the
        // variant, and still needs it, since `large` would halve it.
        $relative = $this->createPngFixture('retina.png', 3840, 1364);

        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertArrayHasKey('xlarge', $variants);
        self::assertArrayNotHasKey('xlarge', $this->generator->generate(
            $this->adapter,
            $this->createPngFixture('plain.png', 1920, 682),
            'image/png',
        ));
    }

    public function testStillGeneratesLargeVariantWhenSourceIsSmaller(): void
    {
        // Even when the source is smaller than every preset, the largest
        // variant is always generated - that's the EXIF-strip safety net so
        // the public download path never falls back to the raw original.
        $relative = $this->createPngFixture('small.png', 100, 100);

        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertSame(['large'], array_keys($variants));
        self::assertFileExists(Path::join($this->sandbox, $variants['large']));
    }

    public function testGeneratesShrinkingVariantsAndAlwaysKeepsLarge(): void
    {
        $relative = $this->createPngFixture('medium.png', 500, 500);

        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');

        self::assertSame(['thumbnail', 'large'], array_keys($variants));
        self::assertFileExists(Path::join($this->sandbox, $variants['thumbnail']));
        self::assertFileExists(Path::join($this->sandbox, $variants['large']));
    }

    public function testReturnsEmptyForUnsupportedMimeType(): void
    {
        $relative = $this->createPngFixture('any.png', 2000, 2000);

        $variants = $this->generator->generate($this->adapter, $relative, 'application/pdf');

        self::assertSame([], $variants);
    }

    public function testReturnsEmptyWhenSourceFileMissing(): void
    {
        $variants = $this->generator->generate($this->adapter, 'does-not-exist.png', 'image/png');

        self::assertSame([], $variants);
    }

    public function testVariantsAreReencodedAsWebpForRasterImages(): void
    {
        $relative = $this->createPngFixture('huge.png', 3000, 3000);

        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');

        foreach ($variants as $variantPath) {
            self::assertStringEndsWith('.webp', $variantPath);
        }
    }

    public function testDeleteVariantsRemovesFiles(): void
    {
        $relative = $this->createPngFixture('cleanup.png', 2000, 2000);
        $variants = $this->generator->generate($this->adapter, $relative, 'image/png');
        self::assertNotEmpty($variants);

        $this->generator->deleteVariants($this->adapter, $variants);

        foreach ($variants as $variantPath) {
            self::assertFileDoesNotExist(Path::join($this->sandbox, $variantPath));
        }
    }

    public function testDeleteVariantsIgnoresMissingFiles(): void
    {
        $this->generator->deleteVariants($this->adapter, ['thumbnail' => 'variants/thumbnail/nope.webp']);

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
