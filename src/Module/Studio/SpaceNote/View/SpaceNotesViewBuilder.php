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
 * L'onglet Notes d'un espace client : une porte sur son espace de notes.
 *
 * **Les notes ne sont plus écrites ici.** Elles vivent dans le module Notes,
 * dans l'espace de notes de l'espace client ; l'onglet en montre la liste et
 * y mène - une note s'ouvre dans l'éditeur des notes, qui sait tout ce que
 * l'ancien mur ne savait pas (dossiers, liens entre notes, historique,
 * recherche, partage d'une note).
 *
 * **Absent pour qui n'a pas les notes.** Module éteint, ou personne sans le
 * droit `notes.markdown.use` : l'onglet ne se montre pas, plutôt que de mener
 * à des écrans qui répondraient 404. Le droit n'est pas donné en passant, il
 * se règle avec les autres.
 */
final readonly class SpaceNotesViewBuilder
{
    public function __construct(
        private SpaceNoteSpaceProvider $provider,
        private NoteSpaceAccess $spaceAccess,
        private MarkdownNoteRepository $notes,
        private NoteFolderRepository $folders,
        private NotesContext $notesContext,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
        private CraftClient $craft,
    ) {}

    /**
     * Ce dont l'onglet a besoin, envoyé avec le reste de la page.
     *
     * @return array<string, mixed>
     */
    public function view(CustomerSpaceInterface $space): array
    {
        return ['spaceNotes' => $this->payload($space)];
    }

    /**
     * L'état de l'onglet : rendu à la page, puis après l'ouverture de
     * l'espace de notes.
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
            // L'import Craft n'existe dans l'onglet que si l'installation a
            // ouvert la connexion.
            'craftEnabled' => $this->craft->isConfigured(),
            'craftPaths' => [
                'documents' => $this->urlGenerator->generate('suite_notes_craft_documents'),
                'import' => $this->urlGenerator->generate('suite_notes_craft_import'),
            ],
        ];
    }

    /** Le module allumé, et le droit de s'en servir. */
    public function enabled(): bool
    {
        return $this->notesContext->isSuiteEnabled()
            && $this->notesContext->isMarkdownEnabled()
            && $this->security->isGranted('notes.markdown.use');
    }

    /**
     * Les notes de l'espace, avec le chemin de leur dossier pour les situer.
     *
     * @return list<array<string, mixed>>
     */
    private function notes(NoteSpaceInterface $noteSpace): array
    {
        $folders = [];
        foreach ($this->folders->findLivingInSpace($noteSpace) as $folder) {
            $folders[(int) $folder->getId()] = $folder;
        }

        return array_map(fn (array $note): array => [
            ...$note,
            'folder' => null === $note['folderId'] ? null : $this->pathOf($folders[$note['folderId']] ?? null),
        ], $this->notes->findListInSpace($noteSpace));
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
