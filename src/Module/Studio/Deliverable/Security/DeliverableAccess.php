<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Security;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\StudioContext;
use Symfony\Bundle\SecurityBundle\Security;

use function array_filter;
use function array_values;

/**
 * Who reads and who edits a deliverable: the one rule, for the whole module.
 *
 * **In a client space**, nothing changes: the space decides. You must see it
 * (its team, or the roles that see everything) and hold the space rights:
 * `studio.spaces.view` to read, `studio.spaces.edit` to write,
 * `studio.spaces.share` to give a reading address, the same right as access
 * to the space itself, since an address opens a client document to whoever
 * holds it. An archived space receives no new deliverable (creation,
 * duplication, copy); the ones it keeps stay editable, because archiving is
 * not deleting.
 *
 * **Without a space**, two shelves:
 * - shared: everyone with `studio.deliverables.view` reads it, everyone with
 *   `studio.deliverables.edit` edits it, and its author too;
 * - personal: its author only, provided they have access to the module, and
 *   they delete it without the delete right.
 *
 * A personal deliverable whose author is gone falls to the administrators,
 * like an orphaned notes space: otherwise nobody could read or delete it any
 * more. Outside that case, an administrator does not open someone else's
 * personal deliverable: the right to see everything covers the modules, not
 * what each person keeps to themselves.
 *
 * **Duplicating** is creating: in Studio, the right to create deliverables;
 * in a space, the right to write there, and not in an archive. The two
 * contexts do not ask for the same right because they do not have the same
 * model (the module's rights, the space's): this is intended, and each one
 * follows the rule of what it creates.
 *
 * **Copying a Studio deliverable into a space** is writing into that space:
 * you must read the original, and be able to create a deliverable in the
 * target space, that is see it with `studio.spaces.edit`, with the spaces
 * module on. An archived space receives none. The other way round, the copy
 * of a space deliverable lands in Studio's "Mes livrables": you must read the
 * space and be able to create a Studio deliverable.
 */
final readonly class DeliverableAccess
{
    public const string VIEW = 'studio.deliverables.view';

    public const string CREATE = 'studio.deliverables.create';

    public const string EDIT = 'studio.deliverables.edit';

    public const string DELETE = 'studio.deliverables.delete';

    public const string SHARE = 'studio.deliverables.share';

    /** Give a reading address for a space deliverable: the right of access to the space. */
    public const string SPACE_SHARE = 'studio.spaces.share';

    public function __construct(
        private Security $security,
        private SpaceVisibility $spaceVisibility,
        private StudioContext $studioContext,
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

        // Its author keeps control of a deliverable they shared: otherwise
        // creating in "Partagés" without the edit right would close the
        // editor to the person who just opened it.
        return DeliverableScopeEnum::Shared === $deliverable->getScope()
            ? $this->security->isGranted(self::EDIT) || $this->isOwner($deliverable)
            : $this->holdsPersonal($deliverable);
    }

    /**
     * See, create or revoke reading links: a send outside the back office,
     * and the list carries the addresses themselves, tokens included.
     *
     * In a space, it is the right to share the space, without the right to
     * edit: giving an address is not writing in the document.
     */
    public function canShare(DeliverableInterface $deliverable): bool
    {
        $space = $deliverable->getSpace();
        if ($space instanceof CustomerSpaceInterface) {
            return $this->security->isGranted(self::SPACE_SHARE) && $this->spaceVisibility->canSee($space);
        }

        return $this->canWrite($deliverable) && $this->security->isGranted(self::SHARE);
    }

    public function canDelete(DeliverableInterface $deliverable): bool
    {
        if (!$deliverable->isStandalone()) {
            return $this->canWrite($deliverable);
        }

        // A personal draft is thrown away by whoever keeps it, right or not:
        // nobody else sees it to do it for them.
        if (DeliverableScopeEnum::Personal === $deliverable->getScope()) {
            return $this->canWrite($deliverable);
        }

        return $this->canWrite($deliverable) && $this->security->isGranted(self::DELETE);
    }

    /**
     * Move from personal to shared, or the reverse: the author only.
     *
     * Making a shared deliverable personal takes it away from the whole team;
     * that is not something another member does on the author's behalf. An
     * orphan is decided by the administrator who took it in.
     */
    public function canChangeScope(DeliverableInterface $deliverable): bool
    {
        return $deliverable->isStandalone()
            && $this->security->isGranted(self::VIEW)
            && ($this->isOwner($deliverable) || $this->adopts($deliverable));
    }

    /**
     * Create, rename, reorder or delete categories: that is reorganising the
     * team's library, so the right to edit deliverables. Filing your own
     * deliverable under an existing category only requires being able to
     * write it.
     */
    public function canManageCategories(): bool
    {
        return $this->security->isGranted(self::VIEW) && $this->security->isGranted(self::EDIT);
    }

    /**
     * Name the client a Studio deliverable was written for, and see that name
     * on the card: it takes the clients module on, and the right to see their
     * list. The selector carries the whole client list, and a right on
     * deliverables does not grant it.
     */
    public function canPickCustomer(): bool
    {
        return $this->studioContext->areCustomersEnabled() && $this->security->isGranted('studio.customers.view');
    }

    public function canCreate(): bool
    {
        return $this->security->isGranted(self::VIEW) && $this->security->isGranted(self::CREATE);
    }

    /**
     * The spaces where the person can drop the copy of a Studio deliverable:
     * the ones they see and write to, archives excluded.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function spacesToCopyInto(): array
    {
        if (!$this->studioContext->areSpacesEnabled() || !$this->security->isGranted('studio.spaces.edit')) {
            return [];
        }

        return array_values(array_filter(
            $this->spaceVisibility->visibleSpaces(),
            static fn (CustomerSpaceInterface $space): bool => !$space->isArchived(),
        ));
    }

    /**
     * Copy a space deliverable into Studio: that is creating a Studio
     * deliverable, with the module on. The copy lands in "Mes livrables".
     */
    public function canCopyToStudio(): bool
    {
        return $this->studioContext->areDeliverablesEnabled() && $this->canCreate();
    }

    /** See the space, enough to know it exists: otherwise nothing is said about it. */
    public function canReadSpace(CustomerSpaceInterface $space): bool
    {
        return $this->studioContext->areSpacesEnabled() && $this->spaceVisibility->canSee($space);
    }

    /**
     * Add one more deliverable to this space: create one, duplicate one, copy
     * a Studio template into it. Archives receive none.
     */
    public function canAddTo(CustomerSpaceInterface $space): bool
    {
        return $this->security->isGranted('studio.spaces.edit')
            && !$space->isArchived()
            && $this->spaceVisibility->canSee($space);
    }

    public function canCopyInto(CustomerSpaceInterface $space): bool
    {
        return $this->studioContext->areSpacesEnabled() && $this->canAddTo($space);
    }

    public function isOwner(DeliverableInterface $deliverable): bool
    {
        $user = $this->user();
        $owner = $deliverable->getOwner();

        return $user instanceof CoreUserInterface && $owner instanceof CoreUserInterface && $owner->getId() === $user->getId();
    }

    /** What is left from a deleted account falls to whoever administers. */
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
