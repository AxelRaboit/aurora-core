<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Manager;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface NoteFavoriteManagerInterface
{
    /**
     * Épingle, ou décroche ce qui l'était.
     *
     * @return bool vrai quand l'élément est épinglé à la sortie
     */
    public function toggle(CoreUserInterface $user, MarkdownNoteInterface|NoteFolderInterface $item): bool;

    /** L'heure où cet élément a été épinglé par cette personne, ou rien. */
    public function favoritedAt(CoreUserInterface $user, MarkdownNoteInterface|NoteFolderInterface $item): ?string;

    /**
     * Ce qu'une personne a épinglé, par identifiant, avec l'heure du geste.
     *
     * @return array{notes: array<int, string>, folders: array<int, string>}
     */
    public function mapFor(CoreUserInterface $user): array;
}
