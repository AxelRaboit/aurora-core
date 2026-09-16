<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use Aurora\Module\Studio\SpaceContent\Service\SpaceGuestUploadPolicy;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

use function in_array;
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
 *  4. **Refusing to execute what was uploaded** - `nosniff` on every
 *     response, and a forced download for the handful of types a browser
 *     would run as a document. See {@see EXECUTABLE_INLINE_TYPES}.
 *
 * Stateless and `final readonly` - pure helper, no DI.
 */
final readonly class BinaryFileServer
{
    /**
     * Types a browser runs as a document, and therefore never serves inline.
     *
     * Uploaded files come back from the application's own origin, so a stored
     * SVG or HTML opened in a tab is script running with the reader's session:
     * it can read the page it is on, and act as them. Forcing a download turns
     * the file back into a file.
     *
     * This costs nothing on the pages that show these assets. A
     * `Content-Disposition` on a subresource is ignored by browsers, so an
     * `<img src="logo.svg">` still draws; what changes is navigating to the
     * address directly, which is the vector.
     *
     * It was already worth having when only staff could upload. It stops being
     * optional now that a client holding a link can, which is what
     * {@see SpaceGuestUploadPolicy}
     * exists for at the other end - two walls, because the allow-list governs
     * one door and this governs every file already stored.
     *
     * @var list<string>
     */
    public const array EXECUTABLE_INLINE_TYPES = [
        'image/svg+xml',
        'text/html',
        'application/xhtml+xml',
        'text/xml',
        'application/xml',
        'text/xsl',
    ];

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

        // Always. Without it a file stored with a harmless type but HTML-shaped
        // content can still be sniffed into a document by some browsers, which
        // makes the list below a guess rather than a rule.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if (null !== $downloadName) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);
        } elseif (in_array((string) $response->getFile()->getMimeType(), self::EXECUTABLE_INLINE_TYPES, true)) {
            // Read off the file, not off the response: `BinaryFileResponse`
            // only fills `Content-Type` in `prepare()`, so asking the headers
            // here answers null and this branch would never be taken - a guard
            // that silently does nothing, which is worse than no guard.
            //
            // No name to offer - the caller did not ask for a download, this is
            // the type refusing to be shown - so the browser falls back to the
            // one in the URL, which is the stored filename.
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        }

        return $response;
    }

    /**
     * Convenience for public assets (CDN-cacheable, no auth).
     *
     * **The opt-out header is what makes this actually public**, and without
     * it the two lines under it were decoration. Symfony's session listener
     * rewrites `Cache-Control` at the end of any request that so much as
     * *reads* the session - `getUsageIndex() !== 0`, not `isStarted()` - and
     * on this application every request reads it, because `LocaleSubscriber`
     * asks the session which language to use before it knows what was
     * requested. Measured in production on 13/09/2026: a published GED image
     * came back `immutable, max-age=0, must-revalidate, private`, so every
     * picture on every public page was revalidated on every view and no
     * shared cache could hold one.
     *
     * The cruel detail is that `setPublic()` made it worse rather than
     * better: the listener computes `$maxAge = hasCacheControlDirective(
     * 'public') ? 0 : (int) $response->getMaxAge()`, so declaring the
     * response public is precisely what turned a day of caching into zero.
     *
     * `NO_AUTO_CACHE_CONTROL_HEADER` is the escape hatch Symfony documents
     * for exactly this case - "a scenario where caching responses with
     * session information in them makes sense". Here there is no session
     * information at all: the bytes are a file on disk, identical for every
     * visitor, and who may read it was already decided before we got here.
     * Symfony strips the header before the response is sent.
     *
     * Deliberately **not** applied to {@see serve()}, whose default is
     * `private` and whose callers are the auth-gated ones.
     */
    public function servePublic(string $absolutePath, string $allowedRoot): BinaryFileResponse
    {
        $response = $this->serve($absolutePath, $allowedRoot, '');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
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
