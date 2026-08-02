<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\EventSubscriber;

use Aurora\Core\Locale\Entity\LocaleInterface;
use Aurora\Module\Configuration\Setting\Entity\SettingInterface;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\Seo\Service\RssFeedService;
use Aurora\Module\Editorial\Seo\Service\SitemapService;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermTranslationInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Drops the sitemap and feed caches the moment anything they list changes.
 *
 * Without this an editor publishes a post and it is absent from
 * `/sitemap.xml` for up to an hour, with nothing to explain the delay — the
 * TTL on those caches is a backstop for an idle site, not the mechanism.
 *
 * No attempt is made to work out whether a given edit actually changed a
 * URL. A cache delete costs nothing and the next request rebuilds lazily;
 * being clever here would only add a way to be wrong.
 *
 * Every check is against an interface, not a concrete class: entities are
 * substitutable by contract, and a client that swapped `Post` for its own
 * subclass would otherwise publish into a sitemap that never refreshed.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
final readonly class SeoCacheInvalidationSubscriber
{
    public function __construct(
        private SitemapService $sitemapService,
        private RssFeedService $rssFeedService,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->dispatch($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->dispatch($args->getObject());
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->dispatch($args->getObject());
    }

    private function dispatch(object $entity): void
    {
        // Anything that changes which posts exist, or in which locales.
        if ($entity instanceof PostInterface
            || $entity instanceof PostTranslationInterface
            || $entity instanceof LocaleInterface
        ) {
            $this->sitemapService->invalidate();
            $this->rssFeedService->invalidate();

            return;
        }

        // Structure the sitemap lists and the feed does not — the feed only
        // carries posts.
        if ($entity instanceof PostTypeInterface
            || $entity instanceof TaxonomyInterface
            || $entity instanceof TaxonomyTermInterface
            || $entity instanceof TaxonomyTermTranslationInterface
        ) {
            $this->sitemapService->invalidate();

            return;
        }

        // The feed's channel header carries the site's name, description and
        // URL; nothing else in settings reaches either file.
        if ($entity instanceof SettingInterface && $this->affectsFeedHeader($entity->getKey())) {
            $this->rssFeedService->invalidate();
        }
    }

    private function affectsFeedHeader(string $key): bool
    {
        return match (ApplicationParameterEnum::tryFrom($key)) {
            ApplicationParameterEnum::SiteName,
            ApplicationParameterEnum::SiteDescription,
            ApplicationParameterEnum::SiteUrl => true,
            default => false,
        };
    }
}
