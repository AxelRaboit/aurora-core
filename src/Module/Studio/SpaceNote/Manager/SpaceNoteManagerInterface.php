<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Dto\SpaceNoteInputInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;

interface SpaceNoteManagerInterface
{
    public function create(CustomerSpaceInterface $space, SpaceNoteInputInterface $input): SpaceNoteInterface;

    public function update(SpaceNoteInterface $note, SpaceNoteInputInterface $input): void;

    /**
     * Note d'où elle vient, sans passer par l'entrée du formulaire.
     *
     * Volontairement hors de {@see SpaceNoteInputInterface} : cette entrée
     * arrive du navigateur, et rien de ce qu'un navigateur envoie ne doit
     * pouvoir déclarer qu'une note vient de Craft.
     */
    public function markImportedFromCraft(SpaceNoteInterface $note, string $craftDocumentId): void;

    /** Épingler ou dépingler, sans rouvrir la note. */
    public function togglePinned(SpaceNoteInterface $note): void;

    public function delete(SpaceNoteInterface $note): void;
}
