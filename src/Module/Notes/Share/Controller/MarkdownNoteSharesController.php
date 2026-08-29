<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Share\Dto\NoteShareInput;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use Aurora\Module\Notes\Share\Serializer\MarkdownNoteShareLinkSerializer;
use Aurora\Module\Notes\Share\Service\NoteShareNotifier;
use Aurora\Module\Notes\Share\Service\SharedNoteScope;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Opening and closing the addresses that reach one of your notes.
 *
 * Every action starts from the note *you own*: `findOneByUserAndId` is the only
 * way a note enters this controller, so a note belonging to somebody else is a
 * 404 here rather than a permission message that confirms it exists.
 */
#[Route('/backend/notes/markdown/shares', name: 'backend_notes_markdown_shares')]
#[IsGranted('notes.markdown.use')]
final class MarkdownNoteSharesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteRepository $notes,
        private readonly MarkdownNoteShareLinkRepository $links,
        private readonly MarkdownNoteShareLinkManagerInterface $shareLinks,
        private readonly MarkdownNoteShareLinkSerializer $serializer,
        private readonly SharedNoteScope $scope,
        private readonly NoteShareNotifier $notifier,
        private readonly PayloadValidator $payloadValidator,
    ) {}

    /**
     * Every link ever made for a note, revoked ones included, plus the descendant count.
     *
     * `__id__` is allowed by the requirement because the view builder generates
     * these paths once, as templates the front fills in per note. Without it the
     * generator refuses the placeholder and the page dies before it renders.
     */
    #[Route('/{noteId}', name: '_list', requirements: ['noteId' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function list(int $noteId): JsonResponse
    {
        $note = $this->ownedNote($noteId);

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess([
            'links' => array_map($this->serializer->serialize(...), $this->links->findForNote($note)),
            // Shown next to the checkbox, so the size of what is about to be
            // published is visible before the click rather than after it.
            'descendantCount' => $this->scope->descendantCount($note),
        ]);
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $input = new NoteShareInput();
        $input->noteId = isset($payload['noteId']) ? (int) $payload['noteId'] : null;
        $input->includeDescendants = (bool) ($payload['includeDescendants'] ?? false);
        $input->label = mb_trim((string) ($payload['label'] ?? ''));

        $recipient = mb_trim((string) ($payload['recipientEmail'] ?? ''));
        $input->recipientEmail = '' === $recipient ? null : $recipient;
        $expires = mb_trim((string) ($payload['expiresAt'] ?? ''));
        $input->expiresAt = '' === $expires ? null : $expires;

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $note = $this->ownedNote((int) $input->noteId);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonInvalidInput(['noteId' => 'notes.markdown.share.errors.unknown_note']);
        }

        $expiresAt = null;
        if (null !== $input->expiresAt) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $input->expiresAt);
            if (false === $parsed) {
                return $this->jsonInvalidInput(['expiresAt' => 'notes.markdown.share.errors.bad_date']);
            }

            // End of the chosen day: somebody picking "until the 14th" means the
            // link works on the 14th, not that it dies as the 14th begins.
            $expiresAt = $parsed->setTime(23, 59, 59);
        }

        $link = $this->shareLinks->create(
            $note,
            $input->includeDescendants,
            $input->recipientEmail,
            $input->label,
            $expiresAt,
        );

        if (null !== $input->recipientEmail) {
            $user = $this->getUser();
            $this->notifier->notify(
                $link,
                $user instanceof CoreUserInterface ? $user->getName() : '',
            );
        }

        return $this->jsonSuccess(['link' => $this->serializer->serialize($link)]);
    }

    #[Route('/{id}/revoke', name: '_revoke', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function revoke(int $id): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof CoreUserInterface) {
            return $this->jsonNotFound();
        }

        $link = $this->links->findOneOwnedBy($user, $id);

        if (!$link instanceof MarkdownNoteShareLinkInterface) {
            return $this->jsonNotFound();
        }

        $this->shareLinks->revoke($link);

        return $this->jsonSuccess(['link' => $this->serializer->serialize($link)]);
    }

    private function ownedNote(int $noteId): ?MarkdownNoteInterface
    {
        $user = $this->getUser();

        if (!$user instanceof CoreUserInterface) {
            return null;
        }

        return $this->notes->findOneByUserAndId($user, $noteId);
    }
}
