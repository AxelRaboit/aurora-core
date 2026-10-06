<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Service;

use Aurora\Core\Storage\Service\ImageRecolorer;
use GdImage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function abs;
use function imagecolorallocate;
use function imagecolorallocatealpha;
use function imagecolorat;
use function imagecreatefrompng;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagepng;
use function imagesetpixel;
use function max;
use function min;
use function mkdir;
use function mt_rand;
use function mt_srand;
use function sys_get_temp_dir;
use function uniqid;

/**
 * A green visual declined in red: the background moves, its lightness stays,
 * and what is laid on it - a photo, a colour to spare - does not change.
 */
final class ImageRecolorerTest extends TestCase
{
    private const int SIZE = 240;

    private string $workDirectory;

    private ImageRecolorer $recolorer;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir().'/aurora-recolorer-'.uniqid();
        mkdir($this->workDirectory, 0o777, true);
        $this->recolorer = new ImageRecolorer(new Filesystem());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDirectory);
    }

    public function testTheVisualsHueMovesAndItsLightnessStays(): void
    {
        $source = $this->visual();
        $result = $this->workDirectory.'/red.png';

        self::assertSame([self::SIZE, self::SIZE], $this->recolorer->recolor($source, $result, 'image/png', '#bd4a55'));

        [$red, $green, $blue] = $this->pixel($result, 10, 10);
        self::assertGreaterThan($green, $red, 'the green background turned red');
        self::assertEqualsWithDelta($this->lightness(...$this->pixel($source, 10, 10)), $this->lightness($red, $green, $blue), 0.02, 'with the same lightness');
    }

    public function testAPhotoLaidOnTheVisualKeepsItsColours(): void
    {
        $source = $this->visual(withPhoto: true);
        $result = $this->workDirectory.'/red.png';

        $this->recolorer->recolor($source, $result, 'image/png', '#bd4a55');

        // The middle of the photo, including its green-blue pixels.
        self::assertSame($this->pixel($source, 120, 120), $this->pixel($result, 120, 120));
        self::assertGreaterThan(0, $this->pixel($result, 10, 10)[0] - $this->pixel($result, 10, 10)[1], 'the background still moved');
    }

    public function testUnprotectedThePhotoMovesToo(): void
    {
        $source = $this->visual(withPhoto: true);
        $result = $this->workDirectory.'/red.png';

        $this->recolorer->recolor($source, $result, 'image/png', '#bd4a55', protectDetail: false);

        self::assertNotSame($this->photoPixels($source), $this->photoPixels($result));
    }

    public function testASparedColourIsLeftAlone(): void
    {
        $source = $this->visual();
        $result = $this->workDirectory.'/red.png';

        $this->recolorer->recolor($source, $result, 'image/png', '#bd4a55', spare: ['#10b981']);

        self::assertSame([16, 185, 129], $this->pixel($result, 200, 30), 'the spared mark kept its green');
    }

    public function testAGivenSourceColourIsTheOneReplaced(): void
    {
        $source = $this->visual();
        $result = $this->workDirectory.'/blue.png';

        // Asked to replace red, of which there is none: nothing moves.
        $this->recolorer->recolor($source, $result, 'image/png', '#0093ed', sourceHex: '#ff0000');

        self::assertSame($this->pixel($source, 10, 10), $this->pixel($result, 10, 10));
    }

    public function testAnImageWithNoColourHasNothingToReplace(): void
    {
        $grey = imagecreatetruecolor(20, 20);
        imagefilledrectangle($grey, 0, 0, 19, 19, imagecolorallocate($grey, 120, 120, 120));
        $source = $this->save($grey, 'grey.png');

        self::assertNull($this->recolorer->recolor($source, $this->workDirectory.'/out.png', 'image/png', '#bd4a55'));
    }

    public function testAnAnimationOrANonImageIsRefused(): void
    {
        $source = $this->visual();

        self::assertNull($this->recolorer->recolor($source, $this->workDirectory.'/out.gif', 'image/gif', '#bd4a55'));
        self::assertNull($this->recolorer->recolor($source, $this->workDirectory.'/out.pdf', 'application/pdf', '#bd4a55'));
    }

    public function testTransparencySurvives(): void
    {
        $image = imagecreatetruecolor(40, 40);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 39, 39, imagecolorallocatealpha($image, 6, 90, 68, 0));
        imagefilledrectangle($image, 0, 0, 9, 9, imagecolorallocatealpha($image, 6, 90, 68, 127));
        $source = $this->save($image, 'alpha.png');
        $result = $this->workDirectory.'/alpha-red.png';

        $this->recolorer->recolor($source, $result, 'image/png', '#bd4a55');

        $out = imagecreatefrompng($result);
        self::assertSame(127, (imagecolorat($out, 2, 2) >> 24) & 0x7F, 'the transparent corner stays transparent');
        self::assertSame(0, (imagecolorat($out, 30, 30) >> 24) & 0x7F);
    }

    /**
     * A dark green gradient, the house's kind of background, with a bright
     * green mark in a corner and, if asked, a noisy "photo" in the middle
     * whose colours include greens and blues.
     */
    private function visual(bool $withPhoto = false): string
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        for ($y = 0; $y < self::SIZE; ++$y) {
            for ($x = 0; $x < self::SIZE; ++$x) {
                $shade = 1 - abs($x - 120) / 240 - abs($y - 120) / 240;
                imagesetpixel($image, $x, $y, imagecolorallocate($image, (int) (4 * $shade), (int) (80 * $shade), (int) (60 * $shade)));
            }
        }

        imagefilledrectangle($image, 190, 20, 210, 40, imagecolorallocate($image, 16, 185, 129));

        if ($withPhoto) {
            mt_srand(7);
            for ($y = 70; $y < 170; ++$y) {
                for ($x = 70; $x < 170; ++$x) {
                    imagesetpixel($image, $x, $y, imagecolorallocate($image, mt_rand(0, 90), mt_rand(60, 200), mt_rand(90, 230)));
                }
            }
        }

        return $this->save($image, 'visual-'.uniqid().'.png');
    }

    private function save(GdImage $image, string $name): string
    {
        $path = $this->workDirectory.'/'.$name;
        imagepng($image, $path);

        return $path;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function pixel(string $path, int $x, int $y): array
    {
        $colour = imagecolorat(imagecreatefrompng($path), $x, $y);

        return [($colour >> 16) & 255, ($colour >> 8) & 255, $colour & 255];
    }

    /** @return list<int> */
    private function photoPixels(string $path): array
    {
        $image = imagecreatefrompng($path);
        $pixels = [];
        for ($position = 80; $position < 160; $position += 7) {
            $pixels[] = imagecolorat($image, $position, $position);
        }

        return $pixels;
    }

    private function lightness(int $red, int $green, int $blue): float
    {
        return (max($red, $green, $blue) + min($red, $green, $blue)) / 510;
    }
}
