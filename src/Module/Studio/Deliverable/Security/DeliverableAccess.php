<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Security;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qui lit et qui modifie un livrable : la seule règle, pour tout le module.
 *
 * **Dans un espace client**, rien ne change : c'est l'espace qui décide. Il
 * faut le voir (son équipe, ou les rôles qui voient tout) et les droits des
 * espaces, `studio.spaces.view` pour lire, `studio.spaces.edit` pour écrire.
 *
 * **Sans espace**, deux rayons :
 * - partagé : tous ceux qui ont `studio.deliverables.view` le lisent, ceux
 *   qui ont `studio.deliverables.edit` le modifient, et son auteur aussi ;
 * - perso : son auteur seul, pour peu qu'il ait accès au module, et il le
 *   supprime sans le droit de suppression.
 *
 * Un livrable perso dont l'auteur a disparu revient aux administrateurs,
 * comme un espace de notes orphelin : sans quoi plus personne ne pourrait ni
 * le lire ni le supprimer. Hors de ce cas, un administrateur n'ouvre pas le
 * livrable perso d'un autre : le droit de tout voir porte sur les modules,
 * pas sur ce que chacun garde pour soi.
 */
final readonly class DeliverableAccess
{
    public const string VIEW = 'studio.deliverables.view';

    public const string CREATE = 'studio.deliverables.create';

    public const string EDIT = 'studio.deliverables.edit';

    public const string DELETE = 'studio.deliverables.delete';

    public const string SHARE = 'studio.deliverables.share';

    public function __construct(
        private Security $security,
        private SpaceVisibility $spaceVisibility,
    ) {}

    public function canRead(DeliverableInterface $deliverable): bool
    {
        $space = $deliverable->getSpace();
        if ($space instanceof CustomerSpaceInterface) {
            return $this->security->isGranted('studio.spaces.view') && $this->spaceVisibility->canSee($space);
        }

        if (!$this->security->isGranted(self::VIEW)) {
            return false;
        }

        if (DeliverableScopeEnum::Shared === $deliverable->getScope()) {
            return true;
        }

        return $this->holdsPersonal($deliverable);
    }

    public function canWrite(DeliverableInterface $deliverable): bool
    {
        $space = $deliverable->getSpace();
        if ($space instanceof CustomerSpaceInterface) {
            return $this->security->isGranted('studio.spaces.edit') && $this->spaceVisibility->canSee($space);
        }

        if (!$this->security->isGranted(self::VIEW)) {
            return false;
        }

        // Son auteur garde la main sur un livrable qu'il a partagé : sans quoi
        // créer dans « Partagés » sans le droit de modifier fermerait
        // l'éditeur à celui qui vient de l'ouvrir.
        return DeliverableScopeEnum::Shared === $deliverable->getScope()
            ? $this->security->isGranted(self::EDIT) || $this->isOwner($deliverable)
            : $this->holdsPersonal($deliverable);
    }

    /** Créer, révoquer un lien de lecture : un envoi hors du back-office. */
    public function canShare(DeliverableInterface $deliverable): bool
    {
        if (!$deliverable->isStandalone()) {
            return $this->canWrite($deliverable);
        }

        return $this->canWrite($deliverable) && $this->security->isGranted(self::SHARE);
    }

    public function canDelete(DeliverableInterface $deliverable): bool
    {
        if (!$deliverable->isStandalone()) {
            return $this->canWrite($deliverable);
        }

        // Un brouillon perso se jette par qui le garde, droit ou pas : personne
        // d'autre ne le voit pour le faire à sa place.
        if (DeliverableScopeEnum::Personal === $deliverable->getScope()) {
            return $this->canWrite($deliverable);
        }

        return $this->canWrite($deliverable) && $this->security->isGranted(self::DELETE);
    }

    /**
     * Passer de perso à partagé, ou l'inverse : à l'auteur seul.
     *
     * Rendre perso un livrable partagé le retire à toute l'équipe ; ce n'est
     * pas un geste qu'un autre membre fait pour lui. Un orphelin se décide
     * par l'administrateur qui l'a recueilli.
     */
    public function canChangeScope(DeliverableInterface $deliverable): bool
    {
        return $deliverable->isStandalone()
            && $this->security->isGranted(self::VIEW)
            && ($this->isOwner($deliverable) || $this->adopts($deliverable));
    }

    public function canCreate(): bool
    {
        return $this->security->isGranted(self::VIEW) && $this->security->isGranted(self::CREATE);
    }

    public function isOwner(DeliverableInterface $deliverable): bool
    {
        $user = $this->user();
        $owner = $deliverable->getOwner();

        return $user instanceof CoreUserInterface && $owner instanceof CoreUserInterface && $owner->getId() === $user->getId();
    }

    /** Ce qui reste d'un compte supprimé revient à qui administre. */
    public function adopts(DeliverableInterface $deliverable): bool
    {
        return !$deliverable->getOwner() instanceof CoreUserInterface && self::isAdmin($this->user());
    }

    public static function isAdmin(?CoreUserInterface $user): bool
    {
        if (!$user instanceof CoreUserInterface) {
            return false;
        }

        return UserRoleEnum::administers($user->getRoles());
    }

    public function user(): ?CoreUserInterface
    {
        $user = $this->security->getUser();

        return $user instanceof CoreUserInterface ? $user : null;
    }

    private function holdsPersonal(DeliverableInterface $deliverable): bool
    {
        if ($this->isOwner($deliverable)) {
            return true;
        }

        return $this->adopts($deliverable);
    }
}
