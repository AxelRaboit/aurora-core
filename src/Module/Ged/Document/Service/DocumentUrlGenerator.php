<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns a {@see DocumentInterface} (or one of its responsive variants) into
 * the user-facing URL pointing at the `/uploads/{path}` catch-all, plus
 * presentation helpers (focal-point CSS, best-variant cascade).
 *
 * Lives here rather than on `AbstractDocument` so the entity stays a pure
 * domain object - URL building requires `UrlGeneratorInterface`, a
 * presentation concern entities should not depend on (CLAUDE.md §3bis).
 *
 * Sole storage URL generator since the Media library was retired in
 * Phase 5 of the Media → GED merge - see
 * `docs/aurora-core/todo/media-ged-merge.md`.
 *
 * All methods accept `null` so call sites can fold `$doc?->getPublicUrl()`
 * into a single call without re-introducing null-safe checks.
 *
 * **Two routes, one method.** A published document is addressed through the
 * public catch-all, which is what an image embedded in a page needs: one
 * stable address, cacheable, no session. Anything else is addressed through
 * `backend_ged_files`, which asks for the privilege. Callers do not choose
 * and mostly do not know - a serializer, a banner builder and an `og:image`
 * tag each ask for "the URL of this document" and get one that works for
 * whoever is entitled to it.
 *
 * The corollary is worth stating: a document that is *not* published, but
 * that somebody has pointed a public page at, now yields an address a
 * visitor cannot open. That is the withholding working, not a bug, and
 * `ged:audit-public-documents` is the command that finds those before the
 * visitors do.
 */
final readonly class DocumentUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * The route that will actually answer for this document's files.
     *
     * Read from the document rather than from the key, because the key of a
     * variant says nothing about the status of the picture it was made from.
     */
    private function routeFor(?DocumentInterface $document): string
    {
        return DocumentStatusEnum::Published === $document?->getStatus()
            ? 'uploads_serve'
            : 'backend_ged_files';
    }

    public function publicUrl(?DocumentInterface $document): ?string
    {
        $filePath = $document?->getFilePath();
        if (null === $filePath) {
            return null;
        }

        return $this->urlGenerator->generate($this->routeFor($document), ['path' => $filePath]);
    }

    /**
     * Absolute URL flavor for cross-origin contexts (RSS feeds, emails,
     * social sharing, JSON-LD payloads). Same null-safe contract.
     */
    public function publicUrlAbsolute(?DocumentInterface $document): ?string
    {
        $filePath = $document?->getFilePath();
        if (null === $filePath) {
            return null;
        }

        return $this->urlGenerator->generate(
            $this->routeFor($document),
            ['path' => $filePath],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * The still that stands for a document, when it has one.
     *
     * Written for PDFs, which get a rendered first page; a video uses the same
     * field for its poster frame, so a player can show something before a byte
     * of the film is fetched.
     */
    public function thumbnailPathUrl(?DocumentInterface $document): ?string
    {
        $path = $document?->getThumbnailPath();

        if (null === $path || '' === $path) {
            return null;
        }

        return $this->urlGenerator->generate($this->routeFor($document), ['path' => $path]);
    }

    public function variantUrl(?DocumentInterface $document, string $variant): ?string
    {
        if (!$document instanceof DocumentInterface) {
            return null;
        }

        $path = $document->getVariants()[$variant] ?? null;

        return null === $path
            ? null
            : $this->urlGenerator->generate($this->routeFor($document), ['path' => $path]);
    }

    /**
     * Best variant for thumbnail-size display: tries `medium` first, falls
     * back to `large`, finally to the original. Matches the cascade most
     * consumers do inline against MediaUrlGenerator.
     */
    public function thumbUrl(?DocumentInterface $document): ?string
    {
        if (!$document instanceof DocumentInterface) {
            return null;
        }

        return $this->variantUrl($document, 'medium')
            ?? $this->variantUrl($document, 'large')
            ?? $this->publicUrl($document);
    }

    /**
     * Returns a CSS `object-position` value like "50% 25%" based on the
     * focal point, or "50% 50%" (centered) when no focal point is set.
     */
    public function focalPositionCss(?DocumentInterface $document): string
    {
        $focalX = $document?->getFocalX();
        $focalY = $document?->getFocalY();

        $x = null !== $focalX ? round($focalX * 100, 2) : 50;
        $y = null !== $focalY ? round($focalY * 100, 2) : 50;

        return sprintf('%s%% %s%%', $x, $y);
    }
}
