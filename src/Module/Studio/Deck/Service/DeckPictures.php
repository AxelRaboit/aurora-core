<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;

use function array_unique;
use function array_values;
use function is_int;
use function sprintf;

/**
 * Which pictures a deck points at, and which of them a stranger cannot see.
 *
 * **One place that knows where a deck keeps picture ids**, because there are
 * now three: a slide's own picture, the backdrop behind any slide, and the
 * logo in the deck's style. The serializer resolves them in one query and the
 * share panel asks which of them are withheld; a second list would drift the
 * day a fourth slot appears, and drift silently - the missing picture would
 * simply not be warned about.
 */
final readonly class DeckPictures
{
    /** The content slots that hold a document id. */
    private const array SLIDE_SLOTS = ['mediaId', 'bgMediaId'];

    public function __construct(private DocumentRepository $documents) {}

    /**
     * Every document id this deck draws, slides and style together, once each.
     *
     * @return list<int>
     */
    public function idsUsedBy(DeckInterface $deck): array
    {
        $ids = [];

        foreach ($deck->getSlides() as $slide) {
            foreach (self::SLIDE_SLOTS as $slot) {
                $id = $slide->getContent()[$slot] ?? null;

                if (is_int($id)) {
                    $ids[] = $id;
                }
            }

            // The layouts that carry several. Left out of `SLIDE_SLOTS`
            // because that list holds slots whose value is one id, and a
            // picture missing from here is a picture the library reports as
            // used by nobody while a slide is drawing it.
            foreach ($slide->getContent()['mediaIds'] ?? [] as $id) {
                if (is_int($id)) {
                    $ids[] = $id;
                }
            }
        }

        $logo = $deck->getStyle()['logoMediaId'] ?? null;

        if (is_int($logo)) {
            $ids[] = $logo;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The pictures a share link's holder will not be able to see.
     *
     * `/uploads/{path}` serves published documents alone, and `draft` is what
     * an upload is until somebody says otherwise. That withholding is
     * deliberate - `DocumentUrlGenerator` says so at length - and it is right:
     * a deck is not a reason to publish a file to the whole library. What was
     * missing is that nobody told the author, who sends the link and learns
     * about it from the person who received it.
     *
     * Asked at the moment of sharing rather than at composing time, because
     * until there is a link there is nobody who cannot see the picture: the
     * back office resolves the same document through `backend_ged_files`, and
     * the editor, the player and the print page all show it perfectly.
     *
     * @return list<array{id: int, name: string}>
     */
    public function withheldIn(DeckInterface $deck): array
    {
        $ids = $this->idsUsedBy($deck);

        if ([] === $ids) {
            return [];
        }

        $withheld = [];

        foreach ($this->documents->findBy(['id' => $ids]) as $document) {
            if (DocumentStatusEnum::Published === $document->getStatus()) {
                continue;
            }

            $withheld[] = [
                'id' => (int) $document->getId(),
                // A document filed without a name is not a reason to say
                // nothing: the id is what the library screen searches on.
                'name' => $document->getOriginalName() ?? sprintf('#%d', $document->getId()),
            ];
        }

        return $withheld;
    }
}
