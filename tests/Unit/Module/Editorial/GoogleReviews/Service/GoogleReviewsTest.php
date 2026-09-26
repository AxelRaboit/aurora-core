<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\GoogleReviews\Service;

use Aurora\Module\Editorial\GitHub\Service\GitHubContributions;
use Aurora\Module\Editorial\GoogleReviews\Service\GoogleReviews;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A business's rating and reviews, and living with the Places API not
 * answering - the same cache shape as {@see GitHubContributions}.
 */
final class GoogleReviewsTest extends TestCase
{
    private const string PAGE = <<<'JSON'
        {
            "status": "OK",
            "result": {
                "rating": 4.8,
                "user_ratings_total": 42,
                "url": "https://maps.google.com/place",
                "reviews": [
                    {"author_name": "Marie", "profile_photo_url": "https://cdn/marie.jpg", "rating": 5, "relative_time_description": "il y a un mois", "text": "Superbe"},
                    {"rating": 4}
                ]
            }
        }
        JSON;

    public function testTheRatingAndReviewsComeFromThePlaceDetails(): void
    {
        $service = new GoogleReviews(new MockHttpClient(new MockResponse(self::PAGE)), new ArrayAdapter(), new NullLogger());

        $place = $service->forPlace('place-id', 'key', 'fr');

        self::assertSame(4.8, $place['rating']);
        self::assertSame(42, $place['total']);
        self::assertSame('https://maps.google.com/place', $place['url']);
        self::assertSame(
            [['author' => 'Marie', 'photo' => 'https://cdn/marie.jpg', 'rating' => 5, 'relativeTime' => 'il y a un mois', 'text' => 'Superbe']],
            $place['reviews'],
        );
    }

    public function testAMissingUrlFallsBackToGoogleSOwnWriteReviewLink(): void
    {
        $service = new GoogleReviews(new MockHttpClient(new MockResponse('{"status": "OK", "result": {"rating": 5}}')), new ArrayAdapter(), new NullLogger());

        $place = $service->forPlace('place-id', 'key', 'fr');

        self::assertSame('https://search.google.com/local/writereview?placeid=place-id', $place['url']);
    }

    public function testAPlaceIsAskedForOnceAndThenServedFromTheCache(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse(self::PAGE);
        });

        $service = new GoogleReviews($client, new ArrayAdapter(), new NullLogger());

        $service->forPlace('place-id', 'key', 'fr');
        $service->forPlace('place-id', 'key', 'fr');

        self::assertSame(1, $calls);
    }

    /** A quota exhausted for a day does not empty the page. */
    public function testTheLastReadOutlivesAnOutage(): void
    {
        $cache = new ArrayAdapter();
        $up = new GoogleReviews(new MockHttpClient(new MockResponse(self::PAGE)), $cache, new NullLogger());
        $up->forPlace('place-id', 'key', 'fr');

        $cache->delete('editorial.google_reviews.'.md5('place-id.fr'));

        $calls = 0;
        $down = new GoogleReviews(new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"status": "OVER_QUERY_LIMIT"}');
        }), $cache, new NullLogger());

        self::assertSame(4.8, $down->forPlace('place-id', 'key', 'fr')['rating'] ?? null);
        self::assertSame(4.8, $down->forPlace('place-id', 'key', 'fr')['rating'] ?? null);
        self::assertSame(1, $calls);
    }

    public function testAnUnknownPlaceShowsNothing(): void
    {
        $service = new GoogleReviews(
            new MockHttpClient(new MockResponse('{"status": "NOT_FOUND"}')),
            new ArrayAdapter(),
            new NullLogger(),
        );

        self::assertNull($service->forPlace('place-id', 'key', 'fr'));
    }
}
