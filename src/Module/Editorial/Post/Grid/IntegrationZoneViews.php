<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\GoogleReviews\Service\GoogleReviews;
use Aurora\Module\Editorial\GoogleReviews\Setting\GoogleReviewsSettings;
use Aurora\Module\Editorial\Instagram\Service\InstagramFeed;
use Aurora\Module\Editorial\Instagram\Setting\InstagramSettings;
use Aurora\Module\Editorial\Newsletter\Setting\NewsletterSettings;
use NumberFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the three third-party zones draw: a client's own Instagram feed,
 * their own Google reviews, their own newsletter's signup field.
 *
 * Each is `null` until the client has switched its own integration on in the
 * settings and entered their own account - a zone placed before that draws
 * nothing, exactly like a deck nobody has shared yet.
 */
final readonly class IntegrationZoneViews
{
    public function __construct(
        private InstagramSettings $instagramSettings,
        private InstagramFeed $instagramFeed,
        private GoogleReviewsSettings $googleReviewsSettings,
        private GoogleReviews $googleReviews,
        private NewsletterSettings $newsletterSettings,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    public function instagramFeed(array $options): ?array
    {
        if (!$this->instagramSettings->isEnabled()) {
            return null;
        }

        $posts = $this->instagramFeed->forAccount(
            $this->instagramSettings->businessAccountId(),
            $this->instagramSettings->accessToken(),
            (int) $options['feedCount'],
        );

        return null === $posts ? null : ['posts' => $posts];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function googleReviews(string $locale): ?array
    {
        if (!$this->googleReviewsSettings->isEnabled()) {
            return null;
        }

        $place = $this->googleReviews->forPlace($this->googleReviewsSettings->placeId(), $this->googleReviewsSettings->apiKey(), $locale);

        if (null === $place) {
            return null;
        }

        $numbers = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $numbers->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return [
            'rating' => $place['rating'],
            'ratingLabel' => $numbers->format($place['rating']),
            'total' => $place['total'],
            'url' => $place['url'],
            'reviews' => $place['reviews'],
        ];
    }

    /**
     * @param array<string, mixed> $held
     *
     * @return array<string, mixed>|null
     */
    public function newsletterSignup(array $held, string $locale): ?array
    {
        if (!$this->newsletterSettings->isEnabled()) {
            return null;
        }

        return [
            'title' => $held['label'],
            'note' => $held['caption'],
            'endpoint' => $this->urlGenerator->generate('editorial_newsletter_subscribe', ['locale' => $locale]),
        ];
    }
}
