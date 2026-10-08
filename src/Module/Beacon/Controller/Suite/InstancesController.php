<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Controller\Suite;

use Aurora\Module\Beacon\Entity\DeployedInstanceInterface;
use Aurora\Module\Beacon\Repository\DeployedInstanceRepository;
use Aurora\Module\Beacon\Setting\BeaconSettingEnum;
use Aurora\Module\Beacon\View\BeaconViewBuilder;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_string;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;

/**
 * The beacon back-office screen (ROLE_DEV): lists the deployed instances the
 * receiver has recorded, and edits the domain allowlist that tells your own
 * deployments from a copy (see LICENSE). Nothing here can accept or reject a
 * ping - it only reads what was recorded and sets the known/unknown reference.
 */
#[Route('/dev/beacon')]
#[IsGranted('ROLE_DEV')]
final class InstancesController extends AbstractController
{
    public function __construct(
        private readonly DeployedInstanceRepository $deployedInstanceRepository,
        private readonly BeaconViewBuilder $viewBuilder,
        private readonly SettingRepository $settingRepository,
    ) {}

    #[Route('', name: 'beacon_instances', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Beacon/suite/instances/index.html.twig', $this->viewBuilder->indexView());
    }

    #[Route('/known-domains', name: 'beacon_known_domains', methods: ['POST'])]
    public function saveKnownDomains(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $raw = is_array($data) && is_array($data['domains'] ?? null) ? $data['domains'] : null;
        if (null === $raw) {
            return $this->json(['success' => false], Response::HTTP_BAD_REQUEST);
        }

        $domains = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $clean = mb_strtolower(mb_trim($entry));
            if ('' !== $clean) {
                $domains[mb_substr($clean, 0, 255)] = true;
            }
        }

        $domains = array_keys($domains);

        $this->settingRepository->set(BeaconSettingEnum::KnownDomains->value, json_encode($domains));

        return $this->json(['success' => true, 'domains' => $domains]);
    }

    #[Route('/{id}/forget', name: 'beacon_forget', methods: ['POST'])]
    public function forget(int $id): JsonResponse
    {
        $instance = $this->deployedInstanceRepository->find($id);
        if (!$instance instanceof DeployedInstanceInterface) {
            return $this->json(['success' => false], Response::HTTP_NOT_FOUND);
        }

        $this->deployedInstanceRepository->remove($instance);

        return $this->json(['success' => true]);
    }
}
