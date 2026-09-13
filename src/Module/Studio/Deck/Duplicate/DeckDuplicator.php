<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Duplicate;

use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Copies a deck, slide for slide.
 *
 * Run through the manager rather than field by field, like Editorial's post
 * duplicator and the contract template one, and for the same reason: the
 * manager is the one place that knows how a deck and its slides are wired
 * together, and a second implementation drifts the first time somebody adds a
 * field - silently, the copy simply missing something.
 *
 * What is deliberately not carried over is the point of the class:
 *
 * - **The customer stays behind.** "Start from this deck" almost always means
 *   the same shape for somebody else; carrying the client over is how a deck
 *   ends up presented to one company with another company's name on it.
 * - **The category comes along**, because it describes the kind of document
 *   rather than its recipient, and that is exactly what is being reused.
 * - **The look comes along too**, and it is most of why anybody duplicates a
 *   deck: "start from this one" means the same theme, the same accent and the
 *   same logo, with other words in it.
 * - **The title says it is a copy**, so two identical rows in the list can be
 *   told apart before either is opened.
 */
final readonly class DeckDuplicator
{
    public function __construct(
        private DeckManager $deckManager,
        private TranslatorInterface $translator,
    ) {}

    public function duplicate(DeckInterface $source): DeckInterface
    {
        $copy = $this->deckManager->create(
            $this->translator->trans('backend.studio.decks.copy_of', ['%title%' => $source->getTitle()]),
        );

        $copy->setDescription($source->getDescription());
        $copy->setCategory($source->getCategory());

        $this->deckManager->writeAppearance($copy, $source->getTheme(), $source->getStyle());

        foreach ($source->getSlides() as $slide) {
            $new = $this->deckManager->addSlide($copy, $slide->getLayout());
            $this->deckManager->writeContent($new, $slide->getContent());
            $new->setSpeakerNotes($slide->getSpeakerNotes());
        }

        return $copy;
    }
}
