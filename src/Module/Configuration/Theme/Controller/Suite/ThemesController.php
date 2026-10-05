<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Theme\Dto\ThemeInputFactoryInterface;
use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Manager\ThemeManagerInterface;
use Aurora\Module\Configuration\Theme\Serializer\ThemeSerializerInterface;
use Aurora\Module\Configuration\Theme\View\ThemesViewBuilder;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/configuration/themes', name: 'suite_configuration_themes')]
#[IsGranted('configuration.themes.manage')]
final class ThemesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly ThemeManagerInterface $themeManager,
        private readonly ThemeSerializerInterface $themeSerializer,
        private readonly PayloadValidator $payloadValidator,
        private readonly ThemesViewBuilder $viewBuilder,
        private readonly ThemeInputFactoryInterface $themeInputFactory,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Configuration/suite/themes/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $input = $this->themeInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $theme = $this->themeManager->create($input);
        } catch (InvalidArgumentException $invalidArgumentException) {
            [$field, $message] = explode('|', $invalidArgumentException->getMessage(), 2) + ['_error', ''];

            return $this->jsonInvalidInput([$field => $message]);
        }

        return $this->jsonSuccess(['theme' => $this->themeSerializer->serialize($theme)]);
    }

    #[Route('/{id}/activate', name: '_activate', methods: [HttpMethodEnum::Post->value])]
    public function activate(ThemeInterface $theme): JsonResponse
    {
        $this->themeManager->activate($theme);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/edit', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(ThemeInterface $theme, Request $request): JsonResponse
    {
        $input = $this->themeInputFactory->fromArray(array_merge($this->decodeJson($request), ['slug' => $theme->getSlug()]));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->themeManager->update($theme, $input);

        return $this->jsonSuccess(['theme' => $this->themeSerializer->serialize($theme)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(ThemeInterface $theme): JsonResponse
    {
        try {
            $this->themeManager->delete($theme);
        } catch (RuntimeException $runtimeException) {
            return $this->jsonFailure($runtimeException->getMessage());
        }

        return $this->jsonSuccess();
    }
}
