<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\GoogleReviews\Service\GoogleReviews;
use Aurora\Module\Editorial\GoogleReviews\Setting\GoogleReviewsSettings;
use Aurora\Module\Editorial\Instagram\Service\InstagramFeed;
use Aurora\Module\Editorial\Instagram\Setting\InstagramSettings;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettings;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Twig\Environment;

/**
 * The three third-party zones, from the settings to the markup - the same
 * edge held for all three as for GitHub activity
 * ({@see GridGitHubActivityZoneTest}): a zone placed before the client has
 * switched the integration on, and accepted its terms, draws nothing.
 */
final class GridWaveFourZonesTest extends IntegrationTestCase
{
    public function testAnInstagramZonePlacedWhileTheIntegrationIsOffDrawsNothing(): void
    {
        static::bootKernel();
        static::getContainer()->get(InstagramSettings::class)->save(enabled: false, termsAccepted: false, accessToken: null, businessAccountId: null, acceptedBy: '');

        self::assertSame('', mb_trim($this->render(['type' => 'instagramFeed'])));
    }

    public function testAnInstagramFeedShowsItsThumbnailsAndMarksItsVideos(): void
    {
        static::bootKernel();
        static::getContainer()->get(InstagramSettings::class)->save(enabled: true, termsAccepted: true, accessToken: 'token', businessAccountId: '999', acceptedBy: 'axel@example.com');

        $page = <<<'JSON'
            {"data": [
                {"id": "1", "media_type": "IMAGE", "media_url": "https://cdn/1.jpg", "permalink": "https://instagram.com/p/1", "caption": "Un mariage"},
                {"id": "2", "media_type": "VIDEO", "media_url": "https://cdn/2.mp4", "thumbnail_url": "https://cdn/2.jpg", "permalink": "https://instagram.com/p/2", "caption": "Un portrait"}
            ]}
            JSON;

        static::getContainer()->set(InstagramFeed::class, new InstagramFeed(new MockHttpClient(new MockResponse($page)), new ArrayAdapter(), new NullLogger()));

        $html = $this->render(['type' => 'instagramFeed', 'options' => ['feedCount' => 6]]);

        self::assertStringContainsString('href="https://instagram.com/p/1"', $html);
        self::assertStringContainsString('src="https://cdn/2.jpg"', $html);
    }

    public function testAGoogleReviewsZonePlacedWhileTheIntegrationIsOffDrawsNothing(): void
    {
        static::bootKernel();
        static::getContainer()->get(GoogleReviewsSettings::class)->save(enabled: false, termsAccepted: false, apiKey: null, placeId: null, acceptedBy: '');

        self::assertSame('', mb_trim($this->render(['type' => 'googleReviews'])));
    }

    public function testAGoogleReviewsZoneShowsTheRatingAndItsMostRecentReviews(): void
    {
        static::bootKernel();
        static::getContainer()->get(GoogleReviewsSettings::class)->save(enabled: true, termsAccepted: true, apiKey: 'key', placeId: 'place-id', acceptedBy: 'axel@example.com');

        $page = '{"status": "OK", "result": {"rating": 4.8, "user_ratings_total": 42, "url": "https://maps.google.com/place", "reviews": [{"author_name": "Marie", "rating": 5, "relative_time_description": "il y a un mois", "text": "Superbe séance"}]}}';
        static::getContainer()->set(GoogleReviews::class, new GoogleReviews(new MockHttpClient(new MockResponse($page)), new ArrayAdapter(), new NullLogger()));

        $html = $this->render(['type' => 'googleReviews']);

        self::assertStringContainsString('Marie', $html);
        self::assertStringContainsString('Superbe séance', $html);
        self::assertStringContainsString('href="https://maps.google.com/place"', $html);
    }

    public function testANewsletterZonePlacedWhileTheIntegrationIsOffDrawsNothing(): void
    {
        static::bootKernel();
        static::getContainer()->get(NewsletterSettings::class)->save(enabled: false, termsAccepted: false, provider: 'brevo', apiKey: null, listId: null, acceptedBy: '');

        self::assertSame('', mb_trim($this->render(['type' => 'newsletterSignup'])));
    }

    public function testANewsletterZoneShowsItsFormAndPostsToTheSubscribeEndpoint(): void
    {
        static::bootKernel();
        static::getContainer()->get(NewsletterSettings::class)->save(enabled: true, termsAccepted: true, provider: 'brevo', apiKey: 'key', listId: '3', acceptedBy: 'axel@example.com');

        $html = $this->render(['type' => 'newsletterSignup'], ['label' => 'Recevez les prochaines dates', 'caption' => 'Une fois par mois']);

        self::assertStringContainsString('Recevez les prochaines dates', $html);
        self::assertStringContainsString('Une fois par mois', $html);
        self::assertStringContainsString('data-newsletter-endpoint="/fr/newsletter"', $html);
    }

    /** @param array<string, mixed> $zone @param array<string, mixed> $held */
    private function render(array $zone, array $held = []): string
    {
        $grid = static::getContainer()->get(GridViewBuilder::class)->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', ...$zone]]],
            ['zones' => ['z1' => $held]],
            'fr',
        );

        self::assertNotNull($grid);

        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('Frontend/themes/default/editorial/post/_grid_zone.html.twig', ['zone' => $grid['zones'][0], 'locale' => 'fr']);
    }
}
