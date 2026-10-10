<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Notes\Comment\Entity\NoteComment;
use Aurora\Module\Notes\Comment\Entity\NoteCommentInterface;
use Aurora\Module\Notes\Comment\Repository\NoteCommentRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Service\NoteAddresses;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_values;
use function in_array;
use function mb_substr;

/**
 * The comments of a note (09/10/2026): listed as threads, written, settled,
 * removed, and announced to whoever takes part.
 *
 * Who is told of a new comment: the note's author and everyone already in
 * the thread, never the one writing, and only if they can still read the
 * note. Who is mentioned in it is told by {@see NoteMentions}, once.
 */
final readonly class NoteComments
{
    public const int MAX_BODY = 5000;

    public const int MAX_QUOTE = 1000;

    public function __construct(
        private NoteCommentRepository $repository,
        private EntityManagerInterface $entityManager,
        private NoteMentions $mentions,
        private NotificationManagerInterface $notifications,
        private NoteSpaceAccess $spaceAccess,
        private NoteAddresses $noteAddresses,
        private TranslatorInterface $translator,
    ) {}

    /**
     * The note's threads, each with its replies, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function threads(MarkdownNoteInterface $note, ?CoreUserInterface $viewer = null): array
    {
        $threads = [];
        $replies = [];
        foreach ($this->repository->findForNote($note) as $comment) {
            $parent = $comment->getParent();
            if (null === $parent) {
                $threads[(int) $comment->getId()] = $this->serialize($comment, $viewer) + ['replies' => []];
            } else {
                $replies[(int) $parent->getId()][] = $this->serialize($comment, $viewer);
            }
        }

        foreach ($replies as $parentId => $list) {
            if (isset($threads[$parentId])) {
                $threads[$parentId]['replies'] = $list;
            }
        }

        return array_values($threads);
    }

    public function find(int $id): ?NoteCommentInterface
    {
        return $this->repository->find($id);
    }

    public function openCount(MarkdownNoteInterface $note): int
    {
        return $this->repository->countOpenThreads($note);
    }

    /**
     * Writes a comment, or a reply when `$parent` is given - always on the
     * thread's first comment, so a thread stays one level deep.
     */
    public function add(MarkdownNoteInterface $note, ?CoreUserInterface $author, ?string $guestName, ?string $quote, string $body, ?NoteCommentInterface $parent = null): NoteCommentInterface
    {
        $comment = new NoteComment();
        $comment->setNote($note);
        $comment->setAuthor($author);
        $comment->setGuestName($author instanceof CoreUserInterface ? null : $guestName);

        $root = $parent?->getParent() ?? $parent;
        $comment->setParent($root);
        // A reply is about its thread's passage, not one of its own.
        $comment->setQuote(!$root instanceof NoteCommentInterface && null !== $quote && '' !== mb_trim($quote) ? mb_substr(mb_trim($quote), 0, self::MAX_QUOTE) : null);
        $comment->setBody(mb_substr(mb_trim($body), 0, self::MAX_BODY));

        $this->entityManager->persist($comment);

        // A reply reopens a settled thread: somebody had more to say.
        $root?->setResolvedAt(null);
        $this->entityManager->flush();

        $actorName = $author?->getName() ?? ($guestName ?: $this->translator->trans('notes.markdown.comments.guest'));
        $this->announce($comment, $root, $actorName, $author);
        $this->mentions->notifyInComment($note, $comment->getBody(), $actorName, $author);

        return $comment;
    }

    public function resolve(NoteCommentInterface $comment, bool $resolved): void
    {
        $root = $comment->getParent() ?? $comment;
        $root->setResolvedAt($resolved ? new DateTimeImmutable() : null);

        $this->entityManager->flush();
    }

    public function delete(NoteCommentInterface $comment): void
    {
        $this->entityManager->remove($comment);
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    public function serialize(NoteCommentInterface $comment, ?CoreUserInterface $viewer = null): array
    {
        $author = $comment->getAuthor();

        return [
            'id' => $comment->getId(),
            'quote' => $comment->getQuote(),
            'body' => $comment->getBody(),
            'authorName' => $author?->getName() ?? ($comment->getGuestName() ?: $this->translator->trans('notes.markdown.comments.guest')),
            'guest' => !$author instanceof CoreUserInterface,
            'mine' => $viewer instanceof CoreUserInterface && $author instanceof CoreUserInterface && $author->getId() === $viewer->getId(),
            'createdAt' => $comment->getCreatedAt()->format(DateTimeInterface::ATOM),
            'resolvedAt' => $comment->getResolvedAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    private function announce(NoteCommentInterface $comment, ?NoteCommentInterface $root, string $actorName, ?CoreUserInterface $actor): void
    {
        $note = $comment->getNote();
        $owner = $note->getUser();
        $recipients = $owner instanceof CoreUserInterface ? [(int) $owner->getId() => $owner] : [];
        if ($root instanceof NoteCommentInterface) {
            foreach ($this->repository->findForNote($note) as $one) {
                $inThread = $one === $root || $one->getParent() === $root;
                $person = $one->getAuthor();
                if ($inThread && null !== $person) {
                    $recipients[(int) $person->getId()] = $person;
                }
            }
        }

        // The mentioned are told by their own notification, not twice.
        $mentioned = NoteMentions::idsIn($comment->getBody());
        $title = mb_trim((string) $note->getTitle());
        foreach ($recipients as $id => $person) {
            if ($actor instanceof CoreUserInterface && $id === $actor->getId()) {
                continue;
            }

            if (in_array($id, $mentioned, true)) {
                continue;
            }

            if (!$this->spaceAccess->readableNote($person, (int) $note->getId()) instanceof MarkdownNoteInterface) {
                continue;
            }

            $this->notifications->notify(
                $person,
                'notes.comment',
                '' === $title ? $this->translator->trans('notes.markdown.untitled') : $title,
                $this->translator->trans($root instanceof NoteCommentInterface ? 'notes.markdown.comments.notified_reply' : 'notes.markdown.comments.notified_new', ['%name%' => $actorName]),
                $this->noteAddresses->noteUrl($note),
                ['noteId' => $note->getId(), 'commentId' => $comment->getId()],
            );
        }
    }
}
