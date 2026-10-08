<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_keys;
use function array_map;
use function fclose;
use function fopen;
use function fread;
use function in_array;
use function is_array;
use function is_string;
use function mb_strtolower;
use function pathinfo;
use function preg_match;
use function sprintf;

use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

/**
 * The font files somebody uploaded, as families a free slide can name.
 *
 * **A font is a document of the library**, filed like any other upload, so it
 * is found, renamed and deleted where every other file is. What this class adds
 * is the reading of it as a family: the key a text box stores (`upload-<id>`),
 * the name the picker shows, and the address the slide loads it from.
 *
 * **Served from the application's own origin** by `DeliverableFontsController`, never
 * through `/uploads`. The content security policy lets a page load fonts from
 * itself alone, and a library kept on object storage answers `/uploads` with a
 * redirect to another host - which `font-src` refuses without a word, leaving
 * the slide in its fallback face.
 *
 * A file is a font when its first bytes say so. The extension is what a person
 * typed and the mime type is what the server guessed - `font/sfnt`,
 * `application/octet-stream` and `font/ttf` all describe the same file - but
 * the four signatures below are what the browser will actually parse.
 */
final readonly class DeckFonts
{
    /** The formats a browser loads with `@font-face`, by extension. */
    public const array EXTENSIONS = ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'];

    /** The first four bytes of each, which no renamed file can fake by accident. */
    private const array SIGNATURES = ['wOF2', 'wOFF', "\x00\x01\x00\x00", 'true', 'OTTO'];

    public function __construct(
        private DocumentRepository $documentRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** Whether an uploaded file is a font a browser can load. */
    public function isFontFile(File $file, string $originalName): bool
    {
        if (!isset(self::EXTENSIONS[$this->extension($originalName)])) {
            return false;
        }

        $handle = @fopen($file->getPathname(), 'rb');

        if (false === $handle) {
            return false;
        }

        $head = (string) fread($handle, 4);
        fclose($handle);

        return in_array($head, self::SIGNATURES, true);
    }

    /** Whether a document of the library is one of these fonts. */
    public function isFontDocument(?DocumentInterface $document): bool
    {
        return $document instanceof DocumentInterface
            && isset(self::EXTENSIONS[$this->extension($document->getOriginalName() ?? $document->getFilePath() ?? '')]);
    }

    /** The type a font is served with, from its extension. */
    public function contentTypeOf(DocumentInterface $document): string
    {
        return self::EXTENSIONS[$this->extension($document->getOriginalName() ?? $document->getFilePath() ?? '')] ?? 'application/octet-stream';
    }

    /**
     * Every uploaded font, for the picker.
     *
     * @return list<array{key: string, name: string, url: string}>
     */
    public function all(): array
    {
        $alias = 'document';
        $builder = $this->documentRepository->createQueryBuilder($alias);
        $extensionConditions = $builder->expr()->orX();

        foreach (array_keys(self::EXTENSIONS) as $extension) {
            $extensionConditions->add($builder->expr()->like(sprintf('LOWER(%s.originalName)', $alias), $builder->expr()->literal('%.'.$extension)));
        }

        /** @var list<DocumentInterface> $fonts */
        $fonts = $builder->where($extensionConditions)->orderBy($alias.'.originalName', 'ASC')->getQuery()->getResult();

        return array_map($this->describe(...), $fonts);
    }

    /**
     * The uploaded fonts a deck's slides name, which is what a share link needs
     * and nothing more: a stranger reading one deck has no business listing
     * every font of the installation.
     *
     * @return list<array{key: string, name: string, url: string}>
     */
    public function usedBy(DeliverableInterface $deck): array
    {
        $ids = [];

        foreach ($deck->getSlides() as $slide) {
            $elements = $slide->getContent()['elements'] ?? null;

            if (!is_array($elements)) {
                continue;
            }

            foreach ($elements as $element) {
                $font = is_array($element) ? ($element['font'] ?? null) : null;

                if (is_string($font) && 1 === preg_match('/^upload-(\d+)$/', $font, $matches)) {
                    $ids[(int) $matches[1]] = true;
                }
            }
        }

        if ([] === $ids) {
            return [];
        }

        $fonts = [];

        foreach ($this->documentRepository->findBy(['id' => array_keys($ids)]) as $document) {
            if ($this->isFontDocument($document)) {
                $fonts[] = $this->describe($document);
            }
        }

        return $fonts;
    }

    /** @return array{key: string, name: string, url: string} */
    public function describe(DocumentInterface $document): array
    {
        $name = pathinfo($document->getOriginalName() ?? $document->getTitle(), PATHINFO_FILENAME);

        return [
            'key' => sprintf('upload-%d', (int) $document->getId()),
            'name' => '' !== $name ? $name : $document->getTitle(),
            'url' => $this->urlGenerator->generate('public_deliverable_font', ['id' => $document->getId()]),
        ];
    }

    private function extension(string $name): string
    {
        return mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
