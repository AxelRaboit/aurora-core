<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Import;

use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;

use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mb_ltrim;
use function mb_substr;
use function parse_url;
use function str_replace;
use function str_starts_with;
use function urldecode;

use const PHP_URL_PATH;

/**
 * A written document, turned into slides.
 *
 * **The rule is one sentence: a heading opens a slide, each block that follows
 * fills one, and several blocks under the same heading make several slides that
 * repeat it.** Everything the reader wrote reaches a slide; nothing is dropped
 * to make the mapping tidier, because an importer that quietly loses a
 * paragraph is worse than no importer.
 *
 * **Why an importer rather than a free-text layout.** The frame is 16:9 and
 * what is arranged in it is what lands on the wall; that promise only holds
 * because the shapes are declared, which is what lets the type be measured and
 * scaled to fit. A block editor embedded in a slide is a document of arbitrary
 * height with no declared shape, so nothing could promise it fits. The editor
 * belongs where flowing text is right - writing - and hands over at the point
 * where it is not - presenting.
 *
 * Consecutive paragraphs become one bullet list rather than one slide each: a
 * paragraph under a heading is almost always a point being made, and a slide
 * per sentence is how an import produces forty slides nobody wanted.
 */
final readonly class DeckFromBlocks
{
    public function __construct(
        private DeckManager $deckManager,
        private DocumentRepository $documents,
        private BlockText $text,
    ) {}

    /**
     * Fill a deck from Editor.js blocks. Returns how many slides were written.
     *
     * `list<mixed>` and not `list<array>`: these blocks arrive as a decoded
     * request body, so nothing has promised that each one is even an array.
     * The loop says so rather than the docblock claiming it.
     *
     * @param list<mixed> $blocks
     */
    public function fill(DeckInterface $deck, array $blocks): int
    {
        $cursor = new ImportCursor();

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            if ('header' === $type) {
                $this->flushParagraphs($deck, $cursor);

                // A heading with nothing under it is a divider announcing what
                // follows, which is exactly what the section layout is for.
                if (!$cursor->filled && '' !== $cursor->title) {
                    $this->write($deck, $cursor, SlideLayoutEnum::Section, ['title' => $cursor->title]);
                }

                $cursor->title = $this->text->plain($data['text'] ?? null);
                $cursor->filled = false;

                continue;
            }

            if ('paragraph' === $type || 'code' === $type) {
                $line = $this->text->plain($data['text'] ?? $data['code'] ?? null);

                if ('' !== $line) {
                    $cursor->paragraphs[] = $line;
                }

                continue;
            }

            $this->flushParagraphs($deck, $cursor);

            match ($type) {
                'list' => $this->writeList($deck, $cursor, $data),
                'quote' => $this->writeQuote($deck, $cursor, $data),
                'table' => $this->writeTable($deck, $cursor, $data),
                'image' => $this->writeImage($deck, $cursor, $data),
                default => null,
            };
        }

        $this->flushParagraphs($deck, $cursor);

        if (!$cursor->filled && '' !== $cursor->title) {
            $this->write($deck, $cursor, SlideLayoutEnum::Section, ['title' => $cursor->title]);
        }

        return $cursor->count;
    }

    /** @param array<string, mixed> $data */
    private function writeList(DeckInterface $deck, ImportCursor $cursor, array $data): void
    {
        $items = $this->listItems($data['items'] ?? null);

        if ([] === $items) {
            return;
        }

        $this->write($deck, $cursor, SlideLayoutEnum::Bullets, ['title' => $cursor->title, 'bullets' => $items]);
    }

    /** @param array<string, mixed> $data */
    private function writeQuote(DeckInterface $deck, ImportCursor $cursor, array $data): void
    {
        $quote = $this->text->plain($data['text'] ?? null);

        if ('' === $quote) {
            return;
        }

        $this->write($deck, $cursor, SlideLayoutEnum::Quote, [
            'quote' => $quote,
            'attribution' => $this->text->plain($data['caption'] ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeTable(DeckInterface $deck, ImportCursor $cursor, array $data): void
    {
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        $rows = [];

        foreach ($content as $row) {
            if (!is_array($row)) {
                continue;
            }

            // The pipe is the slide's cell separator, so a cell that contains
            // one would silently become two. A space either side of a slash is
            // the smallest thing that reads the same and parses as one cell.
            $cells = array_map(
                fn (mixed $cell): string => str_replace('|', '/', $this->text->plain($cell)),
                array_values($row),
            );

            $rows[] = implode(' | ', $cells);
        }

        if ([] === $rows) {
            return;
        }

        $this->write($deck, $cursor, SlideLayoutEnum::Table, ['title' => $cursor->title, 'rows' => $rows]);
    }

    /** @param array<string, mixed> $data */
    private function writeImage(DeckInterface $deck, ImportCursor $cursor, array $data): void
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $document = $this->documentByUrl(is_string($file['url'] ?? null) ? $file['url'] : '');

        // A picture Aurora does not hold is a picture a slide cannot point at:
        // the slot stores a document id, precisely so the address can change
        // with the file. Its caption still reaches the deck rather than being
        // lost with it.
        if (null === $document) {
            $caption = $this->text->plain($data['caption'] ?? null);

            if ('' !== $caption) {
                $cursor->paragraphs[] = $caption;
                $this->flushParagraphs($deck, $cursor);
            }

            return;
        }

        $this->write($deck, $cursor, SlideLayoutEnum::Image, [
            'mediaId' => (int) $document->getId(),
            'caption' => $this->text->plain($data['caption'] ?? null),
        ]);
    }

    private function flushParagraphs(DeckInterface $deck, ImportCursor $cursor): void
    {
        if ([] === $cursor->paragraphs) {
            return;
        }

        $bullets = $cursor->paragraphs;
        $cursor->paragraphs = [];

        $this->write($deck, $cursor, SlideLayoutEnum::Bullets, ['title' => $cursor->title, 'bullets' => $bullets]);
    }

    /**
     * One slide, written through the manager like every other.
     *
     * **A heading is never lost because its slide has no room for one.** A
     * quote and a full-page image carry no title slot, so a heading followed by
     * either used to vanish; it now lands in the kicker, which every layout
     * accepts and which is exactly the line above the content it was.
     *
     * @param array<string, mixed> $content
     */
    private function write(DeckInterface $deck, ImportCursor $cursor, SlideLayoutEnum $layout, array $content): void
    {
        if ('' !== $cursor->title && !in_array('title', $layout->slots(), true)) {
            $content['kicker'] ??= $cursor->title;
        }

        $slide = $this->deckManager->addSlide($deck, $layout);
        $this->deckManager->writeContent($slide, array_filter($content, static fn (mixed $value): bool => '' !== $value && null !== $value));

        $cursor->filled = true;
        ++$cursor->count;
    }

    /**
     * The items of a list, whatever version of the tool wrote them.
     *
     * `@editorjs/list` v1 stored plain strings and v2 stores objects with their
     * own nested items. Both shapes are in the wild in one project the moment a
     * document written last year is re-opened, so both are read. Nesting is
     * flattened: a slide has one level of bullets.
     *
     * @return list<string>
     */
    private function listItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $lines = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $line = $this->text->plain($item);
                $nested = [];
            } elseif (is_array($item)) {
                $line = $this->text->plain($item['content'] ?? null);
                $nested = $this->listItems($item['items'] ?? null);
            } else {
                continue;
            }

            if ('' !== $line) {
                $lines[] = $line;
            }

            foreach ($nested as $child) {
                $lines[] = $child;
            }
        }

        return $lines;
    }

    /**
     * The document an editor's image block points at.
     *
     * The block keeps only the address, because that is all Editor.js asks its
     * uploader for. The address is `/uploads/{filePath}` and `filePath` is a
     * column, so the way back is a lookup rather than a guess. An image pasted
     * from another site resolves to nothing, which is the honest answer: the
     * slot holds a document id.
     */
    private function documentByUrl(string $url): ?object
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $path = urldecode($path);

        if (!str_starts_with($path, '/uploads/')) {
            return null;
        }

        $filePath = mb_ltrim(mb_substr($path, 9), '/');

        return '' === $filePath ? null : $this->documents->findOneBy(['filePath' => $filePath]);
    }
}
