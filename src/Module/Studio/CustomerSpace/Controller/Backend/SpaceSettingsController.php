<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function mb_strlen;
use function mb_trim;

/**
 * Les réglages d'un espace, ouverts au référent.
 *
 * **C'est ce qui donne enfin un sens au rôle de responsable.** Il ne disait
 * jusqu'ici qu'à qui s'adresser ; il ouvre maintenant une porte que ses
 * coéquipiers n'ont pas. Le reste du travail ne bouge pas : un équipier écrit,
 * commente et programme comme avant.
 *
 * **Un 404 et non un 403** pour qui n'y a pas droit, comme partout ailleurs
 * sur un espace : ne pas confirmer qu'une porte existe est moins bavard que
 * de la refuser poliment.
 */
#[Route('/workspace/{id}/settings', name: 'workspace_space_settings', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
final class SpaceSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    /**
     * Ce qu'un mot de passe doit peser au minimum.
     *
     * Huit, le même plancher que partout ailleurs. Plus haut ici serait plus
     * sévère pour une porte intérieure que pour la porte d'entrée, ce qui ne
     * se justifie pas.
     */
    private const int MIN_LENGTH = 8;

    public function __construct(
        private readonly SpaceVisibility $visibility,
        private readonly DriveLock $lock,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** L'état des réglages, sans jamais rendre le mot de passe lui-même. */
    #[Route('', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(CustomerSpace $space): JsonResponse
    {
        $this->denyUnlessReferent($space);

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Pose ou remplace le mot de passe du Drive.
     *
     * Remplacer demande l'ancien : sans cela, un écran laissé ouvert suffirait
     * à le changer, et la serrure ne vaudrait que jusqu'à la prochaine pause
     * café.
     */
    #[Route('/drive-password', name: '_drive_password', methods: [HttpMethodEnum::Post->value])]
    public function setDrivePassword(CustomerSpace $space, Request $request): JsonResponse
    {
        $this->denyUnlessReferent($space);

        $payload = $this->decodeJson($request);
        $password = (string) ($payload['password'] ?? '');
        $current = (string) ($payload['currentPassword'] ?? '');

        if ($space->isDriveLocked() && !$this->lock->matches($space, $current)) {
            return $this->jsonFailure('backend.studio.spaces.settings.errors.wrong_password');
        }

        if (mb_strlen($password) < self::MIN_LENGTH) {
            return $this->jsonFailure('backend.studio.spaces.settings.errors.password_too_short');
        }

        $this->lock->set($space, $password);
        $this->entityManager->flush();

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Rouvre l'onglet, contre le mot de passe en cours.
     *
     * **Le saisir est ce qui distingue une serrure d'un ralentisseur.** Sans
     * cette exigence, quiconque atteint cet écran l'enlève en un clic. Le prix
     * est qu'un oubli bloque : le secours est `aurora:space:drive-password:clear`
     * sur le serveur, et c'est le bon niveau d'autorité pour forcer une porte.
     */
    #[Route('/drive-password/clear', name: '_drive_password_clear', methods: [HttpMethodEnum::Post->value])]
    public function clearDrivePassword(CustomerSpace $space, Request $request): JsonResponse
    {
        $this->denyUnlessReferent($space);

        if (!$space->isDriveLocked()) {
            return $this->jsonSuccess(['settings' => $this->state($space)]);
        }

        $current = mb_trim((string) ($this->decodeJson($request)['currentPassword'] ?? ''));

        if (!$this->lock->matches($space, $current)) {
            return $this->jsonFailure('backend.studio.spaces.settings.errors.wrong_password');
        }

        $this->lock->clear($space);
        $this->entityManager->flush();

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Ouvre l'onglet Drive pour cette session.
     *
     * Sur les réglages et non sur le Drive, parce que c'est ici qu'on sait ce
     * qu'est un mot de passe d'espace ; l'écran du Drive ne fait qu'afficher
     * la demande.
     */
    #[Route('/drive-unlock', name: '_drive_unlock', methods: [HttpMethodEnum::Post->value])]
    public function unlockDrive(CustomerSpace $space, Request $request): JsonResponse
    {
        // Pas `denyUnlessReferent` : déverrouiller n'est pas configurer. Tout
        // équipier qui voit l'espace peut ouvrir l'onglet s'il connaît le mot
        // de passe, ce qui est exactement ce qu'un mot de passe veut dire.
        if (!$this->visibility->canSee($space)) {
            throw $this->createNotFoundException();
        }

        $password = (string) ($this->decodeJson($request)['password'] ?? '');

        if (!$this->lock->unlock($space, $password)) {
            return $this->jsonFailure('backend.studio.spaces.settings.errors.wrong_password');
        }

        return $this->jsonSuccess(['unlocked' => true]);
    }

    /** @return array<string, mixed> */
    private function state(CustomerSpace $space): array
    {
        return [
            'driveLocked' => $space->isDriveLocked(),
            'driveUnlocked' => $this->lock->isUnlocked($space),
            'minPasswordLength' => self::MIN_LENGTH,
        ];
    }

    private function denyUnlessReferent(CustomerSpace $space): void
    {
        if (!$this->visibility->canConfigure($space)) {
            throw $this->createNotFoundException();
        }
    }
}
