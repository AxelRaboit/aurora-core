<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Instagram\Service;

use Aurora\Module\Editorial\GitHub\Service\GitHubContributions;
use Aurora\Module\Editorial\Instagram\Service\InstagramFeed;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reading a business account's recent posts, and living with Meta not
 * answering - the same cache shape as {@see GitHubContributions}.
 */
final class InstagramFeedTest extends TestCase
{
    private const string PAGE = <<<'JSON'
        {
            "data": [
                {"id": "1", "media_type": "IMAGE", "media_url": "https://cdn/1.jpg", "permalink": "https://instagram.com/p/1", "caption": "Un mariage"},
                {"id": "2", "media_type": "VIDEO", "media_url": "https://cdn/2.mp4", "thumbnail_url": "https://cdn/2.jpg", "permalink": "https://instagram.com/p/2", "caption": "Un portrait"},
                {"id": "3", "media_type": "IMAGE"}
            ]
        }
        JSON;

    public function testTheFeedReadsEachPostAndFallsBackToTheImageForAStill(): void
    {
        $service = new InstagramFeed(new MockHttpClient(new MockResponse(self::PAGE)), new ArrayAdapter(), new NullLogger());

        $feed = $service->forAccount('12345', 'token', 24);

        self::assertSame(
            [
                ['id' => '1', 'url' => 'https://cdn/1.jpg', 'thumbnail' => 'https://cdn/1.jpg', 'permalink' => 'https://instagram.com/p/1', 'caption' => 'Un mariage', 'isVideo' => false],
                ['id' => '2', 'url' => 'https://cdn/2.mp4', 'thumbnail' => 'https://cdn/2.jpg', 'permalink' => 'https://instagram.com/p/2', 'caption' => 'Un portrait', 'isVideo' => true],
            ],
            $feed,
        );
    }

    public function testTheFeedIsCutToTheRequestedLimit(): void
    {
        $service = new InstagramFeed(new MockHttpClient(new MockResponse(self::PAGE)), new ArrayAdapter(), new NullLogger());

        self::assertCount(1, $service->forAccount('12345', 'token', 1));
    }

    public function testAFeedIsAskedForOnceAndThenServedFromTheCache(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::PAGE);
        });

        $service = new InstagramFeed($client, new ArrayAdapter(), new NullLogger());

        $service->forAccount('12345', 'token', 24);
        $service->forAccount('12345', 'token', 24);

        self::assertSame(1, $calls);
    }

    /** A token that stops working for a day does not empty the page. */
    public function testTheLastFeedReadOutlivesAnOutage(): void
    {
        $cache = new ArrayAdapter();
        $up = new InstagramFeed(new MockHttpClient(new MockResponse(self::PAGE)), $cache, new NullLogger());
        $up->forAccount('12345', 'token', 24);

        $cache->delete('editorial.instagram.feed.'.md5('12345'));

        $calls = 0;
        $down = new InstagramFeed(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 401]);
        }), $cache, new NullLogger());

        self::assertCount(2, $down->forAccount('12345', 'token', 24) ?? []);
        self::assertCount(2, $down->forAccount('12345', 'token', 24) ?? []);
        self::assertSame(1, $calls);
    }

    public function testAnAccountThatNeverAnsweredShowsNothing(): void
    {
        $service = new InstagramFeed(
            new MockHttpClient(new MockResponse('', ['http_code' => 400])),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertNull($service->forAccount('12345', 'token', 24));
    }

    public function testAnEmptyAccountShowsNothing(): void
    {
        $service = new InstagramFeed(
            new MockHttpClient(new MockResponse('{"data": []}')),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertNull($service->forAccount('12345', 'token', 24));
    }
}
