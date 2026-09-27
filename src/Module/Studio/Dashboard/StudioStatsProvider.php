<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\SpaceScopeEnum;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkloadRow;
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
 * Les chiffres du Studio sur le tableau de bord : ce qui m'attend chez mes
 * clients aujourd'hui.
 *
 * **Les espaces du lecteur, et eux seuls.** Les chiffres comptaient toutes
 * les cartes de tous les espaces, archivés compris, y compris ceux dont le
 * lecteur n'est pas membre : 13 « en attente du client » sur cet écran, 6
 * dans l'espace. Ils viennent maintenant de {@see SpaceWorkload}, sur les
 * espaces que {@see SpaceVisibility} donne pour la portée choisie - les
 * siens d'abord, tous pour qui voit tout et le demande.
 *
 * **Une liste plutôt que des répartitions.** Une barre « où en sont les
 * contenus » ne dit pas chez qui aller ; une ligne par espace qui attend
 * quelque chose, la plus urgente en haut, si.
 */
final readonly class StudioStatsProvider implements DashboardStatsProviderInterface
{
    /** Au-delà, la liste cesse d'être une liste qu'on lit en arrivant. */
    private const int ATTENTION_LIMIT = 8;

    /** Les contrats qui attendent une signature, d'un côté ou de l'autre. */
    private const array AWAITING_SIGNATURE = [
        ContractStatusEnum::Sent,
        ContractStatusEnum::Opened,
        ContractStatusEnum::SignedByCustomer,
    ];

    public function __construct(
        private SpaceVisibility $visibility,
        private SpaceWorkload $workload,
        private ContractRepository $contractRepository,
        private DeckRepository $deckRepository,
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
                'awaitingSignature' => $this->awaitingSignature(),
                'decks' => $this->authorizationChecker->isGranted('studio.decks.view') ? $this->deckRepository->count([]) : null,
                'attention' => $this->attention($rows, $spaces),
                'calendarPath' => $this->urlGenerator->generate('backend_studio_calendar'),
                'contractsPath' => $this->authorizationChecker->isGranted('studio.contracts.view') ? $this->urlGenerator->generate('backend_studio_contracts') : null,
            ],
        ];
    }

    /** Null for a reader who may not look at contracts: no tile rather than a figure they cannot open. */
    private function awaitingSignature(): ?int
    {
        if (!$this->authorizationChecker->isGranted('studio.contracts.view')) {
            return null;
        }

        $counts = $this->contractRepository->countGroupedByStatus();

        return array_sum(array_map(static fn (ContractStatusEnum $status): int => $counts[$status->value] ?? 0, self::AWAITING_SIGNATURE));
    }

    /**
     * Les espaces qui attendent quelque chose, le plus urgent en haut.
     *
     * @param list<SpaceWorkloadRow>             $rows
     * @param array<int, CustomerSpaceInterface> $spaces
     *
     * @return list<array<string, mixed>>
     */
    private function attention(array $rows, array $spaces): array
    {
        $waiting = array_values(array_filter($rows, static fn (SpaceWorkloadRow $row): bool => $row->needsAttention()));
        usort($waiting, static fn (SpaceWorkloadRow $a, SpaceWorkloadRow $b): int => $b->urgency() <=> $a->urgency());

        return array_map(function (SpaceWorkloadRow $row) use ($spaces): array {
            $space = $spaces[$row->spaceId];

            return [
                'id' => $row->spaceId,
                'name' => $space->getName(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
                // Sur l'état le plus urgent, filtré : la ligne dit « 2 relectures
                // en retard », l'espace s'ouvre sur ces deux-là.
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
