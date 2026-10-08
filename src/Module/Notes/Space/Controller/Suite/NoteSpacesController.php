<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Craft\Service\CraftClient;
use Aurora\Module\Notes\Space\Dto\NoteSpaceInputFactoryInterface;
use Aurora\Module\Notes\Space\Dto\NoteSpaceInputInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Manager\NoteSpaceManagerInterface;
use Aurora\Module\Notes\Space\Repository\NoteSpaceRepository;
use Aurora\Module\Notes\Space\Serializer\NoteSpaceSerializerInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

use function array_filter;
use function array_map;
use function array_values;
use function is_numeric;
use function mb_substr;
use function mb_trim;
use function preg_match;

/**
 * Note spaces: list them, create them, configure them, add members.
 *
 * **A space you cannot manage answers 404**, like a note you cannot read:
 * saying "forbidden" would confirm it exists.
 *
 * **A space configured elsewhere refuses to be configured from here** -
 * renaming it, changing its access, adding someone, publishing or removing
 * it. Its name and its team follow whatever configures it (the Studio client
 * space whose notes it keeps), and a change made here would be undone at the
 * next save over there. The refusal is stated plainly rather than as a 404:
 * the person sees the space, there is nothing to hide.
 */
#[Route('/suite/notes/spaces', name: 'suite_notes_spaces')]
#[IsGranted('notes.markdown.use')]
final class NoteSpacesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly NoteSpaceRepository $repository,
        private readonly NoteSpaceManagerInterface $manager,
        private readonly NoteSpaceInputFactoryInterface $inputFactory,
        private readonly NoteSpaceSerializerInterface $serializer,
        private readonly PayloadValidator $payloadValidator,
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly CraftClient $craftClient,
    ) {}

    /** The spaces the person reads, their own first, with their role in each. */
    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Their own always exists, even before their first note.
        $this->spaceAccess->personalSpace($user);
        $spaces = $this->repository->findReadableFor($user);
        $roles = $this->spaceAccess->rolesFor($user, $spaces);

        return $this->jsonSuccess([
            'spaces' => array_map(
                fn (NoteSpaceInterface $space): array => $this->serializer->serialize($space, $user, $roles[(int) $space->getId()] ?? null),
                $spaces,
            ),
            'canCreate' => $this->spaceAccess->canCreateShared(),
            // The panel offers the Craft import in a space's menu, only when
            // the connection is open.
            'craftEnabled' => $this->craftClient->isConfigured(),
        ]);
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted(NoteSpaceAccess::CREATE)]
    public function create(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input) + $this->nameErrors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $space = $this->manager->create($user, $input);

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, NoteSpaceRoleEnum::Manager)]);
    }

    /** A space and its members, for whoever manages it. */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function show(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if (!$space instanceof NoteSpaceInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess([
            'space' => $this->serializer->serialize($space, $user, $this->spaceAccess->roleIn($user, $space)),
            'members' => array_map($this->serializer->serializeMember(...), $this->repository->findMembersOf($space)),
            'canPublish' => $this->spaceAccess->canPublish($user, $space),
        ]);
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if (!$space instanceof NoteSpaceInterface) {
            return $this->jsonNotFound();
        }

        if ($space->isManaged()) {
            return $this->refuseManaged();
        }

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        // The personal space carries no name: the manager ignores it, nothing
        // to require. And closing a space down to "only me" is for the owner
        // alone: a manager who did it would shut the door on themselves,
        // along with every member.
        $errors = $this->payloadValidator->errors($input) + ($space->isPersonal() ? [] : $this->nameErrors($input));
        $closing = !$space->isPersonal()
            && NoteSpaceAccessEnum::Private->value === $input->getAccess()
            && NoteSpaceAccessEnum::Private !== $space->getAccess();
        if ($closing && !$this->spaceAccess->isOwner($user, $space) && !$this->spaceAccess->adopts($user, $space)) {
            $errors['access'] = 'notes.markdown.spaces.errors.private_owner_only';
        }

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($space, $input);

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, $this->spaceAccess->roleIn($user, $space))]);
    }

    /**
     * Open the space for reading on the web, or close it again.
     *
     * A separate right (`notes.spaces.publish`), on top of managing the
     * space: putting a text in front of anybody is not the same decision as
     * sharing it with colleagues. Never one's personal space. The address is
     * derived from the name when none is given.
     */
    #[Route('/{id}/publish', name: '_publish', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function publish(int $id, Request $request, SluggerInterface $slugger): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if (!$space instanceof NoteSpaceInterface || !$this->spaceAccess->canPublish($user, $space)) {
            return $this->jsonNotFound();
        }

        if ($space->isManaged()) {
            return $this->refuseManaged();
        }

        $data = $this->decodeJson($request);

        if (true !== ($data['published'] ?? false)) {
            $this->manager->unpublish($space);

            return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, $this->spaceAccess->roleIn($user, $space))]);
        }

        $wanted = mb_trim((string) ($data['slug'] ?? ''));
        $slug = mb_substr($slugger->slug('' !== $wanted ? $wanted : (string) $space->getName())->lower()->toString(), 0, 120);

        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $this->jsonInvalidInput(['slug' => 'notes.markdown.spaces.errors.bad_slug']);
        }

        if ($this->repository->slugTaken($slug, (int) $space->getId())) {
            return $this->jsonInvalidInput(['slug' => 'notes.markdown.spaces.errors.slug_taken']);
        }

        $this->manager->publish($space, $slug, true === ($data['indexable'] ?? false));

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, $this->spaceAccess->roleIn($user, $space))]);
    }

    /** A shared space is removed with everything it holds; one's own, never. */
    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if (!$space instanceof NoteSpaceInterface || $space->isPersonal()) {
            return $this->jsonNotFound();
        }

        if ($space->isManaged()) {
            return $this->refuseManaged();
        }

        $this->manager->delete($space);

        return $this->jsonSuccess();
    }

    /**
     * Bringing back a removed space: by its owner alone, since a removed
     * space no longer has a role for anybody.
     *
     * A space configured elsewhere does not come back from here: the one of
     * a trashed client space comes back with it, and restoring it alone
     * would reopen the notes of a client the studio removed.
     */
    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->repository->find($id);
        if (!$space instanceof NoteSpaceInterface || !$space->getDeletedAt() instanceof DateTimeImmutable || (!$this->spaceAccess->isOwner($user, $space) && !$this->spaceAccess->adopts($user, $space))) {
            return $this->jsonNotFound();
        }

        if ($space->isManaged()) {
            return $this->refuseManaged();
        }

        $this->manager->restore($space);

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, NoteSpaceRoleEnum::Manager)]);
    }

    /**
     * Add a back-office person as a member, or change their role.
     *
     * Neither in one's personal space, nor the owner: they already manage
     * everything, and a row saying "reader" next to their name would lie.
     */
    #[Route('/{id}/members', name: '_members_set', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function setMember(int $id, Request $request, UserRepository $users): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if (!$space instanceof NoteSpaceInterface || $space->isPersonal()) {
            return $this->jsonNotFound();
        }

        if ($space->isManaged()) {
            return $this->refuseManaged();
        }

        $data = $this->decodeJson($request);
        $role = NoteSpaceRoleEnum::tryFrom((string) ($data['role'] ?? ''));
        $member = isset($data['userId']) && is_numeric($data['userId']) ? $users->find((int) $data['userId']) : null;

        if (!$role instanceof NoteSpaceRoleEnum) {
            return $this->jsonInvalidInput(['role' => 'notes.markdown.spaces.errors.bad_role']);
        }

        if (!$member instanceof CoreUserInterface || UserTypeEnum::Suite !== $member->getType() || $this->spaceAccess->isOwner($member, $space)) {
            return $this->jsonInvalidInput(['userId' => 'notes.markdown.spaces.errors.bad_member']);
        }

        return $this->jsonSuccess(['member' => $this->serializer->serializeMember($this->manager->setMember($space, $member, $role))]);
    }

    #[Route('/{id}/members/{userId}/remove', name: '_members_remove', requirements: ['id' => '\d+|__id__', 'userId' => '\d+|__user__'], methods: [HttpMethodEnum::Post->value])]
    public function removeMember(int $id, int $userId, UserRepository $users): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if ($space instanceof NoteSpaceInterface && $space->isManaged()) {
            return $this->refuseManaged();
        }

        $member = $users->find($userId);
        if (!$space instanceof NoteSpaceInterface || !$member instanceof CoreUserInterface || !$this->manager->removeMember($space, $member)) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess();
    }

    /**
     * The people who can be added: the back-office accounts, by name only.
     * The email address has no business in a menu anybody can open.
     */
    #[Route('/people', name: '_people', methods: [HttpMethodEnum::Get->value])]
    public function people(UserRepository $users): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Only for whoever may add someone: create a space, or manage a
        // shared one. The others have no use for the list.
        $spaces = $this->repository->findReadableFor($user);
        $roles = $this->spaceAccess->rolesFor($user, $spaces);
        $manages = [] !== array_filter($spaces, static fn (NoteSpaceInterface $space): bool => !$space->isPersonal() && true === ($roles[(int) $space->getId()] ?? null)?->canManage());
        if (!$manages && !$this->spaceAccess->canCreateShared()) {
            return $this->jsonSuccess(['people' => []]);
        }

        $people = $users->findBy(['type' => UserTypeEnum::Suite->value], ['name' => 'ASC']);

        return $this->jsonSuccess(['people' => array_values(array_filter(array_map(
            static fn (CoreUserInterface $one): ?array => $one->getId() === $user->getId() ? null : ['id' => $one->getId(), 'name' => $one->getName()],
            $people,
        )))]);
    }

    /** What a space configured elsewhere answers whoever tries to configure it from here. */
    private function refuseManaged(): JsonResponse
    {
        return $this->jsonFailure('notes.markdown.spaces.errors.managed', HttpStatusEnum::Conflict->value);
    }

    /**
     * A shared space is filed by its name: it needs one. The requirement
     * lives here rather than in the DTO, because the personal space has
     * none.
     *
     * @return array<string, string>
     */
    private function nameErrors(NoteSpaceInputInterface $input): array
    {
        return '' === mb_trim((string) $input->getName()) ? ['name' => 'notes.markdown.spaces.errors.name_required'] : [];
    }
}
