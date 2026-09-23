<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

/**
 * Ce qu'une personne a le droit de lire, au-delà de son propre carnet.
 *
 * **Un seul endroit décide.** La question « ai-je le droit de lire cette
 * note » se pose dans l'écran de lecture, dans l'endpoint qui rend une
 * note, et dans la liste : trois réponses écrites à trois endroits auraient
 * fini par diverger, et c'est comme ça qu'on ouvre une note par accident.
 *
 * **La règle tient en trois lignes.** Une note est lisible si elle est à
 * vous ; ou si elle porte sa propre date de partage ; ou si l'un de ses
 * dossiers parents en porte une. Rien d'autre - pas de liste de personnes,
 * pas de niveaux : c'est le choix du 23/09, partagé veut dire partagé avec
 * tout le back-office, en lecture seule.
 *
 * **L'écriture n'est jamais concernée.** Les endpoints qui écrivent passent
 * par `findOneByUserAndId`, donc par le propriétaire, et ce service ne leur
 * sert pas. Tant que l'éditeur enregistre tout seul sans contrôle de
 * concurrence, deux personnes sur une note seraient le dernier qui tape qui
 * écrase l'autre, en silence.
 */
final readonly class NoteReadScope
{
    public function __construct(
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
    ) {}

    /** La note demandée si la personne peut la lire, null sinon. */
    public function readableNote(CoreUserInterface $user, int $id): ?MarkdownNoteInterface
    {
        $note = $this->notes->findOneLiving($id);

        if (!$note instanceof MarkdownNoteInterface) {
            return null;
        }

        return $this->canRead($user, $note) ? $note : null;
    }

    public function canRead(CoreUserInterface $user, MarkdownNoteInterface $note): bool
    {
        if ($note->getUser()->getId() === $user->getId()) {
            return true;
        }

        if ($note->getSharedAt() instanceof DateTimeImmutable) {
            return true;
        }

        return $this->insideSharedFolder($note->getFolder());
    }

    /**
     * Ce que les autres ont partagé : les racines, et ce qu'elles portent.
     *
     * Les dossiers rendus sont les racines du partage **et** leurs
     * descendants, parce qu'un écran qui montre un dossier partagé doit
     * pouvoir l'ouvrir et y descendre.
     *
     * @return array{folders: list<NoteFolderInterface>, notes: list<MarkdownNoteInterface>}
     */
    public function sharedWith(CoreUserInterface $user): array
    {
        $racines = $this->folders->findSharedByOthers($user);
        $dossiers = $this->withDescendants($racines);
        $ids = array_map(static fn (NoteFolderInterface $one): int => (int) $one->getId(), $dossiers);

        $notes = [
            ...$this->notes->findLivingInFoldersRegardlessOfOwner($ids),
            ...$this->notes->findSharedByOthers($user),
        ];

        // Une note partagée seule qui se trouve aussi dans un dossier
        // partagé remonterait deux fois : l'écran l'afficherait en double
        // sans que personne ne comprenne pourquoi.
        $uniques = [];
        foreach ($notes as $note) {
            $uniques[(int) $note->getId()] = $note;
        }

        return ['folders' => $dossiers, 'notes' => array_values($uniques)];
    }

    /**
     * Un dossier, ou l'un de ses parents, porte-t-il une date de partage ?
     *
     * La remontée est bornée par le nombre de dossiers partagés chargés :
     * une boucle dans les parents ne doit pas figer une page, ici pas plus
     * qu'ailleurs.
     */
    private function insideSharedFolder(?NoteFolderInterface $folder): bool
    {
        $vus = [];

        while ($folder instanceof NoteFolderInterface) {
            $id = (int) $folder->getId();

            if (isset($vus[$id])) {
                return false;
            }

            $vus[$id] = true;

            if ($folder->getSharedAt() instanceof DateTimeImmutable && !$folder->getDeletedAt() instanceof DateTimeImmutable) {
                return true;
            }

            $folder = $folder->getParent();
        }

        return false;
    }

    /**
     * Les racines données, plus tout ce qui est rangé dessous.
     *
     * @param list<NoteFolderInterface> $racines
     *
     * @return list<NoteFolderInterface>
     */
    private function withDescendants(array $racines): array
    {
        if ([] === $racines) {
            return [];
        }

        $tous = [];
        foreach ($racines as $racine) {
            $tous[(int) $racine->getId()] = $racine;
        }

        $aVisiter = array_keys($tous);

        while ([] !== $aVisiter) {
            $enfants = $this->folders->findLivingChildrenOf(array_shift($aVisiter));

            foreach ($enfants as $enfant) {
                $id = (int) $enfant->getId();

                if (isset($tous[$id])) {
                    continue;
                }

                $tous[$id] = $enfant;
                $aVisiter[] = $id;
            }
        }

        return array_values($tous);
    }
}
