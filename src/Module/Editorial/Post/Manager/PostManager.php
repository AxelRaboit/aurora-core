<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Manager;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Core\Scheduling\Event\EntityUnscheduledEvent;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
use Aurora\Module\Editorial\Post\Dto\PostInputInterface;
use Aurora\Module\Editorial\Post\Dto\PostTranslationInput;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostRevision;
use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\ThumbnailFitEnum;
use Aurora\Module\Editorial\Post\Gallery\GalleryNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Repository\PostRevisionRepository;
use Aurora\Module\Editorial\Post\Repository\PostSlugHistoryRepository;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Setting\EditorialSettingEnum;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use const DATE_ATOM;

#[AsAlias(PostManagerInterface::class)]
class PostManager implements PostManagerInterface
{
    /**
     * How this module names itself to the rest of the application.
     *
     * A constant because it is half of an identity stored in another module's
     * table: change the string and every synced calendar entry orphans itself.
     */
    private const string SCHEDULE_SOURCE = 'editorial.post';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly PostRepository $postRepository,
        protected readonly PostTypeRepository $postTypeRepository,
        protected readonly TaxonomyTermRepository $termRepository,
        protected readonly DocumentRepository $documentRepository,
        protected readonly PostRevisionRepository $revisionRepository,
        protected readonly PostSlugHistoryRepository $slugHistoryRepository,
        protected readonly SettingRepository $settingRepository,
        protected readonly SluggerInterface $slugger,
        protected readonly Security $security,
        protected readonly PostTextExtractor $textExtractor,
        protected readonly TranslatorInterface $translator,
        protected readonly AuditLogger $auditLogger,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly BannerNormalizer $bannerNormalizer,
        protected readonly GridNormalizer $gridNormalizer,
        protected readonly GalleryNormalizer $galleryNormalizer,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function create(PostInputInterface $input): PostInterface
    {
        $post = $this->createPost();
        $this->applyInput($post, $input);

        $currentUser = $this->security->getUser();
        if ($currentUser instanceof CoreUserInterface) {
            $post->setAuthor($currentUser);
        }

        $post->setReference($this->sequenceGenerator->next(
            $this->settingRepository->getOrDefault(EditorialSettingEnum::PostPrefix),
        ));

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        $this->auditCreated($post);
        $this->syncSchedule($post);

        return $post;
    }

    public function update(PostInterface $post, PostInputInterface $input): void
    {
        $this->applyInput($post, $input);

        // @Version only bumps when the owning entity is itself scheduled for
        // UPDATE. Editing only a translation or a tag would leave the version
        // untouched, and the next editor's stale save would look current.
        $this->entityManager->getUnitOfWork()->scheduleForUpdate($post);
        $this->entityManager->flush();

        $this->snapshotRevision($post);

        $this->auditUpdated($post);
        $this->syncSchedule($post);
    }

    public function delete(PostInterface $post): void
    {
        if ($post->isTrashed()) {
            return;
        }

        $post->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditDeleted($post);
        $this->syncSchedule($post);
    }

    public function restore(PostInterface $post): void
    {
        $post->setDeletedAt(null);
        $this->entityManager->flush();

        $this->auditLogger->log('editorial', 'post.restored', 'Post', $post->getId(), $this->auditPayload($post));
        $this->syncSchedule($post);
    }

    public function forceDelete(PostInterface $post): void
    {
        // Both before the remove: afterwards the id is gone, and the calendar
        // needs it to find the entry it has to drop.
        $this->auditLogger->log('editorial', 'post.force_deleted', 'Post', $post->getId(), $this->auditPayload($post));
        $this->eventDispatcher->dispatch(new EntityUnscheduledEvent(self::SCHEDULE_SOURCE, (int) $post->getId()));

        $this->entityManager->remove($post);
        $this->entityManager->flush();
    }

