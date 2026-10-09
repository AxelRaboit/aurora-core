<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteMemberManagerInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteMemberRepository;
use Aurora\Module\Notes\Share\Serializer\MarkdownNoteMemberSerializerInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The people one note was handed to, one by one.
 *
 * **Who may change this list.** Whoever the space lets administer the note,
 * through {@see NoteSpaceAccess::administrableNote()} - and so never somebody
 * who was themselves handed the note. Letting a guest add guests would make a
 * share spread without the person who started it ever deciding to, and taking
 * it back would mean finding every branch of a tree nobody drew.
 *
 * The listing carries the people one *could* add along with those already
 * there: the screen is one modal, it opens once, and asking twice to draw one
 * list is a round trip for nothing.
 */
#[Route('/suite/notes/markdown/people', name: 'suite_notes_markdown_people')]
#[IsGranted('notes.markdown.use')]
final class MarkdownNoteMembersController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly MarkdownNoteMemberRepository $memberRepository,
        private readonly MarkdownNoteMemberManagerInterface $manager,
        private readonly MarkdownNoteMemberSerializerInterface $serializer,
        private readonly UserRepository $userRepository,
        private readonly NotesContext $notesContext,
    ) {}

    /**
     * The note's guest list, and who else could be on it.
     *
     * `__id__` is allowed by the requirement because the view builder
     * generates this path once, as a template the front fills in per note.
     */
    #[Route('/{noteId}', name: '_list', requirements: ['noteId' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function list(int $noteId): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->administrableNote($user, $noteId);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess([
            'members' => array_map($this->serializer->serialize(...), $this->memberRepository->findForNote($note)),
            'people' => $this->pickableBy($user),
        ]);
    }

    /** Hands the note to somebody, or changes the role they already had. */
    #[Route('/{noteId}', name: '_set', requirements: ['noteId' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function set(int $noteId, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->administrableNote($user, $noteId);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);
        $role = NoteMemberRoleEnum::tryFrom((string) ($payload['role'] ?? ''));
        if (!$role instanceof NoteMemberRoleEnum) {
            return $this->jsonInvalidInput(['role' => 'notes.markdown.people.errors.bad_role']);
        }

        $member = isset($payload['userId']) && is_numeric($payload['userId']) ? $this->userRepository->find((int) $payload['userId']) : null;
        if (!$this->canReceive($member, $user)) {
            return $this->jsonInvalidInput(['userId' => 'notes.markdown.people.errors.bad_person']);
        }

        /* @var CoreUserInterface $member */
        return $this->jsonSuccess(['member' => $this->serializer->serialize($this->manager->setMember($note, $member, $role))]);
    }

    /** Takes the note back from somebody. */
    #[Route('/{noteId}/{userId}/remove', name: '_remove', requirements: ['noteId' => '\d+|__id__', 'userId' => '\d+|__user__'], methods: [HttpMethodEnum::Post->value])]
    public function remove(int $noteId, int $userId): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->administrableNote($user, $noteId);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $member = $this->userRepository->find($userId);
        if (!$member instanceof CoreUserInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['removed' => $this->manager->removeMember($note, $member)]);
    }

    /**
     * The note, if this installation offers sharing at all and the space
     * lets this person administer it.
     *
     * One place rather than three guards: all three routes need exactly the
     * same two answers, and a check written three times is a check that gets
     * forgotten once.
     */
    private function administrableNote(CoreUserInterface $user, int $noteId): ?MarkdownNoteInterface
    {
        if (!$this->notesContext->isCollaborationEnabled()) {
            return null;
        }

        return $this->spaceAccess->administrableNote($user, $noteId);
    }

    /**
     * Whether a note can be handed to this account.
     *
     * Back-office accounts only - a client account has no notes screen to
     * open the note in - and never yourself: you already have the note, and a
     * row saying otherwise would be a second rule about your own access.
     */
    private function canReceive(?object $member, CoreUserInterface $viewer): bool
    {
        return $member instanceof CoreUserInterface
            && UserTypeEnum::Suite === $member->getType()
            && $member->getId() !== $viewer->getId();
    }

    /**
     * The back-office accounts one could hand a note to, by name.
     *
     * Unlike a space's, this list is offered to anybody with the module:
     * sharing one page of your own notebook does not require the right to
     * create a shared space.
     *
     * @return list<array{id: ?int, name: ?string}>
     */
    /**
     * Everybody of the suite, the viewer included and flagged (09/10/2026):
     * who a note's « person » property or an @mention can name. The list
     * above leaves the viewer out because one does not share a note with
     * oneself; one can be the person a note is about.
     */
    #[Route('/everyone', name: '_everyone', methods: [HttpMethodEnum::Get->value])]
    public function everyone(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $people = $this->userRepository->findBy(['type' => UserTypeEnum::Suite->value], ['name' => 'ASC']);

        return $this->jsonSuccess(['people' => array_map(
            static fn (CoreUserInterface $one): array => ['id' => $one->getId(), 'name' => $one->getName(), 'self' => $one->getId() === $user->getId()],
            $people,
        )]);
    }

    private function pickableBy(CoreUserInterface $viewer): array
    {
        $people = $this->userRepository->findBy(['type' => UserTypeEnum::Suite->value], ['name' => 'ASC']);

        return array_values(array_filter(array_map(
            static fn (CoreUserInterface $one): ?array => $one->getId() === $viewer->getId()
                ? null
                : ['id' => $one->getId(), 'name' => $one->getName()],
            $people,
        )));
    }
}
