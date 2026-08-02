<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistory;
use Aurora\Module\Editorial\Post\Entity\PostSlugHistoryInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PostSlugHistoryInterface>
 */
class PostSlugHistoryRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PostSlugHistory::class, PostSlugHistoryInterface::class);
    }

    public function findOneByLocaleAndSlug(string $locale, string $slug): ?PostSlugHistoryInterface
    {
        return $this->findOneBy(['locale' => $locale, 'slug' => $slug]);
    }

    /**
     * Called when a post takes a slug history already holds, so the redirect
     * cannot end up pointing the new URL back at itself.
     */
    public function removeByLocaleAndSlug(string $locale, string $slug): void
    {
        $entry = $this->findOneByLocaleAndSlug($locale, $slug);
        if ($entry instanceof PostSlugHistoryInterface) {
            $this->getEntityManager()->remove($entry);
        }
    }

    public function recordIfNew(PostInterface $post, string $locale, string $oldSlug): void
    {
        if ($this->findOneByLocaleAndSlug($locale, $oldSlug) instanceof PostSlugHistoryInterface) {
            return;
        }

        $entry = new PostSlugHistory();
        $entry->setPost($post);
        $entry->setLocale($locale);
        $entry->setSlug($oldSlug);

        $this->getEntityManager()->persist($entry);
    }
}
