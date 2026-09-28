<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use GdImage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function abs;
use function array_pop;
use function count;
use function fmod;
use function hexdec;
use function imagecolorat;
use function imagesetpixel;
use function intdiv;
use function max;
use function mb_substr;
use function min;
use function round;
use function sqrt;

/**
 * Declines a raster image in another colour: every pixel of one hue takes
 * another, and keeps its lightness, so the relief, the gradients and the
 * glows of the original survive the change.
 *
 * The same recipe the house's scripts used to make the yellow, red and blue
 * copies of the green visuals, brought into the product so a colour
 * alternate is one click in the library rather than a detour through a
 * terminal:
 *
 * - the hue to replace is given, or read off the image as its dominant
 *   saturated hue - the green of a green visual;
 * - a pixel moves by how close its hue is to that one: fully within 20°,
 *   fading out over the next 25°, never by a threshold, which on a smooth
 *   gradient draws a visible edge;
 * - saturation is scaled by the ratio of the two colours' saturations, and
 *   lightness is left alone;
 * - colours to spare (the green of a viewfinder mark, a logo) are protected
 *   by distance, with the same soft edge.
 *
 * What it cannot tell apart is a photograph's own greens from the visual's:
 * a leaf in an inlaid photo turns red with the background. Sparing the
 * photo's colours, or recolouring a source without the photo, is the answer.
 *
 * GIF is refused: GD reads its first frame only, and an animation would come
 * back still.
 */
