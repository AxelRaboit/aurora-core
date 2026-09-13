<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

/**
 * Says which decks draw a given picture, before somebody deletes it.
 *
 * The library's deletion screen asks every tagged provider "who is using
 * this", and until now nobody answered for decks: a photograph on four
 * slides could be deleted with the screen reporting no usage at all, and the
 * four slides would quietly draw nothing. `AuditPublicDocumentsCommand` says
 * as much in its own docblock - the registry that would have told it had no
 * implementations.
 *
 * **Scanned rather than joined**, which is the one place this provider
 * differs from the ones the interface was written for. Those reference a
 * document through a Doctrine relation, so their query is exact and
 * refactor-safe; a slide holds its picture as an integer inside its content
 * JSON, because a column per slot would mean a migration per layout. The
 * lookup is therefore a walk over decks, and `DeckPictures` is what decides
 * which slots count - one list, so a fourth picture slot is covered here the
 * day it is declared there.
 *
 * A walk and not an index, because this runs when somebody clicks a document
 * in the library and a project holds decks by the dozen. The day that stops
 * being true, the shape to reach for is a JSON index on the content column,
 * which is driver-specific and not worth its migration yet.
 */
final readonly class DeckDocumentUsageProvider implements DocumentUsageProviderInterface
{
    public function __construct(
        private DeckRepository $decks,
        private DeckPictures $pictures,
        private UrlGeneratorInterface $urlGenerator,
        // `detail` reaches the screen as-is, so it is translated here. The
        // interface's docblock shows literals because it was written before
        // anybody implemented it.
        private TranslatorInterface $translator,
    ) {}

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach ($this->decks->findAllForList() as $deck) {
            if (!in_array($documentId, $this->pictures->idsUsedBy($deck), true)) {
                continue;
            }

            $usages[] = [
                'type' => 'studio.deck',
                'label' => $deck->getTitle(),
                'detail' => $this->translator->trans('backend.studio.decks.usage_detail'),
                'href' => $this->urlGenerator->generate('backend_studio_deck', ['id' => $deck->getId()]),
            ];
        }

        return $usages;
    }
}
