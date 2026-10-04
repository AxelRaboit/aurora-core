<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Post\Entity\GridSection;
use Aurora\Module\Editorial\Post\Entity\GridSectionInterface;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Repository\GridSectionRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_map;
use function count;
use function is_string;
use function mb_substr;
use function mb_trim;

/**
 * The grid sections a person keeps, offered back in every grid they edit.
 *
 * Personal, like one's notifications: signed in is enough, and every action
 * answers only for the person's own sections - another's is « not found »,
 * not « forbidden », so its existence is not confirmed either. Outside the
 * editorial prefix on purpose: a deliverable uses them too, editorial module
 * on or off.
 */
#[Route('/backend/grid-sections', name: 'backend_grid_sections')]
#[IsGranted('ROLE_USER')]
final class GridSectionsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    /** Enough for a library; more would be a second document kept in pieces. */
    private const int MAX_SECTIONS = 100;

    private const int MAX_NAME = 120;

    public function __construct(
        private readonly GridSectionRepository $sections,
        private readonly GridNormalizer $gridNormalizer,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        return $this->jsonSuccess(['sections' => $this->payload()]);
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $owner = $this->owner();
        $data = $this->decodeJson($request);

        $name = is_string($data['name'] ?? null) ? mb_substr(mb_trim($data['name']), 0, self::MAX_NAME) : '';
        if ('' === $name) {
            return $this->jsonInvalidInput(['name' => 'backend.posts.grid.sections.name_required']);
        }

        if (count($this->sections->findOwnedBy($owner)) >= self::MAX_SECTIONS) {
            return $this->jsonInvalidInput(['name' => 'backend.posts.grid.sections.too_many']);
        }

        // Through the grid's own normaliser, like a saved page: a section
        // holds nothing a grid could not.
        $layout = $this->gridNormalizer->normalizeLayout(['enabled' => true, 'zones' => $data['zones'] ?? []]);
        if ([] === $layout['zones']) {
            return $this->jsonInvalidInput(['zones' => 'backend.posts.grid.sections.empty']);
        }

        $section = new GridSection();
        $section->setName($name)
            ->setOwner($owner)
            ->setLayout($layout['zones'])
            ->setContent($this->gridNormalizer->normalizeContent(['zones' => $data['content'] ?? []], $layout)['zones'] ?? []);

        $this->entityManager->persist($section);
        $this->entityManager->flush();

        return $this->jsonSuccess(['sections' => $this->payload()]);
    }

    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        $section = $this->sections->find($id);
        if (!$section instanceof GridSectionInterface || $section->getOwner()?->getId() !== $this->owner()->getId()) {
            return $this->jsonNotFound();
        }

        $this->entityManager->remove($section);
        $this->entityManager->flush();

        return $this->jsonSuccess(['sections' => $this->payload()]);
    }

    /** @return list<array{id: int|null, name: string, zones: list<array<string, mixed>>, content: array<string, mixed>}> */
    private function payload(): array
    {
        return array_map(
            static fn (GridSectionInterface $section): array => [
                'id' => $section->getId(),
                'name' => $section->getName(),
                'zones' => $section->getLayout(),
                'content' => $section->getContent(),
            ],
            $this->sections->findOwnedBy($this->owner()),
        );
    }

    private function owner(): CoreUserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof CoreUserInterface) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
