<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Service;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_diff;
use function array_map;
use function array_unique;
use function array_values;
use function preg_match_all;

/**
 * `@[Marie Dupont](user:12)` in a note or a comment (09/10/2026), as Notion
 * and Craft mention people: the name as it was written, the person by id, so
 * that a rename changes nothing and the notification finds them.
 *
 * **Only who can read the note is told.** Mentioning someone outside its
 * space must not become a way to show them a title they have no access to:
 * the mention stays in the text, and nobody is notified.
 */
final readonly class NoteMentions
{
    public const string PATTERN = '/@\[([^\]\n]{1,80})\]\(user:(\d{1,10})\)/u';

    public function __construct(
        private UserRepository $users,
        private NoteSpaceAccess $spaceAccess,
        private NotificationManagerInterface $notifications,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    /** @return list<int> the people mentioned, each once */
    public static function idsIn(?string $text): array
    {
        if (null === $text || 0 === preg_match_all(self::PATTERN, $text, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map(intval(...), $matches[2])));
    }

    /**
     * Tells the people mentioned in the new text and not in the old one.
     *
     * @return int how many were told
     */
    public function notifyNew(MarkdownNoteInterface $note, ?string $before, ?string $after, string $actorName, ?CoreUserInterface $actor = null): int
    {
        $added = array_values(array_diff(self::idsIn($after), self::idsIn($before)));

        return $this->notify($note, $added, $actorName, $actor, 'notes.markdown.mention.in_note');
    }

    /** Tells the people mentioned in a comment. */
    public function notifyInComment(MarkdownNoteInterface $note, string $body, string $actorName, ?CoreUserInterface $actor = null): int
    {
        return $this->notify($note, self::idsIn($body), $actorName, $actor, 'notes.markdown.mention.in_comment');
    }

    /** @param list<int> $ids */
    private function notify(MarkdownNoteInterface $note, array $ids, string $actorName, ?CoreUserInterface $actor, string $bodyKey): int
    {
        $told = 0;
        foreach ($ids as $id) {
            if ($actor instanceof CoreUserInterface && $id === $actor->getId()) {
                continue;
            }

            $user = $this->users->find($id);
            if (!$user instanceof CoreUserInterface) {
                continue;
            }
            if (!$this->spaceAccess->readableNote($user, (int) $note->getId()) instanceof MarkdownNoteInterface) {
                continue;
            }

            $title = mb_trim((string) $note->getTitle());
            $this->notifications->notify(
                $user,
                'notes.mention',
                '' === $title ? $this->translator->trans('notes.markdown.untitled') : $title,
                $this->translator->trans($bodyKey, ['%name%' => $actorName]),
                $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => $note->getId()]),
                ['noteId' => $note->getId()],
            );
            ++$told;
        }

        return $told;
    }
}
