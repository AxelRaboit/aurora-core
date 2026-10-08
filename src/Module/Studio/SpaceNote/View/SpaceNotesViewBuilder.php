<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\View;

use Aurora\Module\Notes\Craft\Service\CraftClient;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;
use function array_reverse;
use function implode;

/**
 * A client space's Notes tab: a door onto its notes space.
 *
 * **Notes are no longer written here.** They live in the Notes module, in the
 * client space's notes space; the tab shows their list and leads there - a
 * note opens in the notes editor, which knows everything the old wall did not
 * (folders, links between notes, history, search, sharing a note).
 *
 * **Absent for whoever does not have notes.** Module turned off, or a person
 * without the `notes.markdown.use` right: the tab does not show, rather than
 * leading to screens that would answer 404. The right is not granted in
 * passing, it is set with the others.
 */
final readonly class SpaceNotesViewBuilder
{
    public function __construct(
        private SpaceNoteSpaceProvider $provider,
        private NoteSpaceAccess $spaceAccess,
        private MarkdownNoteRepository $noteRepository,
        private NoteFolderRepository $folderRepository,
        private NotesContext $notesContext,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private CraftClient $craftClient,
    ) {}

    /**
     * What the tab needs, sent with the rest of the page.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return ['spaceNotes' => $this->payload($space)];
    }

    /**
     * The tab's state: rendered with the page, then after the notes space is
     * opened.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        $reader = $this->security->getUser();
        $enabled = $reader instanceof CoreUserInterface && $this->enabled();

        if (!$enabled) {
            return ['enabled' => false];
        }

        $noteSpace = $this->provider->existing($space);
        $role = $noteSpace instanceof NoteSpaceInterface ? $this->spaceAccess->roleIn($reader, $noteSpace) : null;

        return [
            'enabled' => true,
            'noteSpace' => $noteSpace instanceof NoteSpaceInterface ? [
                'id' => $noteSpace->getId(),
                'name' => $noteSpace->getName(),
                'readable' => $role instanceof NoteSpaceRoleEnum,
                'canWrite' => true === $role?->canWrite(),
            ] : null,
            'notes' => $noteSpace instanceof NoteSpaceInterface && $role instanceof NoteSpaceRoleEnum ? $this->notes($noteSpace) : [],
            'paths' => [
                'open' => $this->urlGenerator->generate('workspace_space_notes_open', ['id' => $space->getId()]),
                'library' => $this->urlGenerator->generate('suite_notes_markdown'),
                'create' => $this->urlGenerator->generate('suite_notes_markdown_create'),
                'show' => $this->urlGenerator->generate('suite_notes_markdown_show', ['id' => '__id__']),
            ],
            // The Craft import only exists in the tab if the installation has
            // opened the connection.
            'craftEnabled' => $this->craftClient->isConfigured(),
            'craftPaths' => [
                'documents' => $this->urlGenerator->generate('suite_notes_craft_documents'),
                'import' => $this->urlGenerator->generate('suite_notes_craft_import'),
            ],
        ];
    }

    /** The module turned on, and the right to use it. */
    public function enabled(): bool
    {
        return $this->notesContext->isSuiteEnabled()
            && $this->notesContext->isMarkdownEnabled()
            && $this->security->isGranted('notes.markdown.use');
    }

    /**
     * The space's notes, with their folder path to place them.
     *
     * @return list<array<string, mixed>>
     */
    private function notes(NoteSpaceInterface $noteSpace): array
    {
        $folders = [];
        foreach ($this->folderRepository->findLivingInSpace($noteSpace) as $folder) {
            $folders[(int) $folder->getId()] = $folder;
        }

        return array_map(fn (array $note): array => [
            ...$note,
            'folder' => null === $note['folderId'] ? null : $this->pathOf($folders[$note['folderId']] ?? null),
        ], $this->noteRepository->findListInSpace($noteSpace));
    }

    private function pathOf(?NoteFolderInterface $folder): ?string
    {
        $names = [];

        while ($folder instanceof NoteFolderInterface) {
            $names[] = (string) $folder->getName();
            $folder = $folder->getParent();
        }

        return [] === $names ? null : implode(' / ', array_reverse($names));
    }
}
