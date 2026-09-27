<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Editorial\Post\Share\SiteUsefulLinks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reads and writes the site's useful links for their Configuration tab.
 *
 * A link the normalizer refuses - no words, or an address that is not
 * https or mailto - is dropped rather than refused whole: the answer carries
 * the list as kept, and the tab shows it, so nothing is lost silently.
 */
#[Route('/backend/editorial/useful-links/settings', name: 'backend_editorial_useful_links_settings')]
#[IsGranted('configuration.settings.manage')]
final class UsefulLinksSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly SiteUsefulLinks $siteUsefulLinks,
    ) {}

    #[Route('', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(): JsonResponse
    {
        return $this->jsonSuccess(['links' => $this->siteUsefulLinks->links()]);
    }

    #[Route('', name: '_save', methods: [HttpMethodEnum::Post->value])]
    public function save(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        return $this->jsonSuccess(['links' => $this->siteUsefulLinks->save($payload['links'] ?? [])]);
    }
}
