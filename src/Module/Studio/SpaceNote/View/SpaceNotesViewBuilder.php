<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\View;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\View\MarkdownNotesViewBuilder;
use Aurora\Module\Notes\NotesContext;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceScopeEnum;
use Aurora\Module\Notes\Space\Hosting\HostedNoteSpace;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceScope;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Service\CustomerSpaceNoteHost;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A client space's Notes section: the notes editor, on the space's notes.
 *
 * **Written here, by the team** (10/10/2026). The notes of a client space
 * live in a notes space the client space hosts ({@see CustomerSpaceNoteHost}):
 * the section draws the Notes module's own editor - folders, links between
 * notes, history, search, comments - confined to that one space, and never
 * leaves the client space. The Notes module does not show them.
 *
 * **Open to the space's team**, whether or not they have the Notes module:
 * the client space is their way in. The section only needs the notes engine
 * switched on.
 *
 * The notes space only exists from the first note on: before that, the
 * section says so and its first gesture opens it.
 */
final readonly class SpaceNotesViewBuilder
{
    public function __construct(
        private SpaceNoteSpaceProvider $provider,
        private CustomerSpaceNoteHost $host,
        private NoteSpaceAccess $spaceAccess,
        private NoteSpaceScope $scope,
        private MarkdownNotesViewBuilder $notesViewBuilder,
        private NotesContext $notesContext,
        private Security $security,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * What the section needs, sent with the rest of the page.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return ['spaceNotes' => $this->payload($space)];
    }

    /**
     * The section's state: rendered with the page, then after the notes
     * space is opened.
     *
     * The editor's props are built inside the space's scope, so its lists
     * hold this space alone and every route it calls carries the host
     * parameter that lets the next request back in.
     *
     * @return array<string, mixed>
     */
    public function payload(CustomerSpaceInterface $space): array
    {
        $reader = $this->security->getUser();

        if (!$reader instanceof CoreUserInterface || !$this->enabled()) {
            return ['enabled' => false];
        }

        $noteSpace = $this->provider->existing($space);
        $hosted = $noteSpace instanceof NoteSpaceInterface ? new HostedNoteSpace($noteSpace, $this->host, (string) $space->getId()) : null;
        $role = $hosted instanceof HostedNoteSpace
            ? $this->scope->within(NoteSpaceScopeEnum::Hosted, fn (): ?NoteSpaceRoleEnum => $this->spaceAccess->roleIn($reader, $hosted->space), $hosted)
            : null;

        return [
            'enabled' => true,
            'noteSpace' => $noteSpace instanceof NoteSpaceInterface ? [
                'id' => $noteSpace->getId(),
                'name' => $noteSpace->getName(),
                'readable' => $role instanceof NoteSpaceRoleEnum,
                'canWrite' => true === $role?->canWrite(),
            ] : null,
            'app' => $hosted instanceof HostedNoteSpace && $role instanceof NoteSpaceRoleEnum
                ? $this->scope->within(NoteSpaceScopeEnum::Hosted, fn (): array => $this->editor($reader, $hosted), $hosted)
                : null,
            'paths' => [
                'open' => $this->urlGenerator->generate('workspace_space_notes_open', ['id' => $space->getId()]),
                'library' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId(), 'view' => 'notes']),
            ],
        ];
    }

    /** The notes engine switched on: the section has nothing to draw otherwise. */
    public function enabled(): bool
    {
        return $this->notesContext->isSuiteEnabled() && $this->notesContext->isMarkdownEnabled();
    }

    /**
     * The editor's props, on the note or the folder the address names.
     *
     * Read inside the space's scope: a note or a folder of another space
     * named in the address is simply not found, and the section opens on
     * the space's library.
     *
     * @return array<string, mixed>
     */
    private function editor(CoreUserInterface $reader, HostedNoteSpace $hosted): array
    {
        $query = $this->requestStack->getMainRequest()?->query;
        $noteId = $query?->getInt('note') ?: null;
        $folderId = $query?->getInt('folder') ?: null;

        $folder = null === $folderId ? null : $this->spaceAccess->readableFolder($reader, $folderId);
        $activeId = null !== $noteId && $this->spaceAccess->readableNote($reader, $noteId) instanceof MarkdownNoteInterface ? $noteId : null;

        return $this->notesViewBuilder->indexView($reader, $activeId, $folder instanceof NoteFolderInterface ? $folder : null);
    }
}
