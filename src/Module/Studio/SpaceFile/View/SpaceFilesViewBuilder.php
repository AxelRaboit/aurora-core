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
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class SpaceFilesViewBuilder
{
    public function __construct(
        private SpaceFileRepository $spaceFileRepository,
        private SpaceFileSerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplateGenerator,
        private DriveSettings $drive,
        private DriveLock $lock,
        private SpaceVisibility $visibility,
        private AuthorizationCheckerInterface $authorization,
    ) {}

    /**
     * What the view needs, sent with the rest of the page.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            'spaceFiles' => $this->files($space),
            'spaceFileUploadPath' => $this->urlGenerator->generate('workspace_space_files_upload', ['id' => $space->getId()]),
            'spaceFileAttachPath' => $this->urlGenerator->generate('workspace_space_files_attach', ['id' => $space->getId()]),
            'spaceFileRemovePath' => $this->pathTemplateGenerator->generate('workspace_space_files_remove', ['id' => $space->getId(), 'fileId' => '__id__']),
            'spaceFileVisibilityPath' => $this->pathTemplateGenerator->generate('workspace_space_files_visibility', ['id' => $space->getId(), 'fileId' => '__id__']),
            // This space's Drive folder. `driveEnabled` says the installation
            // has a key; `driveFolderId` says this particular space has
            // designated a folder. Both, otherwise the screen offers a field
            // that would lead nowhere.
            'driveEnabled' => $this->drive->isEnabled(),
            'driveFolderId' => $space->getDriveFolderId(),
            'driveListPath' => $this->urlGenerator->generate('workspace_space_drive_list', ['id' => $space->getId()]),
            'driveFilePath' => $this->pathTemplateGenerator->generate('workspace_space_drive_file', ['id' => $space->getId(), 'fileId' => '__id__']),
            'driveArchivePath' => $this->urlGenerator->generate('workspace_space_drive_archive', ['id' => $space->getId()]),
            // Filing a file writes to the media library: the button only shows
            // to someone who can edit the space, as the route requires.
            'driveImportPath' => $this->canEdit()
                ? $this->pathTemplateGenerator->generate('workspace_space_drive_import', ['id' => $space->getId(), 'fileId' => '__fileId__'])
                : '',
            // The agency's folder: the same for every space, chosen in the
            // Drive configuration. Reserved to the studio, it is never shown to
            // the client: nothing here goes to the portal.
            'driveAgencyFolderId' => $this->drive->agencyFolderId(),
            'driveAgencyListPath' => $this->urlGenerator->generate('workspace_space_drive_agency_list', ['id' => $space->getId()]),
            'driveAgencyFilePath' => $this->pathTemplateGenerator->generate('workspace_space_drive_agency_file', ['id' => $space->getId(), 'fileId' => '__id__']),
            'driveAgencyArchivePath' => $this->urlGenerator->generate('workspace_space_drive_agency_archive', ['id' => $space->getId()]),
            'driveAgencyImportPath' => $this->canEdit()
                ? $this->pathTemplateGenerator->generate('workspace_space_drive_agency_import', ['id' => $space->getId(), 'fileId' => '__fileId__'])
                : '',
            'driveUnlockPath' => $this->urlGenerator->generate('workspace_space_settings_drive_unlock', ['id' => $space->getId()]),
            // The lock as it stands for *this* session: closed but already
            // opened here does not read as closed.
            'driveLocked' => $this->lock->isClosedFor($space),
            // The settings, and who can open them. Decided here rather than
            // guessed in the screen: the rule lives in `SpaceVisibility`.
            'canConfigure' => $this->visibility->canConfigure($space),
            'settingsPath' => $this->urlGenerator->generate('workspace_space_settings_show', ['id' => $space->getId()]),
            // The agency's folder is set where it is missing, in a window of
            // the space settings, without leaving the space. Only for someone
            // in charge of the configuration: the others read the sentence,
            // with no action that would end on a 403.
            'driveAgencyFolderPath' => $this->canConfigureDrive()
                ? $this->urlGenerator->generate('suite_studio_drive_settings_agency_folder')
                : null,
            // The address to share a folder with, to copy in the window: for
            // the agency's, and first of all for the client's, who has to be
            // given it. There is nothing secret about it, it is written in
            // every share.
            'driveServiceAccountEmail' => $this->drive->isEnabled() ? $this->drive->state()['email'] : null,
        ];
    }

    private function canConfigureDrive(): bool
    {
        return $this->authorization->isGranted('configuration.settings.manage');
    }

    /**
     * What each write returns: the whole list.
     *
     * Rather than only the row touched, for the reason the board already
     * gives: a page that patched its own copy would drift from the server in
     * three actions, and a space's file list is short.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        return ['success' => true, 'spaceFiles' => $this->files($space)];
    }

    /**
     * The space's files, as the client reads them.
     *
     * **Those shown to them, and those they sent.** A file dropped by the
     * studio is born hidden from the client, like everything a space can show
     * them: the brief being worked from is not the brand guide handed to them.
     * The route that serves a file asks the same question, so that a guessed
     * address does not serve what the list keeps quiet.
     *
     * @return array<string, mixed>
     */
    public function publicView(SpaceAccessLinkInterface $link, string $token): array
    {
        return [
            'spaceFiles' => array_map(
                fn (SpaceFileInterface $file): array => $this->serializer->serializeForGuest($file, $link, $token),
                $this->spaceFileRepository->findShownForSpace($link->getSpace()),
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function files(CustomerSpaceInterface $space): array
    {
        return array_map($this->serializer->serialize(...), $this->spaceFileRepository->findForSpace($space));
    }

    private function canEdit(): bool
    {
        return $this->authorization->isGranted('studio.spaces.edit');
    }
}
