<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\BinaryFileServer;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catch-all serve endpoint for everything under `var/uploads/`.
 *
 * Aurora stores all files outside the Apache document root (see
 * CLAUDE.md §5bis). This controller is the
 * gateway: every URL of the shape `/uploads/{path}` is intercepted by
 * Symfony, the path-traversal guard runs, and the bytes are streamed
 * either via PHP `readfile()` (dev) or via Apache `mod_xsendfile`
 * (prod - see `XSendfileBootSubscriber`).
 *
 * **Auth model** : public by default (assets typically embedded on
 * public pages: media, profile photos, post featured images, gallery
 * thumbnails). When a sub-area needs stricter gating (OCR factures,
 * signed PDFs, notes-markdown per-user), that area defines its OWN
 * route under a backend prefix (e.g.
 * `/backend/notes/markdown/images/{filename}`) which takes precedence
 * over this catch-all. Apache still never serves the file - both
 * paths pass through PHP.
 *
 * The `{path}` is constrained to disallow `..` segments at the
 * router level, and `BinaryFileServer` re-verifies via `realpath`.
 */
final class UploadsServeController extends AbstractController
{
    public function __construct(
        private readonly BinaryFileServer $binaryFileServer,
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $uploadRoot,
    ) {}

    #[Route(
        '/uploads/{path}',
        name: 'uploads_serve',
        requirements: ['path' => '[^.][^./].*'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(string $path): Response
    {
        // Router-level guard against `..` segments. `BinaryFileServer`
        // re-checks with `realpath` but rejecting earlier is cheaper.
        if (str_contains($path, '/../') || str_starts_with($path, '../') || str_ends_with($path, '/..')) {
            throw $this->createNotFoundException();
        }

        try {
            return $this->binaryFileServer->servePublic(
                $this->binaryFileServer->path($this->uploadRoot, $path),
                $this->uploadRoot,
            );
        } catch (RuntimeException) {
            throw $this->createNotFoundException();
        }
    }
}
