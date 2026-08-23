<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds public URLs for any file stored under `var/uploads/` - wraps the
 * `uploads_serve` route so callers don't hardcode the `/uploads/` prefix.
 * Generic equivalent of `MediaUrlGenerator`, usable by any module that
 * owns its own file storage (GED, Welding PdfDocument, etc.) without
 * pulling in a Media entity.
 *
 * Inject and call `publicUrl($document->getFilePath())` from serializers.
 */
final readonly class UploadUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param string|null $relativePath path relative to `var/uploads/` (e.g.
     *                                  `ged/documents/2026/05/contract.pdf`)
     */
    public function publicUrl(?string $relativePath): ?string
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        return $this->urlGenerator->generate('uploads_serve', ['path' => $relativePath]);
    }

    /** Absolute URL flavor for cross-origin contexts (emails, feeds, JSON-LD). */
    public function publicUrlAbsolute(?string $relativePath): ?string
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        return $this->urlGenerator->generate(
            'uploads_serve',
            ['path' => $relativePath],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
