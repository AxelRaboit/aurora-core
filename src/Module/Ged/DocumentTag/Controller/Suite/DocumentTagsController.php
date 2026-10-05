<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentTag\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Ged\DocumentTag\Dto\DocumentTagInputFactoryInterface;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTag;
use Aurora\Module\Ged\DocumentTag\Manager\DocumentTagManagerInterface;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;
use Aurora\Module\Ged\DocumentTag\Serializer\DocumentTagSerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/ged/tags', name: 'suite_ged_tags')]
#[IsGranted('ged.tags.manage')]
final class DocumentTagsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DocumentTagSerializerInterface $serializer,
        private readonly DocumentTagManagerInterface $manager,
        private readonly PayloadValidator $payloadValidator,
        private readonly DocumentTagRepository $tagRepository,
        private readonly DocumentTagInputFactoryInterface $inputFactory,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        $tags = array_map($this->serializer->serialize(...), $this->tagRepository->findAllOrdered());

        return $this->render('@Ged/suite/tags/index.html.twig', [
            'tags' => $tags,
            'createPath' => $this->urlGenerator->generate('suite_ged_tags_create'),
            'updatePath' => $this->urlGenerator->generate('suite_ged_tags_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('suite_ged_tags_delete', ['id' => '__id__']),
        ]);
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $tag = $this->manager->create($input);

        return $this->jsonSuccess(['tag' => $this->serializer->serialize($tag), 'tags' => $this->allTags()]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(DocumentTag $tag, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->manager->update($tag, $input);

        return $this->jsonSuccess(['tag' => $this->serializer->serialize($tag), 'tags' => $this->allTags()]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(DocumentTag $tag): JsonResponse
    {
        $this->manager->delete($tag);

        return $this->jsonSuccess();
    }

    private function allTags(): array
    {
        return array_map($this->serializer->serialize(...), $this->tagRepository->findAllOrdered());
    }
}
