<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Dto\CustomerInputFactoryInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Manager\CustomerManagerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMemberInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatChannelManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentColumnManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceSync;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(CustomerSpaceManagerInterface::class)]
class CustomerSpaceManager implements CustomerSpaceManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly CustomerSpaceRepository $spaceRepository,
        protected readonly CustomerRepository $customerRepository,
        protected readonly CustomerManagerInterface $customerManager,
        protected readonly CustomerInputFactoryInterface $customerInputFactory,
        protected readonly UserRepository $userRepository,
        protected readonly SpaceContentColumnManagerInterface $columnManager,
        protected readonly SpaceChatChannelManagerInterface $chatChannels,
        protected readonly TranslatorInterface $translator,
        protected readonly SpaceContentItemManagerInterface $contentItems,
        protected readonly SpaceVisibility $visibility,
        protected readonly Security $security,
        /**
         * Optional, and last, so that a client project extending this manager
         * with its own constructor keeps booting: without it, the notes space
         * simply no longer follows the team.
         */
        protected readonly ?SpaceNoteSpaceSync $noteSpaces = null,
    ) {}

    public function create(CustomerSpaceInputInterface $input): CustomerSpaceInterface
    {
        $space = $this->createSpace();

        // The colour is picked before the row exists, so the count it spreads
        // against does not include the space being created.
        if (null === $input->getColourSlot()) {
            $space->setColourSlot($this->spaceRepository->leastUsedColourSlot());
        }

        $this->applyInput($space, $input);
        $this->makeCreatorLead($space);

        $this->entityManager->persist($space);
        $this->seedBoard($space);
        $this->entityManager->flush();

        // After the flush, because the room names the space by id. A space is
        // born with its conversation open: the client's first visit must not
        // depend on somebody from the studio having opened the tab once.
        $this->chatChannels->ensureMain($space);

        $this->auditCreated($space);

        return $space;
    }

    public function update(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        $this->refuseTeamChangeUnlessLead($space, $input);
        $this->applyInput($space, $input);
        $this->entityManager->flush();

        // Its dates carry its name and colour, and leave the calendar when it
        // is archived: they follow it rather than keep the old version.
        $this->contentItems->announceSpace($space);

        // Its note space carries its name and its team, for the same reason.
        $this->noteSpaces?->sync($space);

        $this->auditUpdated($space);
    }

    /**
     * Puts the space in the trash: it leaves the lists, the search, the
     * counts, the editorial calendar and Planning, and its screens, its client
     * page and its access links answer like an unknown address. Nothing is
     * destroyed, so a restore brings everything back as it was.
     *
     * Its dates are taken off the calendar, and its note space follows it to
     * the notes' trash, still managed by it: it comes back with it.
     */
    public function trash(CustomerSpaceInterface $space): void
    {
        if ($space->isTrashed()) {
            return;
        }

        $space->setDeletedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->contentItems->unscheduleSpace($space);
        $this->noteSpaces?->trash($space);

        $this->auditTrashed($space);
    }

    /**
     * Takes the space out of the trash: its cards go back on the calendar,
     * its links answer again, and its note space comes back if it is still
     * there and still its own.
     */
    public function restore(CustomerSpaceInterface $space): void
    {
        if (!$space->isTrashed()) {
            return;
        }

        $space->setDeletedAt(null);
        $this->entityManager->flush();

        $this->contentItems->announceSpace($space);
        $this->noteSpaces?->restore($space);

        $this->auditRestored($space);
    }

    /**
     * Destroys a space for good, with everything in it: its cards, their
     * threads and attachments, its conversations, deliverables and access
     * links go by cascade. The « Supprimer définitivement » button of the
     * trash and the scheduled purge go through here.
     *
     * The documents those attachments point at stay in the media library,
     * and the space's dates are taken off the calendar first, since no
     * cascade announces anything.
     *
     * Its notes are not in it: they live in a note space of the Notes module,
     * which is released to the notes' trash, out of the team's hands and back
     * into the administrators', who can bring it back.
     */
    public function forceDelete(CustomerSpaceInterface $space): void
    {
        $this->auditDeleted($space);
        $this->contentItems->unscheduleSpace($space);
        $this->noteSpaces?->release($space);

        $this->entityManager->remove($space);
        $this->entityManager->flush();
    }

    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int
    {
        $purged = 0;
        foreach ($this->spaceRepository->findTrashedBefore($cutoff) as $space) {
            $this->forceDelete($space);
            ++$purged;
        }

        return $purged;
    }

    /**
     * Gives the new space a board it can be used on.
     *
     * Here rather than on first visit to the board, so the columns exist before
     * anybody looks: a screen that seeds itself on read writes during a GET,
     * and two tabs opened at once then race to create the same five rows.
     *
     * A hook of its own so a client project can seed a different set, or none,
     * without reimplementing creation.
     */
    protected function seedBoard(CustomerSpaceInterface $space): void
    {
        $this->columnManager->seedDefaults($space);
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createSpace(): CustomerSpaceInterface
    {
        return new CustomerSpace();
    }

    /** Same contract as `createSpace`, for the row that joins a space to an account. */
    /**
     * Who is on the team, and who leads it, is the lead's decision.
     *
     * Here and not in a controller, so that every way of saving a space goes
     * through it: the right to edit a space was enough to send oneself back
     * as its lead. A team sent unchanged passes, so a member can still rename
     * the space.
     */
    protected function refuseTeamChangeUnlessLead(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        if ($this->visibility->canConfigure($space) || $this->teamOf($input) === $this->currentTeam($space)) {
            return;
        }

        throw new FieldException('members', $this->translator->trans('suite.studio.spaces.errors.team_lead_only'));
    }

    /**
     * Whoever creates a space leads it, unless they already see every space.
     *
     * Creating was the unguarded half: any team and any roles went through,
     * so somebody could open a space and hand it to others without being in
     * it. A creator who is not an administrator is now always on the team,
     * as its lead - which is what lets them configure the space afterwards.
     */
    protected function makeCreatorLead(CustomerSpaceInterface $space): void
    {
        $creator = $this->security->getUser();

        if ($this->visibility->seesAll() || !$creator instanceof CoreUserInterface) {
            return;
        }

        foreach ($space->getMembers() as $member) {
            if ($member->getUser()->getId() === $creator->getId()) {
                $member->setRole(CustomerSpaceMemberRoleEnum::Lead);

                return;
            }
        }

        $member = $this->createMember();
        $member->setUser($creator);
        $member->setRole(CustomerSpaceMemberRoleEnum::Lead);

        $space->addMember($member);
    }

    /**
     * The team sent, a role per person, in an order that does not depend on
     * the form. An unknown role counts as « member », as `applyMembers` reads it.
     *
     * @return array<int, string>
     */
    protected function teamOf(CustomerSpaceInputInterface $input): array
    {
        $team = [];
        foreach ($input->getMembers() as $row) {
            $team[$row['userId']] = (CustomerSpaceMemberRoleEnum::tryFrom($row['role']) ?? CustomerSpaceMemberRoleEnum::Member)->value;
        }

        ksort($team);

        return $team;
    }

    /** @return array<int, string> */
    protected function currentTeam(CustomerSpaceInterface $space): array
    {
        $team = [];
        foreach ($space->getMembers() as $member) {
            $team[(int) $member->getUser()->getId()] = $member->getRole()->value;
        }

        ksort($team);

        return $team;
    }

    protected function createMember(): CustomerSpaceMemberInterface
    {
        return new CustomerSpaceMember();
    }

    /**
     * Hydrates the entity from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        $space
            ->setName($input->getName())
            ->setDescription($input->getDescription())
            ->setCustomer($this->resolveCustomer($input))
            ->setStatus($input->getStatus())
            ->setTimezone($input->getTimezone());

        $colourSlot = $input->getColourSlot();
        if (null !== $colourSlot) {
            $space->setColourSlot($colourSlot);
        }

        $this->applyMembers($space, $input);
    }

    /**
     * The company this space is for, named or opened on the spot.
     *
     * **A space always belongs to a real customer, and that is what this
     * protects.** Somebody you are only starting to work with has no record
     * yet, and waiting until they do meant going to the customers screen,
     * inventing a legal identity you do not have, and coming back. So the form
     * may hand a name instead of an id, and a prospect is created here - a
     * customer like any other, with one column saying it has not engaged yet.
     *
     * Nothing downstream is made optional by this: the space's serialiser, the
     * page the client opens and the contracts all keep a company to point at.
     *
     * Reported as a field rejection rather than resolved to null, and under the
     * field that produced it: an unknown id is reported on the picker, a
     * missing prospect name on the name.
     */
    protected function resolveCustomer(CustomerSpaceInputInterface $input): CustomerInterface
    {
        $customerId = $input->getCustomerId();

        if (null !== $customerId) {
            $customer = $this->customerRepository->find($customerId);

            if (!$customer instanceof CustomerInterface) {
                throw new FieldException('customerId', $this->translator->trans('suite.studio.spaces.errors.customer_required'));
            }

            return $customer;
        }

        return $this->openProspect($input);
    }

    /**
     * Opens a company for somebody there is no record of yet.
     *
     * Through the customer Manager rather than around it: the audit log then
     * carries the creation the way it carries every other, and a prospect is a
     * customer created by the same path as the rest. A fixture that persisted
     * the entity directly would produce a row no code ever produced.
     *
     * **A name is all this needs.** The address is optional here and required
     * of a client, which is the one thing the status enforces: a prospect can
     * be somebody you have just met, and a space's access links carry their own
     * recipient, so nothing on that screen depends on it. Everything that makes
     * a client - the address, the registration number, the legal form, the
     * registered office - is filled in when they become one.
     */
    protected function openProspect(CustomerSpaceInputInterface $input): CustomerInterface
    {
        $name = $input->getProspectName();

        if (null === $name || '' === $name) {
            throw new FieldException('customerId', $this->translator->trans('suite.studio.spaces.errors.customer_required'));
        }

        return $this->customerManager->create($this->customerInputFactory->fromArray([
            'legalName' => $name,
            // Optional, and that is the whole point: you meet someone, open a
            // space to structure the work, and have only their name. The
            // space's access links carry their own recipient, so nothing here
            // depends on it.
            'contractualEmail' => $input->getProspectEmail(),
            'status' => CustomerStatusEnum::Prospect->value,
        ]));
    }

    /**
     * Brings the membership rows in line with what the form sent.
     *
     * Reconciled rather than cleared and rebuilt: dropping every row and
     * inserting the set back would change each member's id on every save, and
     * ids that churn are what makes an audit trail stop being one. Existing
     * rows keep theirs and only their role moves.
     *
     * An id that no longer resolves to an account is skipped. The picker is fed
     * from the account list, so the only way to send an unknown one is to have
     * edited the payload, or to have had somebody deleted mid-form.
     */
    protected function applyMembers(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        $wanted = [];
        foreach ($input->getMembers() as $row) {
            $wanted[$row['userId']] = CustomerSpaceMemberRoleEnum::tryFrom($row['role']) ?? CustomerSpaceMemberRoleEnum::Member;
        }

        foreach ($space->getMembers()->toArray() as $member) {
            $userId = (int) $member->getUser()->getId();

            if (!isset($wanted[$userId])) {
                $space->removeMember($member);

                continue;
            }

            $member->setRole($wanted[$userId]);
            unset($wanted[$userId]);
        }

        if ([] === $wanted) {
            return;
        }

        $users = $this->userRepository->findBy(['id' => array_keys($wanted)]);

        foreach ($users as $user) {
            $member = $this->createMember();
            $member->setUser($user);
            $member->setRole($wanted[(int) $user->getId()]);

            $space->addMember($member);
        }
    }

    protected function auditCreated(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.created', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditUpdated(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.updated', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditTrashed(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.trashed', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditRestored(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.restored', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditDeleted(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.deleted', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    /**
     * Structured payload logged with every audit entry. Override to add
     * extra fields: `[...parent::auditPayload($space), 'code' => $space->getCode()]`.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(CustomerSpaceInterface $space): array
    {
        return [
            'name' => $space->getName(),
            'customerId' => $space->getCustomer()->getId(),
            'customerName' => $space->getCustomer()->getLegalName(),
            'status' => $space->getStatus()->value,
            'memberCount' => $space->getMembers()->count(),
        ];
    }
}
