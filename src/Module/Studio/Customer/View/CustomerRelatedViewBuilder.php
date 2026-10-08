<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\View;

use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\StudioContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * What surrounds a customer: their contracts, the Studio deliverables written
 * for them, their spaces.
 *
 * **Two screens show it, one way to compute it**: the customer's page, and
 * the Informations tab of each of their spaces (which then leaves out the
 * space being viewed). Both said the same thing and would have said it
 * differently at the first right forgotten on one side.
 *
 * Each list follows Studio's switches and the reader's rights: `null` for
 * what they cannot open, rather than a list of links that would answer 404
 * or 403; a colleague's personal deliverable is not in it
 * ({@see DeliverableAccess::canRead()}), nor is a space they are not a member
 * of ({@see SpaceVisibility::visibleSpaces()}).
 */
final readonly class CustomerRelatedViewBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ContractRepository $contractRepository,
        private DeliverableRepository $deliverableRepository,
        private DeliverableAccess $deliverableAccess,
        private SpaceVisibility $visibility,
        private AuthorizationCheckerInterface $authorizationChecker,
        private TranslatorInterface $translator,
        private StudioContext $studioContext,
    ) {}

    /**
     * @param CustomerSpaceInterface|null $except the space being viewed from, which does not list itself
     *
     * @return array{contracts: list<array<string, mixed>>|null, deliverables: list<array<string, mixed>>|null, spaces: list<array<string, mixed>>|null}
     */
    public function related(CustomerInterface $customer, ?CustomerSpaceInterface $except = null): array
    {
        return [
            'contracts' => $this->contracts($customer),
            'deliverables' => $this->deliverables($customer),
            'spaces' => $this->spaces($customer, $except),
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function contracts(CustomerInterface $customer): ?array
    {
        if (!$this->studioContext->areContractsEnabled() || !$this->authorizationChecker->isGranted('studio.contracts.view')) {
            return null;
        }

        return array_map(fn (ContractInterface $contract): array => [
            'id' => $contract->getId(),
            'label' => $contract->getReference() ?? $this->translator->trans('suite.studio.customers.related.draft_contract'),
            'detail' => $this->translator->trans($contract->getStatus()->getLabel()),
            'status' => $contract->getStatus()->value,
            'url' => $this->urlGenerator->generate('suite_studio_contracts_show', ['id' => $contract->getId()]),
        ], $this->contractRepository->findBy(['customer' => $customer], ['id' => 'DESC']));
    }

    /** @return list<array<string, mixed>>|null */
    private function deliverables(CustomerInterface $customer): ?array
    {
        if (!$this->studioContext->areDeliverablesEnabled() || !$this->authorizationChecker->isGranted(DeliverableAccess::VIEW)) {
            return null;
        }

        return array_values(array_map(fn (DeliverableInterface $deliverable): array => [
            'id' => $deliverable->getId(),
            'label' => $deliverable->getTitle(),
            'detail' => $this->translator->trans($deliverable->getFormat()->labelKey()),
            'url' => $this->urlGenerator->generate('suite_studio_deliverables_edit', ['id' => $deliverable->getId()]),
        ], array_filter(
            $this->deliverableRepository->findLiveStandaloneForCustomer($customer),
            $this->deliverableAccess->canRead(...),
        )));
    }

    /** @return list<array<string, mixed>>|null */
    private function spaces(CustomerInterface $customer, ?CustomerSpaceInterface $except): ?array
    {
        if (!$this->studioContext->areSpacesEnabled() || !$this->authorizationChecker->isGranted('studio.spaces.view')) {
            return null;
        }

        return array_values(array_map(fn (CustomerSpaceInterface $space): array => [
            'id' => $space->getId(),
            'label' => $space->getName(),
            'detail' => $space->isArchived() ? $this->translator->trans('suite.studio.spaces.archived_badge') : null,
            'url' => $this->urlGenerator->generate('workspace_space_content', ['id' => $space->getId()]),
        ], array_filter(
            $this->visibility->visibleSpaces(),
            static fn (CustomerSpaceInterface $space): bool => $space->getCustomer()->getId() === $customer->getId()
                && (!$except instanceof CustomerSpaceInterface || $space->getId() !== $except->getId()),
        )));
    }
}
