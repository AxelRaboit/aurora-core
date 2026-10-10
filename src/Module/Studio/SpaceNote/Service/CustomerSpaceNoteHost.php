<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Hosting\NoteSpaceHostInterface;
use Aurora\Module\Notes\Space\Hosting\NoteSpacePagePaths;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\StudioContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function ctype_digit;

/**
 * A client space hosts its notes: they are written in the client space, by
 * its team, and never shown in the Notes module (10/10/2026).
 *
 * **Who gets in is the client space's rule**, the one every `workspace_*`
 * route applies: the right to the spaces, and being in this one's team (or
 * seeing every space). Not the right to the Notes module: a team member
 * writes the client's notes because they work for the client, not because
 * they keep a notebook of their own.
 *
 * The notes show in the client space's Notes section, which carries the note
 * or the folder in its address.
 */
final readonly class CustomerSpaceNoteHost implements NoteSpaceHostInterface
{
    /** The privilege every client space screen is behind. */
    private const string PRIVILEGE = 'studio.spaces.view';

    public function __construct(
        private CustomerSpaceRepository $spaceRepository,
        private SpaceVisibility $visibility,
        private SpaceNoteSpaceProvider $provider,
        private StudioContext $studioContext,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function getKey(): string
    {
        return SpaceNoteSpaceProvider::MANAGED_BY;
    }

    /**
     * The person is the signed-in one: the client spaces' rule reads the
     * session, like every screen of a space.
     */
    public function enter(string $reference, CoreUserInterface $user): ?NoteSpaceInterface
    {
        if (!ctype_digit($reference) || !$this->studioContext->areSpacesEnabled() || !$this->security->isGranted(self::PRIVILEGE)) {
            return null;
        }

        $space = $this->spaceRepository->find((int) $reference);

        if (!$space instanceof CustomerSpaceInterface || !$this->visibility->canSee($space)) {
            return null;
        }

        return $this->provider->existing($space);
    }

    public function referenceOf(NoteSpaceInterface $space): ?string
    {
        $customerSpace = $this->customerSpaceOf($space);

        return $customerSpace instanceof CustomerSpaceInterface ? (string) $customerSpace->getId() : null;
    }

    public function labelOf(NoteSpaceInterface $space): ?string
    {
        return $this->customerSpaceOf($space)?->getName();
    }

    public function pagePaths(NoteSpaceInterface $space): ?NoteSpacePagePaths
    {
        $customerSpace = $this->customerSpaceOf($space);

        if (!$customerSpace instanceof CustomerSpaceInterface) {
            return null;
        }

        $page = ['id' => $customerSpace->getId(), 'view' => 'notes'];

        return new NoteSpacePagePaths(
            library: $this->urlGenerator->generate('workspace_space_content', $page),
            note: $this->urlGenerator->generate('workspace_space_content', $page + ['note' => NoteSpacePagePaths::PLACEHOLDER]),
            folder: $this->urlGenerator->generate('workspace_space_content', $page + ['folder' => NoteSpacePagePaths::PLACEHOLDER]),
        );
    }

    private function customerSpaceOf(NoteSpaceInterface $space): ?CustomerSpaceInterface
    {
        return $this->spaceRepository->findOneBy(['noteSpace' => $space]);
    }
}
