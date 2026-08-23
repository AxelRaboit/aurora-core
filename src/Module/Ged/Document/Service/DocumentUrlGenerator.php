<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
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
 */
final readonly class DocumentUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function publicUrl(?DocumentInterface $document): ?string
    {
        $filePath = $document?->getFilePath();
        if (null === $filePath) {
            return null;
        }

        return $this->urlGenerator->generate('uploads_serve', ['path' => $filePath]);
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
            'uploads_serve',
            ['path' => $filePath],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function variantUrl(?DocumentInterface $document, string $variant): ?string
    {
        if (!$document instanceof DocumentInterface) {
            return null;
        }

        $path = $document->getVariants()[$variant] ?? null;

        return null === $path
            ? null
            : $this->urlGenerator->generate('uploads_serve', ['path' => $path]);
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
