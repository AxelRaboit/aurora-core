<?php

declare(strict_types=1);

namespace Aurora\Core\Version\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The version the server runs now, for a suite page loaded under an older one.
 *
 * A tab left open across a deployment keeps the scripts it was served: it
 * talks to the new server with the old screens, and nothing says so. The page
 * asks here when it comes back to the front and offers to reload when the
 * answer is not the version it was rendered with.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class VersionController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(private readonly AppVersion $appVersion) {}

    #[Route('/suite/version', name: 'suite_version', methods: [HttpMethodEnum::Get->value])]
    public function current(): JsonResponse
    {
        $response = $this->jsonSuccess(['version' => $this->appVersion->current()]);
        // Never from a cache: an answer kept from before the deployment would
        // say that nothing changed.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
