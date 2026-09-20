<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFileInterface;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Aurora\Module\Studio\SpaceFile\Repository\SpaceFileRepository;
use Aurora\Module\Studio\SpaceFile\Serializer\SpaceFileSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SpaceFilesViewBuilder
{
    public function __construct(
        private SpaceFileRepository $files,
        private SpaceFileSerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
        private DriveSettings $drive,
        private DriveLock $lock,
        private SpaceVisibility $visibility,
    ) {}

    /**
     * Ce dont la vue a besoin, envoyé avec le reste de la page.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'spaceFiles' => $this->files($space),
            'spaceFileUploadPath' => $this->urlGenerator->generate('workspace_space_files_upload', ['id' => $space->getId()]),
            'spaceFileAttachPath' => $this->urlGenerator->generate('workspace_space_files_attach', ['id' => $space->getId()]),
            'spaceFileRemovePath' => $this->pathTemplates->generate('workspace_space_files_remove', ['id' => $space->getId(), 'fileId' => '__id__']),
            // Le dossier Drive de cet espace. `driveEnabled` dit que
            // l'installation a une clé ; `driveFolderId` dit que cet espace-ci
            // a désigné un dossier. Les deux, sinon l'écran propose un champ
            // qui ne mènerait nulle part.
            'driveEnabled' => $this->drive->isEnabled(),
            'driveFolderId' => $space->getDriveFolderId(),
            'driveListPath' => $this->urlGenerator->generate('workspace_space_drive_list', ['id' => $space->getId()]),
            'driveFolderPath' => $this->urlGenerator->generate('workspace_space_drive_folder', ['id' => $space->getId()]),
            'driveFilePath' => $this->pathTemplates->generate('workspace_space_drive_file', ['id' => $space->getId(), 'fileId' => '__id__']),
            'driveArchivePath' => $this->urlGenerator->generate('workspace_space_drive_archive', ['id' => $space->getId()]),
            'driveImportPath' => $this->pathTemplates->generate('workspace_space_drive_import', ['id' => $space->getId(), 'fileId' => '__fileId__']),
            'driveUnlockPath' => $this->urlGenerator->generate('workspace_space_settings_drive_unlock', ['id' => $space->getId()]),
            // La serrure telle qu'elle est pour *cette* session : fermée mais
            // déjà ouverte ici ne se lit pas comme fermée.
            'driveLocked' => $this->lock->isClosedFor($space),
            // Les réglages, et qui peut les ouvrir. Décidé ici plutôt que
            // deviné dans l'écran : la règle vit dans `SpaceVisibility`.
            'canConfigure' => $this->visibility->canConfigure($space),
            'settingsPath' => $this->urlGenerator->generate('workspace_space_settings_show', ['id' => $space->getId()]),
        ];
    }

    /**
     * Ce que renvoie chaque écriture : la liste entière.
     *
     * Plutôt que la seule ligne touchée, pour la raison que le tableau donne
     * déjà : une page qui rafistolerait sa copie s'écarterait du serveur en
     * trois gestes, et la liste des fichiers d'un espace est courte.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        return ['success' => true, 'spaceFiles' => $this->files($space)];
    }

    /**
     * Les fichiers de l'espace, tels que le client les lit.
     *
     * **Il les voit, et c'est la définition de l'onglet.** Un espace est
     * partagé : ses fiches, ses fichiers et sa discussion se lisent des deux
     * côtés. Ce que le studio garde pour lui, ce sont les notes, qui n'ont
     * aucune route publique.
     *
     * @return array<string, mixed>
     */
    public function publicView(SpaceAccessLinkInterface $link, string $token): array
    {
        return [
            'spaceFiles' => array_map(
                fn (SpaceFileInterface $file): array => $this->serializer->serializeForGuest($file, $link, $token),
                $this->files->findForSpace($link->getSpace()),
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function files(CustomerSpaceInterface $space): array
    {
        return array_map($this->serializer->serialize(...), $this->files->findForSpace($space));
    }
}
