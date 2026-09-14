<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Pexels\Service;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Aurora\Module\Ged\Pexels\Setting\PexelsSettingEnum;
use Aurora\Module\Ged\Pexels\Setting\PexelsSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The picker is only as good as what this hands it, and the two things that
 * can go wrong here are silent: a key that was never set, and a provider that
 * answered with something other than photos.
 */
final class PexelsClientTest extends TestCase
{
    /**
     * A real {@see PexelsSettings} over an in-memory store: the class is
     * final, and building it for real also proves the client asks the right
     * question - enabled, accepted and keyed, not merely keyed.
     */
    private function settings(string $apiKey): PexelsSettings
    {
        $store = '' === $apiKey ? [] : [
            PexelsSettingEnum::Enabled->value => '1',
            PexelsSettingEnum::ApiKey->value => base64_encode($apiKey),
            PexelsSettingEnum::TermsAcceptedAt->value => '2026-09-06T12:00:00+00:00',
        ];

        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $store[$key] ?? $default,
        );
        $repository->method('getBoolean')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => '1' === ($store[$key] ?? ($default ? '1' : '0')),
        );

        $encryption = new class implements EncryptionServiceInterface {
            public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }

            public function decrypt(string $encoded): ?string
            {
                $decoded = base64_decode($encoded, strict: true);

                return false === $decoded ? null : $decoded;
            }
        };

        return new PexelsSettings($repository, $encryption);
    }

    public function testAPhotoCanBeFetchedByIdForCallersThatNeverSearched(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/v1/photos/2014422', $url);

            return new MockResponse(json_encode([
                'id' => 2014422,
                'width' => 4000,
                'height' => 3000,
                'alt' => 'A tidy desk',
                'photographer' => 'Jane Doe',
                'photographer_url' => 'https://www.pexels.com/@jane',
                'src' => ['original' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg'],
            ], JSON_THROW_ON_ERROR));
        });

        $photo = (new PexelsClient($http, new NullLogger(), $this->settings('key')))->photo('2014422');

        // The same shape the search hands over, so the importer cannot tell
        // which of the two found the photo.
        self::assertNotNull($photo);
        self::assertSame('2014422', $photo['id']);
        self::assertSame('https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg', $photo['url']);
        self::assertSame('Jane Doe', $photo['authorName']);
    }

    public function testAPhotoLookupIsSkippedWhileTheIntegrationIsOff(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No request should be made while the integration is off.');
        });

        self::assertNull((new PexelsClient($http, new NullLogger(), $this->settings('')))->photo('2014422'));
    }

    /**
     * An id nobody owns answers 404, and an answer shaped like a photo but
     * carrying no file is not one. Both come back as null: what the caller
     * does next is the same, and the log line is what tells them apart.
     */
    public function testAnUnknownOrHollowPhotoIsNull(): void
    {
        $missing = new PexelsClient(
            new MockHttpClient(new MockResponse('{"error":"Not Found"}', ['http_code' => 404])),
            new NullLogger(),
            $this->settings('key'),
        );
        self::assertNull($missing->photo('404404'));

        $hollow = new PexelsClient(
            new MockHttpClient(new MockResponse(json_encode([
                'id' => 7,
                'photographer' => 'Jane Doe',
                'src' => [],
            ], JSON_THROW_ON_ERROR))),
            new NullLogger(),
            $this->settings('key'),
        );
        self::assertNull($hollow->photo('7'));
    }

    public function testAnEmptyIdNeverReachesTheProvider(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('An empty id must not reach the provider.');
        });

        self::assertNull((new PexelsClient($http, new NullLogger(), $this->settings('key')))->photo('  '));
    }

    public function testSearchIsSkippedWhileTheIntegrationIsOff(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No request should be made while the integration is off.');
        });

        $client = new PexelsClient($http, new NullLogger(), $this->settings(''));

        self::assertFalse($client->isConfigured());
        self::assertSame(['results' => [], 'totalPages' => 0], $client->search('desk'));
    }

    public function testSearchIsSkippedForAnEmptyQuery(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('An empty query must not reach the provider.');
        });

        self::assertSame(
            ['results' => [], 'totalPages' => 0],
            (new PexelsClient($http, new NullLogger(), $this->settings('key')))->search('   '),
        );
    }

    public function testSearchKeepsOnlyTheFieldsThePickerNeeds(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'total_results' => 100,
            'per_page' => 24,
            'page' => 1,
            'photos' => [[
                'id' => 2014422,
                'width' => 4000,
                'height' => 3000,
                'avg_color' => '#0f172a',
                'alt' => 'A tidy desk',
                'photographer' => 'Jane Doe',
                'photographer_url' => 'https://www.pexels.com/@jane',
                'src' => [
                    'original' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg',
                    'large' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg?auto=compress&cs=tinysrgb&h=650&w=940',
                    'tiny' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg?auto=compress&cs=tinysrgb&h=130&w=280',
                ],
                // Fields we deliberately drop rather than carry into the DB.
                'liked' => false,
                'photographer_id' => 42,
                'url' => 'https://www.pexels.com/photo/a-tidy-desk-2014422/',
            ]],
        ], JSON_THROW_ON_ERROR)));

        $result = (new PexelsClient($http, new NullLogger(), $this->settings('key')))->search('desk');

        self::assertCount(1, $result['results']);
        self::assertSame([
            'id' => '2014422',
            // The original, not the 940x650 rendition next to it.
            'url' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg',
            'thumbUrl' => 'https://images.pexels.com/photos/2014422/pexels-photo-2014422.jpeg?auto=compress&cs=tinysrgb&h=130&w=280',
            'width' => 4000,
            'height' => 3000,
            'description' => 'A tidy desk',
            'color' => '#0f172a',
            'authorName' => 'Jane Doe',
            'authorUrl' => 'https://www.pexels.com/@jane',
        ], $result['results'][0]);
    }

    /**
     * Pexels counts results where Unsplash counted pages, so the pager it
     * feeds is a division we do - and one that has to round up, or the last
     * few photos of a search are unreachable.
     */
    public function testPageCountIsDerivedFromTheResultCount(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'total_results' => 49,
            'per_page' => 24,
            'photos' => [],
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(3, (new PexelsClient($http, new NullLogger(), $this->settings('key')))->search('desk')['totalPages']);
    }

    /**
     * A provider that is down must not take the editing screen with it: the
     * picker shows an empty tab, the page being written is untouched.
     */
    public function testAFailingProviderYieldsAnEmptyResultRatherThanAnException(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        self::assertSame(
            ['results' => [], 'totalPages' => 0],
            (new PexelsClient($http, new NullLogger(), $this->settings('key')))->search('desk'),
        );
    }

    /** Pexels wants the bare key; prefixing it the way Unsplash wanted is a 401. */
    public function testTheKeyIsSentAsAPlainAuthorizationHeader(): void
    {
        $seen = null;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = $options['headers'] ?? [];

            return new MockResponse('{"photos":[],"total_results":0,"per_page":24}');
        });

        (new PexelsClient($http, new NullLogger(), $this->settings('s3cret')))->search('desk');

        self::assertContains('Authorization: s3cret', $seen);
    }
}