final readonly class ImageRecolorer
{
    /** Degrees either side of the source hue that move fully. */
    private const float CORE_DEGREES = 16.0;

    /** Degrees over which the move then fades to nothing. */
    private const float FADE_DEGREES = 18.0;

    /** Luminance spread, in [0, 1], above which a block reads as detail. */
    private const float DETAIL_SPREAD = 0.04;

    /** RGB distance under which a spared colour is left exactly as it was. */
    private const float SPARE_CORE = 24.0;

    /** RGB distance over which that protection then fades out. */
    private const float SPARE_FADE = 32.0;

    /** Pixels read to find the dominant hue: enough, and fast on any size. */
    private const int HUE_SAMPLES = 40000;

    public function __construct(
        private Filesystem $filesystem,
    ) {}

    /**
     * Recolours the source image into the destination path.
     *
     * @param string       $targetHex     the colour to go to, `#rrggbb`
     * @param string|null  $sourceHex     the colour to replace; null reads it off the image
     * @param list<string> $spare         colours to leave alone, `#rrggbb`
     * @param bool         $protectDetail leave the textured parts - an inlaid
     *                                    photo, a screenshot - as they are
     *
     * @return array{0: int, 1: int}|null the size written, or null when the
     *                                    image is not a still raster, cannot be
     *                                    read, or has no colour to replace
     */
    public function recolor(
        string $sourceAbsolutePath,
        string $destinationAbsolutePath,
        string $mimeType,
        string $targetHex,
        ?string $sourceHex = null,
        array $spare = [],
        bool $protectDetail = true,
    ): ?array {
        $mime = MimeTypeEnum::tryFrom($mimeType);
        if (!$mime?->isRasterImage() || $mime->supportsAnimation() || !is_file($sourceAbsolutePath)) {
            return null;
        }

        $image = $this->load($sourceAbsolutePath, $mime);
        if (!$image instanceof GdImage) {
            return null;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $from = null !== $sourceHex ? $this->hsl(...$this->rgb($sourceHex)) : $this->dominantHue($image);
        if (null === $from) {
            imagedestroy($image);

            return null;
        }

        [$targetHue, $targetSaturation] = $this->hsl(...$this->rgb($targetHex));
        $saturationScale = min(1.6, max(0.4, $targetSaturation / max(0.05, $from[1])));
        $spared = array_map($this->rgb(...), $spare);

        $width = imagesx($image);
        $height = imagesy($image);
        [$shield, $block] = $protectDetail ? $this->detailShield($image) : [[], 1];

        // Colour => the move at full strength, packed: the weight on 7 bits
        // above the new RGB. One integer per colour met, because a photo
        // brings hundreds of thousands of them.
        $moves = [];

        for ($y = 0; $y < $height; ++$y) {
            $row = $shield[intdiv($y, $block)] ?? [];
            for ($x = 0; $x < $width; ++$x) {
                $pixel = imagecolorat($image, $x, $y);
                $colour = $pixel & 0xFFFFFF;
                $move = $moves[$colour] ??= $this->move($colour, $from[0], $targetHue, $saturationScale, $spared);

                $weight = ($move >> 24) / 127 * (1 - ($row[intdiv($x, $block)] ?? 0.0));
                if ($weight <= 0.0) {
                    continue;
                }

                $r = ($colour >> 16) & 255;
                $g = ($colour >> 8) & 255;
                $b = $colour & 255;
                imagesetpixel($image, $x, $y, ($pixel & 0x7F000000)
                    | ((int) round($r + (((($move >> 16) & 255) - $r) * $weight)) << 16)
                    | ((int) round($g + (((($move >> 8) & 255) - $g) * $weight)) << 8)
                    | (int) round($b + ((($move & 255) - $b) * $weight)));
            }
        }

        $this->filesystem->mkdir(Path::getDirectory($destinationAbsolutePath));
        $this->save($image, $destinationAbsolutePath, $mime);
        imagedestroy($image);

        return [$width, $height];
    }

    /**
     * The dominant saturated hue of an image, and how saturated it is.
     *
     * A histogram of hues over a grid of samples, each weighted by its
     * saturation, so a large dark gradient and a small vivid glow of the same
     * green both count, and greys count for nothing. The peak's hue is then
     * averaged over its neighbours, on the circle.
     *
     * @return array{0: float, 1: float, 2: float}|null hue in degrees, saturation, lightness
     */
    public function dominantHue(GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) sqrt(($width * $height) / self::HUE_SAMPLES));

        $bins = array_fill(0, 36, 0.0);
        $samples = [];

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $pixel = imagecolorat($image, $x, $y);
                if ((($pixel >> 24) & 0x7F) > 100) {
                    continue;
                }

                [$h, $s, $l] = $this->hsl(($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255);
                if ($s < 0.15) {
                    continue;
                }

                if ($l < 0.04) {
                    continue;
                }

                if ($l > 0.92) {
                    continue;
                }

                // By saturation, not by vividness: the large dark gradient of
                // a background is what the visual is "in", more than a small
                // bright sky in an inlaid photo.
                $weight = $s;
                $bins[(int) ($h / 10) % 36] += $weight;
                $samples[] = [$h, $s, $weight];
            }
        }

        $peak = array_search(max($bins), $bins, true);
        if (!is_int($peak) || $bins[$peak] <= 0.0) {
            return null;
        }

        // Circular mean of the samples in the peak and its two neighbours.
        $sin = 0.0;
        $cos = 0.0;
        $saturation = 0.0;
        $total = 0.0;
        foreach ($samples as [$h, $s, $weight]) {
            $bin = (int) ($h / 10) % 36;
            if (min(abs($bin - $peak), 36 - abs($bin - $peak)) > 1) {
                continue;
            }

            $sin += sin(deg2rad($h)) * $weight;
            $cos += cos(deg2rad($h)) * $weight;
            $saturation += $s * $weight;
            $total += $weight;
        }

        $hue = fmod(rad2deg(atan2($sin, $cos)) + 360, 360);

        return [$hue, $saturation / $total, 0.5];
    }

    /** @param list<array{0: int, 1: int, 2: int}> $spared */
    private function move(int $colour, float $fromHue, float $toHue, float $saturationScale, array $spared): int
    {
        $r = ($colour >> 16) & 255;
        $g = ($colour >> 8) & 255;
        $b = $colour & 255;

        [$h, $s, $l] = $this->hsl($r, $g, $b);

        $distance = abs(fmod($h - $fromHue + 540, 360) - 180);
        $weight = min(1.0, max(0.0, (self::CORE_DEGREES + self::FADE_DEGREES - $distance) / self::FADE_DEGREES));

        foreach ($spared as [$sr, $sg, $sb]) {
            if (0.0 === $weight) {
                break;
            }

            $gap = sqrt(($r - $sr) ** 2 + ($g - $sg) ** 2 + ($b - $sb) ** 2);
            $weight *= min(1.0, max(0.0, ($gap - self::SPARE_CORE) / self::SPARE_FADE));
        }

        if ($weight <= 0.0) {
            return 0;
        }

        [$nr, $ng, $nb] = $this->rgbFromHsl($toHue, min(1.0, $s * $saturationScale), $l);

        return ((int) round($weight * 127) << 24) | ((int) round($nr) << 16) | ((int) round($ng) << 8) | (int) round($nb);
    }

    /**
     * How much each block of the image is to be left alone, in [0, 1].
     *
     * A visual's background is a smooth gradient; a photo or a screenshot
     * laid on it is full of detail. The luminance spread of each block tells
     * them apart, then a blur over the neighbouring blocks fills the flat
     * patches inside a photo (a clear sky) and softens the edge, so nothing
     * draws a seam around the protected part.
     *
     * @return array{0: array<int, array<int, float>>, 1: int} shield by block row and column, and the block size
     */
    private function detailShield(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $block = max(4, (int) round(min($width, $height) / 90));
        $rows = intdiv($height - 1, $block) + 1;
        $columns = intdiv($width - 1, $block) + 1;

        $detail = [];
        for ($by = 0; $by < $rows; ++$by) {
            for ($bx = 0; $bx < $columns; ++$bx) {
                $sum = 0.0;
                $squares = 0.0;
                $count = 0;
                for ($y = $by * $block; $y < min($height, ($by + 1) * $block); $y += 2) {
                    for ($x = $bx * $block; $x < min($width, ($bx + 1) * $block); $x += 2) {
                        $pixel = imagecolorat($image, $x, $y);
                        $luma = (0.299 * (($pixel >> 16) & 255) + 0.587 * (($pixel >> 8) & 255) + 0.114 * ($pixel & 255)) / 255;
                        $sum += $luma;
                        $squares += $luma * $luma;
                        ++$count;
                    }
                }

                $mean = $sum / $count;
                $detail[$by][$bx] = sqrt(max(0.0, $squares / $count - $mean * $mean)) > self::DETAIL_SPREAD ? 1.0 : 0.0;
            }
        }

        // Small patches of detail - a pictogram, a line of text, the corner
        // of a frame - belong to the visual and change colour with it. What
        // is left is photos and screenshots; each is grown by three blocks, so
        // its edge and its frame seal it, its enclosed flat parts - a clear
        // sky, a plain wall - are filled, and it is shrunk back one block further,
        // so the soft edge below falls inside it rather than on the background. A flat inside
        // has no detail of its own and may share the background's hue: only
        // being enclosed tells it apart.
        $large = $this->withoutSmallPatches($detail, $rows, $columns, max(40, (int) ($rows * $columns * 0.004)));
        $closed = $this->spread($this->filled($this->spread($large, $rows, $columns, 3, true), $rows, $columns), $rows, $columns, 4, false);

        // A block's worth of softness, so no seam is drawn at the border.
        $shield = [];
        for ($by = 0; $by < $rows; ++$by) {
            for ($bx = 0; $bx < $columns; ++$bx) {
                $total = 0.0;
                $count = 0;
                for ($dy = -1; $dy <= 1; ++$dy) {
                    for ($dx = -1; $dx <= 1; ++$dx) {
                        if (isset($closed[$by + $dy][$bx + $dx])) {
                            $total += $closed[$by + $dy][$bx + $dx];
                            ++$count;
                        }
                    }
                }

                $shield[$by][$bx] = $total / $count;
            }
        }

        return [$shield, $block];
    }

    /**
     * The mask without its patches smaller than the minimum.
     *
     * @param array<int, array<int, float>> $mask
     *
     * @return array<int, array<int, float>>
     */
    private function withoutSmallPatches(array $mask, int $rows, int $columns, int $minimum): array
    {
        $seen = [];
        for ($by = 0; $by < $rows; ++$by) {
            for ($bx = 0; $bx < $columns; ++$bx) {
                if (1.0 !== $mask[$by][$bx]) {
                    continue;
                }

                if (isset($seen[$by][$bx])) {
                    continue;
                }

                $patch = [];
                $queue = [[$by, $bx]];
                $seen[$by][$bx] = true;
                while ([] !== $queue) {
                    [$y, $x] = array_pop($queue);
                    $patch[] = [$y, $x];
                    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dy, $dx]) {
                        if (1.0 === ($mask[$y + $dy][$x + $dx] ?? 0.0) && !isset($seen[$y + $dy][$x + $dx])) {
                            $seen[$y + $dy][$x + $dx] = true;
                            $queue[] = [$y + $dy, $x + $dx];
                        }
                    }
                }

                if (count($patch) < $minimum) {
                    foreach ($patch as [$y, $x]) {
                        $mask[$y][$x] = 0.0;
                    }
                }
            }
        }

        return $mask;
    }

    /**
     * The mask with its holes filled: every empty block that cannot reach
     * the edge of the image through empty blocks is enclosed, so filled.
     *
     * @param array<int, array<int, float>> $mask
     *
     * @return array<int, array<int, float>>
     */
    private function filled(array $mask, int $rows, int $columns): array
    {
        $outside = [];
        $queue = [];
        for ($by = 0; $by < $rows; ++$by) {
            for ($bx = 0; $bx < $columns; ++$bx) {
                $edge = 0 === $by || 0 === $bx || $rows - 1 === $by || $columns - 1 === $bx;
                if ($edge && 0.0 === $mask[$by][$bx]) {
                    $outside[$by][$bx] = true;
                    $queue[] = [$by, $bx];
                }
            }
        }

        while ([] !== $queue) {
            [$y, $x] = array_pop($queue);
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dy, $dx]) {
                if (0.0 === ($mask[$y + $dy][$x + $dx] ?? 1.0) && !isset($outside[$y + $dy][$x + $dx])) {
                    $outside[$y + $dy][$x + $dx] = true;
                    $queue[] = [$y + $dy, $x + $dx];
                }
            }
        }

        for ($by = 0; $by < $rows; ++$by) {
            for ($bx = 0; $bx < $columns; ++$bx) {
                if (!isset($outside[$by][$bx])) {
                    $mask[$by][$bx] = 1.0;
                }
            }
        }

        return $mask;
    }

    /**
     * Grows (or shrinks) a mask of blocks by a square of the given radius.
     *
     * @param array<int, array<int, float>> $mask
     *
     * @return array<int, array<int, float>>
     */
    private function spread(array $mask, int $rows, int $columns, int $radius, bool $grow): array
    {
        $want = $grow ? 1.0 : 0.0;
        $out = [];
        for ($by = 0; $by < $rows; ++$by) {
            for ($bx = 0; $bx < $columns; ++$bx) {
                $value = 1.0 - $want;
                for ($dy = -$radius; $dy <= $radius && $value !== $want; ++$dy) {
                    for ($dx = -$radius; $dx <= $radius; ++$dx) {
                        // Off the image counts as empty when growing and as
                        // filled when shrinking, so a photo at the edge keeps it.
                        if (($mask[$by + $dy][$bx + $dx] ?? (1.0 - $want)) === $want) {
                            $value = $want;

                            break;
                        }
                    }
                }

                $out[$by][$bx] = $value;
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        return [(int) hexdec(mb_substr($hex, 1, 2)), (int) hexdec(mb_substr($hex, 3, 2)), (int) hexdec(mb_substr($hex, 5, 2))];
    }

    /** @return array{0: float, 1: float, 2: float} hue in degrees, saturation and lightness in [0, 1] */
    private function hsl(int $r, int $g, int $b): array
    {
        $r /= 255.0;
        $g /= 255.0;
        $b /= 255.0;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d <= 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + 6, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    /** @return array{0: float, 1: float, 2: float} channels in [0, 255] */
    private function rgbFromHsl(float $h, float $s, float $l): array
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match ((int) ($h / 60) % 6) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return [($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255];
    }

    private function load(string $path, MimeTypeEnum $mime): ?GdImage
    {
        $resource = match (true) {
            $mime->isJpeg() => @imagecreatefromjpeg($path),
            MimeTypeEnum::Png === $mime => @imagecreatefrompng($path),
            MimeTypeEnum::Webp === $mime => @imagecreatefromwebp($path),
            default => false,
        };

        return $resource instanceof GdImage ? $resource : null;
    }

    private function save(GdImage $image, string $path, MimeTypeEnum $mime): void
    {
        match (true) {
            $mime->isJpeg() => imagejpeg($image, $path, 85),
            MimeTypeEnum::Png === $mime => imagepng($image, $path, 6),
            MimeTypeEnum::Webp === $mime => imagewebp($image, $path, 85),
            default => null,
        };
    }
}
