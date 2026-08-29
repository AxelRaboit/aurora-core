<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading a note without an account.
 *
 * Unauthenticated by design: the address is the credential, which is the model
 * every note application uses for "anyone with the link". Two properties hold
 * it together, and both are enforced here rather than trusted:
 *
 * - **Nothing in the request widens the view.** The note ids a guest may reach
 *   come from {@see SharedNoteScope}, computed from the link. Asking for
 *   another id returns 404 whether or not that note exists.
 * - **Every failure looks the same.** Unknown, expired and revoked tokens all
 *   render one page. Telling them apart tells a stranger which guesses landed.
 *
 * Read-only, and there is no write route to gate: see
 * `project_notes_share_link_read_only` for what is deliberately absent, the
 * rate limit included.
 */
#[Route('/notes/share', name: 'notes_share')]
final class NoteShareController extends AbstractController
{
    public function __construct(
        private readonly MarkdownNoteShareLinkManagerInterface $shareLinks,
        private readonly SharedNoteScope $scope,
        private readonly MarkdownNoteImageService $images,
    ) {}

    /**
     * The token's alphabet is constrained in the route, so a path carrying
     * anything else never reaches a query.
     */
    #[Route(
        '/{token}',
        name: '',
        requirements: ['token' => '[A-Za-z0-9]{32,64}'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function show(string $token): Response
    {
        $link = $this->shareLinks->resolveUsable($token);

        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->unavailable();
        }

        return $this->render('@Notes/share/show.html.twig', $this->pageView($link, $link->getNote()));
    }

    /**
     * Another note from the same share, reached by following a `[[link]]`.
     *
     * The id is checked against the link's scope, never looked up on its own.
     *
     * `__id__` is allowed by the requirement so the page can be handed one path
     * template to fill in per link, rather than a path per note. It reaches the
     * action as the integer 0, which is in no scope, so it 404s like any other
     * id the link does not carry.
     */
    #[Route(
        '/{token}/{id}',
        name: '_note',
        requirements: ['token' => '[A-Za-z0-9]{32,64}', 'id' => '\d+|__id__'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function note(string $token, int $id): Response
    {
        $link = $this->shareLinks->resolveUsable($token);

        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->unavailable();
        }

        $note = $this->scope->noteInScope($link, $id);

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->unavailable();
        }

        return $this->render('@Notes/share/show.html.twig', $this->pageView($link, $note));
    }

    /**
     * An image embedded in a shared note.
     *
     * Without this route every picture in a shared note is a broken icon: the
     * backend one builds its path from the *current user's* directory and a
     * guest has no user. The path is built from the note owner's directory
     * instead, and the same traversal guard applies - the filename cannot
     * escape it.
     */
    #[Route(
        '/{token}/images/{filename}',
        name: '_image',
        requirements: ['token' => '[A-Za-z0-9]{32,64}', 'filename' => '[^/]+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function image(string $token, string $filename): Response
    {
        $link = $this->shareLinks->resolveUsable($token);

        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            throw $this->createNotFoundException();
        }

        try {
            // The backend service's own traversal guard is what makes this safe:
            // it resolves the realpath and refuses anything that leaves the
            // owner's directory. Reusing it means the guest route cannot drift
            // away from the rule the authenticated one enforces.
            $path = $this->images->path($filename, $link->getNote()->getUser());
        } catch (RuntimeException) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageView(MarkdownNoteShareLinkInterface $link, MarkdownNoteInterface $note): array
    {
        $scope = $this->scope->notesFor($link);

        $token = $link->getToken();

        return [
            'token' => $token,
            'note' => $note,
            // Generated here rather than spelled out in the JS: a path written by
            // hand in a front-end file has no way of noticing when its route
            // moves, and a dead one 404s quietly instead of failing.
            'imagePrefix' => str_replace(
                '__filename__',
                '',
                $this->generateUrl('backend_notes_markdown_images_serve', ['filename' => '__filename__']),
            ),
            'shareImagePath' => $this->generateUrl('notes_share_image', [
                'token' => $token,
                'filename' => '__filename__',
            ]),
            'shareNotePath' => $this->generateUrl('notes_share_note', [
                'token' => $token,
                'id' => '__id__',
            ]),
            'noteCount' => count($scope),
            // The tree is handed to the page so a shared branch can be navigated,
            // and it carries titles and ids only - never the bodies of notes the
            // reader has not opened.
            'tree' => array_map(static fn (MarkdownNoteInterface $n): array => [
                'id' => (int) $n->getId(),
                'title' => $n->getTitle(),
                'parentId' => $n->getParent()?->getId(),
            ], $scope),
            'titleIndex' => $this->scope->titleIndex($link),
        ];
    }

    private function unavailable(): Response
    {
        // 404 rather than 410: "gone" would confirm that this address was once
        // real, which is one bit more than a stranger should get.
        return $this->render('@Notes/share/unavailable.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