    public function restoreRevision(PostInterface $post, PostRevisionInterface $revision): void
    {
        $this->applySnapshot($post, $revision->getSnapshot());

        $this->entityManager->flush();

        // Restoring is itself a change worth a revision - otherwise stepping
        // back loses whatever it stepped back from.
        $this->snapshotRevision($post);

        $this->auditLogger->log('editorial', 'post.revision_restored', 'Post', $post->getId(), [
            ...$this->auditPayload($post),
            'revisionId' => $revision->getId(),
        ]);

        // A snapshot carries `scheduledAt`, so stepping back can move the date or
        // remove it. Easy to forget, and forgetting it leaves a calendar entry on
        // a day the post no longer claims.
        $this->syncSchedule($post);
    }

    public function emptyTrash(): int
    {
        $posts = $this->postRepository->findAllTrashed();
        if ([] === $posts) {
            return 0;
        }

        foreach ($posts as $post) {
            $this->auditLogger->log('editorial', 'post.force_deleted', 'Post', $post->getId(), $this->auditPayload($post));
            $this->eventDispatcher->dispatch(new EntityUnscheduledEvent(self::SCHEDULE_SOURCE, (int) $post->getId()));
            $this->entityManager->remove($post);
        }

        $this->entityManager->flush();

        return count($posts);
    }

    /**
     * Tells whoever is listening whether this post has a date.
     *
     * The calendar module listens, and nothing here knows that: the signal goes
     * into core, so Editorial and Planning never depend on one another and with
     * no calendar installed this is a no-op. Same shape as the contact signal.
     *
     * Announced whenever the post has a `scheduledAt` and is not in the trash -
     * published included. A publication that went out on the 14th is still a date
     * somebody set, and having the entry vanish at the moment it happened would
     * empty the past for no reason a reader could work out.
     *
     * Called explicitly from each write rather than from a Doctrine listener.
     * The listener would catch every path for free, including fixtures, but the
     * calendar's write is a flush of its own - and a flush inside a flush is the
     * oldest trap in this ORM. Explicit calls are the honest trade: more places
     * to remember, and a test that names each one.
     */
    protected function syncSchedule(PostInterface $post): void
    {
        $id = $post->getId();
        if (null === $id) {
            return;
        }

        $scheduledAt = $post->getScheduledAt();

        if (!$scheduledAt instanceof DateTimeImmutable || $post->isTrashed()) {
            $this->eventDispatcher->dispatch(new EntityUnscheduledEvent(self::SCHEDULE_SOURCE, $id));

            return;
        }

        $this->eventDispatcher->dispatch(new EntityScheduledEvent(
            sourceType: self::SCHEDULE_SOURCE,
            sourceId: $id,
            // Untitled drafts exist, and a calendar entry with an empty label
            // would be an unclickable sliver. Named for what it is instead.
            label: $this->anyTitle($post) ?? $this->translator->trans('backend.posts.untitled'),
            startAt: $scheduledAt,
            calendarName: $this->translator->trans('backend.posts.calendar_name'),
            sourceLabel: $this->translator->trans('backend.nav.sections.editorial'),
            url: $this->urlGenerator->generate('backend_editorial_posts_edit', ['id' => $id]),
        ));
    }

    /**
     * Whether the last `demoteIfNotPublishable` actually demoted something.
     *
     * A flag rather than a return value because the method's job is to answer with
     * an input, and every caller already uses it that way. The controller reads
     * this straight after, which is the only moment it means anything.
     */
    protected bool $pendingReview = false;

    public function wasDemotedToReview(): bool
    {
        return $this->pendingReview;
    }

    public function demoteIfNotPublishable(PostInputInterface $input, ?PostInterface $post = null): PostInputInterface
    {
        if (PostStatusEnum::Published->value !== $input->getStatus()) {
            return $input;
        }

        $allowed = $post instanceof PostInterface
            ? $this->security->isGranted(PostVoter::PUBLISH, $post)
            : ($this->security->isGranted(UserRoleEnum::Admin->value) || $this->security->isGranted(UserRoleEnum::Dev->value));

        if ($allowed) {
            return $input;
        }

        // Demoted, and from here the caller has to say so. Until this existed the
        // post simply became `pending_review` with nobody told - which is why the
        // status looked like a feature that did not work.
        $this->pendingReview = true;

        return $input->withStatus(PostStatusEnum::PendingReview->value);
    }

