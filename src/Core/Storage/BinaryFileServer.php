<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Centralised builder for `BinaryFileResponse` returned by every
 * `*_serve` controller (Media, profile photos, OCR, PDF, notes images,
 * galleries, …).
 *
 * Encapsulates three concerns that were duplicated across modules:
 *
 *  1. **Path-traversal guard** - `serve()` accepts the absolute path
 *     plus the absolute allow-list root, refuses anything that doesn't
 *     resolve under that root (`realpath` based).
 *  2. **Cache headers** - public/private + max-age, with an opinionated
 *     default of `private, max-age=3600` (auth-gated assets) and an
 *     explicit `public(...)` factory for assets meant to be CDN-cached.
 *  3. **X-Sendfile offload** - `BinaryFileResponse::trustXSendfileTypeHeader()`
 *     is enabled at boot, so this helper just sets the header. In dev
 *     (no `mod_xsendfile`) Symfony falls back to `readfile()` -
 *     transparent.
 *
 * Stateless and `final readonly` - pure helper, no DI.
 */
final readonly class BinaryFileServer
{
    /**
     * Build a response that serves `$absolutePath`, after checking it
     * resolves inside `$allowedRoot`. Caller is responsible for the
     * auth/ownership check before reaching here.
     *
     * @param string  $absolutePath full filesystem path of the file to serve
     * @param string  $allowedRoot  absolute path that `$absolutePath` must reside under
     * @param string  $cacheControl Cache-Control header value (default: private, 1h)
     * @param ?string $downloadName when set, prompts a download with this filename
     *
     * @throws RuntimeException when the file is missing, unreadable, or escapes the allowed root
     */
    public function serve(
        string $absolutePath,
        string $allowedRoot,
        string $cacheControl = 'private, max-age=3600',
        ?string $downloadName = null,
    ): BinaryFileResponse {
        $real = realpath($absolutePath);
        if (false === $real || !is_file($real)) {
            throw new RuntimeException(sprintf('File not found: %s', $absolutePath));
        }

        $rootReal = realpath($allowedRoot);
        if (false === $rootReal) {
            throw new RuntimeException(sprintf('Allowed root does not exist: %s', $allowedRoot));
        }

        // The file must reside *under* the allowed root - never alongside
        // or above. We compare with a trailing separator so a path that
        // simply shares a prefix (e.g. `/var/uploadsX/...`) is rejected.
        $normalisedRoot = mb_rtrim($rootReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (!str_starts_with($real, $normalisedRoot)) {
            throw new RuntimeException(sprintf('Path escapes allowed root: %s', $absolutePath));
        }

        $response = new BinaryFileResponse($real);
        $response->headers->set('Cache-Control', $cacheControl);

        if (null !== $downloadName) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);
        }

        return $response;
    }

    /**
     * Convenience for public assets (CDN-cacheable, no auth). Uses the
     * Response API (`setPublic` + `setMaxAge`) rather than a raw header
     * so Symfony's session listener doesn't downgrade it to `private`
     * when a logged-in user requests the file.
     */
    public function servePublic(string $absolutePath, string $allowedRoot): BinaryFileResponse
    {
        $response = $this->serve($absolutePath, $allowedRoot, '');
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->headers->addCacheControlDirective('immutable');

        return $response;
    }

    /**
     * Join a relative path under a root, returning the absolute path
     * suitable to hand to `serve()`. Does not check existence - the
     * caller's `serve()` call does. Useful for the common pattern
     * `$server->serve($server->path($root, $userInput), $root)`.
     */
    public function path(string $root, string $relativePath): string
    {
        return Path::join($root, $relativePath);
    }
}
