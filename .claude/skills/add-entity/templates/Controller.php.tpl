<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use {{NAMESPACE}}\Dto\{{NAME}}InputFactoryInterface;
use {{NAMESPACE}}\Entity\{{NAME}}Interface;
use {{NAMESPACE}}\Manager\{{NAME}}ManagerInterface;
use {{NAMESPACE}}\Repository\{{NAME}}Repository;
use {{NAMESPACE}}\Serializer\{{NAME}}SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/{{PLURAL_KEBAB}}', name: 'suite_{{PLURAL_SNAKE}}')]
#[IsGranted('{{PERMISSION}}')]
class {{PLURAL_NAME}}Controller extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly {{NAME}}Repository ${{NAME_CAMEL}}Repository,
        protected readonly {{NAME}}SerializerInterface ${{NAME_CAMEL}}Serializer,
        protected readonly {{NAME}}ManagerInterface ${{NAME_CAMEL}}Manager,
        protected readonly {{NAME}}InputFactoryInterface ${{NAME_CAMEL}}InputFactory,
        protected readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        // TODO: implement a {{PLURAL_NAME}}ViewBuilder for the index payload.
        return $this->render('@{{TWIG_NAMESPACE}}/suite/{{PLURAL_SNAKE}}/index.html.twig', [
            '{{PLURAL_SNAKE}}' => array_map(
                fn ({{NAME}}Interface ${{NAME_CAMEL}}): array => $this->{{NAME_CAMEL}}Serializer->serialize(${{NAME_CAMEL}}),
                $this->{{NAME_CAMEL}}Repository->findAll(),
            ),
        ]);
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $input = $this->{{NAME_CAMEL}}InputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        ${{NAME_CAMEL}} = $this->{{NAME_CAMEL}}Manager->create($input);

        return $this->jsonSuccess(['{{NAME_CAMEL}}' => $this->{{NAME_CAMEL}}Serializer->serialize(${{NAME_CAMEL}})]);
    }

    #[Route('/{id}/edit', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update({{NAME}}Interface ${{NAME_CAMEL}}, Request $request): JsonResponse
    {
        $input = $this->{{NAME_CAMEL}}InputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->{{NAME_CAMEL}}Manager->update(${{NAME_CAMEL}}, $input);

        return $this->jsonSuccess(['{{NAME_CAMEL}}' => $this->{{NAME_CAMEL}}Serializer->serialize(${{NAME_CAMEL}})]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete({{NAME}}Interface ${{NAME_CAMEL}}): JsonResponse
    {
        $this->{{NAME_CAMEL}}Manager->delete(${{NAME_CAMEL}});

        return $this->jsonSuccess();
    }
}
