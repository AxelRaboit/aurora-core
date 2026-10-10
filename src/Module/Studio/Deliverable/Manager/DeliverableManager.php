<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Manager;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\ClientNotice\Service\ClientNoticeRecorder;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadingHeader;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

use function array_key_exists;
use function ctype_digit;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_trim;
use function str_starts_with;

/**
 * Writes deliverables: creation, saving, copying, deletion.
 *
 * The grid goes through the site pages' normalizer: it is the same
 * construction, with the same zones and the same safeguards, and what is
 * accepted here is what knows how to render there.
 */
readonly class DeliverableManager
{
    public const int TITLE_MAX = 255;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GridNormalizer $gridNormalizer,
        private LocaleContextInterface $localeContext,
        private DeliverableCategoryRepository $deliverableCategoryRepository,
        private DocumentRepository $documentRepository,
        private AuditLogger $auditLogger,
        private DeliverableRepository $deliverableRepository,
        private CustomerRepository $customerRepository,
        private SlidesManager $slidesManager,
        /**
         * Optional, and last, so that a client project extending this class
         * with its own constructor keeps booting: without it, the client is
         * simply not told of this gesture.
         */
        protected readonly ?ClientNoticeRecorder $clientNoticeRecorder = null,
    ) {}

    /**
     * A new deliverable, ready to compose.
     *
     * The grid is on from the start: a deliverable has no other body, and a
     * switch to flip before writing the first line would be a wasted action.
     * In a space, "Préparé pour" takes the client's company name; without a
     * space, there is nobody to name yet.
     *
     * The scope only counts without a space: in a space, the space's team is
     * the reader. The format is decided here once and for all, see
     * {@see DeliverableFormatEnum}.
     */
    public function create(
        ?CustomerSpaceInterface $space,
        string $title,
        ?CoreUserInterface $owner = null,
        DeliverableScopeEnum $scope = DeliverableScopeEnum::Shared,
        ?DeliverableCategoryInterface $category = null,
        DeliverableFormatEnum $format = DeliverableFormatEnum::Page,
    ): DeliverableInterface {
        $deliverable = $this->instantiate($space, $title, $this->localeContext->getDefaultLocale(), $format);
        $deliverable
            ->setOwner($owner)
            ->setCategory($space instanceof CustomerSpaceInterface ? null : $category)
            ->setScope($space instanceof CustomerSpaceInterface ? DeliverableScopeEnum::Shared : $scope);
        $layout = $this->gridNormalizer->normalizeLayout(['enabled' => true]);

        $deliverable
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent([], $layout))
            ->setAppearance(DeliverableAppearance::normalize([]))
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => $space?->getCustomer()->getLegalName() ?? '']));

        $this->entityManager->persist($deliverable);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deliverable.created', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));

        return $deliverable;
    }

    /**
     * The entity being created, in one place: a project that extends the
     * deliverable (one more field, a relation) overrides this and gets its
     * class on every creation, copy or duplication, without rewriting the
     * manager. It is the same hook as the one for client spaces.
     */
    protected function instantiate(
        ?CustomerSpaceInterface $space,
        string $title,
        string $locale,
        DeliverableFormatEnum $format = DeliverableFormatEnum::Page,
    ): DeliverableInterface {
        return new Deliverable($space, $title, $locale, $format);
    }

    /**
     * A new Studio deliverable, started from a template: its body, its
     * appearance, its image, its header, its language and its format, under
     * the title just typed.
     *
     * Like a duplication, with two differences: the shelf and the category
     * come from the creation dialog, and the new one is not a template itself
     * (nor does it take the template's client, if it had one): you start from
     * a template to write to someone.
     */
    public function createFromTemplate(
        DeliverableInterface $template,
        string $title,
        ?CoreUserInterface $owner = null,
        DeliverableScopeEnum $scope = DeliverableScopeEnum::Shared,
        ?DeliverableCategoryInterface $category = null,
    ): DeliverableInterface {
        $deliverable = $this->instantiate(null, $title, $template->getLocale(), $template->getFormat());
        $deliverable
            ->setOwner($owner)
            ->setScope($scope)
            ->setCategory($category)
            ->setReadingHeader($template->getReadingHeader());

        $deliverable = $this->persistCopy($template, $deliverable);
        $this->auditLogger->log('studio', 'deliverable.created', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable, ['from' => $template->getId()]));

        return $deliverable;
    }

    /**
     * Is what the editor holds older than what is saved?
     *
     * The editor sends back the modification date it received when opening
     * or saving the deliverable. If it no longer matches the database,
     * someone else came by in the meantime, and saving would erase their
     * work: the shared deliverable has several authors. A request that does
     * not carry it is not compared, so that a call from elsewhere stays
     * possible, and `force` saves anyway, when the author chose to overwrite
     * what the other did.
     *
     * @param array<string, mixed> $data
     */
    public function isStale(DeliverableInterface $deliverable, array $data): bool
    {
        $seen = $data['updatedAt'] ?? null;
        if (true === ($data['force'] ?? false) || !is_string($seen) || '' === $seen) {
            return false;
        }

        try {
            return new DateTimeImmutable($seen)->getTimestamp() !== $deliverable->getUpdatedAt()->getTimestamp();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Saves what the editor sends, in full.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string> the errors by field, empty when everything went through
     */
    public function update(DeliverableInterface $deliverable, array $data): array
    {
        $errors = $this->errors($data);
        if ([] !== $errors) {
            return $errors;
        }

        // A deliverable is always a grid: its editor offers no way to turn it
        // off, and a grid turned off would render an empty page.
        $layout = $this->gridNormalizer->normalizeLayout([...(is_array($data['gridLayout'] ?? null) ? $data['gridLayout'] : []), 'enabled' => true]);
        $summary = is_string($data['summary'] ?? null) ? mb_trim($data['summary']) : '';

        $deliverable
            ->setTitle(mb_trim((string) $data['title']))
            ->setSummary('' === $summary ? null : $summary)
            ->setLocale((string) $data['locale'])
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent($data['gridContent'] ?? [], $layout))
            ->setAppearance(DeliverableAppearance::normalize($data['appearance'] ?? []))
            ->setReadingHeader(DeliverableReadingHeader::normalize($data['readingHeader'] ?? []))
            // Without a space, there is no client to see it: the box stays
            // unchecked, whatever the editor says.
            ->setVisibleToClient(!$deliverable->isStandalone() && true === ($data['visibleToClient'] ?? false))
            ->touch();

        // The category, the "modèle" box, the client and the image only change
        // if the request names them: a call that omits them does not clear
        // them. A space deliverable never has a category; it is never a
        // template and has no client other than its space's, which the entity
        // guarantees on its own.
        if (!$deliverable->isStandalone()) {
            $deliverable->setCategory(null);
        } elseif (array_key_exists('categoryId', $data)) {
            $deliverable->setCategory($this->category($data['categoryId']));
        }

        if (array_key_exists('template', $data)) {
            $deliverable->setTemplate(true === $data['template']);
        }

        if (array_key_exists('customerId', $data)) {
            $deliverable->setCustomer($this->customer($data['customerId']));
        }

        if (array_key_exists('thumbnailId', $data)) {
            $deliverable->setThumbnail($this->thumbnail($data['thumbnailId']));
        }

        $this->entityManager->flush();

        return [];
    }

    /** Open or closed to the client, without reopening the editor: this is the list's action. */
    public function setVisibleToClient(DeliverableInterface $deliverable, bool $visible): void
    {
        $wasVisible = $deliverable->isVisibleToClient();
        $deliverable->setVisibleToClient($visible);
        $this->entityManager->flush();
        if ($visible) {
            $this->auditLogger->log('studio', 'deliverable.shown_to_client', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));

            // A page or a deck reaching the client's « Documents » tab.
            $space = $deliverable->getSpace();
            if (!$wasVisible && null !== $space) {
                $this->clientNoticeRecorder?->record($space, ClientNoticeTypeEnum::DeliverableShared, $deliverable->getTitle());
            }
        } else {
            $this->auditLogger->log('studio', 'deliverable.hidden_from_client', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));
        }
    }

    /**
     * A copy, to start from an existing deliverable: last month's report to
     * write this one.
     *
     * Closed to the client, whatever the original's state: a copy is work in
     * progress. Its reading links do not follow, they were given for the
     * original.
     *
     * It keeps the original's client, whom it still addresses, but not the
     * "modèle" box: duplicating a template means starting to fill it in.
     */
    public function duplicate(DeliverableInterface $source, string $title, ?CoreUserInterface $author = null): DeliverableInterface
    {
        $copy = $this->instantiate($source->getSpace(), $title, $source->getLocale(), $source->getFormat());
        // The copy belongs to whoever makes it, on the same shelf as the
        // original: a copy of a shared deliverable stays with the team.
        $copy
            ->setOwner($author ?? $source->getOwner())
            ->setScope($source->getScope())
            ->setCategory($source->getCategory())
            ->setCustomer($source->getCustomer())
            ->setReadingHeader($source->getReadingHeader());

        $copy = $this->persistCopy($source, $copy);
        $this->auditLogger->log('studio', 'deliverable.duplicated', 'Deliverable', $copy->getId(), $this->auditPayload($copy, ['from' => $source->getId()]));

        return $copy;
    }

    /**
     * A Studio deliverable copied into a client's space: the audit or
     * strategy template you fill in for them.
     *
     * A page or a presentation: a presentation takes its slides, their
     * notes, its theme and style along, see {@see self::persistCopy()}. It is
     * also what « Partir d'un modèle » does from a space's Deliverables tab.
     * The copy then lives in the space, under its rights; the original stays
     * in Studio, untouched. It arrives closed to the client, like any copy,
     * and "Préparé pour" takes the name of the space's client.
     *
     * No template flag and no client of its own: in a space, the space states
     * both, and a copy of a template is the document you fill in.
     */
    public function copyToSpace(DeliverableInterface $source, CustomerSpaceInterface $space, string $title, ?CoreUserInterface $author = null): DeliverableInterface
    {
        $copy = $this->instantiate($space, $title, $source->getLocale(), $source->getFormat());
        $copy
            ->setOwner($author)
            ->setScope(DeliverableScopeEnum::Shared)
            ->setReadingHeader(DeliverableReadingHeader::normalize([
                ...$source->getReadingHeader(),
                'preparedFor' => $space->getCustomer()->getLegalName(),
            ]));

        $copy = $this->persistCopy($source, $copy);
        $this->auditLogger->log('studio', 'deliverable.copied_to_space', 'Deliverable', $copy->getId(), $this->auditPayload($copy, ['from' => $source->getId()]));

        return $copy;
    }

    /**
     * A space deliverable copied into Studio, to make a template of it: it
     * lands in the personal deliverables of whoever copies it, with no client
     * to name.
     */
    public function copyToStudio(DeliverableInterface $source, string $title, ?CoreUserInterface $author = null): DeliverableInterface
    {
        $copy = $this->instantiate(null, $title, $source->getLocale(), $source->getFormat());
        $copy
            ->setOwner($author)
            ->setScope(DeliverableScopeEnum::Personal)
            ->setReadingHeader(DeliverableReadingHeader::normalize([...$source->getReadingHeader(), 'preparedFor' => '']));

        $copy = $this->persistCopy($source, $copy);
        $this->auditLogger->log('studio', 'deliverable.copied_to_studio', 'Deliverable', $copy->getId(), $this->auditPayload($copy, ['from' => $source->getId()]));

        return $copy;
    }

    /** Personal or shared, for a deliverable without a space. */
    public function setScope(DeliverableInterface $deliverable, DeliverableScopeEnum $scope, ?CoreUserInterface $by = null): void
    {
        if (!$deliverable->isStandalone()) {
            return;
        }

        $deliverable->setScope($scope);
        // An orphan that changes shelf has been taken in: it goes to whoever
        // decided it, rather than staying without an author.
        if (!$deliverable->getOwner() instanceof CoreUserInterface && $by instanceof CoreUserInterface) {
            $deliverable->setOwner($by);
        }

        $this->entityManager->flush();
        $this->auditLogger->log('studio', 'deliverable.scope_changed', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));
    }

    /**
     * The category sent by the editor or the creation dialog.
     *
     * An id that no longer resolves leaves the deliverable without a category
     * rather than refusing the save: it can only come from a category deleted
     * between opening the page and saving.
     */
    public function category(mixed $id): ?DeliverableCategoryInterface
    {
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;

        return null === $id ? null : $this->deliverableCategoryRepository->find($id);
    }

    /**
     * The client sent by a Studio deliverable's settings. An id that no
     * longer resolves leaves it without a client, like a category deleted in
     * the meantime.
     */
    public function customer(mixed $id): ?CustomerInterface
    {
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;

        return null === $id ? null : $this->customerRepository->find($id);
    }

    /**
     * The image sent by the editor: a media library document, and an image.
     * An id that resolves nothing, or a PDF, leaves the deliverable without
     * an image rather than refusing the save.
     */
    private function thumbnail(mixed $id): ?DocumentInterface
    {
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
        $document = null === $id ? null : $this->documentRepository->find($id);

        return $document instanceof DocumentInterface && str_starts_with((string) $document->getMimeType(), 'image/') ? $document : null;
    }

    /**
     * The original's body into the copy, then saved: a page's grid, or a
     * slideshow's theme and slides, notes included.
     */
    private function persistCopy(DeliverableInterface $source, DeliverableInterface $copy): DeliverableInterface
    {
        $copy
            ->setSummary($source->getSummary())
            ->setGridLayout($source->getGridLayout())
            ->setGridContent($source->getGridContent())
            ->setAppearance($source->getAppearance())
            ->setThumbnail($source->getThumbnail());

        $this->entityManager->persist($copy);

        if ($source->isSlides() && $copy->isSlides()) {
            $this->slidesManager->copySlides($copy, $source);
        }

        $this->entityManager->flush();

        return $copy;
    }

    /**
     * Moves the deliverable to the trash: it leaves the lists, the search and
     * the counts, and its reading links stop answering. Nothing is destroyed:
     * its images stay counted by the media library, its links and their
     * history stay in the database, and restoring puts everything back as it
     * was.
     */
    public function trash(DeliverableInterface $deliverable): void
    {
        if ($deliverable->isTrashed()) {
            return;
        }

        $deliverable->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();
        $this->auditLogger->log('studio', 'deliverable.trashed', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));
    }

    /** Takes the deliverable out of the trash: its reading links resume, as they were. */
    public function restore(DeliverableInterface $deliverable): void
    {
        if (!$deliverable->isTrashed()) {
            return;
        }

        $deliverable->setDeletedAt(null);
        $this->entityManager->flush();
        $this->auditLogger->log('studio', 'deliverable.restored', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));
    }

    /**
     * Destroys the deliverable for good, with its reading links. The trash's
     * "Supprimer définitivement" button and the scheduled purge go through
     * here: it is the only place where a deliverable disappears.
     */
    public function forceDelete(DeliverableInterface $deliverable): void
    {
        // Logged before being removed: afterwards it has no id any more.
        // Final, and the only record of who did it.
        $this->auditLogger->log('studio', 'deliverable.deleted', 'Deliverable', $deliverable->getId(), $this->auditPayload($deliverable));

        $this->entityManager->remove($deliverable);
        $this->entityManager->flush();
    }

    /**
     * Destroys what has been in the trash since before this date, and returns
     * how many: the scheduled purge, after the delay all trashes share.
     */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int
    {
        $purged = 0;
        foreach ($this->deliverableRepository->findTrashedBefore($cutoff) as $deliverable) {
            $this->forceDelete($deliverable);
            ++$purged;
        }

        return $purged;
    }

    /**
     * What an audit log line says about a deliverable: its title, its space,
     * its shelf, its format and whether it is a template. The actions that
     * affect someone other than the author are logged (create, copy, delete,
     * open or close to the client, change shelf, give or revoke an address);
     * a content save is not, it would have one line per typing pause.
     *
     * Each call writes its action out in full: the audit label test only
     * reads literal arguments, and a built action would escape its check.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function auditPayload(DeliverableInterface $deliverable, array $extra = []): array
    {
        return [
            'title' => $deliverable->getTitle(),
            'space' => $deliverable->getSpace()?->getId(),
            'scope' => $deliverable->isStandalone() ? $deliverable->getScope()->value : null,
            'format' => $deliverable->getFormat()->value,
            'template' => $deliverable->isTemplate(),
            ...$extra,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function errors(array $data): array
    {
        $errors = [];
        $title = is_string($data['title'] ?? null) ? mb_trim($data['title']) : '';

        if ('' === $title) {
            $errors['title'] = 'suite.studio.deliverables.errors.title_required';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = 'suite.studio.deliverables.errors.title_too_long';
        }

        if (!in_array($data['locale'] ?? null, $this->localeContext->getActiveLocales(), true)) {
            $errors['locale'] = 'suite.studio.deliverables.errors.locale_invalid';
        }

        if (isset($data['gridLayout']) && !is_array($data['gridLayout'])) {
            $errors['gridLayout'] = 'suite.studio.deliverables.errors.grid_invalid';
        }

        return $errors;
    }
}
