<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Space\Dto\NoteSpaceInputFactoryInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
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
 * Les espaces de notes : les lister, les créer, les régler, y inscrire.
 *
 * **Un espace qu'on ne peut pas gérer répond 404**, comme une note qu'on ne
 * peut pas lire : dire « interdit » confirmerait qu'il existe.
 */
#[Route('/backend/notes/spaces', name: 'backend_notes_spaces')]
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
    ) {}

    /** Les espaces que la personne lit, le sien d'abord, avec son rôle dans chacun. */
    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Le sien existe toujours, même avant sa première note.
        $this->spaceAccess->personalSpace($user);
        $spaces = $this->repository->findReadableFor($user);
        $roles = $this->spaceAccess->rolesFor($user, $spaces);

        return $this->jsonSuccess([
            'spaces' => array_map(
                fn (NoteSpaceInterface $space): array => $this->serializer->serialize($space, $user, $roles[(int) $space->getId()] ?? null),
                $spaces,
            ),
            'canCreate' => $this->spaceAccess->canCreateShared(),
        ]);
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted(NoteSpaceAccess::CREATE)]
    public function create(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $space = $this->manager->create($user, $input);

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, NoteSpaceRoleEnum::Manager)]);
    }

    /** Un espace et ses inscrits, pour qui le gère. */
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

        $data = $this->decodeJson($request);

        // L'espace personnel n'a pas de nom à exiger : il n'en porte pas.
        if ($space->isPersonal()) {
            $data['name'] = 'personal';
        }

        $input = $this->inputFactory->fromArray($data);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($space, $input);

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, $this->spaceAccess->roleIn($user, $space))]);
    }

    /**
     * Ouvrir l'espace en lecture sur le web, ou le refermer.
     *
     * Un droit à part (`notes.spaces.publish`), en plus de gérer l'espace :
     * mettre un texte sous les yeux de n'importe qui n'est pas la même
     * décision que le partager avec des collègues. Jamais son espace
     * personnel. L'adresse se déduit du nom quand on n'en donne pas.
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

    /** Un espace partagé se retire avec tout ce qu'il range ; le sien, jamais. */
    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->spaceAccess->managedSpace($user, $id);
        if (!$space instanceof NoteSpaceInterface || $space->isPersonal()) {
            return $this->jsonNotFound();
        }

        $this->manager->delete($space);

        return $this->jsonSuccess();
    }

    /**
     * Le retour d'un espace retiré : par son propriétaire seul, puisqu'un
     * espace retiré n'a plus de rôle pour personne.
     */
    #[Route('/{id}/restore', name: '_restore', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $space = $this->repository->find($id);
        if (!$space instanceof NoteSpaceInterface || !$space->getDeletedAt() instanceof DateTimeImmutable || !$this->spaceAccess->isOwner($user, $space)) {
            return $this->jsonNotFound();
        }

        $this->manager->restore($space);

        return $this->jsonSuccess(['space' => $this->serializer->serialize($space, $user, NoteSpaceRoleEnum::Manager)]);
    }

    /**
     * Inscrire une personne du back-office, ou changer son rôle.
     *
     * Ni dans son espace personnel, ni le propriétaire : il gère déjà tout,
     * et une ligne qui dirait « lecteur » à côté de son nom mentirait.
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

        $data = $this->decodeJson($request);
        $role = NoteSpaceRoleEnum::tryFrom((string) ($data['role'] ?? ''));
        $member = isset($data['userId']) && is_numeric($data['userId']) ? $users->find((int) $data['userId']) : null;

        if (!$role instanceof NoteSpaceRoleEnum) {
            return $this->jsonInvalidInput(['role' => 'notes.markdown.spaces.errors.bad_role']);
        }

        if (!$member instanceof CoreUserInterface || UserTypeEnum::Backend !== $member->getType() || $this->spaceAccess->isOwner($member, $space)) {
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
        $member = $users->find($userId);
        if (!$space instanceof NoteSpaceInterface || !$member instanceof CoreUserInterface || !$this->manager->removeMember($space, $member)) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess();
    }

    /**
     * Les personnes qu'on peut inscrire : les comptes du back-office, par
     * leur nom seulement. L'adresse e-mail n'a rien à faire dans un menu
     * que tout le monde peut ouvrir.
     */
    #[Route('/people', name: '_people', methods: [HttpMethodEnum::Get->value])]
    public function people(UserRepository $users): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $people = $users->findBy(['type' => UserTypeEnum::Backend->value], ['name' => 'ASC']);

        return $this->jsonSuccess(['people' => array_values(array_filter(array_map(
            static fn (CoreUserInterface $one): ?array => $one->getId() === $user->getId() ? null : ['id' => $one->getId(), 'name' => $one->getName()],
            $people,
        )))]);
    }
}
