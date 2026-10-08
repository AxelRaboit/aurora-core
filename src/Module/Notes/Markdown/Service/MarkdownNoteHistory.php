<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Live\Service\NotePresence;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRevisionRepository;
use Aurora\Module\Notes\Markdown\Setting\MarkdownNoteSettingEnum;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The past versions of a note.
 *
 * **A version is the state about to be replaced**, kept just before the save
 * that changes it. Not one per save: the editor saves every few seconds while
 * you write, and the history would be one version per sentence. A new one is
 * only taken if the last one is more than `RevisionIntervalMinutes` minutes
 * old (settings > Notes), and at most `RevisionsLimit` remain per note, the
 * oldest going first.
 *
 * Restoring a version first keeps the current state, always, interval or
 * not: going back must lose nothing.
 *
 * **During a co-editing session the interval is what makes it work.** One
 * elected client sends the write-back for the whole room, every few seconds,
 * so without the interval a session of an hour would be a thousand versions.
 * With it, a session leaves one version every `RevisionIntervalMinutes` - the
 * timed snapshot such a session needs, and it needed no machinery of its own.
 * What it did need is the right name on it, which is `handsOn()` below.
 */
final readonly class MarkdownNoteHistory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarkdownNoteRevisionRepository $markdownNoteRevisionRepository,
        private SettingRepository $settingRepository,
        private NotePresence $notePresence,
    ) {}

    /**
     * To call before saving a new title or a new text: keeps the current
     * state if it changes and the last version is old enough.
     */
    public function beforeChange(MarkdownNoteInterface $note, ?string $title, ?string $content, ?CoreUserInterface $author, ?MarkdownNoteShareLinkInterface $viaLink = null): void
    {
        $unchanged = ($title ?? '') === ($note->getTitle() ?? '') && ($content ?? '') === ($note->getContent() ?? '');
        if ($unchanged || ('' === ($note->getTitle() ?? '') && '' === ($note->getContent() ?? ''))) {
            return;
        }

        $latest = $this->markdownNoteRevisionRepository->findLatestForNote($note);
        $minutes = max(0, (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::RevisionIntervalMinutes));
        if ($latest instanceof MarkdownNoteRevision && $latest->getCreatedAt() > new DateTimeImmutable(sprintf('-%d minutes', $minutes))) {
            return;
        }

        $this->keep($note, $author, $viaLink);
    }

    /** Keeps the note's current state, no matter what. */
    public function keep(MarkdownNoteInterface $note, ?CoreUserInterface $author, ?MarkdownNoteShareLinkInterface $viaLink = null): MarkdownNoteRevision
    {
        $revision = new MarkdownNoteRevision($note, $author, $viaLink, $this->handsOn($note, $author));
        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        $limit = (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::RevisionsLimit);
        if ($limit > 0) {
            $this->markdownNoteRevisionRepository->pruneBeyond($note, $limit);
        }

        return $revision;
    }

    /**
     * Who was writing the note at this moment, the saver included.
     *
     * **Read from the server's own presence, never from the request.** Who
     * else was in the room is exactly the kind of claim a browser must not be
     * allowed to make about other people: a page could otherwise put a
     * colleague's name on a version they never saw. Presence is written by the
     * beat every page sends, so this holds whether or not a realtime hub is
     * running.
     *
     * Readers are left out - only pages reported as being in the editor. And
     * the saver is added explicitly rather than looked up, because their own
     * beat may have gone stale in the seconds before the save, and a version
     * missing the one name we are certain of would be the worst of both.
     *
     * @return list<array{id: int, name: ?string}>
     */
    private function handsOn(MarkdownNoteInterface $note, ?CoreUserInterface $author): array
    {
        $hands = [];

        if ($author instanceof CoreUserInterface) {
            $hands[(int) $author->getId()] = ['id' => (int) $author->getId(), 'name' => $author->getName()];
        }

        foreach ($this->notePresence->on($note) as $person) {
            if (!$person['editing']) {
                continue;
            }

            $hands[$person['userId']] = ['id' => $person['userId'], 'name' => $person['name']];
        }

        return array_values($hands);
    }

    /** Puts back the title and text of a version, after keeping the current state. */
    public function restore(MarkdownNoteInterface $note, MarkdownNoteRevision $revision, ?CoreUserInterface $author): void
    {
        $this->keep($note, $author);

        $note->setTitle($revision->getTitle());
        $note->setContent($revision->getContent());
        // An editor left open on the old text will see the conflict instead
        // of overwriting the restored version.
        $note->bumpVersion();

        $this->entityManager->flush();
    }
}
