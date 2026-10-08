<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Module\Notes\Live\Service\NoteLiveHub;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHistory;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
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
 * **A link can now write**, if somebody ticked the box when making it. That
 * makes this a write endpoint with no account behind it, so three things hold
 * it in place and all three are enforced here:
 *
 * - **A writing link writes its own note, never its scope.** `includeLinked`
 *   reaches most of a vault in three hops; it widens what a share *shows* and
 *   nothing else. {@see MarkdownNoteShareLinkInterface::canWriteNote()} is the
 *   single place that answers the question.
 * - **A rate limit on the write route**, keyed on the client IP. The outer
 *   wall only, since an attacker rotates addresses; the inner one is that
 *   every write keeps the previous state as a version first, so nothing a
 *   flood does is unrecoverable.
 * - **Only the text.** Title and body go through
 *   {@see MarkdownNoteManagerInterface::updateText()}, which cannot refile the
 *   note, retag it, or reach another row.
 */
#[Route('/notes/share', name: 'notes_share')]
final class NoteShareController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteShareLinkManagerInterface $shareLinks,
        private readonly SharedNoteScope $scope,
        private readonly MarkdownNoteImageService $images,
        private readonly StoredFileResponder $storedFileResponder,
        private readonly MarkdownNoteManagerInterface $noteManager,
        private readonly MarkdownNoteHistory $history,
        // Autowired by parameter name: `$notesShareWriteLimiter` resolves to
        // the `notes_share_write` limiter declared in config, the way the
        // contract pages reach theirs.
        private readonly RateLimiterFactoryInterface $notesShareWriteLimiter,
        private readonly NoteLiveHub $liveHub,
        private readonly NotesContext $notesContext,
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

        return $this->render('@Notes/share/show.html.twig', $this->pageView($link, $link->getNote(), $this->scope->notesFor($link)));
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

        // Walked once per request: it reads the owner's whole notebook.
        $scope = $this->scope->notesFor($link);
        $note = $this->scope->noteInScope($scope, $id);

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->unavailable();
        }

        return $this->render('@Notes/share/show.html.twig', $this->pageView($link, $note, $scope));
    }

    /**
     * An image embedded in a shared note.
     *
     * Without this route, every image of a shared note is a broken icon: the
     * back office one builds its key with the logged-in person, and a guest is
     * not one. Here the key is the note owner's, whom the token just pointed
     * to.
     *
     * The token is the only authorisation, and it was already validated
     * above: `resolveUsable` refuses a revoked or expired link. What follows
     * therefore decides nothing more, it serves.
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

        // The name's shape is checked by the service, which returns null
        // rather than build a key out of anything. Reusing the same entry
        // point as the authenticated route keeps both on the same rule.
        // The bucket is the one of the note's space: a note in a shared space
        // stores its images there, not with its author - who may no longer
        // exist anyway.
        $key = $this->images->keyOrNull($filename, $this->images->bucketOf($link->getNote()));

        if (null === $key) {
            throw $this->createNotFoundException();
        }

        return $this->storedFileResponder->respond($key);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * A guest rewriting the note their link opens.
     *
     * The order of the checks is the point. The limiter runs **before** the
     * token is looked up, so a flood of guesses costs one counter rather than
     * one query each. Then the link has to resolve, then it has to be the
     * link's own note and the link has to be allowed to write - all three
     * answered by the entity, not here. Only then is the payload read.
     *
     * Conflicts behave like the back office's: a save that started from an
     * outdated version is refused with a 409 carrying the current one, and the
     * page decides. `force` goes through, as it does inside.
     */
    #[Route(
        '/{token}/{id}/save',
        name: '_save',
        requirements: ['token' => '[A-Za-z0-9]{32,64}', 'id' => '\d+'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function save(string $token, int $id, Request $request): JsonResponse
    {
        // Switched off for the installation: an unauthenticated write left
        // running by accident is the one thing nobody wants, so it is refused
        // before anything else - including before the limiter, which has
        // nothing to protect once the door is shut.
        if (!$this->notesContext->isCollaborationEnabled()) {
            return $this->jsonNotFound();
        }

        if (!$this->notesShareWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('notes.markdown.share.errors.too_many_writes', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->shareLinks->resolveUsable($token);
        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->jsonNotFound();
        }

        // From the link's scope, never from the id alone - the same rule as
        // reading. Then the write question, which is narrower still.
        $note = $this->scope->noteInScope($this->scope->notesFor($link), $id);
        if (!$note instanceof MarkdownNoteInterface || !$link->canWriteNote($note, new DateTimeImmutable())) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);
        $title = $this->text($payload['title'] ?? null);
        $content = $this->text($payload['content'] ?? null);

        $version = isset($payload['version']) && is_numeric($payload['version']) ? (int) $payload['version'] : null;
        $force = true === ($payload['force'] ?? false);

        if (!$force && null !== $version && $version !== $note->getVersion()) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, ['conflict' => true, 'version' => $note->getVersion()]);
        }

        // The state about to be replaced becomes a version first, and it is
        // recorded as written through this link: with no account behind the
        // write, the link is the only thing that can name whoever did it.
        $this->history->beforeChange($note, $title, $content, null, $link);

        $this->noteManager->updateText($note, $title, $content);

        // The people inside see the guest's save arrive, named by the link
        // they came through - which is the only name there is.
        $this->liveHub->publishChanged($note, $link->getRecipientEmail() ?: ($link->getLabel() ?: null));

        return $this->jsonSuccess(['version' => $note->getVersion()]);
    }

    /** A field of the payload as text, or null when it was not sent. */
    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function pageView(MarkdownNoteShareLinkInterface $link, MarkdownNoteInterface $note, array $scope): array
    {
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
                $this->generateUrl('suite_notes_markdown_images_serve', ['filename' => '__filename__']),
            ),
            'shareImagePath' => $this->generateUrl('notes_share_image', [
                'token' => $token,
                'filename' => '__filename__',
            ]),
            'shareNotePath' => $this->generateUrl('notes_share_note', [
                'token' => $token,
                'id' => '__id__',
            ]),
            // The note as it is, banner and styling included: a shared link
            // shows the note, not a stripped-down version of it. The image
            // lives with whoever hosts it, so a guest sees it without
            // anything being opened to them.
            'cover' => [
                'url' => $note->getCoverUrl(),
                'creditName' => $note->getCoverCreditName(),
                'creditUrl' => $note->getCoverCreditUrl(),
                'position' => $note->getCoverPosition(),
            ],
            'appearance' => $note->getAppearance()->value,
            // Whether this page may write, and where to. Both come from the
            // link, never from the request: the page is told what it may do
            // rather than asked to find out.
            'canWrite' => $this->notesContext->isCollaborationEnabled() && $link->canWriteNote($note, new DateTimeImmutable()),
            'saveNotePath' => $this->generateUrl('notes_share_save', [
                'token' => $token,
                'id' => (int) $note->getId(),
            ]),
            'noteVersion' => $note->getVersion(),
            'noteCount' => count($scope),
            // The list is handed to the page so a share carrying several
            // notes can be navigated, and it carries titles and ids only -
            // never the bodies of notes the reader has not opened.
            'tree' => array_map(static fn (MarkdownNoteInterface $scopedNote): array => [
                'id' => (int) $scopedNote->getId(),
                'title' => $scopedNote->getTitle(),
            ], $scope),
            'titleIndex' => $this->scope->titleIndex($scope),
        ];
    }

    private function unavailable(): Response
    {
        // 404 rather than 410: "gone" would confirm that this address was once
        // real, which is one bit more than a stranger should get.
        return $this->render('@Notes/share/unavailable.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
    }
}