    // ── Hooks: instanciation ──────────────────────────────────────────────────

    /**
     * Returns the interface, not the concrete class: a client's own Post
     * extends AbstractPost, not this one, so a concrete return type would
     * make the override it is here to allow impossible to write.
     */
    protected function createPost(): PostInterface
    {
        return new Post();
    }

    protected function createPostRevision(): PostRevisionInterface
    {
        return new PostRevision();
    }

    // ── Hooks: hydratation ────────────────────────────────────────────────────

    protected function applyInput(PostInterface $post, PostInputInterface $input): void
    {
        $postType = $this->postTypeRepository->find($input->getPostTypeId());
        if (null === $postType) {
            throw new InvalidArgumentException($this->translator->trans('backend.posts.errors.post_type_not_found', ['{id}' => $input->getPostTypeId()]));
        }

        $post->setPostType($postType);

        $status = PostStatusEnum::from($input->getStatus());
        $post->setStatus($status);

        $post->setScheduledAt(
            PostStatusEnum::Scheduled === $status ? $this->hydrateDate($input->getScheduledAt()) : null,
        );

        // Kept whatever the status is. An end date belongs to the post rather than
        // to a moment in its life, and clearing it on every save would silently
        // undo the thing somebody set on purpose.
        $post->setUnpublishAt($this->hydrateDate($input->getUnpublishAt()));

        // Stamped once, on the first publish: re-saving a live post must not
        // move it back to the top of a date-ordered listing.
        if (PostStatusEnum::Published === $status && !$post->getPublishedAt() instanceof DateTimeImmutable) {
            $post->setPublishedAt(new DateTimeImmutable());
        }

        $post->setThumbnail($this->findMedia($input->getThumbnailId()));
        // An unknown value falls back rather than failing: a fit is a
        // presentation choice, and refusing a save over one would lose an
        // author's text for the sake of a dropdown.
        $post->setThumbnailFit(ThumbnailFitEnum::tryFrom($input->getThumbnailFit()) ?? ThumbnailFitEnum::Cover);
        $post->setThumbnailFocal($input->getThumbnailFocalX(), $input->getThumbnailFocalY());
        $post->setCommentsEnabled($input->isCommentsEnabled());
        $post->setTitleVisible($input->isTitleVisible());

        // Normalised here rather than in the DTO: this is the write boundary,
        // and it is the only place guaranteed to run whatever built the input.
        // Before the translations, because their texts are keyed by the ids
        // this call settles - an item the layout dropped must not keep its
        // words in five languages.
        $bannerLayout = $this->bannerNormalizer->normalizeLayout($input->getBannerLayout());
        $post->setBannerLayout($bannerLayout);

        // Same order and the same reason as the banner above: the zones have
        // to be settled before the translations, because their ids are what
        // each language's content hangs off.
        $gridLayout = $this->gridNormalizer->normalizeLayout($input->getGridLayout());
        $post->setGridLayout($gridLayout);

        // And the gallery, for the third time and the same reason: its items'
        // ids are what each language's alt text and captions hang off.
        $galleryLayout = $this->galleryNormalizer->normalizeLayout($input->getGalleryLayout());
        $post->setGalleryLayout($galleryLayout);

        $this->syncTerms($post, $input->getTermIds());
        $this->syncRelatedPosts($post, $input->getRelatedPostIds());

        $ogImages = $this->buildMediaMap(array_values(array_filter(
            array_map(static fn (PostTranslationInput $t): ?int => $t->ogImageMediaId, $input->getTranslations()),
        )));

        foreach ($input->getTranslations() as $locale => $translationInput) {
            $this->applyTranslation($post, (string) $locale, $translationInput, $ogImages, $bannerLayout, $gridLayout, $galleryLayout);
        }
    }

