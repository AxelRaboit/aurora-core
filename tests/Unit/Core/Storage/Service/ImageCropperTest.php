<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Service;

use Aurora\Core\Storage\Service\ImageCropper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ImageCropperTest extends TestCase
{
    private string $workDir;
    private ImageCropper $cropper;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/aurora-cropper-'.uniqid();
        mkdir($this->workDir, 0o777, true);
        $this->cropper = new ImageCropper(new Filesystem());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    public function testCropReturnsRequestedDimensions(): void
    {
        $source = $this->writePng('source.png', 20, 20);
        $destination = $this->workDir.'/cropped.png';

        $dimensions = $this->cropper->crop($source, $destination, 'image/png', 2, 3, 8, 6);

        self::assertSame([8, 6], $dimensions);
        self::assertFileExists($destination);
        [$width, $height] = getimagesize($destination);
        self::assertSame(8, $width);
        self::assertSame(6, $height);
    }

    public function testCropInPlaceOverwritesSource(): void
    {
        $source = $this->writePng('inplace.png', 30, 30);

        $dimensions = $this->cropper->crop($source, $source, 'image/png', 0, 0, 10, 10);

        self::assertSame([10, 10], $dimensions);
        [$width, $height] = getimagesize($source);
        self::assertSame(10, $width);
        self::assertSame(10, $height);
    }

    public function testCropClampsRectangleToImageBounds(): void
    {
        $source = $this->writePng('clamp.png', 16, 16);
        $destination = $this->workDir.'/clamped.png';

        // Requested width/height exceed the image - must clamp to what's left.
        $dimensions = $this->cropper->crop($source, $destination, 'image/png', 10, 10, 100, 100);

        self::assertSame([6, 6], $dimensions);
    }

    public function testCropHandlesJpeg(): void
    {
        $source = $this->workDir.'/photo.jpg';
        $image = imagecreatetruecolor(40, 40);
        imagejpeg($image, $source, 85);
        imagedestroy($image);
        $destination = $this->workDir.'/photo-cropped.jpg';

        $dimensions = $this->cropper->crop($source, $destination, 'image/jpeg', 5, 5, 20, 20);

        self::assertSame([20, 20], $dimensions);
    }

    public function testCropReturnsNullForNonRasterMime(): void
    {
        $source = $this->workDir.'/doc.pdf';
        file_put_contents($source, '%PDF-1.4');

        self::assertNull($this->cropper->crop($source, $this->workDir.'/out.pdf', 'application/pdf', 0, 0, 10, 10));
    }

    public function testCropReturnsNullForMissingSource(): void
    {
        self::assertNull($this->cropper->crop($this->workDir.'/missing.png', $this->workDir.'/out.png', 'image/png', 0, 0, 10, 10));
    }

    private function writePng(string $name, int $width, int $height): string
    {
        $path = $this->workDir.'/'.$name;
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
