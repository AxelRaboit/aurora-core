<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Service\PostPictures;
use PHPUnit\Framework\TestCase;

use function sort;

/**
 * The pictures a grid draws, wherever a zone keeps them.
 *
 * The walk once read only `mediaId` and `mediaIds`: a list's portraits, a
 * custom surface's picture, a social post's avatar, a QR code's logo and a
 * banner zone's pictures were reported as used by nobody.
 */
final class PostPicturesTest extends TestCase
{
    public function testItFindsEverySlotAZoneCanHoldPicturesIn(): void
    {
        $layout = ['zones' => [
            ['type' => 'media', 'mediaId' => 1],
            ['type' => 'gallery', 'mediaIds' => [2, 3]],
            ['type' => 'items', 'items' => [['mediaId' => 4], ['mediaId' => null], ['mediaId' => 5]]],
            ['type' => 'text', 'background' => ['type' => 'solid', 'mediaId' => 6, 'videoId' => 7]],
            ['type' => 'socialPost', 'options' => ['socialAvatarId' => 8]],
            ['type' => 'qrCode', 'options' => ['qrLogoId' => 9]],
            ['type' => 'banner', 'banner' => [
                'logoMediaId' => 10,
                'background' => ['mediaId' => 11, 'mobileMediaId' => 12],
                'items' => [['mediaId' => 13]],
            ]],
        ]];

        $ids = new PostPictures()->idsInGridLayout($layout);
        sort($ids);

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13], $ids);
    }

    public function testItWalksIntoAStacksChildren(): void
    {
        $layout = ['zones' => [
            ['type' => 'stack', 'children' => [
                ['type' => 'items', 'items' => [['mediaId' => 21]]],
                ['type' => 'text', 'background' => ['mediaId' => 22]],
            ]],
        ]];

        $ids = new PostPictures()->idsInGridLayout($layout);
        sort($ids);

        self::assertSame([21, 22], $ids);
    }

    public function testItIgnoresWhatIsNotAPictureId(): void
    {
        $layout = ['zones' => [
            ['type' => 'media', 'mediaId' => 0],
            ['type' => 'media', 'mediaId' => '5'],
            ['type' => 'items', 'items' => ['oops', ['mediaId' => -3]]],
            'not a zone',
        ]];

        self::assertSame([], new PostPictures()->idsInGridLayout($layout));
    }
}
