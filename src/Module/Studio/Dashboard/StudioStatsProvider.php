<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\SpaceScopeEnum;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkloadRow;
use Aurora\Module\Studio\StudioContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function usort;

/**
 * Studio's numbers on the dashboard: what is waiting for me at my clients'
 * today.
 *
 * **The reader's spaces, and only those.** The numbers used to count every
 * card of every space, archived ones included, including those the reader
 * is not a member of: 13 "en attente du client" on this screen, 6 in the
 * space. They now come from {@see SpaceWorkload}, over the spaces
 * {@see SpaceVisibility} gives for the chosen scope - their own first, all
 * of them for whoever sees everything and asks for it.
 *
 * **A list rather than breakdowns.** A "where the content stands" bar does
 * not say whose place to go to; one row per space waiting on something, the
 * most urgent at the top, does.
 */
final readonly class StudioStatsProvider implements DashboardStatsProviderInterface
{
    /** Beyond this, the list stops being a list read on arrival. */
    private const int ATTENTION_LIMIT = 8;

    /**
     * The contracts sent to the client, who has not answered yet.
     *
     * Separate from those waiting on me: a single counter mixed the two
     * waits, and the one that needs a gesture from me got lost in it.
     */
    private const array WITH_CUSTOMER = [
        ContractStatusEnum::Sent,
        ContractStatusEnum::Opened,
    ];

    public function __construct(
        private SpaceVisibility $visibility,
        private SpaceWorkload $workload,
        private ContractRepository $contractRepository,
        private DeliverableRepository $deliverableRepository,
        private StudioContext $studioContext,
        private Security $security,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function getModuleKey(): string
    {
        return 'studio';
    }

    public function getStats(): array
    {
        $scope = SpaceScopeEnum::fromRequest($this->requestStack->getMainRequest()?->query->get('studioScope'));
        $spaces = [];
        foreach ($this->visibility->spacesIn($scope) as $space) {
            $spaces[(int) $space->getId()] = $space;
        }

        $rows = $this->workload->forSpaces(array_values($spaces));
        $sum = static fn (string $field): int => array_sum(array_map(static fn (SpaceWorkloadRow $row): int => $row->{$field}, $rows));

        return [
            'studio' => [
                'scope' => $scope->value,
                'hasScopeChoice' => $this->visibility->hasScopeChoice(),
                'activeSpaces' => count($rows),
                'withClient' => $sum('withClient'),
                'lateReview' => $sum('lateReview'),
                'changesRequested' => $sum('changesRequested'),
                'missed' => $sum('missed'),
                'upcoming' => $sum('upcoming'),
                'upcomingDays' => SpaceWorkload::HORIZON_DAYS,
                'awaitingSignature' => $this->countContracts(self::WITH_CUSTOMER),
                'awaitingCountersignature' => $this->countContracts([ContractStatusEnum::SignedByCustomer]),
                'deliverables' => $this->countDeliverables(),
                'deliverablesPath' => $this->deliverablesPath(),
                'attention' => $this->attention($rows, $spaces),
                'calendarPath' => $this->urlGenerator->generate('suite_studio_spaces_calendar'),
                'contractsPath' => $this->canSeeContracts() ? $this->urlGenerator->generate('suite_studio_contracts') : null,
                // Each counter opens the list on its step, and not the whole
                // list.
                'contractsWithCustomerPath' => $this->contractsPathFor('with_customer'),
                'contractsToCountersignPath' => $this->contractsPathFor('to_countersign'),
            ],
        ];
    }

    /**
     * Null for a reader who may not look at contracts: no tile rather than a
     * figure they cannot open.
     *
     * @param list<ContractStatusEnum> $statuses
     */
    private function countContracts(array $statuses): ?int
    {
        if (!$this->canSeeContracts()) {
            return null;
        }

        $counts = $this->contractRepository->countGroupedByStatus();

        return array_sum(array_map(static fn (ContractStatusEnum $status): int => $counts[$status->value] ?? 0, $statuses));
    }

    private function canSeeContracts(): bool
    {
        return $this->studioContext->areContractsEnabled() && $this->authorizationChecker->isGranted('studio.contracts.view');
    }

    /**
     * The Studio deliverables the reader opens, pages and presentations, null
     * when the module is not at hand: no tile rather than a number with no
     * destination. Those of spaces are counted in their space.
     */
    private function countDeliverables(): ?int
    {
        $user = $this->security->getUser();
        if (!$user instanceof CoreUserInterface || !$this->studioContext->areDeliverablesEnabled() || !$this->authorizationChecker->isGranted(DeliverableAccess::VIEW)) {
            return null;
        }

        return $this->deliverableRepository->countStandaloneFor($user);
    }

    private function deliverablesPath(): ?string
    {
        return $this->studioContext->areDeliverablesEnabled() && $this->authorizationChecker->isGranted(DeliverableAccess::VIEW)
            ? $this->urlGenerator->generate('suite_studio_deliverables')
            : null;
    }

    private function contractsPathFor(string $step): ?string
    {
        return $this->canSeeContracts()
            ? $this->urlGenerator->generate('suite_studio_contracts', ['step' => $step])
            : null;
    }

    /**
     * The spaces waiting on something, the most urgent at the top.
     *
     * @param list<SpaceWorkloadRow>             $rows
     * @param array<int, CustomerSpaceInterface> $spaces
     *
     * @return list<array<string, mixed>>
     */
    private function attention(array $rows, array $spaces): array
    {
        $waiting = array_values(array_filter($rows, static fn (SpaceWorkloadRow $row): bool => $row->needsAttention()));
        usort($waiting, static fn (SpaceWorkloadRow $left, SpaceWorkloadRow $right): int => $right->urgency() <=> $left->urgency());

        return array_map(function (SpaceWorkloadRow $row) use ($spaces): array {
            $space = $spaces[$row->spaceId];

            return [
                'id' => $row->spaceId,
                'name' => $space->getName(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                // On the most urgent state, filtered: the row says "2 relectures
                // en retard", the space opens on those two.
                'path' => $this->urlGenerator->generate('workspace_space_content', ['id' => $row->spaceId, 'state' => $this->mostUrgentState($row)]),
                ...$row->toArray(),
            ];
        }, array_slice($waiting, 0, self::ATTENTION_LIMIT));
    }

    private function mostUrgentState(SpaceWorkloadRow $row): string
    {
        return match (true) {
            $row->missed > 0 => 'missed',
            $row->lateReview > 0 => 'late_review',
            $row->changesRequested > 0 => 'changes_requested',
            default => 'with_client',
        };
    }
}
