<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\StoredFileResponder;
use Aurora\Module\Notes\Comment\Entity\NoteCommentInterface;
use Aurora\Module\Notes\Comment\Service\NoteComments;
use Aurora\Module\Notes\Comment\Service\NoteMentions;
use Aurora\Module\Notes\Live\Service\NoteGuestIdentity;
use Aurora\Module\Notes\Live\Service\NoteLiveHub;
use Aurora\Module\Notes\Live\Service\NotePresence;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHistory;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Markdown\View\MarkdownNoteDisplay;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly MarkdownNoteImageService $imageService,
        private readonly StoredFileResponder $storedFileResponder,
        private readonly MarkdownNoteManagerInterface $noteManager,
        private readonly MarkdownNoteHistory $history,
        // Autowired by parameter name: `$notesShareWriteLimiter` resolves to
        // the `notes_share_write` limiter declared in config, the way the
        // contract pages reach theirs.
        private readonly RateLimiterFactoryInterface $notesShareWriteLimiter,
        private readonly NoteLiveHub $liveHub,
        private readonly NotesContext $notesContext,
        private readonly NotePresence $presence,
        private readonly NoteGuestIdentity $guestIdentity,
        // `notes_share_live` and `notes_share_coedit_write`, autowired by name
        // like the one above.
        private readonly RateLimiterFactoryInterface $notesShareLiveLimiter,
        private readonly RateLimiterFactoryInterface $notesShareCoeditWriteLimiter,
        private readonly MarkdownNoteDisplay $display,
        private readonly NoteComments $comments,
        private readonly NoteMentions $mentions,
        private readonly TranslatorInterface $translator,
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
    public function show(string $token, Request $request): Response
    {
        $link = $this->shareLinks->resolveUsable($token);

        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->unavailable();
        }

        return $this->withGuestIdentity(
            $this->render('@Notes/share/show.html.twig', $this->pageView($link, $link->getNote(), $this->scope->notesFor($link))),
            $link,
            $request,
        );
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
    public function note(string $token, int $id, Request $request): Response
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

        // The page itself, asking for a note it includes (`![[Note]]`,
        // 09/10/2026): the same scope as the page, so only a note this link
        // already shows; its text, nothing more.
        if ($request->isXmlHttpRequest() && 'json' === $request->getPreferredFormat()) {
            $response = $this->jsonSuccess([
                'note' => [
                    'id' => (int) $note->getId(),
                    'title' => $note->getTitle(),
                    'content' => $note->getContent(),
                ],
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        return $this->withGuestIdentity(
            $this->render('@Notes/share/show.html.twig', $this->pageView($link, $note, $scope)),
            $link,
            $request,
        );
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
        $key = $this->imageService->keyOrNull($filename, $this->imageService->bucketOf($link->getNote()));

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

        // A write-back sent for a live room says so, and counts against its
        // own, wider limit: a session writes on every pause in the typing.
        // Chosen before the token is looked up, like the limiter always was,
        // and only ever honoured on a link whose live co-editing is ticked -
        // see below - so claiming it buys nothing on any other link.
        $payload = $this->decodeJson($request);
        $coedit = true === ($payload['coedit'] ?? false);
        $limiter = $coedit ? $this->notesShareCoeditWriteLimiter : $this->notesShareWriteLimiter;

        if (!$limiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('notes.markdown.share.errors.too_many_writes', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->shareLinks->resolveUsable($token);
        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->jsonNotFound();
        }

        if ($coedit && !$link->allowsCoediting()) {
            return $this->jsonNotFound();
        }

        // The link's own note, taken from the link and not from the id: the
        // id is only ever checked against it. Deliberately **not** the share's
        // scope - `notesFor()` reads and decrypts the owner's whole notebook
        // to follow `[[links]]`, and no note it finds that way is writable
        // anyway. An unauthenticated request should touch as little of
        // somebody's notes as the answer needs.
        $note = $link->getNote();
        // A locked note is read, not written, by a link too (09/10/2026).
        if (!$link->canWriteNote($note, new DateTimeImmutable()) || $note->getId() !== $id || $note->isLocked()) {
            return $this->jsonNotFound();
        }

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

        $before = $note->getContent();
        $this->noteManager->updateText($note, $title, $content);

        // Somebody mentioned in what the guest wrote is told, under the
        // link's name: the only one there is (09/10/2026).
        $this->mentions->notifyNew($note, $before, $content, $this->guestName($link));

        // The people inside see the guest's save arrive, named by the link
        // they came through - which is the only name there is.
        $this->liveHub->publishChanged($note, $link->getRecipientEmail() ?: ($link->getLabel() ?: null));

        return $this->jsonSuccess(['version' => $note->getVersion()]);
    }

    /**
     * A guest on a note whose link opens live co-editing: "I am here", answered
     * with everything the page needs to write with the others.
     *
     * The guest twin of the back office's beat, and the same answer, so the
     * page runs the very same live code. Every check of the write route holds
     * here, in the same order - the installation's switch, then the limiter
     * before any query, then a usable link that writes its own note - plus the
     * link's own "live co-editing" box: a link without it writes the old way,
     * type then save, and has no room to enter.
     *
     * **Who the guest is comes from the server.** {@see NoteGuestIdentity}
     * hands them an id above every account, kept in a cookie scoped to this
     * link; the page is told it and never chooses it.
     */
    #[Route(
        '/{token}/{id}/live',
        name: '_live',
        requirements: ['token' => '[A-Za-z0-9]{32,64}', 'id' => '\d+|__id__'],
        methods: [HttpMethodEnum::Post->value],
    )]
    public function live(string $token, int $id, Request $request): JsonResponse
    {
        if (!$this->notesContext->isCollaborationEnabled()) {
            return $this->jsonNotFound();
        }

        if (!$this->notesShareLiveLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('notes.markdown.share.errors.too_many_writes', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->shareLinks->resolveUsable($token);
        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->jsonNotFound();
        }

        $note = $link->getNote();
        if (!$link->allowsCoediting() || !$link->canWriteNote($note, new DateTimeImmutable()) || $note->getId() !== $id) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);

        // The guest's page says it leaves: out of the room now, the way the
        // back office's beat does it. Without an identity there is nobody to
        // take out.
        if (true === ($payload['leaving'] ?? false)) {
            $leavingId = $this->guestIdentity->idFrom($request);
            if (null !== $leavingId) {
                $this->presence->leave($note, $leavingId);
                $this->liveHub->publishPresence($note, $this->presence->on($note));
            }

            return $this->jsonSuccess(['left' => true]);
        }

        $guestId = $this->guestIdentity->idFrom($request) ?? $this->guestIdentity->issue();

        $others = $this->presence->beatAsGuest($note, $guestId, true === ($payload['editing'] ?? false));

        // Everybody, this guest included: each page drops itself from a
        // pushed room, exactly as in the back office.
        $this->liveHub->publishPresence($note, $this->presence->on($note));

        $response = $this->jsonSuccess([
            'people' => $others,
            'selfUserId' => $guestId,
            // No name: a guest is "Guest" in each reader's own language, and
            // the link's label - often a recipient's address - is not for the
            // room to read.
            'selfName' => null,
            'streamUrl' => $this->liveHub->subscribeUrl($note),
            'awareness' => $this->liveHub->awarenessGrant($note),
            'beatSeconds' => NotePresence::BEAT_SECONDS,
            'version' => $note->getVersion(),
            'coediting' => true,
        ]);

        $subscription = $this->liveHub->subscriptionCookie($note);
        if ($subscription instanceof Cookie) {
            $response->headers->setCookie($subscription);
        }

        // Scoped to this link's own pages: the guest is the same person from
        // one beat to the next, and no other route ever sees the number.
        $response->headers->setCookie($this->guestIdentity->cookieFor(
            $guestId,
            $this->generateUrl('notes_share', ['token' => $token]),
        ));

        return $response;
    }

    /**
     * The guest's identity, set with the page itself on a live link.
     *
     * **Before the first beat, not by it.** The page beats as it starts and
     * again as it opens the field, two requests at once; issued by the beat,
     * the identity was minted twice, and the room kept a ghost guest for fifty
     * seconds - a ghost with the lowest guest id, which then owed the
     * newcomer an answer it could never give. Set here, every beat carries it.
     */
    private function withGuestIdentity(Response $response, MarkdownNoteShareLinkInterface $link, Request $request): Response
    {
        if (!$link->allowsCoediting() || !$this->notesContext->isCollaborationEnabled()) {
            return $response;
        }

        $guestId = $this->guestIdentity->idFrom($request) ?? $this->guestIdentity->issue();
        $response->headers->setCookie($this->guestIdentity->cookieFor(
            $guestId,
            $this->generateUrl('notes_share', ['token' => $link->getToken()]),
        ));

        return $response;
    }

    /**
     * The comments of a shared note (09/10/2026), on a link that writes: a
     * link for reading shows the note, not the discussion around it. A guest
     * comments under the name they give; nothing else is asked of them.
     */
    #[Route(
        '/{token}/{id}/comments',
        name: '_comments',
        requirements: ['token' => '[A-Za-z0-9]{32,64}', 'id' => '\d+'],
        methods: [HttpMethodEnum::Get->value, HttpMethodEnum::Post->value],
    )]
    public function comments(string $token, int $id, Request $request): JsonResponse
    {
        if (!$this->notesContext->isCollaborationEnabled()) {
            return $this->jsonNotFound();
        }

        $posting = $request->isMethod(HttpMethodEnum::Post->value);
        if ($posting && !$this->notesShareWriteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure('notes.markdown.share.errors.too_many_writes', HttpStatusEnum::TooManyRequests->value);
        }

        $link = $this->shareLinks->resolveUsable($token);
        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->jsonNotFound();
        }

        $note = $link->getNote();
        if ($note->getId() !== $id || !$link->canWriteNote($note, new DateTimeImmutable())) {
            return $this->jsonNotFound();
        }

        if ($posting) {
            $payload = $this->decodeJson($request);
            $body = mb_trim($this->text($payload['body'] ?? null) ?? '');
            if ('' === $body) {
                return $this->jsonInvalidInput(['body' => 'notes.markdown.comments.empty']);
            }

            $parent = null;
            if (is_int($payload['parentId'] ?? null)) {
                $parent = $this->comments->find($payload['parentId']);
                if (!$parent instanceof NoteCommentInterface || $parent->getNote()->getId() !== $note->getId()) {
                    return $this->jsonNotFound();
                }
            }

            $name = mb_substr(mb_trim($this->text($payload['guestName'] ?? null) ?? ''), 0, 80);
            $this->comments->add($note, null, '' === $name ? null : $name, $this->text($payload['quote'] ?? null), $body, $parent);
        }

        return $this->jsonSuccess(['threads' => $this->comments->threads($note)]);
    }

    /** How a guest is named to the people inside: the link's recipient or label. */
    private function guestName(MarkdownNoteShareLinkInterface $link): string
    {
        return $link->getRecipientEmail() ?: ($link->getLabel() ?: $this->translator->trans('notes.markdown.comments.guest'));
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
            'display' => $this->display->describe($note),
            // The rest of what the note is made of (09/10/2026): its tags and
            // when it last changed. Its folder stays out - it would show a
            // guest how the author files things, which the link never gave.
            'meta' => [
                'tags' => array_values($note->getTags()),
                'updatedAt' => $note->getUpdatedAt()->format(DateTimeInterface::ATOM),
            ],
            // Whether this page may write, and where to. Both come from the
            // link, never from the request: the page is told what it may do
            // rather than asked to find out.
            'canWrite' => $this->notesContext->isCollaborationEnabled() && $link->canWriteNote($note, new DateTimeImmutable()) && !$note->isLocked(),
            'saveNotePath' => $this->generateUrl('notes_share_save', [
                'token' => $token,
                'id' => (int) $note->getId(),
            ]),
            'noteVersion' => $note->getVersion(),
            // Comments on a link that writes, locked note or not: a comment
            // changes nothing in the text (09/10/2026).
            'commentsPath' => $this->notesContext->isCollaborationEnabled() && $link->canWriteNote($note, new DateTimeImmutable())
                ? $this->generateUrl('notes_share_comments', ['token' => $token, 'id' => (int) $note->getId()])
                : '',
            // Live co-editing, when the link opens it on its own note and a
            // hub is there to carry it. The page then joins the room through
            // `liveBeatPath`; without either, it writes the old way and never
            // beats - rather than announce a live session that cannot start.
            'coediting' => $this->notesContext->isCollaborationEnabled()
                && $this->liveHub->isEnabled()
                && $link->allowsCoediting()
                && $link->canWriteNote($note, new DateTimeImmutable())
                && !$note->isLocked(),
            'liveBeatPath' => $this->generateUrl('notes_share_live', [
                'token' => $token,
                'id' => '__id__',
            ]),
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
