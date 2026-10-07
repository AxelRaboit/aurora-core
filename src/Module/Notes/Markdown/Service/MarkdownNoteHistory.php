<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRevisionRepository;
use Aurora\Module\Notes\Markdown\Setting\MarkdownNoteSettingEnum;
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
 */
final readonly class MarkdownNoteHistory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarkdownNoteRevisionRepository $revisions,
        private SettingRepository $settings,
    ) {}

    /**
     * To call before saving a new title or a new text: keeps the current
     * state if it changes and the last version is old enough.
     */
    public function beforeChange(MarkdownNoteInterface $note, ?string $title, ?string $content, ?CoreUserInterface $author): void
    {
        $unchanged = ($title ?? '') === ($note->getTitle() ?? '') && ($content ?? '') === ($note->getContent() ?? '');
        if ($unchanged || ('' === ($note->getTitle() ?? '') && '' === ($note->getContent() ?? ''))) {
            return;
        }

        $latest = $this->revisions->findLatestForNote($note);
        $minutes = max(0, (int) $this->settings->getOrDefault(MarkdownNoteSettingEnum::RevisionIntervalMinutes));
        if ($latest instanceof MarkdownNoteRevision && $latest->getCreatedAt() > new DateTimeImmutable(sprintf('-%d minutes', $minutes))) {
            return;
        }

        $this->keep($note, $author);
    }

    /** Keeps the note's current state, no matter what. */
    public function keep(MarkdownNoteInterface $note, ?CoreUserInterface $author): MarkdownNoteRevision
    {
        $revision = new MarkdownNoteRevision($note, $author);
        $this->entityManager->persist($revision);
        $this->entityManager->flush();

        $limit = (int) $this->settings->getOrDefault(MarkdownNoteSettingEnum::RevisionsLimit);
        if ($limit > 0) {
            $this->revisions->pruneBeyond($note, $limit);
        }

        return $revision;
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
