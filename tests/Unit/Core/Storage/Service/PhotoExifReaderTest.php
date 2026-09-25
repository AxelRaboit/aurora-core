<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\Service;

use Aurora\Core\Storage\Service\PhotoExifReader;
use PHPUnit\Framework\TestCase;

/**
 * The camera settings of a photograph, in the words a caption prints.
 */
final class PhotoExifReaderTest extends TestCase
{
    public function testTheSettingsReadTheWayAPhotographerSaysThem(): void
    {
        $described = PhotoExifReader::describe([
            'IFD0' => ['Make' => 'Canon', 'Model' => 'Canon EOS R6'],
            'EXIF' => [
                'FNumber' => '28/10',
                'ExposureTime' => '1/500',
                'ISOSpeedRatings' => 100,
                'FocalLength' => '35/1',
                'UndefinedTag:0xA434' => 'RF24-70mm F2.8 L IS USM',
                'DateTimeOriginal' => '2026:09:14 18:32:05',
            ],
        ]);

        self::assertSame([
            'camera' => 'Canon EOS R6',
            'lens' => 'RF24-70mm F2.8 L IS USM',
            'aperture' => 'f/2.8',
            'shutter' => '1/500 s',
            'iso' => 'ISO 100',
            'focal' => '35 mm',
            'takenAt' => '2026-09-14',
        ], $described);
    }

    public function testAMakerMissingFromTheModelIsAdded(): void
    {
        self::assertSame('SONY ILCE-7M3', PhotoExifReader::describe(['IFD0' => ['Make' => 'SONY', 'Model' => 'ILCE-7M3']])['camera']);
    }

    public function testALongExposureIsInSeconds(): void
    {
        self::assertSame('2 s', PhotoExifReader::describe(['EXIF' => ['ExposureTime' => '2/1']])['shutter']);
    }

    /** Where the picture was taken is never kept. */
    public function testThePositionIsNotRead(): void
    {
        $described = PhotoExifReader::describe(['GPS' => ['GPSLatitude' => ['45/1', '12/1', '0/1']], 'IFD0' => ['Model' => 'X100V']]);

        self::assertSame(['camera' => 'X100V'], $described);
    }
}
