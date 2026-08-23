<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Service;

use Aurora\Core\Storage\Service\ImageVariantGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class ImageVariantGeneratorTest extends TestCase
{
    private string $sandbox;
    private Filesystem $filesystem;
    private ImageVariantGenerator $generator;

    protected function setUp(): void
    {
        $this->sandbox = Path::join(sys_get_temp_dir(), 'aurora-variant-'.uniqid());
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->sandbox);
        $this->generator = new ImageVariantGenerator($this->filesystem, $this->sandbox);
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

        $variants = $this->generator->generate($relative, 'image/png');

        self::assertSame(['thumbnail', 'medium', 'large'], array_keys($variants));
        foreach ($variants as $name => $variantPath) {
            self::assertFileExists(Path::join($this->sandbox, $variantPath), "variant {$name} not written");
            self::assertStringContainsString('variants/'.$name.'/', $variantPath);
        }
    }

    public function testStillGeneratesLargeVariantWhenSourceIsSmaller(): void
    {
        // Even when the source is smaller than every preset, the largest
        // variant is always generated - that's the EXIF-strip safety net so
        // the public download path never falls back to the raw original.
        $relative = $this->createPngFixture('small.png', 100, 100);

        $variants = $this->generator->generate($relative, 'image/png');

        self::assertSame(['large'], array_keys($variants));
        self::assertFileExists(Path::join($this->sandbox, $variants['large']));
    }

    public function testGeneratesShrinkingVariantsAndAlwaysKeepsLarge(): void
    {
        $relative = $this->createPngFixture('medium.png', 500, 500);

        $variants = $this->generator->generate($relative, 'image/png');

        self::assertSame(['thumbnail', 'large'], array_keys($variants));
        self::assertFileExists(Path::join($this->sandbox, $variants['thumbnail']));
        self::assertFileExists(Path::join($this->sandbox, $variants['large']));
    }

    public function testReturnsEmptyForUnsupportedMimeType(): void
    {
        $relative = $this->createPngFixture('any.png', 2000, 2000);

        $variants = $this->generator->generate($relative, 'application/pdf');

        self::assertSame([], $variants);
    }

    public function testReturnsEmptyWhenSourceFileMissing(): void
    {
        $variants = $this->generator->generate('does-not-exist.png', 'image/png');

        self::assertSame([], $variants);
    }

    public function testVariantsAreReencodedAsWebpForRasterImages(): void
    {
        $relative = $this->createPngFixture('huge.png', 3000, 3000);

        $variants = $this->generator->generate($relative, 'image/png');

        foreach ($variants as $variantPath) {
            self::assertStringEndsWith('.webp', $variantPath);
        }
    }

    public function testDeleteVariantsRemovesFiles(): void
    {
        $relative = $this->createPngFixture('cleanup.png', 2000, 2000);
        $variants = $this->generator->generate($relative, 'image/png');
        self::assertNotEmpty($variants);

        $this->generator->deleteVariants($variants);

        foreach ($variants as $variantPath) {
            self::assertFileDoesNotExist(Path::join($this->sandbox, $variantPath));
        }
    }

    public function testDeleteVariantsIgnoresMissingFiles(): void
    {
        $this->generator->deleteVariants(['thumbnail' => 'variants/thumbnail/nope.webp']);

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
