<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Security;

use Aurora\Core\Module\Security\ModulePermissionVoter;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\SpaceScopeEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quels espaces une personne voit, et lesquels elle administre.
 *
 * **Une seule réponse à « voit-il tout »**, et c'est la raison d'être de ce
 * service. Trois écrans se posent la question, et trois copies auraient fini
 * par répondre différemment le jour où un quatrième rôle apparaît.
 *
 * Le privilège dit *ce qu'on peut faire*, l'appartenance dit *sur quoi*. Les
 * deux se cumulent : un équipier sans le privilège de modifier ne modifie pas,
 * même sur son propre espace ; un équipier qui l'a ne modifie que les siens.
 *
 * Administrateur et développeur court-circuitent déjà chaque privilège dans
 * {@see ModulePermissionVoter} ; ils
 * court-circuitent l'appartenance ici, pour que les deux moitiés du modèle
 * disent la même chose.
 */
final readonly class SpaceVisibility
{
    public function __construct(
        private Security $security,
        private CustomerSpaceRepository $spaces,
    ) {}

    /**
     * Voit-elle tous les espaces, sans être membre d'aucun ?
     *
     * Les deux rôles qui passent au-dessus des privilèges, et eux seuls.
     */
    public function seesAll(): bool
    {
        if ($this->security->isGranted(UserRoleEnum::Dev->value)) {
            return true;
        }

        return $this->security->isGranted(UserRoleEnum::Admin->value);
    }

    /** @return list<CustomerSpaceInterface> */
    public function visibleSpaces(): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            return [];
        }

        return $this->spaces->findVisibleTo($user, $this->seesAll());
    }

    /**
     * The spaces a cross-space screen shows, for this scope.
     *
     * « Mine » is the spaces the reader is a member of. Somebody who sees
     * every space but is a member of none gets every space instead: their own
     * would be an empty screen, and they are the one person for whom « all »
     * is the natural answer.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function spacesIn(SpaceScopeEnum $scope): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            return [];
        }

        $mine = $this->spaces->findVisibleTo($user, false);

        if (!$this->seesAll()) {
            return $mine;
        }

        return SpaceScopeEnum::Mine === $scope && [] !== $mine ? $mine : $this->spaces->findVisibleTo($user, true);
    }

    /**
     * Whether « mine » and « all » are two different answers for this reader,
     * so the screen should offer the switch at all.
     */
    public function hasScopeChoice(): bool
    {
        $user = $this->security->getUser();

        return $this->seesAll() && $user instanceof CoreUserInterface && [] !== $this->spaces->findVisibleTo($user, false);
    }

    public function canSee(CustomerSpaceInterface $space): bool
    {
        if ($this->seesAll()) {
            return true;
        }

        $user = $this->security->getUser();

        return $user instanceof CoreUserInterface
            && $this->spaces->isVisibleTo($space, $user, false);
    }

    /**
     * Peut-elle ouvrir les réglages de cet espace ?
     *
     * **C'est ce qui donne enfin un sens au rôle de référent.** Il ne disait
     * jusqu'ici qu'à qui s'adresser ; il ouvre maintenant une porte que ses
     * coéquipiers n'ont pas. Le reste du travail dans l'espace ne change pas :
     * un équipier écrit, commente et programme comme avant.
     */
    public function canConfigure(CustomerSpaceInterface $space): bool
    {
        if ($this->seesAll()) {
            return true;
        }

        $user = $this->security->getUser();

        if (!$user instanceof CoreUserInterface) {
            return false;
        }

        foreach ($space->getMembers() as $member) {
            if ($member->getUser() === $user && CustomerSpaceMemberRoleEnum::Lead === $member->getRole()) {
                return true;
            }
        }

        return false;
    }
}
