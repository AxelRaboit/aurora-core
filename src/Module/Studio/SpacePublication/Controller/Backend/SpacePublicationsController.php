<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpacePublication\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpacePublication\View\SpacePublicationsViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_string;
use function mb_substr;
use function mb_trim;

/**
 * Starts a document for this client.
 *
 * A publication of the built-in page type, a draft, shared by link only and
 * attached to the space, prepared for the client by name. Everything after
 * that happens in the publications' editor, where the answer sends the author.
 *
 * Two rights, because it is two acts: writing in this client's space, and
 * creating a publication.
 */
#[Route('/workspace/{id}/publications', name: 'workspace_space_publications', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
final class SpacePublicationsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    private const int MAX_TITLE = 255;

    public function __construct(
        private readonly PostManagerInterface $postManager,
        private readonly PostInputFactoryInterface $inputFactory,
        private readonly PostTypeRepository $postTypes,
        private readonly LocaleContextInterface $localeContext,
        private readonly EditorialContext $editorialContext,
        private readonly SpacePublicationsViewBuilder $viewBuilder,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    #[IsGranted('editorial.posts.create')]
    public function create(CustomerSpace $space, Request $request): JsonResponse
    {
        $page = $this->postTypes->findOneBySlug('page');

        if (!$this->editorialContext->isPostsEnabled() || !$page instanceof PostTypeInterface) {
            return $this->jsonNotFound();
        }

        $raw = $this->decodeJson($request)['title'] ?? null;
        $title = is_string($raw) ? mb_substr(mb_trim($raw), 0, self::MAX_TITLE) : '';

        if ('' === $title) {
            return $this->jsonInvalidInput(['title' => 'backend.studio.space_publications.errors.title_required']);
        }

        $post = $this->postManager->create($this->inputFactory->fromArray([
            'postTypeId' => $page->getId(),
            'status' => PostStatusEnum::Draft->value,
            'visibility' => PostVisibilityEnum::Link->value,
            // Who it is for, said once here rather than typed by hand on every
            // document: the editor can change it, a brand name for instance.
            'readingPage' => ['preparedFor' => $space->getCustomer()->getLegalName()],
            // No comments and no share buttons on a client's document: the
            // reading page drops them anyway, and the settings should say so.
            'commentsEnabled' => false,
            'shareEnabled' => false,
            'translations' => [$this->localeContext->getDefaultLocale() => ['title' => $title]],
        ]));
        $post->setCustomerSpaceId((int) $space->getId());

        $this->entityManager->flush();

        return $this->jsonSuccess([
            'editPath' => $this->generateUrl('backend_editorial_posts_edit', ['id' => $post->getId()]),
            'publications' => $this->viewBuilder->publications($space),
        ]);
    }
}
