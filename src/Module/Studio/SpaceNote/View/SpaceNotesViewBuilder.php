<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Repository\SpaceNoteRepository;
use Aurora\Module\Studio\SpaceNote\Serializer\SpaceNoteSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SpaceNotesViewBuilder
{
    public function __construct(
        private SpaceNoteRepository $notes,
        private SpaceNoteSerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /**
     * Ce dont la vue a besoin, envoyé avec le reste de la page.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'notes' => $this->notes($space),
            'noteCreatePath' => $this->urlGenerator->generate('workspace_space_notes_create', ['id' => $space->getId()]),
            'noteUpdatePath' => $this->pathTemplates->generate('workspace_space_notes_update', ['id' => $space->getId(), 'noteId' => '__id__']),
            'noteDeletePath' => $this->pathTemplates->generate('workspace_space_notes_delete', ['id' => $space->getId(), 'noteId' => '__id__']),
            'notePinPath' => $this->pathTemplates->generate('workspace_space_notes_pin', ['id' => $space->getId(), 'noteId' => '__id__']),
            // L'adresse où l'éditeur dépose ses images. Propre à l'espace, donc
            // le fichier se range dans son dossier au lieu d'aller dans le tas
            // générique des images d'édition.
            'noteImagePath' => $this->urlGenerator->generate('workspace_space_notes_image', ['id' => $space->getId()]),
        ];
    }

    /**
     * Ce que renvoie chaque écriture : le mur entier.
     *
     * Plutôt que la seule note modifiée, pour la raison que le tableau donne
     * déjà : épingler réordonne tout, et une page qui rafistolerait sa copie
     * s'écarterait du serveur en trois gestes. Un mur de notes est petit.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        return ['success' => true, 'notes' => $this->notes($space)];
    }

    /** @return list<array<string, mixed>> */
    public function notes(CustomerSpaceInterface $space): array
    {
        return array_map($this->serializer->serialize(...), $this->notes->findForSpace($space));
    }
}
