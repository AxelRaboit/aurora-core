<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Core\Encryption\Service\EncryptionServiceInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\GoogleReviews\Service\GoogleReviews;
use Aurora\Module\Editorial\GoogleReviews\Setting\GoogleReviewsSettings;
use Aurora\Module\Editorial\Instagram\Service\InstagramFeed;
use Aurora\Module\Editorial\Instagram\Setting\InstagramSettings;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettings;
use Aurora\Module\Editorial\Post\Grid\IntegrationZoneViews;
use Aurora\Module\Planning\Sync\Manager\ModuleCalendarProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What each third-party zone draws, given what its own Settings and its own
 * client hold - {@see InstagramFeed},
 * {@see GoogleReviews} and the
 * three Settings classes are all `final readonly`, so this builds real
 * instances on stubbed dependencies rather than mocking them, the same way
 * {@see ModuleCalendarProvider} is
 * tested elsewhere.
 */
final class IntegrationZoneViewsTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $store = [];

    public function testAnInstagramZoneDrawsNothingUntilTheClientHasSwitchedItOn(): void
    {
        $views = $this->views(new MockHttpClient(new MockResponse('{"data": []}')));

        self::assertNull($views->instagramFeed(['feedCount' => 6]));
    }

    public function testAnInstagramZoneReadsTheClientsOwnAccountUpToTheChosenCount(): void
    {
        $instagramSettings = $this->instagramSettings();
        $instagramSettings->save(enabled: true, termsAccepted: true, accessToken: 'token', businessAccountId: '999', acceptedBy: 'axel@example.com');

        $page = <<<'JSON'
            {"data": [
                {"id": "1", "media_type": "IMAGE", "media_url": "https://cdn/1.jpg", "permalink": "https://instagram.com/p/1", "caption": "Un"},
                {"id": "2", "media_type": "IMAGE", "media_url": "https://cdn/2.jpg", "permalink": "https://instagram.com/p/2", "caption": "Deux"}
            ]}
            JSON;

        $views = $this->views(new MockHttpClient(new MockResponse($page)), instagramSettings: $instagramSettings);

        self::assertCount(1, $views->instagramFeed(['feedCount' => 1])['posts'] ?? []);
    }

    public function testAGoogleReviewsZoneDrawsNothingUntilTheClientHasSwitchedItOn(): void
    {
        $views = $this->views(new MockHttpClient(new MockResponse('{"status": "NOT_FOUND"}')));

        self::assertNull($views->googleReviews('fr'));
    }

    public function testAGoogleReviewsZoneReadsTheClientsOwnPlace(): void
    {
        $googleReviewsSettings = $this->googleReviewsSettings();
        $googleReviewsSettings->save(enabled: true, termsAccepted: true, apiKey: 'key', placeId: 'place-id', acceptedBy: 'axel@example.com');

        $page = '{"status": "OK", "result": {"rating": 4.6, "user_ratings_total": 12, "url": "https://maps.google.com/p", "reviews": []}}';
        $views = $this->views(new MockHttpClient(new MockResponse($page)), googleReviewsSettings: $googleReviewsSettings);

        $place = $views->googleReviews('fr');

        self::assertSame(4.6, $place['rating']);
        self::assertSame('4,6', $place['ratingLabel']);
        self::assertSame(12, $place['total']);
    }

    public function testANewsletterZoneDrawsNothingUntilTheClientHasSwitchedItOn(): void
    {
        $views = $this->views(new MockHttpClient(new MockResponse('')));

        self::assertNull($views->newsletterSignup(['label' => 'Recevez les prochaines dates', 'caption' => 'Une fois par mois'], 'fr'));
    }

    public function testANewsletterZoneCarriesItsOwnTranslatedWordsAndTheSubscribeEndpoint(): void
    {
        $newsletterSettings = $this->newsletterSettings();
        $newsletterSettings->save(enabled: true, termsAccepted: true, provider: 'brevo', apiKey: 'key', listId: '3', acceptedBy: 'axel@example.com');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/fr/newsletter');

        $views = $this->views(new MockHttpClient(new MockResponse('')), newsletterSettings: $newsletterSettings, urlGenerator: $urlGenerator);

        self::assertSame(
            ['title' => 'Recevez les prochaines dates', 'note' => 'Une fois par mois', 'endpoint' => '/fr/newsletter'],
            $views->newsletterSignup(['label' => 'Recevez les prochaines dates', 'caption' => 'Une fois par mois'], 'fr'),
        );
    }

    private function views(
        MockHttpClient $httpClient,
        ?InstagramSettings $instagramSettings = null,
        ?GoogleReviewsSettings $googleReviewsSettings = null,
        ?NewsletterSettings $newsletterSettings = null,
        ?UrlGeneratorInterface $urlGenerator = null,
    ): IntegrationZoneViews {
        return new IntegrationZoneViews(
            $instagramSettings ?? $this->instagramSettings(),
            new InstagramFeed($httpClient, new ArrayAdapter(), new NullLogger()),
            $googleReviewsSettings ?? $this->googleReviewsSettings(),
            new GoogleReviews($httpClient, new ArrayAdapter(), new NullLogger()),
            $newsletterSettings ?? $this->newsletterSettings(),
            $urlGenerator ?? $this->createStub(UrlGeneratorInterface::class),
        );
    }

    private function instagramSettings(): InstagramSettings
    {
        return new InstagramSettings($this->repository(), $this->encryption());
    }

    private function googleReviewsSettings(): GoogleReviewsSettings
    {
        return new GoogleReviewsSettings($this->repository(), $this->encryption());
    }

    private function newsletterSettings(): NewsletterSettings
    {
        return new NewsletterSettings($this->repository(), $this->encryption());
    }

    private function repository(): SettingRepository
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            fn (string $key, ?string $default = null): ?string => $this->store[$key] ?? $default,
        );
        $repository->method('getBoolean')->willReturnCallback(
            fn (string $key, bool $default = false): bool => '1' === ($this->store[$key] ?? ($default ? '1' : '0')),
        );
        $repository->method('saveMany')->willReturnCallback(function (iterable $entries): void {
            foreach ($entries as [$key, $value]) {
                $this->store[$key] = $value;
            }
        });

        return $repository;
    }

    /** Reversible and obviously not real, so a leaked ciphertext in a failure message is harmless. */
    private function encryption(): EncryptionServiceInterface
    {
        return new class implements EncryptionServiceInterface {
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
    }
}
