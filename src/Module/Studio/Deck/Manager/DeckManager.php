<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLinkInterface;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Everything that writes a deck goes through here.
 *
 * One place that knows how a deck and its slides are wired together, for the
 * same reason `ContractTemplateDuplicator` insists on it: a second
 * implementation drifts the first time somebody adds a field, and it drifts
 * silently - the copy simply missing something nobody looks for.
 *
 * The slides themselves are written by {@see SlidesManager}, which the
 * slides-format deliverables share: the methods below that touch a slide only
 * hand it on, so the two owners cannot drift apart.
 */
class DeckManager
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SlidesManager $slides,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Puts the deck in the trash: it leaves the lists, the search and the
     * counts, and its share links stop answering. Nothing is destroyed: its
     * slides, its pictures (still counted by the media library) and its links
     * with their history stay, and a restore puts everything back.
     */
    public function trash(DeckInterface $deck): void
    {
        if ($deck->isTrashed()) {
            return;
        }

        $deck->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deck.trashed', 'Deck', $deck->getId(), $this->auditPayload($deck));
    }

    /** Takes the deck out of the trash: its share links answer again, as they were. */
    public function restore(DeckInterface $deck): void
    {
        if (!$deck->isTrashed()) {
            return;
        }

        $deck->setDeletedAt(null);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deck.restored', 'Deck', $deck->getId(), $this->auditPayload($deck));
    }

    /**
     * Destroys the deck for good, with its slides and its share links. The
     * trash's "Delete permanently" button and the scheduled purge both come
     * here: it is the only place a deck disappears.
     */
    public function forceDelete(DeckInterface $deck): void
    {
        $id = $deck->getId();
        $payload = $this->auditPayload($deck);

        $this->entityManager->remove($deck);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'deck.deleted', 'Deck', $id, $payload);
    }

    /**
     * The journal of a deck, as the deliverables keep theirs.
     *
     * Creating, filing, copying and sharing a deck left no trace: a link
     * handed out to a stranger could not be traced back to who made it. These
     * are called once the write is flushed, so the row has its id.
     */
    public function recordCreated(DeckInterface $deck): void
    {
        $this->auditLogger->log('studio', 'deck.created', 'Deck', $deck->getId(), $this->auditPayload($deck));
    }

    public function recordUpdated(DeckInterface $deck): void
    {
        $this->auditLogger->log('studio', 'deck.updated', 'Deck', $deck->getId(), $this->auditPayload($deck));
    }

    public function recordDuplicated(DeckInterface $copy, DeckInterface $source): void
    {
        $this->auditLogger->log('studio', 'deck.duplicated', 'Deck', $copy->getId(), $this->auditPayload($copy, ['source' => $source->getId()]));
    }

    /** @param 'issued'|'revoked'|'hidden'|'deleted' $what */
    public function recordShareLink(string $what, DeckShareLinkInterface $link, ?int $linkId = null): void
    {
        $id = $linkId ?? $link->getId();
        $payload = $this->auditPayload($link->getDeck(), [
            'label' => $link->getLabel(),
            'expires' => $link->getExpiresAt() instanceof DateTimeImmutable,
            'locked' => $link->isLocked(),
        ]);

        // One literal call per action: the label test reads the action names
        // from the source, and a built string is one it cannot see.
        match ($what) {
            'issued' => $this->auditLogger->log('studio', 'deck_link.issued', 'DeckShareLink', $id, $payload),
            'revoked' => $this->auditLogger->log('studio', 'deck_link.revoked', 'DeckShareLink', $id, $payload),
            'hidden' => $this->auditLogger->log('studio', 'deck_link.hidden', 'DeckShareLink', $id, $payload),
            'deleted' => $this->auditLogger->log('studio', 'deck_link.deleted', 'DeckShareLink', $id, $payload),
        };
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(DeckInterface $deck, array $extra = []): array
    {
        return [
            'title' => $deck->getTitle(),
            'customer' => $deck->getCustomer()?->getId(),
            'template' => $deck->isTemplate(),
            ...$extra,
        ];
    }

    public function create(string $title): DeckInterface
    {
        $deck = new Deck();
        $deck->setTitle($title);

        $this->entityManager->persist($deck);

        return $deck;
    }

    /**
     * Write a deck's appearance: the theme it starts from, and what it changes.
     *
     * @param array<string, mixed> $style
     */
    public function writeAppearance(DeckInterface $deck, DeckThemeEnum $theme, array $style): DeckInterface
    {
        $this->slides->writeAppearance($deck, $theme, $style);

        return $deck;
    }

    /** Add a slide at the end of the deck, cf. {@see SlidesManager::addSlide()}. */
    public function addSlide(DeckInterface $deck, SlideLayoutEnum $layout): SlideInterface
    {
        return $this->slides->addSlide($deck, $layout);
    }

    /**
     * Write a slide's content, keeping only the slots its layout declares,
     * cf. {@see SlidesManager::writeContent()}.
     *
     * @param array<string, mixed> $content
     */
    public function writeContent(SlideInterface $slide, array $content): SlideInterface
    {
        return $this->slides->writeContent($slide, $content);
    }

    /** Copy one slide, placed right after the one it copies. */
    public function duplicateSlide(SlideInterface $source): SlideInterface
    {
        return $this->slides->duplicateSlide($source);
    }

    /** The look and the slides of one deck, into another. */
    public function copySlides(DeckInterface $target, DeckInterface $source): void
    {
        $this->slides->copySlides($target, $source);
    }

    /**
     * Put the slides in the order given, by id.
     *
     * @param list<int> $orderedIds
     */
    public function reorderSlides(DeckInterface $deck, array $orderedIds): void
    {
        $this->slides->reorderSlides($deck, $orderedIds);
    }
}