    /**
     * Block images are deliberately not garbage-collected here. They point at
     * media-library entries, which are shared and addressable by id - a post
     * dropping a block must not delete a file another post still shows.
     *
     * @param array<int, DocumentInterface> $ogImages
     * @param array<string, mixed>          $bannerLayout  the already-normalised layout, which says which banner items exist
     * @param array<string, mixed>          $gridLayout    likewise, for the content grid's zones
     * @param array<string, mixed>          $galleryLayout likewise, for the gallery's items
     */
    protected function applyTranslation(PostInterface $post, string $locale, PostTranslationInput $input, array $ogImages = [], array $bannerLayout = [], array $gridLayout = [], array $galleryLayout = []): void
    {
        $translation = $post->translate($locale);

        $translation->setTitle($input->title);
        // Only this locale's words. Against the post's layout, so text for an
        // item that no longer exists is dropped instead of lingering unseen.
        $translation->setBanner($this->bannerNormalizer->normalizeTexts($input->banner, $bannerLayout));
        $translation->setGrid($this->gridNormalizer->normalizeContent($input->grid, $gridLayout));
        $translation->setGallery($this->galleryNormalizer->normalizeContent($input->gallery, $galleryLayout));
        $translation->setDescription($input->description);
        $translation->setMetaTitle($input->metaTitle);
        $translation->setMetaDescription($input->metaDescription);
        $translation->setCustomFields($input->customFields);
        $translation->setOgImage(null !== $input->ogImageMediaId ? ($ogImages[$input->ogImageMediaId] ?? null) : null);
        $translation->setCanonicalUrl($input->canonicalUrl);
        $translation->setNoindex($input->noindex);
        $translation->setFocusKeyword($input->focusKeyword);
        $translation->setJsonLd($input->jsonLd);

        $this->applySlug($post, $translation, $locale, $input);

        $translation->setSearchContent($this->textExtractor->extract($translation));
    }

    // ── Hooks: audit ──────────────────────────────────────────────────────────

    protected function auditCreated(PostInterface $post): void
    {
        $this->auditLogger->log('editorial', 'post.created', 'Post', $post->getId(), $this->auditPayload($post));
    }

    protected function auditUpdated(PostInterface $post): void
    {
        $this->auditLogger->log('editorial', 'post.updated', 'Post', $post->getId(), $this->auditPayload($post));
    }

