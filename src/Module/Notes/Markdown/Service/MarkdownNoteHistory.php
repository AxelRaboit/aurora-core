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
 * Les versions passées d'une note.
 *
 * **Une version est l'état qu'on s'apprête à remplacer**, gardé juste avant
 * l'enregistrement qui le change. Pas une par enregistrement : l'éditeur
 * enregistre toutes les quelques secondes pendant qu'on écrit, et l'historique
 * serait une version par phrase. Une nouvelle n'est prise que si la dernière
 * a plus de `RevisionIntervalMinutes` minutes (réglages > Notes), et il en
 * reste au plus `RevisionsLimit` par note, les plus anciennes partant d'abord.
 *
 * Restaurer une version garde d'abord l'état courant, toujours, intervalle ou
 * pas : revenir en arrière ne doit rien faire perdre.
 */
final readonly class MarkdownNoteHistory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarkdownNoteRevisionRepository $revisions,
        private SettingRepository $settings,
    ) {}

    /**
     * À appeler avant d'enregistrer un nouveau titre ou un nouveau texte :
     * garde l'état courant s'il change et que la dernière version est assez
     * ancienne.
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

    /** Garde l'état courant de la note, quoi qu'il arrive. */
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

    /** Remet le titre et le texte d'une version, après avoir gardé l'état courant. */
    public function restore(MarkdownNoteInterface $note, MarkdownNoteRevision $revision, ?CoreUserInterface $author): void
    {
        $this->keep($note, $author);

        $note->setTitle($revision->getTitle());
        $note->setContent($revision->getContent());
        // Un éditeur resté ouvert sur l'ancien texte verra le conflit au lieu
        // d'écraser la version restaurée.
        $note->bumpVersion();

        $this->entityManager->flush();
    }
}
