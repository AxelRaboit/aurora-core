<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Live\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Live\Service\NoteLiveHub;
use Aurora\Module\Notes\Live\Service\NotePresence;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One call that says "I am on this note" and answers with everything the page
 * needs to stay live.
 *
 * **One call rather than three.** The page arrives wanting to know who else is
 * here, where to listen, and whether the note has moved since it loaded. Those
 * three answers change together and are read together, so splitting them would
 * be three round trips for one truth - and, without a hub, three times the
 * polling.
 *
 * **It is also the heartbeat.** The same call records that the page is still
 * open. There is no "leaving" call, which is the one thing about presence that
 * cannot be made reliable: a closed laptop sends nothing. A page that stops
 * calling drops out of the list on its own.
 *
 * Reading the note is enough to be in the room. Being in the room is not
 * writing: the editor is opened by the ordinary route, which asks the ordinary
 * question.
 */
#[Route('/suite/notes/markdown/live', name: 'suite_notes_markdown_live')]
#[IsGranted('notes.markdown.use')]
final class NoteLiveController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly NotePresence $presence,
        private readonly NoteLiveHub $hub,
    ) {}

    /**
     * `__id__` is allowed by the requirement because the view builder
     * generates this path once, as a template the front fills in per note.
     */
    #[Route('/{id}', name: '_beat', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function beat(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);
        $editing = true === ($payload['editing'] ?? false);

        $others = $this->presence->beat($note, $user, $editing);

        // Pushed to the others with everybody in it, this reader included:
        // each page drops itself from the list it receives, and a push that
        // left the sender out would make them disappear from their
        // neighbours' screens every time somebody else arrived.
        $this->hub->publishPresence($note, $this->presence->on($note));

        $response = $this->jsonSuccess([
            'people' => $others,
            // Who this reader is, so the page can drop itself from a pushed
            // room: the beat's own answer already excludes them, but a push
            // carries everybody by design.
            'selfUserId' => $user->getId(),
            // Carried by the cursor this page publishes, so the others can
            // label it. The room already names everybody; this is the same
            // name, said by the one page that is sure of it.
            'selfName' => $user->getName(),
            // Null when no hub is running, which is what tells the page to
            // keep asking instead of opening a connection to nothing.
            'streamUrl' => $this->hub->subscribeUrl($note),
            // What the page needs to say where its own cursor is: an address,
            // a topic, and a token that may publish on that topic alone. Null
            // without a hub, and the page then shows no cursors rather than
            // half of them.
            'awareness' => $this->hub->awarenessGrant($note),
            'beatSeconds' => NotePresence::BEAT_SECONDS,
            // So a page that just connected notices it is behind without
            // waiting for somebody else's save to be pushed.
            'version' => $note->getVersion(),
        ]);

        $cookie = $this->hub->subscriptionCookie($note);
        if ($cookie instanceof Cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