    protected function auditDeleted(PostInterface $post): void
    {
        $this->auditLogger->log('editorial', 'post.deleted', 'Post', $post->getId(), $this->auditPayload($post));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(PostInterface $post): array
    {
        return ['title' => $this->anyTitle($post), 'status' => $post->getStatus()->value];
    }

    /**
     * The post's title in whichever language has one.
     *
     * A post is translated, so there is no single title; the first one that
     * exists is what a log line or a calendar entry can honestly show. Shared by
     * both rather than written twice, because a post with a title in one language
     * and not another should not read differently in the two places.
     */
    protected function anyTitle(PostInterface $post): ?string
    {
        foreach ($post->getTranslations() as $translation) {
            $title = $translation->getTitle();
            if (null !== $title) {
                return $title;
            }
        }

        return null;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * A renamed slug is remembered so the old URL keeps resolving. Taking a
     * slug that history holds drops that entry first, or the redirect would
     * loop onto itself.
     */
    private function applySlug(PostInterface $post, PostTranslationInterface $translation, string $locale, PostTranslationInput $input): void
    {
        $previous = $translation->getSlug();
        $next = $input->slug ?? (null !== $input->title ? $this->slugger->slug($input->title)->lower()->toString() : null);

        if ($next === $previous) {
            return;
        }

        if (null !== $next && '' !== $next) {
            $this->slugHistoryRepository->removeByLocaleAndSlug($locale, $next);
        }

        if (null !== $previous && '' !== $previous) {
            $this->slugHistoryRepository->recordIfNew($post, $locale, $previous);
        }

        $translation->setSlug($next);
    }

    /** @param list<int> $termIds */
    private function syncTerms(PostInterface $post, array $termIds): void
    {
        foreach ($post->getTerms() as $existing) {
            if (!in_array($existing->getId(), $termIds, true)) {
                $post->removeTerm($existing);
            }
        }

        $missing = $this->missingIds($post->getTerms()->map(static fn ($term): ?int => $term->getId())->toArray(), $termIds);
        if ([] === $missing) {
            return;
        }

        foreach ($this->termRepository->findBy(['id' => $missing]) as $term) {
            $post->addTerm($term);
        }
    }

    /** @param list<int> $relatedPostIds */
    private function syncRelatedPosts(PostInterface $post, array $relatedPostIds): void
    {
        $relatedPostIds = array_values(array_filter($relatedPostIds, static fn (int $id): bool => $id !== $post->getId()));

        foreach ($post->getRelatedPosts() as $existing) {
            if (!in_array($existing->getId(), $relatedPostIds, true)) {
                $post->removeRelatedPost($existing);
            }
        }

        $missing = $this->missingIds($post->getRelatedPosts()->map(static fn ($related): ?int => $related->getId())->toArray(), $relatedPostIds);
        if ([] === $missing) {
            return;
        }

        foreach ($this->postRepository->findBy(['id' => $missing]) as $related) {
            $post->addRelatedPost($related);
        }
    }

    /**
     * @param array<int, ?int> $current
     * @param list<int>        $wanted
     *
     * @return list<int>
     */
    private function missingIds(array $current, array $wanted): array
    {
        return array_values(array_filter($wanted, static fn (int $id): bool => !in_array($id, $current, true)));
    }

    private function snapshotRevision(PostInterface $post): void
    {
        $revision = $this->createPostRevision();
        $revision->setPost($post);
        $revision->setPostVersion($post->getVersion());
        $revision->setStatus($post->getStatus());
        $revision->setSnapshot($this->buildSnapshot($post));

        $user = $this->security->getUser();
        if ($user instanceof CoreUserInterface) {
            $revision->setAuthor($user);
        }

        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        $limit = (int) $this->settingRepository->get(
            ApplicationParameterEnum::PostRevisionsLimit->value,
            ApplicationParameterEnum::PostRevisionsLimit->getDefaultValue(),
        );

        if ($limit > 0) {
            $this->revisionRepository->pruneOlderThanLimit($post, $limit);
        }
    }

    /** @return array<string, mixed> */
    private function buildSnapshot(PostInterface $post): array
    {
        $translations = [];
        foreach ($post->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'title' => $translation->getTitle(),
                'slug' => $translation->getSlug(),
                // The body, which is a grid and lives in two halves: what each
                // zone holds is here, the arrangement is on the post below.
                // Taking one without the other restores words with nowhere to
                // go, or an arrangement with nothing in it.
                'grid' => $translation->getGrid(),
                'description' => $translation->getDescription(),
                'metaTitle' => $translation->getMetaTitle(),
                'metaDescription' => $translation->getMetaDescription(),
                'customFields' => $translation->getCustomFields(),
                'ogImageMediaId' => $translation->getOgImage()?->getId(),
                'canonicalUrl' => $translation->getCanonicalUrl(),
                'noindex' => $translation->isNoindex(),
                'focusKeyword' => $translation->getFocusKeyword(),
                'jsonLd' => $translation->getJsonLd(),
            ];
        }

        return [
            'status' => $post->getStatus()->value,
            'postTypeId' => $post->getPostType()->getId(),
            'thumbnailId' => $post->getThumbnail()?->getId(),
            'termIds' => array_values($post->getTerms()->map(static fn ($term): ?int => $term->getId())->toArray()),
            'relatedPostIds' => array_values($post->getRelatedPosts()->map(static fn ($related): ?int => $related->getId())->toArray()),
            'publishedAt' => $post->getPublishedAt()?->format(DATE_ATOM),
            'scheduledAt' => $post->getScheduledAt()?->format(DATE_ATOM),
            'gridLayout' => $post->getGridLayout(),
            'bannerLayout' => $post->getBannerLayout(),
            'translations' => $translations,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function applySnapshot(PostInterface $post, array $snapshot): void
    {
        $post->setStatus(PostStatusEnum::tryFrom((string) ($snapshot['status'] ?? '')) ?? PostStatusEnum::Draft);
        $post->setPublishedAt($this->hydrateDate($snapshot['publishedAt'] ?? null));
        $post->setScheduledAt($this->hydrateDate($snapshot['scheduledAt'] ?? null));

        // Before the translations: what each zone holds is normalised against
        // the arrangement, so the arrangement has to be back first or every
        // zone would be dropped as belonging to nothing.
        $post->setGridLayout($this->gridNormalizer->normalizeLayout($snapshot['gridLayout'] ?? null));
        $post->setBannerLayout($this->bannerNormalizer->normalizeLayout($snapshot['bannerLayout'] ?? null));

        $snapshotTranslations = is_array($snapshot['translations'] ?? null) ? $snapshot['translations'] : [];

        $ogImageIds = array_map(
            static fn (mixed $t): int => is_array($t) ? (int) ($t['ogImageMediaId'] ?? 0) : 0,
            $snapshotTranslations,
        );
        $thumbnailId = (int) ($snapshot['thumbnailId'] ?? 0);

        $media = $this->buildMediaMap(array_values(array_filter(
            [$thumbnailId, ...array_values($ogImageIds)],
            static fn (int $id): bool => $id > 0,
        )));

        $post->setThumbnail($media[$thumbnailId] ?? null);

        $this->syncTerms($post, $this->positiveIds($snapshot['termIds'] ?? null));
        $this->syncRelatedPosts($post, $this->positiveIds($snapshot['relatedPostIds'] ?? null));

        foreach ($snapshotTranslations as $locale => $data) {
            if (!is_array($data)) {
                continue;
            }

            $translation = $post->translate((string) $locale);
            $translation->setTitle($data['title'] ?? null);
            $translation->setSlug($data['slug'] ?? null);
            $translation->setGrid($this->gridNormalizer->normalizeContent(
                $data['grid'] ?? null,
                $post->getGridLayout(),
            ));
            $translation->setDescription($data['description'] ?? null);
            $translation->setMetaTitle($data['metaTitle'] ?? null);
            $translation->setMetaDescription($data['metaDescription'] ?? null);
            $translation->setCustomFields(is_array($data['customFields'] ?? null) ? $data['customFields'] : []);
            $translation->setOgImage($media[(int) ($data['ogImageMediaId'] ?? 0)] ?? null);
            $translation->setCanonicalUrl($data['canonicalUrl'] ?? null);
            $translation->setNoindex((bool) ($data['noindex'] ?? false));
            $translation->setFocusKeyword($data['focusKeyword'] ?? null);
            $translation->setJsonLd(is_array($data['jsonLd'] ?? null) ? $data['jsonLd'] : null);

            $translation->setSearchContent($this->textExtractor->extract($translation));
        }
    }

    /** @return list<int> */
    private function positiveIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(intval(...), $raw), static fn (int $id): bool => $id > 0));
    }

    private function findMedia(?int $id): ?DocumentInterface
    {
        return null !== $id ? $this->documentRepository->find($id) : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, DocumentInterface>
     */
    private function buildMediaMap(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $map = [];
        foreach ($this->documentRepository->findBy(['id' => array_unique($ids)]) as $document) {
            $map[$document->getId()] = $document;
        }

        return $map;
    }

    private function hydrateDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
